# Deployment

Releases are published on GitHub (see [DEVELOPMENT.md](DEVELOPMENT.md#continuous-integration-and-releases)). Every release
has the server tarball, CLI archives for all usual platforms and a `SHA256SUMS` file.

## Server

Requirements: nginx, php-fpm 8.4, [composer](https://getcomposer.org), and the PHP extensions `apcu`, `pdo_sqlite`,
`mbstring`, `ctype` (`composer install` checks this).

1. Download `linkrot-server-<version>.tar.gz` and verify it against `SHA256SUMS`.
2. Unpack it into the deploy root. It contains exactly `app/` and `htdocs/`:

   ```
   tar -xzf linkrot-server-<version>.tar.gz -C {deployroot}
   ```

3. Install the dependencies. The release has no `vendor/` folder, this step is part of the deployment:

   ```
   {deployroot}/app/install.sh
   ```

   It runs `composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader
   --classmap-authoritative` in `app/`. Because the class map is authoritative, run it again after every upgrade.
   Afterwards it checks `app_data/config.php`: if the file is missing it prints a prominent notice with what to create
   (until then every request is answered with "server misconfigured"), and if it exists it is loaded the way the server
   does, so mistakes show up at deploy time. If `app_data/` exists it also creates the database or migrates it to the
   schema of this release (`app/bin/migrate`); on a first installation run `install.sh` again after step 4. The script's
   exit code is 0 for a missing config, and non-zero if the migration fails.

   Once the config is valid it also checks the installation end to end (`app/bin/check-install`): with a temporary token
   named `test-install-check` it asks the health endpoint, uploads a small file through `base_url`, opens its page,
   downloads it and checks the headers (`Content-Disposition: attachment`, `nosniff`, and `X-Rot`, which only arrives
   if the `/_files/` location of nginx passes it on, see [nginx.conf.example](nginx.conf.example)). The token and the
   file are removed again, also when the check fails, and an old test token left by an interrupted run is replaced. A
   failed check makes `install.sh` exit with 1. Run it as the user php-fpm runs as, after nginx is configured. On a host
   that cannot reach its own `base_url`, use `install.sh --no-network-check`.

4. Create `{deployroot}/app_data/` (never touched by a deployment) with the `files/` folder and a `config.php`; `app_data/`
   and everything in it must belong to the user php-fpm runs as. See [SERVER.md](SERVER.md#configuration) for all options.

   ```php
   <?php
   return [
       'base_url' => 'https://linkrot.example',
       'max_rm_after' => '1y',
   ];
   ```

   **nginx and php-fpm as different users:** php-fpm writes the files, but nginx opens them itself (`X-Accel-Redirect`). Every
   directory down to `app_data/files/` must be searchable for nginx's user (mode `x` for others, e.g. `chmod o+x`, or an ACL
   such as `setfacl -m u:www-data:x`), and the files must be readable. The server creates `files/` as `0711` and the data
   files as `0644` regardless of the umask. The symptom of a missing permission is a `403` on `/dl/...` and
   `open() ".../app_data/files/<id>" failed (13: Permission denied)` in nginx's error log; `namei -l <path to a file>` shows
   which directory is the problem.
5. Add the server block from [nginx.conf.example](nginx.conf.example) (`root` is `{deployroot}/htdocs`, the internal
   `/_files/` location points at `{deployroot}/app_data/files/`). Its `/assets/` location caches the stylesheet and the
   script of the pages forever; that is safe because a release names them after their content, so an upgrade brings new
   names.
6. Create a token for every device, as the web user. The token is shown once:

   ```
   sudo -u www-data {deployroot}/app/bin/lr-auth get my-laptop
   ```

### Timeouts

A chunk request (default 32 MiB) writes, reads back and `fsync`s one chunk; it does not depend on the file size.
Keep `fastcgi_read_timeout` (nginx, default 60s) and php-fpm's `request_terminate_timeout` above what a chunk needs on
your disk and uplink, and `client_body_timeout` (default 60s) above the slowest upload speed you want to support
for one chunk. A slow client times out one chunk and the CLI resumes from the offset the server reports.

### Upgrading

Replace `app/` and `htdocs/` (remove the old ones, unpack the new tarball, run `app/install.sh` again) and leave
`app_data/` alone. `install.sh` migrates the database schema; the server itself only checks the version and answers
`503 schema_outdated` until `install.sh` has run. Restart php-fpm (or reload opcache)
afterwards; this also resets the failed-login counters in APCu.

## CLI

Download the archive for your platform, verify it against `SHA256SUMS`, unpack it and put the binary into your `PATH`:

```
tar -xzf linkrot_<version>_linux_amd64.tar.gz
install -m 0755 linkrot ~/.local/bin/linkrot
ln -s linkrot ~/.local/bin/lr          # optional short name, help and completion use the name it is called as

linkrot config --server https://linkrot.example
linkrot config --token <token from lr-auth>
```

Shell completion (replace `linkrot` with `lr` if that is what you call it):

```
linkrot completion bash > ~/.local/share/bash-completion/completions/linkrot
linkrot completion zsh  > "${fpath[1]}/_linkrot"
linkrot completion fish > ~/.config/fish/completions/linkrot.fish
```
