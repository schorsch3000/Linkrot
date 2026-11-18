# Linkrot server as a single container: nginx, php-fpm (pm = ondemand) and the TLS helper, on Alpine.
# docs/DOCKER.md explains the runtime side; compose.yaml is the stack to start from.
#
#   docker build -t linkrot .                                  development build (version "dev")
#   docker build --build-arg LINKROT_VERSION=1.2.3 -t linkrot .  what the release workflow does

ARG ALPINE_VERSION=3.22

# The same tree as the release tarball (make -C server build), with the production dependencies installed the way
# app/install.sh does it.
FROM alpine:${ALPINE_VERSION} AS build
ARG LINKROT_VERSION=dev
RUN apk add --no-cache bash jq make \
        php84 php84-phar php84-openssl php84-zip php84-mbstring php84-iconv php84-ctype php84-pdo_sqlite php84-pecl-apcu \
        php84-xmlwriter \
    && { [ -e /usr/bin/php ] || ln -s php84 /usr/bin/php; }
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /src
COPY LICENSE ./
COPY server/ server/
RUN make -C server build VERSION="${LINKROT_VERSION}" \
    && composer --working-dir=server/build/dist/app install --no-dev --no-interaction --no-progress --prefer-dist \
        --optimize-autoloader --classmap-authoritative

FROM alpine:${ALPINE_VERSION}
ARG LINKROT_VERSION=dev
LABEL org.opencontainers.image.title="linkrot" \
      org.opencontainers.image.description="Share a file from the terminal. The link rots on its own." \
      org.opencontainers.image.source="https://github.com/schorsch3000/Linkrot" \
      org.opencontainers.image.licenses="GPL-3.0-or-later" \
      org.opencontainers.image.version="${LINKROT_VERSION}"

RUN apk add --no-cache tini bash nginx msmtp acme.sh curl openssl ca-certificates \
        php84 php84-fpm php84-opcache php84-openssl php84-mbstring php84-iconv php84-ctype php84-pdo_sqlite \
        php84-pecl-apcu php84-xmlwriter \
    && { [ -e /usr/bin/php ] || ln -s php84 /usr/bin/php; } \
    && { [ -e /usr/sbin/php-fpm ] || ln -s php-fpm84 /usr/sbin/php-fpm; }

COPY --from=build /src/server/build/dist/ /srv/linkrot/
COPY docker/php.ini /etc/php84/conf.d/90-linkrot.ini
COPY docker/php-fpm.conf /etc/linkrot/php-fpm.conf
COPY docker/lib.sh /usr/local/lib/linkrot/lib.sh
COPY docker/linkrot-start docker/linkrot-tls docker/linkrot-admin /usr/local/bin/

# Any user may run the container (compose.yaml says 1000:1000): everything it writes at runtime is under /tmp/linkrot
# and in the bind-mounted app_data.
RUN mkdir -p /srv/linkrot/app_data && chmod 1777 /srv/linkrot/app_data \
    && chmod 0755 /usr/local/bin/linkrot-start /usr/local/bin/linkrot-tls /usr/local/bin/linkrot-admin

USER 1000:1000
WORKDIR /srv/linkrot
EXPOSE 8080 8443
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD wget -q -O /dev/null http://127.0.0.1:8080/_ping || exit 1
ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/linkrot-start"]
