#!/usr/bin/env bash
# Unpacks the shared hosting zip like a user would and checks what is in it: the document root files, the setup, the
# .htaccess files and the code with its dependencies, nothing of the development. The setup itself is tested end to end
# by cli/e2e (TestEndToEndShared).
# usage: scripts/shared-test.sh <zip>
set -euo pipefail

zip="$(realpath "${1:?usage: shared-test.sh <zip>}")"
root="$(mktemp -d)"
trap 'rm -rf "$root"' EXIT

unzip -q "$zip" -d "$root"
for file in index.php setup.php .htaccess favicon.svg linkrot-app/.htaccess linkrot-app/vendor/autoload.php linkrot-app/assets.php linkrot-app/LICENSE; do
	[ -f "$root/$file" ] || { echo "$file is missing from the zip" >&2; exit 1; }
done
for file in setup-code.php linkrot-paths.php linkrot-data; do
	[ ! -e "$root/$file" ] || { echo "$file must not be in the zip: it belongs to an installation" >&2; exit 1; }
done
[ ! -d "$root/linkrot-app/vendor/phpunit" ] || { echo "dev dependencies are in the zip" >&2; exit 1; }
grep -q 'Require all denied' "$root/linkrot-app/.htaccess" || { echo "linkrot-app/.htaccess does not lock the code" >&2; exit 1; }
grep -q 'RewriteRule \^ index.php' "$root/.htaccess" || { echo ".htaccess has no front controller" >&2; exit 1; }

# runs without APCu and says that it is not set up yet
out="$(cd "$root" && REQUEST_URI=/ REQUEST_METHOD=GET php -d apc.enabled=0 index.php)"
grep -q 'not_set_up' <<<"$out" || { echo "index.php before the setup: $out" >&2; exit 1; }
php -l "$root/setup.php" >/dev/null
echo "shared-test: ok"
