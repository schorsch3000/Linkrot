# Development

## Layout

```
Makefile                 runs the same target in server/ and cli/
.githooks/pre-commit     runs `make precommit`
server/
  Makefile
  app/                   everything that is deployed as `app/`: src/ (PSR-4), bin/lr-auth,
                         composer.json + composer.lock (all used PHP extensions are listed under `require`),
                         vendor/ (not in git, not in the release)
  htdocs/index.php   front controller
  app_data/              local dev data (gitignored)
  tests/
cli/
  Makefile
  go.mod                 module github.com/schorsch3000/Linkrot
  cmd/ internal/
docs/
```

## Makefiles

Client and server each have a Makefile with the usual targets:

| target | what |
| --- | --- |
| `install` | install dependencies (`composer install` / `go mod download`, tools) |
| `build` | server: nothing to compile, assemble the deployable tree in `build/dist`; cli: `go build` into `bin/` |
| `dist` | release artefacts: server `build/linkrot-server-$(VERSION).tar.gz`, cli `dist/*` for all platforms (`make dist VERSION=1.2.3`) |
| `run` | server: `php -S` with `download_mode = php`; cli: `go run` |
| `test` | phpunit / `go test ./...` |
| `lint` | static analysis and style checks without modifying files |
| `fmt` | apply formatting |
| `clean` | remove build artefacts |
| `precommit` | tests, linting and format check; must pass for a commit |

The root Makefile forwards `install`, `build`, `dist`, `test`, `lint`, `fmt`, `clean`, `precommit` to both parts and has
`install-hooks`.

## Tooling

PHP (8.4, no framework):

- **phpstan** level 7
- **rector** to enforce current coding style and PHP 8.4 features (run in check mode in `precommit`)
- **php-cs-fixer** with the PER-CS ruleset as code formatter
- **phpunit** for tests; the rate limiter runs against an in-memory implementation, because APCu is off in the CLI

Go:

- `go vet`, `golangci-lint`, `gofumpt` (formatter), `go test ./...`
- cobra for the command line, no other heavy dependencies

## End-to-end tests

`cli/e2e` runs the real CLI code against the real server: once on `php -S` directly and once behind nginx, which
covers the `X-Accel-Redirect` path (ranges, security headers, the internal location). They are part of `make -C cli test`
and are skipped if php, the server dependencies (`make -C server install`), APCu or nginx are missing.

## Continuous integration and releases

`.github/workflows/ci.yml` runs on pushes to `main` and on pull requests: the server's `precommit` (PHP 8.4 with
APCu), the CLI's `precommit` plus a cross-compile of every release platform, and the end-to-end job (php + nginx,
`LINKROT_E2E_REQUIRED=1` so that missing prerequisites fail instead of skip). Tool versions of the Go linters are pinned in
`cli/Makefile`.

`.github/workflows/release.yml` publishes a GitHub release when a tag `vMAJOR.MINOR.PATCH[-suffix]` is pushed (a suffix makes
it a pre-release). It runs CI first, then builds and attaches:

- `linkrot-server-<version>.tar.gz`: exactly two top-level folders, `htdocs/` and `app/`; no `vendor/`, tests or tool
  configs. `app/` contains `composer.json` and `composer.lock`, because `composer install --no-dev` is part of the
  deployment ([DEPLOYMENT.md](DEPLOYMENT.md)). `make -C server dist` fails if the layout is not as expected.
- `linkrot_<version>_<os>_<arch>.tar.gz` / `.zip`: the CLI for linux (amd64, arm64, armv7, 386), macOS (amd64, arm64),
  windows (amd64, arm64, 386, as zip) and freebsd (amd64, arm64). Static binaries, built with `-trimpath`; the version is
  shown by `--version`.
- `SHA256SUMS` for all of them.

```
git tag v1.0.0 && git push origin v1.0.0
```

## Pre-commit hook

`.githooks/pre-commit` runs `make precommit` in both parts and aborts the commit when it fails. `make install-hooks`
sets `git config core.hooksPath .githooks`. `git commit --no-verify` (`-n`) is **not used**, by agents or by humans
working on this repository.

## Conventions

- work on feature branches, never push to `main`
- small, concrete tasks tracked with `git-bug`; reference the task id in commit messages
- documentation in `docs/` is the source of truth for behaviour; change the docs together with the code
