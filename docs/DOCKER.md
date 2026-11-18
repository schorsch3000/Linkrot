# Docker

The server as a docker compose stack: one container with nginx, php-fpm and the TLS helper, the data in a folder next
to `compose.yaml`. It is the same server as the release tarball ([DEPLOYMENT.md](DEPLOYMENT.md)), set up for you.

## Quick start

On a machine that `files.example.com` points to, with ports 80 and 443 free:

```
mkdir linkrot && cd linkrot
curl -fsSLO https://raw.githubusercontent.com/schorsch3000/Linkrot/main/compose.yaml
curl -fsSLO https://raw.githubusercontent.com/schorsch3000/Linkrot/main/linkrot-admin && chmod +x linkrot-admin
mkdir app_data && sudo chown 1000:1000 app_data
$EDITOR compose.yaml                 # LINKROT_ACME_EMAIL, or another TLS mode
docker compose up -d
./linkrot-admin                      # 2) add a host: files.example.com, then 5) create a token
```

On the device: `lr config --server https://files.example.com --token` and paste the token.

## The image

`ghcr.io/schorsch3000/linkrot`, for `linux/amd64` and `linux/arm64`:

| tag | what |
| --- | --- |
| `latest`, `0`, `0.13`, `0.13.1` | the releases; `latest` and the short tags move with every release that is no pre-release |
| `0.14.0-rc1` | a pre-release, only under its own tag |
| `edge` | the last commit on `main` that passed CI |

Pin `0.13` (or an exact version) in `compose.yaml` if an upgrade should only happen when you decide it. Every image has a
signed build provenance attestation in the registry:

```
gh attestation verify oci://ghcr.io/schorsch3000/linkrot:latest --repo schorsch3000/Linkrot
```

Inside: Alpine with nginx, php-fpm 8.4 (`pm = ondemand`: no worker while there is no request), APCu, opcache, msmtp
for mail and acme.sh for Let's Encrypt, under tini. The code is in `/srv/linkrot/app` and `/srv/linkrot/htdocs`, built
like the release tarball, with the production dependencies installed. Idle, only the nginx processes, the php-fpm
master and a sleeping shell for the certificate run.

For development, build it from the checkout: uncomment `build: .` in `compose.yaml` and run `docker compose up -d --build`,
or `docker build -t linkrot .`.

## Data and user

