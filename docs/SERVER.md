# Server

PHP 8.4 and SQLite, served by nginx + php-fpm. No framework: a small PSR-4 router and handlers. Runtime
dependencies are PHP extensions only (all of them declared in `composer.json` under `require`, e.g.
`ext-pdo_sqlite`, `ext-apcu`, `ext-hash`).

There is no user management. The server consists of

- public URLs: the about page `/`, the download pages and downloads (`/file/...`, `/dl/...`), the static pages of a host
  (`/page/<name>`) and its stylesheet (`/assets/user.<hash>.css`)
- a token-guarded HTTP API under `/api/v1` (see [API.md](API.md)), which also changes the look of the public pages
  per host ([Appearance](#appearance))
- the CLI script `lr-auth` to manage tokens

## Deployment layout

```
{deployroot}/htdocs   public folder served by nginx; `try_files` sends everything that is not there to /index.php
{deployroot}/app          all code
{deployroot}/app_data     application data, never touched by deployments
```

`app_data` contains the list of hosts and one folder per host (a tenant):

```
app_data/global-config.php          the hosts this installation answers for
app_data/<host>/config.php           configuration of that host, merged over the defaults in code
app_data/<host>/linkrot.sqlite       its database (WAL mode, busy_timeout set)
app_data/<host>/files/<id>           its uploaded files, named by id only
app_data/<host>/cleanup.lock         lock file for its cleanup
app_data/<host>/custom/              what the host replaced through the API (only exists after an upload):
                                     taglines.txt, headerlines.txt, user.<hash>.css, css.json (which stylesheet is
                                     current, which ones were retired when), css.lock
app_data/<host>/pages/               its static pages (only exists after an upload): <name>.md (the original), <name>.html
                                     (rendered body), index.json (title, link, order, ... of all), pages.lock
```

### Tenants

One installation serves any number of hostnames (subdomains of one domain or completely different domains). Every
host is a tenant of its own: own configuration, tokens, files, cleanup and failed-login counters. Nothing is shared
but the code.

`app_data/global-config.php` lists the allowed hosts, lower case, without port:

```php
<?php
return [
    'hosts' => [
        'files.example.com',
        'share.example.org',
    ],
];
```

The `Host` header of a request is normalized (lower case, port and trailing dot removed) and must equal an entry of the
list. Any other host, including a malformed one, is answered with `421 Misdirected Request` before anything under
`app_data` is touched; the header never reaches a path unchecked. A listed host whose folder or `config.php` is
missing is answered with "server misconfigured". `app/bin/hosts` prints the list, `install.sh --add-host <host>`
enrolls a new host.

The command line tools (`lr-auth`, `check-config`, `check-install`, `migrate`) work on the host named in
`LINKROT_HOST`. It can be omitted if exactly one host is configured; `migrate` without it migrates every host.

All folders are owned by the user nginx and php-fpm run as. `lr-auth` is run as that same user, so
no special permission handling is needed.

Uploaded files are never in `htdocs`. nginx has an `internal` location mapped to `app_data/`; PHP
authorises the request and answers with `X-Accel-Redirect: /_files/<host>/files/<id>`. See [nginx.conf.example](nginx.conf.example).

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
| `size` | declared size in bytes; null for a streaming upload until it is sealed |
| `sha256` | declared sha256 (hex), unique index; null for a streaming upload until it is sealed (the unique index allows any number of nulls) |
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

`meta`: key/value, holds the settings of the host (`setting.<name>` with `1` or `0`, one row per setting that was ever
changed, see [Appearance](#appearance)), `last_cleanup` (the last run that finished, drives the interval), `last_cleanup_ok` (the last run
that finished without any error) and `last_cleanup_error` (JSON with time and message of the last failed run or run that
could not remove a file; the next clean run drops it). The health endpoint reports them ([API.md](API.md)).

### Filename handling

- the user supplied name is never used on disk, files are stored as `app_data/<host>/files/<id>`
- the name is reduced to a base name, control characters, path separators and NUL are stripped, length limited,
  empty names are rejected
- `Content-Disposition` carries an ASCII fallback `filename=` plus RFC 5987 `filename*=UTF-8''...`
- in URLs the name is `rawurlencode`d, in HTML it is escaped

## Upload

Details of the HTTP protocol are in [API.md](API.md). Server side behaviour:

1. **Create.** The client sends name, size, sha256 and optional `rm_after` / `uses`. The server
   - rejects files over `max_file_size`, files that would take the host over its `quota` (`507 quota_exceeded`) and files for which `disk_free_space` is insufficient
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
   - read the freshly written region back in 1 MiB blocks (page cache is hot) and feed each block to two hashes: the
     chunk's, compared with the digest, and the running sha256 of the whole file. On mismatch the DB offset is **not** advanced; the garbage beyond the committed offset is overwritten
     by the retry and `422 chunk_hash_mismatch` is returned
   - `fflush` + `fsync`, then advance `offset` and `updated_at` in one statement together with the running hash
     (`files.hash_state`, a serialized `HashContext`), so state and offset can never disagree. If the state is missing
     or unusable (an upload from before this column existed, a manual edit) it is rebuilt once from the bytes before the
     offset
5. **Completion.** The running hash is finished with the last chunk and compared against the declared sha256, the
   file is not read again, so the last request is as short as any other. Match: status `ready`, `completed_at` and `expires_at` set, response carries the URL. Mismatch: file and
   row are deleted and `422 file_hash_mismatch` is returned, the client has to start over.

6. **Streaming uploads** (`stream: true`, see [API.md](API.md#streaming-uploads)) differ in three places. Create inserts the row
   with null `size` and `sha256` and an empty data file, without any lookup by hash (so also without resume or
   duplicate check). Chunks are written exactly like above, the data file just grows, but no chunk completes the upload
   and `max_file_size` is checked per chunk. The **seal** takes the same lock as a chunk, requires the size to equal the
   offset, finishes the running hash (rebuilt from the file if the stored state is unusable) and compares it with the
   given sha256, trims the data file to the size (bytes of a rejected chunk may lie behind the offset), and only then
   looks for a duplicate: a ready file with the same sha256 wins and the stream is deleted, an incomplete upload of the
   same bytes is deleted, a stale one is cleaned up. Abandoned streams are removed by the cleanup like other uploads
   (`upload_timeout` after the last chunk). Schema version 3 made `size` and `sha256` nullable.

PHP limits are irrelevant because the body is read raw from `php://input` (no multipart) and chunks are small
(10-100 MB range, default 32 MiB). nginx `client_max_body_size` must be at least `chunk_size + 32` plus margin.

## Download

- `GET /`: a small page about linkrot itself, in the style of the download pages: what it is, three steps, and a button
  to the project on GitHub. Static text only: no script, no commands and nothing about the files or the configuration of
  the instance. With `show_startpage` off it is an empty page (`200`, empty body). Never cached (`Cache-Control:
  no-store`), so a changed setting is visible at once.
- `GET /file/{id}/{name}`: simple page with filename, size, a download button and a QR button (needs the script) that opens a modal with the QR code of the page itself, generated on the server as inline SVG with `bacon/bacon-qr-code`, and a "From a terminal" section with the `linkrot down` command, the curl command and the SHA-256 (each with a copy button; each line and, without any line, the whole section can be switched off, see [Appearance](#appearance)). No expiry shown. If `{name}` does
  not match the stored name, answer `302` to the canonical URL. Everything is HTML-escaped and the page is served
  with a strict CSP (`default-src 'none'; img-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; script-src 'self'`:
  stylesheet and script come from this origin only, `htdocs/assets/page.css` and `page.js`, there is no inline script and
  no inline style element; the style attribute is allowed for the icon colour only; the custom stylesheet of a host is
  also from this origin, so the policy needs no change). A release build renames the assets
  after their content (`page.<hash>.css`, mapping in `app/assets.php`, `scripts/build-assets.sh`), so nginx can cache
  them forever, see [nginx.conf.example](nginx.conf.example). Viewing the page never consumes a use, so
  chat link previews are harmless.
- `GET /dl/{id}/{name}`: the actual download. The name segment is not checked (the stored name is always used in
  the headers). Headers: `Content-Disposition: attachment` (always enforced), `Content-Type:
  application/octet-stream`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, and
  `X-Accel-Redirect: /_files/<host>/files/<id>`. nginx adds `Content-Length` and handles `Range`.
  For the nerds there is also `X-Rot: <line>`, a tagline in the shape of header parameters (`ripe; mold=low; ...`).
  A file always gets the same line, from the built-in list or from the `headerlines` file of the host; with
  `show_taglines` off there is no `X-Rot` header at all. nginx drops it unless the `/_files/` location passes it on with
  `add_header X-Rot $upstream_http_x_rot always;` (in [nginx.conf.example](nginx.conf.example)); without that line
  the header is just missing.
- A file is only served if it is `ready`, not past `expires_at` and not exhausted. A file that is past its time
  is treated as **not found** and removed on the spot.
- **Uses.** A `GET` counts as a use if it has no `Range` header or a range starting at 0. `HEAD` and the
  `/file` page do not count. The decrement is one atomic statement (`UPDATE ... SET uses_left = uses_left - 1
  WHERE uses_left > 0`), so concurrent downloads cannot exceed the limit. When `uses_left` reaches 0 the file is
  treated as expired for all following requests; the physical deletion is left to the cleanup, because
  unlinking before nginx has opened the file would break the download that just consumed the last use.
- Config switch `download_mode = xaccel|php`. `php` streams the file from PHP; it exists for development and
  end-to-end tests with `php -S` and must not be used in production.

## Appearance

What a host can change about its public pages, through the API (`lr config`, `lr custom`; details and limits in
[API.md](API.md)). Nothing here is in `config.php`, because the users of a host change it, not the operator.

**Settings** are six booleans, all `true` until changed: `show_taglines`, `show_startpage`, `show_title`,
`show_linkrot_line`, `show_curl_line`, `show_hash_line`. They are rows `setting.<name>` in the `meta` table of the host
(`app/src/Settings.php`, `SettingsStore.php`), so there is no schema change, and are read on every page request.
`show_taglines` covers the footer of every page and the `X-Rot` header; `show_title` the headline with the logo on
every page; `show_startpage` the page at `/`; the three line settings the commands of the download page, which loses its
whole "From a terminal" section when all three are off.

**Custom files** are `taglines` (footer lines, one per line), `headerlines` (the `X-Rot` lines) and `css`, in
`app_data/<host>/custom/`:

- the built-in version is only served, never written: `GET` returns the built-in lines (or, for `css`, a starter file
  with the color variables) as long as nothing was uploaded, so a release that adds taglines reaches every host that
  did not replace them. A reset deletes the file
- an upload is validated (UTF-8, control characters, line and byte limits; header lines must be printable ASCII because
  they become a header value) and replaces the file atomically (temp file and rename). A refused upload changes nothing
- the taglines and header lines of a host replace the built-in ones completely; a file picks its line by
  `crc32(id) % count`, so it keeps its line as long as the list does not change. With `show_taglines` off neither is used
- the stylesheet is linked after the built-in one: `<link rel="stylesheet" href="/assets/user.<hash>.css">`, the hash
  being the first 16 hex characters of the sha256 of the content. `GET /assets/user.<hash>.css` is public and served by PHP
  from the host's folder with `Cache-Control: public, max-age=31536000, immutable` (nginx hands names it has no file for
  to `index.php`, see [nginx.conf.example](nginx.conf.example)). A host can only reach its own files, and only names
  matching `user.<16 hex>.css`
- a replaced or reset stylesheet is retired (`custom/css.json`: current hash, retired hashes with the time), not
  deleted: pages that were loaded a moment ago still ask for it. At most 5 are kept; the cleanup removes the others
  after 24 hours. Changes of that file are serialized with `custom/css.lock`

### Static pages

A host can upload Markdown pages (`lr page`, [API.md](API.md#get-pages-getputdelete-pagesname-get-page-template---static-pages)),
mainly for an imprint. Every published page is linked in the footer of every page of the host (`<nav class="pages">`,
sorted by `order`, then link text); the empty start page of `show_startpage` off is not a page and has no footer.

- **Upload.** `PageStore::put` checks the size, UTF-8 and the slug, parses the head (`Frontmatter`: `title`, `link`,
  `order`, `published`, `lang`; a deliberately tiny format, not YAML), and renders the Markdown with `league/commonmark`
  (`PageRenderer`) once. It writes `<name>.html`, then `<name>.md` (the upload, byte for byte: `get` returns it
  unchanged), then `index.json`, under `pages/pages.lock`; files are written atomically (temp file and rename).
- **Strict.** The renderer refuses what it would otherwise have to guess about: raw HTML (also stripped by the library as
  a second line of defense), links with a scheme other than https, http, mailto and tel, images other than https,
  `data:image/png|gif|jpeg|webp;base64` and paths on the server, and `//host` URLs. The error names the line. Nesting is
  limited. External links get `rel="nofollow noopener noreferrer"`, images `loading="lazy"` and `referrerpolicy="no-referrer"`,
  headings move down one level. Tables, strikethrough and autolinks are on.
- **Serving.** A request only reads `index.json` (for the footer) and the `.html` of the page, never the Markdown, and does
  no Markdown work. `/page/<name>` is a normal page of the site (the custom stylesheet and taglines apply), with the
  `custompage` and `custompage_<name>` classes on `<body>`, and with the CSP of the other pages except `img-src 'self'
  https: data:`. The `ETag` is a hash of the whole page, because the footer links and the look of the host are part of it.
  A draft and an unknown name are the same 404.
- **Cache is disposable.** `.md` is the truth. If `index.json` is missing or damaged, or was made by an older
  `PageRenderer::VERSION` (raised whenever the output for the same Markdown can change, for example with a library update),
  the next request renders every page again from its `.md`. A page the stricter rules no longer accept stays downloadable
  and listed, as a draft.
- **Limits.** 20 pages, 64 KB each, title 100 and link text 40 characters.

## Cleanup

There is no cronjob. Any request may run the cleanup **after** the response has been sent
(`fastcgi_finish_request`), when `now - last_cleanup > cleanup_interval` (default 8h). It can also be triggered by
`linkrot cleanup` (`POST /api/v1/cleanup`, ignores the interval).

Locking: `flock(LOCK_EX | LOCK_NB)` on `app_data/<host>/cleanup.lock`. If somebody else holds it, skip. After taking the
lock, re-check `last_cleanup` so a second request does not repeat the work.

Cleanup does three steps in this order:

1. **Database first, one quick transaction.** Delete rows that are
   - `ready` and past `expires_at`, or with `uses_left = 0`
   - `uploading` with `updated_at` older than `upload_timeout` (default 1d)
   - without a data file and older than `orphan_row_timeout` (default 1h)
2. **Then files.** Delete every file in `app_data/<host>/files/` that is unknown to the database. Files younger than a short
   grace period are skipped so an upload that is just being created is never touched. Data files of the rows removed
   in step 1 are removed as part of this.
3. **Retired stylesheets.** A replaced or reset custom stylesheet (`custom/user.<hash>.css`, see
   [API.md](API.md#getputdelete-customname---files-that-replace-the-built-in-look)) is retired, not deleted, because a
   page that was loaded a moment ago still links it. This step removes the ones retired more than 24 hours ago.

Finally `last_cleanup` is written (and `last_cleanup_ok` if every file that had to go is gone, otherwise
`last_cleanup_error`) and a one-line result (counts, failures) is logged.

Independently of the cleanup, a request that touches an expired file treats it as not found and deletes it
(see Download).

## Authentication

- API requests carry `Authorization: Bearer <token>`. Everything under `/api/v1` requires it; the public URLs do not:
  `/`, `/file/`, `/dl/` and the stylesheet of a host (`/assets/user.<hash>.css`).
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

`app_data/<host>/config.php` returns an array which is merged over the defaults (`return [];` is valid). The public URL of a host, used for share links, is not configured: it is `https://<host>` with the host from `global-config.php`. Only `localhost` and `127.x` hosts get `http://` plus the port of the request (development). A leftover `base_url` key makes the config invalid. The settings and custom files that the users of a host change through the API are not in this file, see [Appearance](#appearance).

| key | default | meaning |
| --- | --- | --- |
| `chunk_size` | 32 MiB | must be within 10-100 MB |
| `max_file_size` | 0 (unlimited) | upper bound for a single file |
| `quota` | 0 (unlimited) | most data this host may hold at once, as a size: `100`, `100b`, `100kb`, `100mb`, `100gb`, `1tb` (decimal units, case does not matter) or an integer (bytes). Counts every live file and running upload (a sized upload with its full size from the start, a streaming one with what it has received); expired files the cleanup has not removed yet do not count. Uploads over it are refused with `507 quota_exceeded`. Concurrent creates can overshoot it by what they reserve in the same moment |
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
- the custom stylesheet is same-origin CSS from the token holder of the host. The page CSP (`default-src 'none'`,
  `style-src 'self'`) keeps it from loading anything from elsewhere, and a stylesheet cannot run script; it can change how
  the pages of that host look, nothing else
