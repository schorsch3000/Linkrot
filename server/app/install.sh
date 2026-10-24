#!/usr/bin/env bash
# Installs the production dependencies and checks the setup. Run after unpacking a release, and again after
# every upgrade.
set -euo pipefail

cd "$(dirname "$0")"

command -v composer >/dev/null 2>&1 || {
	echo "install.sh: composer is required: https://getcomposer.org" >&2
	exit 1
}

composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --classmap-authoritative

# app_data lives next to app/ and is never touched by a deployment (LINKROT_DATA overrides it, as for the server).
data_dir="${LINKROT_DATA:-$(cd .. && pwd)/app_data}"
config="$data_dir/config.php"

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
[ -d "$data_dir/files" ] || echo "install.sh: note: $data_dir/files does not exist yet, create it (writable by the php-fpm user)." >&2
