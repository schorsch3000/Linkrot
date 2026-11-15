# Development

## Layout

```
Makefile                 runs the same target in server/ and cli/
.githooks/pre-commit     runs `make precommit`
server/
  Makefile
  app/                   everything that is deployed as `app/`: src/ (PSR-4), bin/ (`lr-auth`, `migrate`,
                         `check-config`, `check-install`, `check-mail`, `pages-cache-clear`, `hosts`),
                         composer.json + composer.lock (all used PHP extensions are listed under `require`),
                         install.sh (the deployment's `composer install --no-dev ...`),
                         vendor/ (not in git, not in the release)
  htdocs/index.php   front controller
  htdocs/assets/     page.css and page.js of the pages (a release build renames them after their content); the
                         custom stylesheets of the hosts are not here but in app_data/<host>/custom/
  app_data/              local dev data (gitignored), `make run` enrolls `localhost`
  tests/
cli/
  Makefile
  go.mod                 module github.com/schorsch3000/Linkrot
  cmd/ internal/
docs/
  img/                   the images of the README (made by `make showcase`, committed)
  showcase/              what makes them: build.sh, screenshots.mjs, record.py (development only, not shipped)
```

## Makefiles

Client and server each have a Makefile with the usual targets:

| target | what |
| --- | --- |
| `install` | install dependencies (`composer install` / `go mod download`, tools) |
| `build` | server: nothing to compile, assemble the deployable tree in `build/dist`; cli: `go build` into `bin/` |
| `dist` | release artefacts: server `build/linkrot-server-$(VERSION).tar.gz`, cli `dist/*` for all platforms (`make dist VERSION=1.2.3`) |
| `dist-test` | server only: unpacks the tarball like a deployment and runs `app/install.sh` on it, also end to end against `php -S` (CI runs it) |
| `coverage` | server only: the line coverage of `app/src` as text (needs Xdebug or PCOV) |
| `preview` | server only: sample download pages for every icon type, to open in a browser (`build/preview/index.html`) |
| `run` | server: `php -S` with `download_mode = php`; cli: `go run` |
| `test` | phpunit / `go test ./...` |
| `lint` | static analysis and style checks without modifying files |
| `repro` | builds the release artefacts twice with a different umask, time zone and locale and fails if they differ (CI runs it) |
| `release-test` | cli only: checks `dist.sh` (archive times from `SOURCE_DATE_EPOCH` or the commit, no leftover temporary files, checksums, independence of the builder) and `require-ci.sh` against a fake `gh`; part of `precommit` |
| `audit` | known vulnerabilities of the dependencies (network needed, fails on any finding) |
| `fmt` | apply formatting |
| `clean` | remove build artefacts |
| `precommit` | tests, linting and format check; must pass for a commit |

The root Makefile forwards `install`, `build`, `dist`, `test`, `lint`, `audit`, `repro`, `fmt`, `clean` and `precommit` to both parts and
has `install-hooks` and `showcase`.

## Tooling

PHP (8.4, no framework):

- **phpstan** level 7
- **rector** to enforce current coding style and PHP 8.4 features (run in check mode in `precommit`)
- **php-cs-fixer** with the PER-CS ruleset as code formatter
- **phpunit** for tests; the rate limiter runs against an in-memory implementation, because APCu is off in the CLI

Go:

- `go vet`, `golangci-lint`, `gofumpt` (formatter), `go test ./...`
- cobra for the command line, no other heavy dependencies

## The images of the README

`make showcase` (root Makefile, `docs/showcase/build.sh`) makes everything in `docs/img/`: screenshots of the pages and a
recording of a terminal session. Nothing is staged by hand: it builds the CLI, starts the PHP server on a throwaway data
directory with two hosts, uploads sample files through the API and applies a theme, a few Markdown pages and taglines.

- **Screenshots** (`screenshots.mjs`): headless Chromium through `playwright-core`, light and dark, at 2x. The browser is
  told that `files.example.com` is this machine (`--host-resolver-rules`), so the pages show the URLs a deployment shows.
- **Recording** (`record.py`): a bash in a pseudo-terminal, typed at the speed of a person, the real `lr` and `curl`, colors
  and progress bar included. It writes an asciicast (`demo.cast`, playable with `asciinema play`), `svg-term` renders the
  animated `demo.svg` that the README embeds. The only edit is cosmetic and documented in the script: the origin of the
  local server (`http://localhost:<port>`) is shown as `https://files.example.com`.
- **Needs** go, php (with apcu and pdo_sqlite), python3, node with npm (`npm ci` runs in `docs/showcase`, the packages are
  pinned in its `package-lock.json`) and a Chromium: `CHROMIUM=/path/to/chromium make showcase` if it is not on the `PATH`.
  In a container Chromium runs with `--no-sandbox`.

Run it again when the look of the pages changes, and commit the images. It is not part of `make precommit` or CI.

## End-to-end tests

`cli/e2e` runs the real CLI code against the real server: once on `php -S` directly and once behind nginx, which
covers the `X-Accel-Redirect` path (ranges, security headers, the internal location). They are part of `make -C cli test`
and are skipped if php, the server dependencies (`make -C server install`), APCu or nginx are missing.

## Continuous integration and releases

`.github/workflows/ci.yml` runs on pushes to `main` and on pull requests: the server's `precommit` (PHP 8.4 with
APCu), the CLI's `precommit` plus a cross-compile of every release platform, and the end-to-end job (php + nginx,
`LINKROT_E2E_REQUIRED=1` so that missing prerequisites fail instead of skip). Tool versions of the Go linters are pinned in
`cli/Makefile`.

Both jobs also run `make audit` (`composer audit --locked`, `govulncheck`), which **fails on any known vulnerability**; since
the release requires green CI, a new advisory blocks releases until the dependency is updated. `audit.yml` runs the same
checks every Monday, so a new advisory shows up even when nobody pushes. `.github/dependabot.yml` opens weekly pull
requests for Go modules, Composer packages and GitHub Actions. All actions are pinned by commit SHA (the comment names
the version); Dependabot keeps both up to date. `govulncheck` needs `vuln.go.dev`, which a restricted network may
block.

`.github/workflows/release.yml` publishes a GitHub release when a tag `vMAJOR.MINOR.PATCH[-suffix]` is pushed (a suffix makes
it a pre-release). It does not repeat the CI checks: the job `ci-passed` requires a successful CI run for the tagged
commit (waiting for one that is still running, failing if there is none, so tag after CI on `main` or the pull request
is green). The builds run next to it, the CLI platforms in parallel, and the release job attaches:

- `linkrot-server-<version>.tar.gz`: exactly two top-level folders, `htdocs/` and `app/`; no `vendor/`, tests or tool
  configs. `app/` contains `composer.json` and `composer.lock`, because `composer install --no-dev` (wrapped by `app/install.sh`) is part of the
  deployment ([DEPLOYMENT.md](DEPLOYMENT.md)). The release version is written into that `composer.json` as `version`
  (and the hash in the lock file refreshed, `server/scripts/stamp-version.sh`); `make dist` without a release version
  leaves it out. `make -C server dist` fails if the layout is not as expected.
- `linkrot_<version>_<os>_<arch>.tar.gz` / `.zip`: the CLI for linux (amd64, arm64, armv7, 386), macOS (amd64, arm64),
  windows (amd64, arm64, 386, as zip) and freebsd (amd64, arm64). Static binaries, built with `-trimpath`; the version is
  shown by `--version`.
- `SHA256SUMS` for all of them, and a signed build provenance attestation for every file (`actions/attest-build-provenance`,
  needs a public repository; the release job has `id-token: write` and `attestations: write` for it).

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
