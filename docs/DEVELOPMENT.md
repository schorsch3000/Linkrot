# Development

## Layout

```
Makefile                 runs the same target in server/ and cli/
.githooks/pre-commit     runs `make precommit`
server/
  Makefile
  composer.json          all used PHP extensions are listed under `require` (ext-pdo_sqlite, ext-apcu, ...); vendor-dir is app/vendor
  app/                   code (PSR-4), app/bin/lr-auth
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
| `build` | server: nothing to compile, assemble a deployable tree; cli: `go build` |
| `run` | server: `php -S` with `download_mode = php`; cli: `go run` |
| `test` | phpunit / `go test ./...` |
| `lint` | static analysis and style checks without modifying files |
| `fmt` | apply formatting |
| `clean` | remove build artefacts |
| `precommit` | tests, linting and format check; must pass for a commit |

The root Makefile forwards `install`, `build`, `test`, `lint`, `fmt`, `clean`, `precommit` to both parts and has
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

## Pre-commit hook

`.githooks/pre-commit` runs `make precommit` in both parts and aborts the commit when it fails. `make install-hooks`
sets `git config core.hooksPath .githooks`. `git commit --no-verify` (`-n`) is **not used**, by agents or by humans
working on this repository.

## Conventions

- work on feature branches, never push to `main`
- small, concrete tasks tracked with `git-bug`; reference the task id in commit messages
- documentation in `docs/` is the source of truth for behaviour; change the docs together with the code
