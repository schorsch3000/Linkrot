#!/usr/bin/env bash
# Builds release archives for all supported platforms into dist/.
# usage: scripts/dist.sh <version>      (version without the leading v)
set -euo pipefail

version="${1:?usage: dist.sh <version>}"
cd "$(dirname "$0")/.."

# os/arch[/goarm]; the arch label in the archive name is the last part
targets=(
	linux/amd64
	linux/arm64
	linux/arm/7
	linux/386
	darwin/amd64
	darwin/arm64
	windows/amd64
	windows/arm64
	windows/386
	freebsd/amd64
	freebsd/arm64
)

mtime="@$(git log -1 --format=%ct 2>/dev/null || echo 0)"
rm -rf dist
mkdir -p dist

# builds one target (os/arch[/goarm]); runs in parallel, so output goes to its own stage directory
build() {
	local goos goarch goarm label name exe stage
	IFS=/ read -r goos goarch goarm <<<"$1"
	label="$goarch"
	[ -n "${goarm:-}" ] && label="armv${goarm}"
	name="linkrot_${version}_${goos}_${label}"
	exe="linkrot"
	[ "$goos" = windows ] && exe="linkrot.exe"

	stage="$(mktemp -d)"
	cp ../LICENSE "$stage/LICENSE"
	echo "building $name"
	CGO_ENABLED=0 GOOS="$goos" GOARCH="$goarch" GOARM="${goarm:-}" \
		go build -trimpath \
		-ldflags "-s -w -X github.com/schorsch3000/Linkrot/internal/cli.Version=${version}" \
		-o "$stage/$exe" ./cmd/linkrot

	chmod 0755 "$stage/$exe" # not the umask of whoever builds
	chmod 0644 "$stage/LICENSE"
	if [ "$goos" = windows ]; then
		(cd "$stage" && TZ=UTC touch -d "$mtime" "$exe" LICENSE && zip -q -X "$OLDPWD/dist/$name.zip" "$exe" LICENSE)
	else
		tar --sort=name --owner=0 --group=0 --numeric-owner --mtime="$mtime" \
			-C "$stage" -czf "dist/$name.tar.gz" "$exe" LICENSE
	fi
	rm -rf "$stage"
}
export -f build
export version mtime

# xargs exits non-zero if any build failed
# shellcheck disable=SC2016 # $1 is meant to expand in the child shell
printf '%s\n' "${targets[@]}" | xargs -P "$(nproc)" -I{} bash -c 'set -euo pipefail; build "$1"' _ {}

(cd dist && sha256sum -- * >SHA256SUMS)
ls -l dist
