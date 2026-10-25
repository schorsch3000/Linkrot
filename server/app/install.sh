#!/usr/bin/env bash
# Installs the production dependencies and checks the setup. Run after unpacking a release, and again after
# every upgrade.
# usage: install.sh [--no-network-check]
#   --no-network-check  skip the round trip through base_url (offline installs, a host that cannot reach itself)
set -euo pipefail

network_check=1
for arg in "$@"; do
	case "$arg" in
	--no-network-check) network_check=0 ;;
	*)
		echo "usage: install.sh [--no-network-check]" >&2
		exit 2
		;;
	esac
done

cd "$(dirname "$0")"

command -v composer >/dev/null 2>&1 || {
	echo "install.sh: composer is required: https://getcomposer.org" >&2
	exit 1
}

composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --classmap-authoritative

# app_data lives next to app/ and is never touched by a deployment (LINKROT_DATA overrides it, as for the server).
data_dir="${LINKROT_DATA:-$(cd .. && pwd)/app_data}"
config="$data_dir/config.php"

# Creates the database or migrates it to the schema of this release. The server only checks the version.
if [ -d "$data_dir" ]; then
	LINKROT_DATA="$data_dir" php bin/migrate
else
	echo "install.sh: note: $data_dir does not exist yet, create it (owned by the php-fpm user) and run install.sh again to set up the database." >&2
fi

if [ ! -d "$data_dir/files" ]; then
	echo "install.sh: note: $data_dir/files does not exist yet, create it (writable by the php-fpm user)." >&2
elif ! find "$data_dir/files" -maxdepth 0 -perm -001 | grep -q .; then
	# nginx opens the files itself (X-Accel-Redirect). If it runs as another user than php-fpm it needs search permission.
	echo "install.sh: note: $data_dir/files is not searchable by others. If nginx runs as another user than php-fpm, downloads fail with 403: chmod o+x $data_dir/files" >&2
fi

if [ ! -f "$config" ]; then
	cat >&2 <<MSG

================================================================================
 ACTION REQUIRED: there is no configuration yet.
 Until it exists every request is answered with "server misconfigured".

 Create $config, readable by the user php-fpm runs as:

     <?php
     return [
         'base_url' => 'https://your.server.example',   // public URL, used for the share links
     ];

 The folder $data_dir/files must exist and be writable by that user too.
 All options: docs/SERVER.md (Configuration)
================================================================================

MSG
	exit 0
fi

# The config exists: load it the way the server does, so that mistakes show up now and not as a 500 later.
if ! error="$(bin/check-config "$config" 2>&1)"; then
	cat >&2 <<MSG

================================================================================
 WARNING: $config is not valid:

 $error

 (Checked as $(id -un). If that user may not read the file but php-fpm's user can, this is a false alarm.)
================================================================================

MSG
	exit 0
fi

echo "install.sh: $config is valid."

if [ "$network_check" = 0 ]; then
	echo "install.sh: the end-to-end check was skipped (--no-network-check)."
	exit 0
fi

# End to end: a temporary token (test-install-check, removed again) does health, upload, page, download and checks
# the headers through the public base_url. A failure makes install.sh fail.
if ! LINKROT_DATA="$data_dir" bin/check-install; then
	cat >&2 <<MSG

================================================================================
 FAILED: the end-to-end check did not pass, see the lines above.
 The installation itself is done. Fix the cause and run install.sh again; if this host cannot reach its own
 base_url (hairpin NAT, no DNS), run install.sh --no-network-check.
================================================================================

MSG
	exit 1
fi
echo "install.sh: end-to-end check passed."
