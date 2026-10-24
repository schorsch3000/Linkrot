# Linkrot

Terminal-native file sharing with links that rot.

Linkrot has two parts:

- a **CLI** (Go, `linkrot`, usually symlinked or aliased as `lr`) to upload, list, edit, download and delete files
- a **server** (PHP 8.4 + SQLite, behind nginx) that receives the files, hands out a share URL once an upload is
  complete, and deletes the file again after a while, after which the URL is dead

The point: as a terminal user I want to share a file with someone by running one command, get a URL on stdout,
and not worry about cleaning up later.

## Principles

- **One user, many devices.** There is no user management: no login, no registration, no password, no email, no
  admin UI, no web upload. The server offers public download links and a token-guarded API. All tokens belong to
  the same (single) user; they exist so each device can have its own token. A compromised token is revoked by
  deleting just that token, nothing else needs rotating.
- **Links rot.** Every file has an expiry, and optionally a maximum number of downloads. Expired files behave as
  not found even if the cleanup has not yet physically removed them.
- **No scheduler.** The server is a classic PHP request/response application without cronjob. Cleanup runs lazily,
  piggybacking on incoming requests (see [docs/SERVER.md](docs/SERVER.md)).
- **Resumable uploads.** Files are uploaded in small chunks (default 32 MiB) that are individually verified, so
  a lost connection costs at most one chunk.
- **Downloads are guarded.** Files never live in the public folder; PHP decides, nginx sends (`X-Accel-Redirect`).
- **Terminal friendly.** Results go to stdout, progress and messages to stderr, `--json` where it makes sense,
  shell completion for bash, zsh and fish.

## Documentation

| Document | Content |
| --- | --- |
| [docs/SERVER.md](docs/SERVER.md) | server architecture, layout, data model, cleanup, locking, rate limiting, security, configuration, `lr-auth` |
| [docs/API.md](docs/API.md) | HTTP API: upload protocol, file management, public download URLs, errors |
| [docs/CLI.md](docs/CLI.md) | CLI commands, flags, config file, exit codes, completion |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | installing a release: server tarball, nginx, tokens, CLI binaries, upgrading |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | repository layout, tooling, Makefiles, pre-commit hook, conventions |
| [docs/nginx.conf.example](docs/nginx.conf.example) | nginx server block snippet |

## Repository layout

```
server/            PHP application (see docs/SERVER.md)
cli/               Go client (see docs/CLI.md)
docs/              documentation
.githooks/         versioned git hooks (activated by `make install-hooks`)
Makefile           delegates to server/ and cli/
```

## Decisions at a glance

| Topic | Decision |
| --- | --- |
| Public file id | 16 random characters, first 4 lowercase, lookalikes `1 0 l o I O` removed (see [SERVER.md](docs/SERVER.md#identifiers)). No uuid, no sqids. |
| Upload | sequential chunks, each chunk followed by its own sha256, whole-file sha256 verified at completion |
| Duplicate uploads | refused; the existing file is reported |
| Expiry | `rm-after` counts from upload completion (`up`) or from now (`set`); capped by a mandatory server-side maximum |
| Download limit | optional `--uses n`; exhausted files count as expired |
| Cleanup | lazy, on requests, guarded by `flock`; also triggerable with `linkrot cleanup` |
| Rate limiting | own limiter on failed authentication, stored in APCu, keyed by hashed client IP |
| Tokens | only hashes stored; named per device; `lr-auth get\|del\|ls` |
| Quality gates | phpstan level 7, rector, php-cs-fixer, phpunit; Go: `go vet`, `golangci-lint`, `gofumpt`, `go test`; pre-commit hook runs `make precommit` |
