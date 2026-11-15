#!/usr/bin/env bash
# Builds release archives for all supported platforms into dist/.
# usage: scripts/dist.sh <version>      (version without the leading v; JOBS=n limits the parallel builds, default: all cores;
#                                        TARGETS="linux/amd64 windows/amd64" builds only those, for the tests of this script)
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

if [ -n "${TARGETS:-}" ]; then
	read -ra targets <<<"$TARGETS"
fi

# The time of the archive entries: SOURCE_DATE_EPOCH (reproducible-builds.org), else the time of the commit. Never a
# made-up one: building from a source tarball without either must say so instead of dating everything 1970.
if [ -n "${SOURCE_DATE_EPOCH:-}" ]; then
	epoch="$SOURCE_DATE_EPOCH"
elif ! epoch="$(git log -1 --format=%ct 2>/dev/null)" || [ -z "$epoch" ]; then
	echo "dist.sh: there is no commit to take the time of the archives from; set SOURCE_DATE_EPOCH" >&2
	exit 1
fi
mtime="@$epoch"
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
	# Gone on every way out of this shell, also when a build fails (xargs lets the other targets finish). The path is
	# expanded now: the local variable does not exist any more when the trap runs.
	# shellcheck disable=SC2064
	trap "rm -rf '$stage'" EXIT
	cp ../LICENSE "$stage/LICENSE"
	echo "building $name"
	CGO_ENABLED=0 GOOS="$goos" GOARCH="$goarch" GOARM="${goarm:-}" \
		go build -trimpath \
		-ldflags "-s -w -X github.com/schorsch3000/Linkrot/internal/cli.Version=${version}" \
		-o "$stage/$exe" ./cmd/linkrot

	chmod 0755 "$stage/$exe" # not the umask of whoever builds
	chmod 0644 "$stage/LICENSE"
	if [ "$goos" = windows ]; then
		# zip stores local time: without TZ=UTC the archive would depend on the time zone of whoever builds
		(cd "$stage" && export TZ=UTC && touch -d "$mtime" "$exe" LICENSE && zip -q -X "$OLDPWD/dist/$name.zip" "$exe" LICENSE)
	else
		tar --sort=name --owner=0 --group=0 --numeric-owner --mtime="$mtime" \
			-C "$stage" -czf "dist/$name.tar.gz" "$exe" LICENSE
	fi
}
export -f build
export version mtime

# xargs exits non-zero if any build failed
# shellcheck disable=SC2016 # $1 is meant to expand in the child shell
printf '%s\n' "${targets[@]}" | xargs -P "${JOBS:-$(nproc)}" -I{} bash -c 'set -euo pipefail; build "$1"' _ {}

# the archives, by name in byte order: a glob would sort by the locale of the builder
sums="$(cd dist && find . -maxdepth 1 -type f -printf '%P\n' | LC_ALL=C sort | xargs sha256sum --)"
printf '%s\n' "$sums" >dist/SHA256SUMS
ls -l dist
