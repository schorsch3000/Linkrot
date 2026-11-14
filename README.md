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
- **One installation, many hosts.** Every hostname listed in `app_data/global-config.php` is a tenant of its own:
  own tokens, files, database, quota and cleanup (see [docs/SERVER.md](docs/SERVER.md#tenants)). The share URL of a
  host is `https://<host>`, nothing to configure.
- **Customizable per host, through the API only.** Six on/off settings (taglines, start page, headline, and the three
  command lines of the download page) and three files (taglines, `X-Rot` header lines, a stylesheet) change how the
  public pages look, and up to 20 Markdown pages (an imprint, a privacy notice) are linked in the footer of every page.
  `lr config`, `lr custom` and `lr page` manage them, and `lr page edit` / `lr custom edit` open a file in your editor and upload it on every save; there is still no admin UI.
- **Resumable uploads.** Files are uploaded in small chunks (default 32 MiB) that are individually verified, so
  a lost connection costs at most one chunk.
- **Downloads are guarded.** Files never live in the public folder; PHP decides, nginx sends (`X-Accel-Redirect`).
- **Terminal friendly.** Results go to stdout, progress and messages to stderr, `--json` where it makes sense,
  shell completion for bash, zsh and fish.

## License

Copyright (C) 2026 Schorsch3000

Linkrot is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as
published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. It is
distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See [LICENSE](LICENSE) for the full text. Every release archive
contains it.

The dependencies keep their own licenses, all compatible with the GPL: `bacon/bacon-qr-code` (BSD-2-Clause),
`dasprid/enum` (BSD-2-Clause), `league/commonmark` and `league/config` (BSD-3-Clause), `dflydev/dot-access-data` (MIT),
`nette/schema` and `nette/utils` (BSD-3-Clause or GPL), `psr/event-dispatcher` and the `symfony` polyfill and contracts (MIT), `spf13/cobra` and `spf13/pflag` (Apache-2.0 / BSD-3-Clause), `pelletier/go-toml`
(MIT), `inconshreveable/mousetrap` (Apache-2.0). Development tools are not shipped.

## Documentation

| Document | Content |
| --- | --- |
| [docs/SERVER.md](docs/SERVER.md) | server architecture, layout, tenants, data model, cleanup, locking, rate limiting, appearance (settings and custom files), security, configuration, `lr-auth` |
| [docs/API.md](docs/API.md) | HTTP API: upload protocol, file management, settings, custom files, static pages, public URLs, errors |
| [docs/CLI.md](docs/CLI.md) | CLI commands (`up`, `ls`, `config`, `custom`, `page`, ...), flags, config file, exit codes, completion |
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
| Streaming upload | `lr up` reads stdin when no file is given (refused on a terminal): created without size and hash, same verified chunks, sealed with the sha256 of everything (see [API.md](docs/API.md#streaming-uploads)) |
| Duplicate uploads | refused; the existing file is reported |
| Expiry | `rm-after` counts from upload completion (`up`) or from now (`set`); capped by a mandatory server-side maximum |
| Download limit | optional `--uses n`; exhausted files count as expired |
| Public URL | `https://<host>` from `global-config.php`; plain `http://` with the request's port only for `localhost` and `127.x` (development). No `base_url` setting |
| Settings | six booleans per host, all on by default, stored in the host's database, changed with `lr config set` (see [API.md](docs/API.md#get-settings-patch-settings---the-look-of-the-public-pages)) |
| Custom files | `taglines`, `headerlines` and `css` per host, replaced with `lr custom put`; the built-in version is served but never written to disk until an upload; a replaced stylesheet stays reachable for a day (see [API.md](docs/API.md#getputdelete-customname---files-that-replace-the-built-in-look)) |
| Static pages | Markdown with a small strict head (`title`, `link`, `order`, `published`, `lang`), rendered once at upload with `league/commonmark`, no raw HTML, https and data images; the original is kept and downloadable; linked in the footer of every page (see [API.md](docs/API.md#get-pages-getputdelete-pagesname-get-page-template---static-pages)) |
| Cleanup | lazy, on requests, guarded by `flock`; also triggerable with `linkrot cleanup` |
| Rate limiting | own limiter on failed authentication, stored in APCu, keyed by hashed client IP |
| Tokens | only hashes stored; named per device; `lr-auth get\|del\|ls` |
| Quality gates | phpstan level 7, rector, php-cs-fixer, phpunit; Go: `go vet`, `golangci-lint`, `gofumpt`, `go test`; pre-commit hook runs `make precommit` |