`./app_data` is bind-mounted to `/srv/linkrot/app_data`: `global-config.php`, one folder per host with its
`config.php`, database and files, exactly as in [SERVER.md](SERVER.md#tenants). Back up this folder and you have
everything, the certificate of `LINKROT_TLS=acme` included (`app_data/.acme/`).

The container never runs as root. `user: "1000:1000"` in `compose.yaml` is the user and group it runs as, and
`./app_data` must belong to them (`chown -R 1000:1000 app_data`), or set `user:` to the owner of the folder. If it is not
writable the container stops right away and says so in its log. If the folder does not exist, docker creates it owned
by root, and the same message appears.

Every start migrates the databases to the schema of the image, clears the cached pages and checks every `config.php`
(`app/install.sh --no-composer`). An upgrade is:

```
docker compose pull && docker compose up -d
```

## TLS

`LINKROT_TLS` picks one of three modes. Inside the container nginx listens on 8080 (http) and 8443 (https);
`compose.yaml` maps them to 80 and 443.

### `acme`: Let's Encrypt

The default of `compose.yaml`. Needs `LINKROT_ACME_EMAIL` (the account at the CA), every host pointing to the machine,
and port 80 reaching the container (the http-01 challenge).

One certificate covers every host of `global-config.php` (not `localhost` or IP addresses). It is requested at start
if there is none, and again when the list of hosts changed: `linkrot-admin` does that right after adding a host, and
the renewal check does it twice a day, so a host added by hand is picked up too. Until the first certificate is there,
port 80 answers `503`, then it redirects to https. Renewal is automatic (acme.sh, when the certificate is due), nginx
is reloaded afterwards.

`LINKROT_ACME_SERVER=letsencrypt_test` uses the staging CA (certificates no browser trusts, but no rate limits), for
trying things out. Any other value acme.sh knows works too (a URL of an ACME directory, or `zerossl`). Account and
certificates are in `app_data/.acme/`; remove that folder to start over.

### `file`: your own certificate

For a machine that already has certificates, for example from certbot. Mount the certificate (with its chain) and the
key, readable for the user of the container:

```yaml
    environment:
      LINKROT_TLS: file
    volumes:
      - ./certs:/certs:ro          # /certs/fullchain.pem and /certs/privkey.pem
```

Other names: `LINKROT_TLS_CERT` and `LINKROT_TLS_KEY`. For certbot, mount `/etc/letsencrypt:/etc/letsencrypt:ro`
and set them to `/etc/letsencrypt/live/<name>/fullchain.pem` and `.../privkey.pem`: the symlinks of `live/` point into
`archive/`, so the whole folder is needed. certbot makes the keys readable for root only; give the group of the
container read access (for example a deploy hook that runs `chgrp 1000` and `chmod 640` on the key). The files are
checked every minute and nginx reloads when they changed. Port 80 redirects to https.

### `off`: your proxy does TLS

Plain http on 8080, for a reverse proxy in front (Traefik, Caddy, nginx, ...) that terminates TLS. Publish the port
only to the proxy, for example `"127.0.0.1:8080:8080"`, or no port at all and the proxy in the same docker network.
The share links are always `https://<host>`, so the proxy must serve that.

The proxy has to:

- pass the `Host` header on unchanged; hosts that are not in `global-config.php` get `421`
- send the address of the client in `X-Forwarded-For` (or the header named by `LINKROT_REAL_IP_HEADER`, for example
  `X-Real-IP` or `CF-Connecting-IP`). The failed logins and the rate limits count per client address; without it every
  client is the proxy, and one locked-out client locks out everyone
- allow request bodies of at least `chunk_size` + 1 MB (default 32 MiB, up to 100 MB), and not time out a chunk upload
  before 300 s

Only proxies in `LINKROT_TRUSTED_PROXIES` (CIDR ranges, separated by spaces) may set the client address. The default is
loopback and the private networks (`10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 fc00::/7`), which covers a proxy in a
docker network. Narrow it if untrusted machines share these networks with the container.

## Mail

Tokens by mail ([SERVER.md](SERVER.md#tokens-by-mail)) need `mail.from` in `app_data/global-config.php`, the `email` of
the host, and an SMTP relay for msmtp:

| variable | default | |
| --- | --- | --- |
| `LINKROT_SMTP_HOST` | | the relay; without it no mail is sent |
| `LINKROT_SMTP_TLS` | `starttls` | `starttls`, `tls` (implicit TLS) or `off` |
| `LINKROT_SMTP_PORT` | 587, 465 with `tls`, 25 with `off` | |
| `LINKROT_SMTP_USER` | | without it no authentication |
| `LINKROT_SMTP_PASSWORD` | | or `LINKROT_SMTP_PASSWORD_FILE`, a file with the password (a docker secret) |

`./linkrot-admin`, `9) check that mail can be sent` mails a code to an address of your choice and asks for it, as
`install.sh` does on a server of its own.

## linkrot-admin

`./linkrot-admin` (next to `compose.yaml`) runs `linkrot-admin` in the running container
(`docker compose exec linkrot linkrot-admin`). Without arguments it is a menu:

```
   1) list the hosts
   2) add a host
   3) rewrite config.php (one host or all)
   4) list the tokens of a host
   5) create a token
   6) remove a token
   7) check the installation (migrate, page cache, every config.php)
   8) end-to-end check of a host (through its public URL)
   9) check that mail can be sent
  10) request the certificate again (LINKROT_TLS=acme)
   q) quit
```

Where a host is needed it asks for one from a numbered list; `rewrite` also offers all hosts at once. With arguments
it runs one tool of `app/bin` ([SERVER.md](SERVER.md)), for scripts:

```
./linkrot-admin hosts add files.example.com --email me@example.com   # also creates the database (and the certificate)
./linkrot-admin hosts rewrite
./linkrot-admin lr-auth get my-laptop
docker compose exec -e LINKROT_HOST=share.example.org linkrot linkrot-admin lr-auth ls   # with more than one host
./linkrot-admin install          # what every start does
./linkrot-admin --help
```

The end-to-end check (8) goes through `https://<host>` like a client, from inside the container. Behind a router
that cannot send the machine's own public address back to it (hairpin NAT), it fails although the server works for
everyone else.

## Variables

| variable | default | |
| --- | --- | --- |
| `LINKROT_TLS` | `off` | `acme`, `file` or `off`, see [TLS](#tls) (`compose.yaml` sets `acme`) |
| `LINKROT_ACME_EMAIL` | | `acme`: account at the CA |
| `LINKROT_ACME_SERVER` | `letsencrypt` | `acme`: the CA |
| `LINKROT_TLS_CERT`, `LINKROT_TLS_KEY` | `/certs/fullchain.pem`, `/certs/privkey.pem` | `file` |
| `LINKROT_TRUSTED_PROXIES` | loopback and private networks | `off`: who may set the client address |
| `LINKROT_REAL_IP_HEADER` | `X-Forwarded-For` | `off`: the header with the client address |
| `LINKROT_SMTP_*` | | [Mail](#mail) |
| `LINKROT_PHP_WORKERS` | 8 | most requests at once (php-fpm `pm.max_children`); a worker needs 20-30 MB while it runs |
| `LINKROT_NGINX_WORKERS` | 1 | nginx worker processes |
| `LINKROT_ACCESS_LOG` | `off` | `on`: nginx logs every request to the log of the container |

The healthcheck of the image asks php-fpm through nginx (`/_ping`, answered for the container itself only). Errors of
nginx, php-fpm and PHP go to the log of the container: `docker compose logs -f`.
