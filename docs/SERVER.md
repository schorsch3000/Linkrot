# Server

PHP 8.4 and SQLite, served by nginx + php-fpm. No framework: a small PSR-4 router and handlers. Runtime
dependencies are PHP extensions only (all of them declared in `composer.json` under `require`, e.g.
`ext-pdo_sqlite`, `ext-apcu`, `ext-hash`).

There is no user management. The server consists of

- public download URLs (`/file/...`, `/dl/...`)
- a token-guarded HTTP API under `/api/v1` (see [API.md](API.md))
- the CLI script `lr-auth` to manage tokens

## Deployment layout

```
{deployroot}/htdocs   public folder served by nginx; `try_files` sends everything that is not there to /index.php
{deployroot}/app          all code
{deployroot}/app_data     application data, never touched by deployments
```

`app_data` contains:

```
app_data/config.php       configuration, merged over the defaults in code
app_data/linkrot.sqlite   database (WAL mode, busy_timeout set)
app_data/files/<id>       uploaded files, named by id only
app_data/cleanup.lock     lock file for the cleanup
```

All three folders are owned by the user nginx and php-fpm run as. `lr-auth` is run as that same user, so
no special permission handling is needed.

Uploaded files are never in `htdocs`. nginx has an `internal` location mapped to `app_data/files/`; PHP
authorises the request and answers with `X-Accel-Redirect`. See [nginx.conf.example](nginx.conf.example).

## Identifiers

The public id of a file is also its primary key and is used by the CLI for `rm`, `set`, `down` and `info`.

- 16 characters, generated with the CSPRNG (`random_int` per character, no modulo bias)
- characters 1-4 come from the **lowercase set** (32 characters: digits `2-9` plus `a-z` without `l` and `o`),
  which makes typing and shell completion of the start of an id easier
- characters 5-16 come from the **full set** (56 characters: digits `2-9`, `a-z` and `A-Z`, without `1 0 l o I O`)
- about 90 bits of entropy; collisions are handled by the unique index plus retry
- ids are case sensitive; the column uses `BINARY` collation and nginx must not lowercase paths
- an id is only accepted as exactly 16 characters of the right alphabets; anything else is `404` without a DB lookup

The id is the secret. A file in state `uploading` is not reachable under `/file` or `/dl`.

## Data model

All times are UTC unix seconds.

`files`

| column | meaning |
| --- | --- |
| `id` | public id, primary key |
| `name` | display / download filename (sanitised, see below) |
| `size` | declared size in bytes |
| `sha256` | declared sha256 (hex), unique index |
| `status` | `uploading` or `ready` |
| `upload_offset` | number of verified bytes received (authoritative; the API calls it `offset`) |
| `created_at` | row created |
| `updated_at` | last chunk or metadata activity |
| `completed_at` | upload completed, null while uploading |
| `rm_after` | requested lifetime in seconds (already capped) |
| `expires_at` | `completed_at + rm_after`, null while uploading |
| `uses_left` | remaining downloads, null = unlimited |

`tokens`

| column | meaning |
| --- | --- |
| `name` | unique, `^[a-z-]+$`, max 32 characters; usually the device name |
| `hash` | sha256 of the token; the token itself is never stored |
| `created_at`, `last_used_at` | `last_used_at` is updated at most once per hour to avoid a write per request |

`meta`: key/value, holds `last_cleanup`.

### Filename handling

- the user supplied name is never used on disk, files are stored as `app_data/files/<id>`
- the name is reduced to a base name, control characters, path separators and NUL are stripped, length limited,
  empty names are rejected
- `Content-Disposition` carries an ASCII fallback `filename=` plus RFC 5987 `filename*=UTF-8''...`
- in URLs the name is `rawurlencode`d, in HTML it is escaped

## Upload

Details of the HTTP protocol are in [API.md](API.md). Server side behaviour:

1. **Create.** The client sends name, size, sha256 and optional `rm_after` / `uses`. The server
   - rejects files over `max_file_size` and files for which `disk_free_space` is insufficient
   - looks up the sha256. Expired-but-uncleaned matches are deleted first. A live match with status `uploading`
     is a **resume**: same id, current offset, metadata updated from the new request. A live match with status
     `ready` is **refused** with `409 duplicate` carrying the id and URL of the existing file
   - otherwise creates the row (`uploading`) and the data file, sized with `ftruncate` (sparse). Only stdlib
     functionality is used; there is no `fallocate`. The `disk_free_space` check at create is the early warning,
     and a write failure mid-upload answers `507` and leaves the upload resumable
