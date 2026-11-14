#!/usr/bin/env bash
# Unpacks the release tarball like a deployment would and runs app/install.sh: without a config it must say
# what to do, with a valid one it must be happy, with a broken one it must complain.
# usage: scripts/dist-test.sh <tarball>
set -euo pipefail

tarball="$(realpath "${1:?usage: dist-test.sh <tarball>}")"
root="$(mktemp -d)"
trap 'rm -rf "$root"' EXIT

tar -xzf "$tarball" -C "$root"
mkdir -p "$root/app_data"
host=127.0.0.1
hd="$root/app_data/$host"

for icon in favicon.svg favicon.ico apple-touch-icon.png; do
	[ -f "$root/htdocs/$icon" ] || { echo "$icon is missing from the release" >&2; exit 1; }
done

# assets have content-hashed names, the manifest maps the plain names to them
[ -f "$root/app/assets.php" ] || { echo "assets manifest is missing from the release" >&2; exit 1; }
if [ -e "$root/htdocs/assets/page.css" ] || [ -e "$root/htdocs/assets/page.js" ]; then
	echo "assets still have their plain names" >&2
	exit 1
fi
for name in page.css page.js; do
	# shellcheck disable=SC2016 # PHP code, not shell
	hashed="$(php -r '$m = require $argv[1]; echo $m[$argv[2]] ?? "";' "$root/app/assets.php" "$name")"
	[[ "$hashed" =~ ^${name%.*}\.[0-9a-f]{12}\.${name##*.}$ ]] || { echo "manifest has no hashed name for $name: '$hashed'" >&2; exit 1; }
	[ -s "$root/htdocs/assets/$hashed" ] || { echo "$hashed is missing from the release" >&2; exit 1; }
	[ "$(sha256sum "$root/htdocs/assets/$hashed" | cut -c1-12)" = "$(cut -d. -f2 <<<"$hashed")" ] || { echo "$hashed does not match its content" >&2; exit 1; }
done

out="$("$root/app/install.sh" --no-network-check 2>&1)"
grep -q "ACTION REQUIRED" <<<"$out" || { echo "no instructions without config:" >&2; echo "$out" >&2; exit 1; }
grep -q -- "--add-host" <<<"$out" || { echo "instructions do not name --add-host" >&2; exit 1; }
[ -f "$root/app/vendor/autoload.php" ] || { echo "composer install did not run" >&2; exit 1; }
[ ! -d "$root/app/vendor/phpunit" ] || { echo "dev dependencies were installed" >&2; exit 1; }


out="$("$root/app/install.sh" --add-host 127.0.0.1:1 --no-network-check 2>&1)" || { echo "--add-host failed:" >&2; echo "$out" >&2; exit 1; }
grep -q "ACTION REQUIRED: there is no configuration for $host" <<<"$out" || { echo "no instructions for the new host:" >&2; echo "$out" >&2; exit 1; }
grep -q "$hd/config.php" <<<"$out" || { echo "instructions do not name the file" >&2; exit 1; }
grep -q "'$host'" "$root/app_data/global-config.php" || { echo "host not listed in global-config.php" >&2; exit 1; }
[ -d "$hd/files" ] || { echo "--add-host did not create the files folder" >&2; exit 1; }
[ -s "$hd/linkrot.sqlite" ] || { echo "install.sh did not create the database" >&2; exit 1; }
LINKROT_DATA="$root/app_data" "$root/app/bin/lr-auth" ls >/dev/null || { echo "database is not usable after install.sh" >&2; exit 1; }
out="$("$root/app/install.sh" --add-host "$host" --no-network-check 2>&1)" || { echo "enrolling twice failed" >&2; exit 1; }
[ "$(grep -c "'$host'" "$root/app_data/global-config.php")" = 1 ] || { echo "host listed twice" >&2; exit 1; }
if "$root/app/install.sh" --add-host '../etc' --no-network-check >/dev/null 2>&1; then echo "a path as host was accepted" >&2; exit 1; fi

printf "<?php return [];\n" >"$hd/config.php"
out="$("$root/app/install.sh" --no-network-check 2>&1)"
grep -q "is valid" <<<"$out" || { echo "valid config not accepted:" >&2; echo "$out" >&2; exit 1; }

printf "<?php return ['base_url' => 'https://lr.example'];\n" >"$hd/config.php"
out="$("$root/app/install.sh" --no-network-check 2>&1)"
grep -q "base_url is gone" <<<"$out" || { echo "invalid config not reported:" >&2; echo "$out" >&2; exit 1; }

chmod 750 "$hd/files"
out="$("$root/app/install.sh" --no-network-check 2>&1)"
grep -q "not searchable by others" <<<"$out" || { echo "closed files directory not reported:" >&2; echo "$out" >&2; exit 1; }
chmod 755 "$hd/files"
out="$("$root/app/install.sh" --no-network-check 2>&1)"
! grep -q "not searchable" <<<"$out" || { echo "false alarm for an open files directory" >&2; exit 1; }

# End to end for real: php -S serves the unpacked release, install.sh does the whole round trip through https://<host> (loopback: http plus the port in LINKROT_AUTHORITY).
port="$(python3 -c 'import socket; s = socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1])')"
printf "<?php return ['download_mode' => 'php'];\n" >"$hd/config.php"
export LINKROT_AUTHORITY="127.0.0.1:$port"
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

# the pages of the release link the hashed assets, and the server delivers them
home="$(curl -fsS "http://127.0.0.1:$port/")"
css="$(grep -oE '/assets/page\.[0-9a-f]{12}\.css' <<<"$home" | head -1)"
[ -n "$css" ] || { echo "the about page does not link the hashed stylesheet" >&2; exit 1; }
[[ "$(curl -sS -o /dev/null -w '%{http_code} %{content_type}' "http://127.0.0.1:$port$css")" =~ ^200\ text/css ]] || { echo "$css is not served as text/css" >&2; exit 1; }
csp="$(curl -sSI "http://127.0.0.1:$port/" | tr -d '\r' | grep -i '^content-security-policy:')"
grep -q "style-src 'self'" <<<"$csp" || { echo "unexpected content security policy: $csp" >&2; exit 1; }

# a host that is not listed gets 421, whatever the path
[ "$(curl -sS -o /dev/null -w '%{http_code}' -H 'Host: unlisted.example' "http://127.0.0.1:$port/")" = 421 ] || { echo "an unlisted host was not answered with 421" >&2; exit 1; }

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
