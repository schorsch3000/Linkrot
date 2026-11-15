#!/usr/bin/env bash
# Makes the images of the README (docs/img): screenshots of the pages and a recording of a terminal session.
#
# Everything is real: this builds the CLI, starts the PHP server on a throwaway data directory, uploads sample files with
# the API, takes screenshots with headless Chromium (light and dark) and records the actual output of the actual CLI.
# The one cosmetic step is documented in record.py: the origin of the local server in the recording is shown as
# https://files.example.com.
#
# Needs: go, php (with apcu, pdo_sqlite), python3, node and npm, and a Chromium (CHROMIUM=/path, default: chromium).
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$here/../.." && pwd)"
out="$root/docs/img"
chromium="${CHROMIUM:-$(command -v chromium || command -v chromium-browser || command -v google-chrome || true)}"
[ -n "$chromium" ] || { echo "showcase: no Chromium found, set CHROMIUM=/path/to/chromium" >&2; exit 1; }

tmp="$(mktemp -d)"
server=""
cleanup() {
	[ -z "$server" ] || kill "$server" 2>/dev/null || true
	rm -rf "$tmp"
}
trap cleanup EXIT

[ -d "$here/node_modules" ] || (cd "$here" && npm ci --no-audit --no-fund)

echo "showcase: building the CLI"
mkdir -p "$tmp/bin"
(cd "$root/cli" && go build -trimpath -o "$tmp/bin/lr" ./cmd/linkrot)

echo "showcase: starting a server on a throwaway data directory"
port="$(python3 -c 'import socket; s = socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1])')"
data="$tmp/app_data"
for host in localhost files.example.com; do
	mkdir -p "$data/$host/files"
	printf "<?php\nreturn ['download_mode' => 'php'];\n" >"$data/$host/config.php"
done
printf "<?php\nreturn ['hosts' => ['localhost', 'files.example.com']];\n" >"$data/global-config.php"
export LINKROT_DATA="$data"
php "$root/server/app/bin/migrate" >/dev/null
token_local="$(LINKROT_HOST=localhost php "$root/server/app/bin/lr-auth" get demo)"
token_files="$(LINKROT_HOST=files.example.com php "$root/server/app/bin/lr-auth" get demo)"
php -d apc.enable_cli=1 -S "127.0.0.1:$port" -t "$root/server/htdocs" "$root/server/htdocs/index.php" >"$tmp/server.log" 2>&1 &
server=$!
for _ in $(seq 50); do
	(exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null && break
	sleep 0.1
done

mkdir -p "$out"
echo "showcase: screenshots"
PORT="$port" TOKEN="$token_files" CHROMIUM="$chromium" OUT="$out" node "$here/screenshots.mjs"

echo "showcase: recording"
export LINKROT_CONFIG="$tmp/lr.toml"
"$tmp/bin/lr" config --server "http://localhost:$port" --token "$token_local"
# a fresh server would say in the first line that its cleanup has not run yet; let it
"$tmp/bin/lr" cleanup -q
PORT="$port" BIN="$tmp/bin" WORK="$tmp/work" OUT="$out" python3 "$here/record.py"
"$here/node_modules/.bin/svg-term" --in "$out/demo.cast" --out "$out/demo.svg" --window --no-cursor --width 122 --height 22 --padding 12

echo "showcase: done"
ls -l "$out"
