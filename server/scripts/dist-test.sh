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

out="$("$root/app/install.sh" --no-network-check 2>&1)"
grep -q "ACTION REQUIRED" <<<"$out" || { echo "no instructions without config:" >&2; echo "$out" >&2; exit 1; }
grep -q "$root/app_data/config.php" <<<"$out" || { echo "instructions do not name the file" >&2; exit 1; }
[ -f "$root/app/vendor/autoload.php" ] || { echo "composer install did not run" >&2; exit 1; }
[ ! -d "$root/app/vendor/phpunit" ] || { echo "dev dependencies were installed" >&2; exit 1; }

[ -s "$root/app_data/linkrot.sqlite" ] || { echo "install.sh did not create the database" >&2; exit 1; }
LINKROT_DATA="$root/app_data" "$root/app/bin/lr-auth" ls >/dev/null || { echo "database is not usable after install.sh" >&2; exit 1; }

printf "<?php return ['base_url' => 'https://lr.example'];\n" >"$root/app_data/config.php"
out="$("$root/app/install.sh" --no-network-check 2>&1)"
grep -q "is valid" <<<"$out" || { echo "valid config not accepted:" >&2; echo "$out" >&2; exit 1; }

printf "<?php return ['base_url' => 'lr.example'];\n" >"$root/app_data/config.php"
out="$("$root/app/install.sh" --no-network-check 2>&1)"
grep -q "base_url must be an https URL" <<<"$out" || { echo "invalid config not reported:" >&2; echo "$out" >&2; exit 1; }

chmod 750 "$root/app_data/files"
out="$("$root/app/install.sh" --no-network-check 2>&1)"
grep -q "not searchable by others" <<<"$out" || { echo "closed files directory not reported:" >&2; echo "$out" >&2; exit 1; }
chmod 755 "$root/app_data/files"
out="$("$root/app/install.sh" --no-network-check 2>&1)"
! grep -q "not searchable" <<<"$out" || { echo "false alarm for an open files directory" >&2; exit 1; }

# End to end for real: php -S serves the unpacked release, install.sh does the whole round trip through base_url.
port="$(python3 -c 'import socket; s = socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1])')"
printf "<?php return ['base_url' => 'http://127.0.0.1:%s', 'download_mode' => 'php'];\n" "$port" >"$root/app_data/config.php"
LINKROT_DATA="$root/app_data" php -d apc.enable_cli=1 -S "127.0.0.1:$port" -t "$root/htdocs" "$root/htdocs/index.php" >"$root/server.log" 2>&1 &
server=$!
trap 'kill "$server" 2>/dev/null || true; rm -rf "$root"' EXIT
for _ in $(seq 50); do
	(exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null && break
	sleep 0.1
done

out="$("$root/app/install.sh" 2>&1)" || { echo "install.sh failed against a working server:" >&2; echo "$out" >&2; exit 1; }
grep -q "end-to-end check passed" <<<"$out" || { echo "no end-to-end result:" >&2; echo "$out" >&2; exit 1; }
grep -q "download headers: ok, X-Rot: " <<<"$out" || { echo "X-Rot was not checked:" >&2; echo "$out" >&2; exit 1; }
tokens="$(LINKROT_DATA="$root/app_data" "$root/app/bin/lr-auth" ls)"
! grep -q "test-install-check" <<<"$tokens" || { echo "the test token was not removed" >&2; exit 1; }

# a leftover test token of an interrupted run is replaced
LINKROT_DATA="$root/app_data" "$root/app/bin/lr-auth" get test-install-check >/dev/null
"$root/app/install.sh" >/dev/null 2>&1 || { echo "a leftover test token broke the check" >&2; exit 1; }

# no server: the check fails with a clear message, --no-network-check skips it
kill "$server"
wait "$server" 2>/dev/null || true
if out="$("$root/app/install.sh" 2>&1)"; then echo "install.sh passed without a server" >&2; exit 1; fi
grep -q "FAILED" <<<"$out" || { echo "failure not reported:" >&2; echo "$out" >&2; exit 1; }
"$root/app/install.sh" --no-network-check >/dev/null 2>&1 || { echo "--no-network-check did not skip the check" >&2; exit 1; }
tokens="$(LINKROT_DATA="$root/app_data" "$root/app/bin/lr-auth" ls)"
! grep -q "test-install-check" <<<"$tokens" || { echo "the test token survived a failed check" >&2; exit 1; }

echo "dist-test: ok"