2. **Chunks are strictly sequential.** Every chunk is `chunk_size` bytes except the last one. The request body is
   `chunk bytes || sha256(chunk bytes)` (32 raw bytes), so a client can hash while it streams and just append the
   digest at the end. There is no per-chunk hash header and no trailer, because trailers do not reach php-fpm.
3. nginx buffers the request body (`fastcgi_request_buffering on`), so **no PHP is running while the bytes are on
   the wire**. The PHP request is short.
4. In PHP, for a chunk request:
   - take `flock(LOCK_EX | LOCK_NB)` on the data file; if busy answer `409 locked` (`Retry-After: 1`). `flock` is
     released automatically when the process dies, so no lease/timeout logic is needed
   - inside the lock check that `Upload-Offset` equals the DB offset, otherwise `409 offset_mismatch` with the
     current offset
   - `fseek` to the offset, `stream_copy_to_stream(php://input, $file, $length)` so no data is handled in PHP
     userland, then `fread(php://input, 32)` for the trailing digest
   - verify with `hash_update_stream` over the freshly written region (page cache is hot) and compare with the
     digest. On mismatch the DB offset is **not** advanced; the garbage beyond the committed offset is overwritten
     by the retry and `422 chunk_hash_mismatch` is returned
   - `fflush` + `fsync`, then advance `offset` and `updated_at` in a quick transaction
5. **Completion.** After the last chunk the whole file is re-read once and compared against the declared
   sha256. Match: status `ready`, `completed_at` and `expires_at` set, response carries the URL. Mismatch: file and
   row are deleted and `422 file_hash_mismatch` is returned, the client has to start over.

PHP limits are irrelevant because the body is read raw from `php://input` (no multipart) and chunks are small
(10-100 MB range, default 32 MiB). nginx `client_max_body_size` must be at least `chunk_size + 32` plus margin.

## Download

- `GET /`: a small page about linkrot itself, in the style of the download pages: what it is, three steps, and a button
  to the project on GitHub. Static text only: no script, no commands and nothing about the files or the configuration of
  the instance.
- `GET /file/{id}/{name}`: simple page with filename, size, a download button and a QR button (needs the script) that opens a modal with the QR code of the page itself, generated on the server as inline SVG with `bacon/bacon-qr-code`. No expiry shown. If `{name}` does
  not match the stored name, answer `302` to the canonical URL. Everything is HTML-escaped and the page is served
  with a strict CSP (`default-src 'none'; style-src 'unsafe-inline'`). Viewing the page never consumes a use, so
  chat link previews are harmless.
- `GET /dl/{id}/{name}`: the actual download. The name segment is not checked (the stored name is always used in
  the headers). Headers: `Content-Disposition: attachment` (always enforced), `Content-Type:
  application/octet-stream`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, and
  `X-Accel-Redirect: /_files/<id>`. nginx adds `Content-Length` and handles `Range`.
- A file is only served if it is `ready`, not past `expires_at` and not exhausted. A file that is past its time
  is treated as **not found** and removed on the spot.
- **Uses.** A `GET` counts as a use if it has no `Range` header or a range starting at 0. `HEAD` and the
  `/file` page do not count. The decrement is one atomic statement (`UPDATE ... SET uses_left = uses_left - 1
  WHERE uses_left > 0`), so concurrent downloads cannot exceed the limit. When `uses_left` reaches 0 the file is
  treated as expired for all following requests; the physical deletion is left to the cleanup, because
  unlinking before nginx has opened the file would break the download that just consumed the last use.
- Config switch `download_mode = xaccel|php`. `php` streams the file from PHP; it exists for development and
  end-to-end tests with `php -S` and must not be used in production.

## Cleanup

There is no cronjob. Any request may run the cleanup **after** the response has been sent
(`fastcgi_finish_request`), when `now - last_cleanup > cleanup_interval` (default 8h). It can also be triggered by
`linkrot cleanup` (`POST /api/v1/cleanup`, ignores the interval).

