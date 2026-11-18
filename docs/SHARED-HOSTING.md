# Shared hosting

`linkrot-shared-<version>.zip` is the server for a webspace without a shell: FTP (or the file manager of the hoster)
and a browser are enough. It is the same server as the release tarball ([DEPLOYMENT.md](DEPLOYMENT.md)) with its
dependencies inside, a setup page instead of `install.sh` and `app/bin`, and `.htaccess` files instead of an nginx
block.

## Requirements

- PHP 8.4 or newer with `pdo_sqlite`, `mbstring`, `ctype`, `iconv`, `xmlwriter` (most hosters switch them on by
  default; `setup.php` says if one is missing). APCu is not needed.
- Apache or LiteSpeed that reads `.htaccess` (mod_rewrite). A hoster with nginx in front that ignores `.htaccess` does
  not work: the setup notices it and refuses.
- https for the host: the share links are always `https://<host>`.

## Installation

1. Download `linkrot-shared-<version>.zip` from the release (verify it like the other files, see
   [DEPLOYMENT.md](DEPLOYMENT.md#verifying-a-release)).
2. Unpack it into the document root of the host (`public_html`, `htdocs`, `www`, ... or the folder a subdomain points
   to), so that `index.php`, `setup.php`, `.htaccess` and `linkrot-app/` are directly in it. Many FTP clients hide files
   that start with a dot: make sure `.htaccess` is uploaded too.
3. Open `https://<host>/setup.php` and click **Write a code**. The setup writes a code into `setup-code.php` next to
   `setup.php`. Open that file with FTP or the file manager and copy the code into the setup page.
4. The setup does the rest by itself:
   - it checks the requirements
   - it puts the data into `linkrot-data/` **next to** the document root if PHP may create a folder there (nothing the
     web server could hand out), otherwise into `linkrot-data/` below it, locked with a `.htaccess`
   - your browser tests the web server (see [What the setup tests](#what-the-setup-tests)), the setup takes the results
   - it fills in the host you called it with: click **Add**, then **Create** for a token
5. On your device: `lr config --server https://<host> --token` and paste the token.
6. **Lock the setup** at the bottom of the page: it removes `setup-code.php`. To come back, open `setup.php` and ask
   for a new code.

## Who can use the setup

Only whoever can read files of the webspace. The setup writes the code into a PHP file: the web server runs it
instead of showing it, so a visitor of the web site never sees the code, and a valid code is never replaced (asking
for codes does not lock you out). Knowing the URL of `setup.php` is not enough, and being the first visitor is not
either.

The code is 128 random bits and expires after an hour without use; a wrong code costs half a second. The session is a
cookie (`HttpOnly`, `SameSite=Strict`, `Secure` over https) that is derived from the code, so removing
`setup-code.php` ends every session. Every action is a request of the setup page itself with a header that a form of
another site cannot send.

If PHP may not write into the document root, the setup says so: create `setup-code.php` yourself with a line
`// CODE: <a secret of at least 20 characters>` and enter that secret.

## What the setup tests

Your browser sends requests to the setup, as the CLI would send them to the server; the setup turns the answers into
the configuration:

| test | why | if it fails |
| --- | --- | --- |
| a path that is no file reaches PHP | the front controller of `.htaccess` (mod_rewrite) | error: nothing works |
| the `Authorization` header reaches PHP | Apache drops it when PHP runs as CGI/FastCGI; `.htaccess` passes it on | error: no token works |
| `POST`, `PUT`, `PATCH`, `DELETE` reach PHP | uploads and the API; some firewalls (mod_security) block them | error |
| the biggest request body that gets through (32 MiB down to 1 MB) | the upload chunks must fit | `chunk_size` is set to what fits; below 1 MB: error |
| `linkrot-data/` (if it is below the document root) and `linkrot-app/` cannot be fetched | otherwise anybody could download every file, bypassing expiry and uses | error, no host can be added |
| who can send a file: `X-Sendfile`, `X-LiteSpeed-Location`, PHP | the web server is faster, PHP always works | `download_mode` is the first that sent the test file |

On the server side it checks the PHP extensions and functions (`fsync`, `flock`, `set_time_limit`, `mail`), and the file
system of the data folder: WAL, the default journal of the databases, needs shared memory that a network file system
cannot provide, so `sqlite_journal` is `delete` unless the setup can see a local file system (`/proc/mounts`) on which
WAL works ([SERVER.md](SERVER.md#tenants)).

**Test again** in the setup repeats the tests and updates the `config.php` of every host (for example after the hoster
changed something).

## What is where

```
public_html/                  the document root
  index.php .htaccess assets/  the server
  setup.php                    the setup
  setup-code.php               the code, while the setup is open (not in the zip)
  linkrot-paths.php            where code and data are, written by the setup (not in the zip)
  linkrot-app/                 the code with its dependencies, .htaccess: deny all
linkrot-data/                  the data, next to the document root if PHP may create it there (else below it)
  global-config.php            the hosts, mail.from, sqlite_journal
  setup.json                   what the tests found
  <host>/config.php            the configuration of the host, see SERVER.md
  <host>/linkrot.sqlite        files and tokens
  <host>/ratelimit.sqlite      the failed logins (without APCu)
  <host>/files/                the uploads
```

Back up `linkrot-data/`; it is everything. `config.php` and `global-config.php` can be edited with FTP as on any other
installation ([SERVER.md](SERVER.md#configuration)); **Rewrite config.php** in the setup brings a `config.php` up to
the options of the current release.

## Upgrading

Upload the files of the new zip over the old ones. `linkrot-paths.php`, `setup-code.php` and the data are not in the zip,
so nothing of the installation is overwritten. The first request after the upgrade migrates the databases
(`auto_migrate` in `linkrot-paths.php`); **Migrate the databases** in the setup does the same by hand.

## Differences to a server with nginx

- Downloads: PHP sends the file itself (`download_mode` `php`, with single ranges, so interrupted downloads resume)
  unless the web server can (`xsendfile`, `litespeed`).
- No `limit_req` of nginx: there is no protection against floods of requests except what the hoster has. The failed
  logins are counted as everywhere ([SERVER.md](SERVER.md#rate-limiting)), without APCu in `ratelimit.sqlite`.
- Without php-fpm (`fastcgi_finish_request`) or LiteSpeed, the request that runs the lazy cleanup waits for it.
- Tokens by mail use PHP's `mail()`, which most hosters deliver. Set the sender in the setup (**Mail**) and send a test
  mail from there.

## Development

`make -C server shared` builds the tree of the zip in `server/build/shared`, `make -C server shared-dist` the zip
(reproducible, `make -C server repro` checks it), `make -C server shared-test` unpacks and checks it. The parts that
exist only in the zip are in `server/shared/` (`setup.php`, the `.htaccess` files); the setup itself is
`server/app/src/Setup/`. End to end: `LINKROT_E2E_SHARED=server/build/shared go -C cli test ./e2e/ -run Shared -v`
runs the setup and the CLI against `php -S`; CI also runs it against Apache with mod_php
([DEVELOPMENT.md](DEVELOPMENT.md#end-to-end-tests)).
