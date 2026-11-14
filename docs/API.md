# HTTP API

Base path `/api/v1`. All endpoints need `Authorization: Bearer <token>` except the public download URLs at the
end of this document. Request and response bodies are JSON unless stated otherwise. Times are UTC unix seconds.
Durations are strings like `1h`, `2d`, `3w`, `4m`, `5y` (see [SERVER.md](SERVER.md#durations)).

## File object

```json
{
  "id": "abcd7KfRtm4pWxnZ",
  "name": "report.pdf",
  "size": 1234567,
  "sha256": "9f86d0...",
  "status": "ready",
  "offset": 1234567,
  "created_at": 1760000000,
  "completed_at": 1760000100,
  "expires_at": 1760604900,
  "uses_left": null,
  "url": "https://linkrot.example/file/abcd7KfRtm4pWxnZ/report.pdf"
}
```

`status` is `uploading` or `ready`. `expires_at` and `completed_at` are `null` while uploading. `size` and `sha256` are
`null` while a [streaming upload](#streaming-uploads) is not sealed yet. `uses_left` is `null`
for unlimited. `url` is only present when `status` is `ready`.

## Upload protocol

```
client                                  server
  | POST /files {name,size,sha256,...}    |
  |-------------------------------------->|  create or resume
  |<-- {id, chunk_size, offset}           |
  | PATCH /files/{id}/content             |
  |   Upload-Offset: N                    |
  |   body = chunk || sha256(chunk)       |
  |-------------------------------------->|  verify, advance
  |<-- {offset}                           |
  |        ... repeat ...                 |
  | PATCH (last chunk)                    |
  |-------------------------------------->|  verify chunk and whole file
  |<-- file object with url               |
```

A client that lost its connection asks `GET /files/{id}` or simply repeats the create request; both give the current
offset.

### `POST /files` - create or resume

Request:

```json
{ "name": "report.pdf", "size": 1234567, "sha256": "9f86d0...", "rm_after": "2d", "uses": 5 }
```

`rm_after` and `uses` are optional; the server defaults apply. `size` >= 0 (a zero byte file is complete right away).

| status | meaning | body |
| --- | --- | --- |
| `201` | new upload created (a zero byte file is already `ready`) | file object plus `chunk_size` |
| `200` | resume of an incomplete upload with the same sha256; metadata is replaced by the values of this request | file object (with the current `offset`) plus `chunk_size` |
| `409 duplicate` | a live, completed file with the same sha256 exists | error with `id` and `url` of the existing file |
| `400` | invalid name/size/hash/duration, or same sha256 with a different size, or `rm_after` over the cap | error |
| `413 too_large` | over `max_file_size` | error |
| `507 insufficient_storage` | not enough disk space | error |
| `507 quota_exceeded` | the file would take the host over its `quota` | error |

### Streaming uploads

For data whose size and hash are not known up front (stdin) the upload is created without them and **sealed** at the
end. Chunks are exactly as in the normal protocol, the hash of the whole file is the last thing that is sent:

```
client                                  server
  | POST /files {name,stream:true,...}    |
  |-------------------------------------->|  create, no dedupe, no resume by hash
  |<-- {id, chunk_size, offset:0}         |
  | PATCH /files/{id}/content             |
  |   Upload-Offset: N                    |
  |   body = chunk || sha256(chunk)       |  chunk of 1..chunk_size bytes
  |-------------------------------------->|  verify, advance
  |<-- {offset}                           |
  |        ... repeat ...                 |
  | POST /files/{id}/seal {size,sha256}   |
  |-------------------------------------->|  compare with the running hash, complete
  |<-- file object with url               |
```

`POST /files` with `"stream": true` takes `name`, `rm_after` and `uses` as usual and must not contain `size` or
`sha256` (`400`). The answer is `201` with the file object (`size` and `sha256` are `null`, `offset` is `0`) plus
`chunk_size`. There is no lookup by hash, so every create is a new upload; the id is the only handle.

Chunks of a streaming upload use the same request as above, with these differences: a chunk has 1 to `chunk_size`
bytes, only the last chunk may be shorter (the offset of every chunk must be a multiple of `chunk_size`, `400`
otherwise), and a chunk that would take the file over `max_file_size` is answered `413 too_large`, one that would take
the host over its `quota` `507 quota_exceeded`; both delete the upload. A chunk never completes the upload. The server keeps the running sha256 as it does for normal uploads.

### `POST /files/{id}/seal` - complete a streaming upload

```json
{ "size": 1234567, "sha256": "9f86d0..." }
```

| status | meaning | body |
| --- | --- | --- |
| `200` | size and hash match; the file is `ready` (`rm_after` and `uses` apply from now). Repeating the same seal of a sealed file gives the same answer again | file object with `url` |
| `409 offset_mismatch` | `size` differs from the bytes the server received; the upload stays open | error with `offset` (current) |
| `409 duplicate` | a live, completed file with the same sha256 exists; the upload is deleted | error with `id` and `url` of the existing file |
| `409 locked` | another request is writing this upload right now; `Retry-After: 1` | error |
| `422 file_hash_mismatch` | the sha256 does not match what was received; the upload is deleted, start over | error |
| `400` | `size` or `sha256` missing or malformed | error |
| `404 not_found` | unknown id, or not a streaming upload that is still `uploading` | error |

A zero byte stream is sealed with size `0` and the sha256 of the empty string without any chunk. An incomplete upload
(normal or streaming) of the same bytes that exists when a stream is sealed is deleted; its client starts over and
meets the duplicate.

### `GET /files/{id}` - state of one file

Returns the file object. Also used to find the offset after a failure and by `linkrot info`.

### `PATCH /files/{id}/content` - upload one chunk

Headers:

- `Upload-Offset: <n>` - byte offset of this chunk; must equal the server's offset
- `Content-Type: application/offset+octet-stream`
- `Content-Length: <chunk length + 32>`

Body: the chunk bytes immediately followed by the raw 32 byte sha256 digest of exactly those bytes. The chunk length is
`min(chunk_size, size - offset)`; any other length is `400`.

| status | meaning | body |
| --- | --- | --- |
| `200` | chunk accepted, more to come | `{ "offset": <new offset> }` |
| `200` | last chunk accepted and whole file verified | file object with `status: ready` and `url` |
| `409 offset_mismatch` | `Upload-Offset` differs from the server offset | error with `offset` (current) |
| `409 locked` | another request is writing this upload right now; `Retry-After: 1` | error |
| `422 chunk_hash_mismatch` | digest does not match; nothing was committed, resend the chunk | error |
| `422 file_hash_mismatch` | whole file does not match the declared sha256; file and row are deleted, start over | error |
| `404 not_found` | unknown id, or file is not `uploading` | error |
| `507 insufficient_storage` | write failed; upload stays resumable | error |

### `GET /files` - list

Returns `{ "files": [ <file object>, ... ], "quota": { "used": <bytes>, "limit": <bytes or null> } }`, newest first, including incomplete uploads (`status: uploading`).
Expired files are not listed. `used` is what the live files and running uploads occupy, `limit` the host's `quota` (`null` if there is none). The CLI uses this to resolve id prefixes, for shell completion and to show the quota below `ls`.

### `PATCH /files/{id}` - change metadata

```json
{ "name": "new.pdf", "rm_after": "3d", "uses": 2 }
```

All members optional, at least one required. `rm_after` is counted **from now** (`expires_at = now + rm_after`) and
must be within the cap. `uses` sets the number of **remaining** downloads. Returns the updated file object. For a file
that is still `uploading`, `rm_after` is stored and applied at completion.

### `DELETE /files/{id}`

Deletes row and data file. `204`. Unknown id: `404`.

### `POST /cleanup`

Runs the cleanup now (ignores the interval, still takes the cleanup lock). Returns `200` with
`{ "ran": true, "rows_deleted": n, "files_deleted": n }`, or `{ "ran": false }` if another cleanup holds the lock.

### `GET /health` - is the server ready?

Needs a token. Always `200` with the worst level of all checks and the checks themselves:

```json
{"status": "warn", "checks": [
  {"name": "database", "level": "ok",   "message": "schema version 3"},
  {"name": "storage",  "level": "ok",   "message": "data and files directory are writable"},
  {"name": "disk",     "level": "ok",   "message": "212.4 GiB free of 458.0 GiB"},
  {"name": "cleanup",  "level": "warn", "message": "the cleanup has not run yet"}
]}
```

`level` is `ok`, `warn` (it works, somebody should look) or `error` (it cannot do its job). Errors: the data directory is
missing or not writable, the database cannot be used. Warnings: free disk space below the larger of 10% and 1 GiB, the
last successful cleanup older than twice `cleanup_interval` or never, a cleanup that had errors (a file it could not
remove). While the database schema is not the one of this release (`app/install.sh` has not run) every endpoint,
this one included, answers `503 schema_outdated`.

### `GET /settings`, `PATCH /settings` - the look of the public pages

Both need a token. The settings belong to the host, are all booleans and are all `true` by default. `GET` answers
`{"settings": {...}}` with every setting. `PATCH` takes a JSON object with any of them, changes only those and answers
the same way; an empty body, an unknown name or a value that is not `true` or `false` is `400 invalid_request` and
changes nothing.

| setting | `false` means |
| --- | --- |
| `show_taglines` | no tagline at the bottom of any page, no `X-Rot` header on downloads |
| `show_startpage` | `/` answers `200` with an empty body instead of the about page |
| `show_title` | no "linkrot" headline with the logo on any page |
| `show_linkrot_line` | no `linkrot down` line on the download page |
| `show_curl_line` | no curl line on the download page |
| `show_hash_line` | no SHA-256 line on the download page |

The "From a terminal" section of the download page disappears when all three lines are off.

### `GET|PUT|DELETE /custom/{name}` - files that replace the built-in look

Need a token. `name` is `taglines` (the footer line of the pages, one per line), `headerlines` (the `X-Rot` header of
downloads, one per line) or `css` (see below). The bodies are plain text, not JSON.

- `GET` returns the file. As long as nothing was uploaded it returns the built-in lines and writes nothing, so a later
  release that adds lines reaches every host that did not replace them. The header `X-Customized: true|false` says which.
- `PUT` replaces the file with the request body and answers `{"name", "customized": true, "lines": n}`. Empty lines are
  skipped, spaces around a line are dropped and a repeated line counts once. The file is used instead of the built-in
  lines, which stay unused until a reset. Refused with `400 invalid_request` (the message names the lines): invalid
  UTF-8, control characters, a line longer than the limit, no usable line at all, and for `headerlines` anything but
  printable ASCII (a header value must not contain line breaks). Refused with `413 too_large`: too many bytes
  or too many lines. A refused upload changes nothing.
- `DELETE` removes the file, back to the built-in lines (`204`, also if there was nothing to remove).

| name | bytes | lines | characters per line |
| --- | --- | --- | --- |
| `taglines` | 64 KB | 2000 | 200 |
| `headerlines` | 16 KB | 500 | 120 |

`show_taglines` off still switches both off: custom lines are only used while it is on.

**`css`** is a stylesheet that every page links after the built-in one (`<link rel="stylesheet" href="/assets/user.<hash>.css">`),
so it only has to contain what it changes. `GET` returns a starter file as long as nothing was uploaded: the color
variables of the built-in stylesheet, light and dark, with comments. `PUT` (at most 64 KB of valid UTF-8 without NUL
bytes; `400` or `413` otherwise) answers `{"name": "css", "customized": true, "url": "/assets/user.<hash>.css", "hash": ...}`.
The hash is the first 16 hex characters of the sha256 of the content, so the URL changes with every new stylesheet.
`DELETE` goes back to the built-in look.

`GET /assets/user.<hash>.css` needs no token and is served with `Cache-Control: public, max-age=31536000, immutable`.
A replaced or reset stylesheet is not deleted at once, because a page that was loaded a moment ago still asks for it: it
stays available for 24 hours and the cleanup removes it then. At most 5 old stylesheets are kept, the oldest go first.
nginx has to hand names it has no file for to `index.php`, see [nginx.conf.example](nginx.conf.example).

### `GET /pages`, `GET|PUT|DELETE /pages/{name}`, `GET /page-template` - static pages

Need a token. A host can have up to 20 pages, each a Markdown file of at most 64 KB. Every published page is linked in
the footer of every page of the host (not on an empty start page, which is not a page). `{name}` is 1 to 40 characters
of `a-z`, `0-9` and `-`, not at the start or end, and is the URL: `/page/{name}`.

A file may start with a head between two lines of `---`, one `key: value` per line (not YAML: no lists, nothing
multi-line; empty lines and lines starting with `#` are ignored). All keys are optional, an unknown or repeated key is
an error:

| key | meaning | default |
| --- | --- | --- |
| `title` | headline of the page and title of the browser tab, up to 100 characters | the name |
| `link` | text of the link in the footer, up to 40 characters | the title |
| `order` | the links are sorted by this whole number (-9999 to 9999), lowest first, then by text | `100` |
| `published` | `false`: a draft, neither reachable (`404`) nor linked | `true` |
| `lang` | language of the page, `en`, `de`, `de-AT`, ... | `en` |

The Markdown is strict. Allowed: the usual CommonMark, tables, strikethrough, and URLs that become links. Refused with
`400 invalid_request`, the message names the lines: **any raw HTML**, links other than `https`, `http`, `mailto`, `tel`
or a path on this server (so no `javascript:`), images other than `https`, `data:image/png|gif|jpeg|webp;base64,...`
or a path on this server, a mistake in the head, invalid UTF-8 or NUL bytes. Over 64 KB is `413 too_large`, the 21st
page `400`. Headings are moved down one level (the title is the `h1`). External links get
`rel="nofollow noopener noreferrer"`, images `loading="lazy"` and `referrerpolicy="no-referrer"`. The body of a page has
the classes `custompage` and `custompage_<name>` for the custom stylesheet.

- `GET /pages` lists the pages in the order of the footer: `{"pages": [{"slug", "title", "link", "order", "published",
  "lang", "url", "updated_at"}]}`.
- `PUT /pages/{name}` takes the Markdown as the body, checks it, renders it once and stores both. Answers the entry of
  the list. Replaces a page of the same name. A refused upload changes nothing.
- `GET /pages/{name}` returns the file exactly as it was uploaded (`text/markdown`), byte for byte, so it can be edited
  and uploaded again. `?format=html` returns the rendered body instead, also of a draft (for a preview).
- `DELETE /pages/{name}` removes the page and its original (`204`, also if there was none).
- `GET /page-template` returns a page to start from: every key of the head, and dummy content with a list, a table and a
  quote. It is a draft (`published: false`), so uploading it unchanged does not put dummy text in every footer.

`GET /page/{name}` is public: the page in the style of the site, with the strict CSP of the other pages except that images
may come from any `https` address and from `data:` URLs (`img-src 'self' https: data:`), still no script. Cached with an
`ETag` and `Cache-Control: no-cache`, so a repeat visit is a `304` and a change is visible at once. A draft and an
unknown name are the same `404`.

## Public download URLs

No authentication.

- `GET /` - the about page of linkrot (an empty page if `show_startpage` is off). Never cached.
- `GET /file/{id}/{name}` - HTML page with filename, size, a download button and, unless switched off, the commands to
  fetch the file from a terminal. A wrong `{name}` gets a `302` to the canonical URL.
- `GET /dl/{id}/{name}` - the download itself (`attachment`, original filename, size, `X-Accel-Redirect`, `Range`
  supported, and an `X-Rot` header unless `show_taglines` is off). `{name}` is not checked.
- `GET /page/{name}` - a published static page of the host, see [above](#get-pages-getputdelete-pagesname-get-page-template---static-pages).
- `GET /assets/user.<hash>.css` - the custom stylesheet of the host, see [above](#getputdelete-customname---files-that-replace-the-built-in-look).

Unknown, expired, exhausted or incomplete files answer `404` in the same way for all of these cases.

## Errors

```json
{ "error": { "code": "offset_mismatch", "message": "human readable text", "offset": 1048576 } }
```

`code` is stable and meant for programs; extra members (`offset`, `id`, `url`) depend on the code.

| HTTP | codes |
| --- | --- |
| 400 | `invalid_request` |
| 401 | `unauthorized` |
| 404 | `not_found` |
| 409 | `duplicate`, `offset_mismatch`, `locked` |
| 413 | `too_large` |
| 422 | `chunk_hash_mismatch`, `file_hash_mismatch` |
| 429 | `rate_limited` (`Retry-After` set) |
| 507 | `insufficient_storage` |
| 500 | `internal` |

Failed authentication counts against the rate limit of the client address (see [SERVER.md](SERVER.md#rate-limiting)).
