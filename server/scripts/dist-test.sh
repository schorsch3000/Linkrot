#!/usr/bin/env bash
# Unpacks the release tarball like a deployment would and runs app/install.sh: without a config it must say
# what to do, with a valid one it must be happy, with a broken one it must complain.
# usage: scripts/dist-test.sh <tarball>
set -euo pipefail

tarball="$(realpath "${1:?usage: dist-test.sh <tarball>}")"
root="$(mktemp -d)"
trap 'rm -rf "$root"' EXIT

tar -xzf "$tarball" -C "$root"
mkdir -p "$root/app_data/files"

for icon in favicon.svg favicon.ico apple-touch-icon.png; do
	[ -f "$root/htdocs/$icon" ] || { echo "$icon is missing from the release" >&2; exit 1; }
done

out="$("$root/app/install.sh" 2>&1)"
grep -q "ACTION REQUIRED" <<<"$out" || { echo "no instructions without config:" >&2; echo "$out" >&2; exit 1; }
grep -q "$root/app_data/config.php" <<<"$out" || { echo "instructions do not name the file" >&2; exit 1; }
[ -f "$root/app/vendor/autoload.php" ] || { echo "composer install did not run" >&2; exit 1; }
[ ! -d "$root/app/vendor/phpunit" ] || { echo "dev dependencies were installed" >&2; exit 1; }

[ -s "$root/app_data/linkrot.sqlite" ] || { echo "install.sh did not create the database" >&2; exit 1; }
LINKROT_DATA="$root/app_data" "$root/app/bin/lr-auth" ls >/dev/null || { echo "database is not usable after install.sh" >&2; exit 1; }

printf "<?php return ['base_url' => 'https://lr.example'];\n" >"$root/app_data/config.php"
out="$("$root/app/install.sh" 2>&1)"
grep -q "is valid" <<<"$out" || { echo "valid config not accepted:" >&2; echo "$out" >&2; exit 1; }

printf "<?php return ['base_url' => 'lr.example'];\n" >"$root/app_data/config.php"
out="$("$root/app/install.sh" 2>&1)"
grep -q "base_url must be an http(s) URL" <<<"$out" || { echo "invalid config not reported:" >&2; echo "$out" >&2; exit 1; }

chmod 750 "$root/app_data/files"
out="$("$root/app/install.sh" 2>&1)"
grep -q "not searchable by others" <<<"$out" || { echo "closed files directory not reported:" >&2; echo "$out" >&2; exit 1; }
chmod 755 "$root/app_data/files"
out="$("$root/app/install.sh" 2>&1)"
! grep -q "not searchable" <<<"$out" || { echo "false alarm for an open files directory" >&2; exit 1; }

echo "dist-test: ok"