Locking: `flock(LOCK_EX | LOCK_NB)` on `app_data/cleanup.lock`. If somebody else holds it, skip. After taking the
lock, re-check `last_cleanup` so a second request does not repeat the work.

Cleanup does two steps in this order:

1. **Database first, one quick transaction.** Delete rows that are
   - `ready` and past `expires_at`, or with `uses_left = 0`
   - `uploading` with `updated_at` older than `upload_timeout` (default 1d)
   - without a data file and older than `orphan_row_timeout` (default 1h)
2. **Then files.** Delete every file in `app_data/files/` that is unknown to the database. Files younger than a short
   grace period are skipped so an upload that is just being created is never touched. Data files of the rows removed
   in step 1 are removed as part of this.

Finally `last_cleanup` is written and a one-line result (counts) is logged.

Independently of the cleanup, a request that touches an expired file treats it as not found and deletes it
(see Download).

## Authentication

- API requests carry `Authorization: Bearer <token>`. Everything except `/file/` and `/dl/` requires it.
- Tokens are 32 random bytes, encoded as `lr_` + base64url. The server stores only `sha256(token)` and looks the
  token up by hash, so there is nothing to compare in a timing-sensitive way.
- `last_used_at` is bumped at most once per hour.

### `lr-auth`

PHP CLI script (`server/app/bin/lr-auth`), run as the web user:

```
lr-auth get <name>   create a token for <name>, print it once to stdout (names are unique, ^[a-z-]+$)
lr-auth del <name>   delete the token of <name>; it can no longer be used
lr-auth ls           list tokens: name and last used date only
```

The raw token cannot be shown again, so `del` takes the name (the original idea was `del <token>`, but only hashes are
stored).

## Rate limiting

nginx has a fixed `limit_req` which only protects against DoS. On top of that the application limits failed
authentication itself:

- storage is **APCu** (`apcu_inc` is atomic and shared between php-fpm workers); no database and no session files,
  because both come with locking
- key `rl:` + `sha256(client ip)`; IPv6 addresses are reduced to their `/64` first; the address is `REMOTE_ADDR`
  (the operator configures nginx `real_ip` if there is a proxy)
- fixed window: default 10 failed attempts per 15 minutes, configurable, hard limit: further requests get
  `429` with `Retry-After`, valid tokens included, until the window ends
- the limiter sits behind an interface; tests use an in-memory implementation because APCu is off in the CLI
- counters are lost when php-fpm restarts, which is accepted

## Configuration

`app_data/config.php` returns an array which is merged over the defaults:

| key | default | meaning |
| --- | --- | --- |
| `base_url` | (required) | public URL used to build share links |
| `chunk_size` | 32 MiB | must be within 10-100 MB |
| `max_file_size` | 0 (unlimited) | upper bound for a single file |
| `default_rm_after` | `1w` | lifetime if the client does not ask for one |
| `max_rm_after` | `1y` | **mandatory cap** for any requested lifetime; can be set to e.g. `1000y`, that is an explicit decision, not a default |
| `default_uses` | unlimited | download limit if the client does not ask for one |
| `cleanup_interval` | `8h` | minimum time between lazy cleanups |
| `upload_timeout` | `1d` | unfinished uploads without activity are removed |
| `orphan_row_timeout` | `1h` | rows without a data file are removed |
| `rate_limit_attempts` / `rate_limit_window` | 10 / 15 min | failed-auth limiter |
| `download_mode` | `xaccel` | `xaccel` or `php` (development only) |

### Durations

Format `<n><unit>` with units `h`, `d`, `w`, `m`, `y`. `m` is **month**. Smallest unit is 1 hour, there are no minutes
or seconds. For calculation `d = 24h`, `w = 7d`, `m = 30d`, `y = 365d`. Requested values above `max_rm_after`
are rejected with `400`, not silently clamped.

## Security notes

- download is always `attachment` with `nosniff`; there is no content type guessing from user names
- ids are unguessable and are the only secret on public URLs
- rate limiting only counts failed authentication; guessing ids is mitigated by 90 bits of entropy and the nginx limit
- SQLite is used in WAL mode; all statements are prepared
- the data directory is outside of the document root
