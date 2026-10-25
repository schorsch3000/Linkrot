#!/usr/bin/env bash
# Copies composer.json and, for a release version like 1.2.3 or 1.2.3-rc1, adds it as "version" (1.2.3-foo becomes 1.2.3+foo). The lock file keeps
# a hash of composer.json, which includes the version, so it is refreshed (hash only, no package changes).
# Platform requirements are ignored: the release runner has no PHP 8.4, and only the hash is wanted.
# usage: scripts/stamp-version.sh <composer.json> <target composer.json> <version>
set -euo pipefail

src="${1:?usage: stamp-version.sh <composer.json> <target> <version>}"
dst="${2:?}"
version="${3:?}"

if ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
	cp "$src" "$dst"
	exit 0
fi

# composer only knows stability suffixes (-rc1, -beta2, ...); any other suffix of a release tag (-foo) is kept as
# build metadata (+foo), which composer accepts
if ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-(alpha|beta|rc|RC|a|b|p|pl|patch)[.-]?[0-9]*)?$ ]]; then
	version="${version/-/+}"
fi

jq --arg v "$version" '{name, description, version: $v} + del(.name, .description)' "$src" >"$dst"
composer --working-dir="$(dirname "$dst")" update --lock --no-install --ignore-platform-reqs --no-autoloader --no-scripts --no-interaction --no-progress --quiet
