#!/usr/bin/env bash
# Installs the production dependencies. Run after unpacking a release, and again after every upgrade.
set -euo pipefail

cd "$(dirname "$0")"

command -v composer >/dev/null 2>&1 || {
	echo "install.sh: composer is required: https://getcomposer.org" >&2
	exit 1
}

exec composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --classmap-authoritative
