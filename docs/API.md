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

`status` is `uploading` or `ready`. `expires_at` and `completed_at` are `null` while uploading. `uses_left` is `null`
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

Returns `{ "files": [ <file object>, ... ] }`, newest first, including incomplete uploads (`status: uploading`).
Expired files are not listed. The CLI uses this to resolve id prefixes and for shell completion.

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

## Public download URLs

No authentication.

- `GET /file/{id}/{name}` - HTML page with filename, size and a download button. A wrong `{name}` gets a `302` to
  the canonical URL.
- `GET /dl/{id}/{name}` - the download itself (`attachment`, original filename, size, `X-Accel-Redirect`, `Range`
  supported). `{name}` is not checked.

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
