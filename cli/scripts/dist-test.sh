#!/usr/bin/env bash
# Checks what the release depends on in scripts/dist.sh, on two targets (one tar.gz, one zip) to keep it quick:
# the archive times come from SOURCE_DATE_EPOCH or the commit and never silently from 1970, nothing leaks into $TMPDIR
# when a build fails, SHA256SUMS lists exactly the archives in a fixed order, and the result does not depend on the
# umask, the time zone or the locale of whoever builds.
# usage: scripts/dist-test.sh
set -euo pipefail
cd "$(dirname "$0")/.."

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
fail() {
	echo "dist-test: $*" >&2
	exit 1
}
export TARGETS="linux/amd64 windows/amd64"
epoch=1000000000 # 2001-09-09 01:46:40 UTC

# build <name> [VAR=value ...]: runs dist.sh with its own TMPDIR, copies dist/ to $tmp/<name>.dist
build() {
	local name="$1"
	shift
	mkdir -p "$tmp/$name.tmp"
	env TMPDIR="$tmp/$name.tmp" "$@" scripts/dist.sh 0.0.0-test >"$tmp/$name.log" 2>&1 || {
		cat "$tmp/$name.log" >&2
		fail "$name: the build failed"
	}
	rm -rf "$tmp/$name.dist"
	cp -r dist "$tmp/$name.dist"
}

# 1. the times come from SOURCE_DATE_EPOCH
build epoch SOURCE_DATE_EPOCH="$epoch"
# (output is captured first: grep -q ending the pipe early would fail it under pipefail)
listing="$(tar --full-time -tvf "$tmp"/epoch.dist/*.tar.gz)"
grep -q '2001-09-09 01:46:40' <<<"$listing" || fail "tar entries do not carry SOURCE_DATE_EPOCH: $listing"
listing="$(unzip -Z "$tmp"/epoch.dist/*.zip)"
grep -q '01-Sep-09 01:46\|2001-Sep-09 01:46\|20010909.014640' <<<"$listing" || fail "zip entries do not carry SOURCE_DATE_EPOCH: $listing"

# 2. nothing is left in $TMPDIR, SHA256SUMS lists exactly the archives, in a fixed order, and verifies
[ -z "$(ls -A "$tmp/epoch.tmp")" ] || fail "temporary files left behind: $(ls "$tmp/epoch.tmp")"
(cd "$tmp/epoch.dist" && find . -type f ! -name SHA256SUMS -printf '%P\n' | LC_ALL=C sort | xargs sha256sum -- >"$tmp/expected-sums")
diff -u "$tmp/expected-sums" "$tmp/epoch.dist/SHA256SUMS" || fail "SHA256SUMS is not the archives in C-locale order"
(cd "$tmp/epoch.dist" && sha256sum -c --quiet SHA256SUMS) || fail "SHA256SUMS does not verify"

# 3. the same inputs give the same bytes, whatever the umask, time zone and locale of the builder
build plain SOURCE_DATE_EPOCH="$epoch" TZ=UTC LC_ALL=C
build other SOURCE_DATE_EPOCH="$epoch" TZ=Asia/Tokyo LC_ALL=C.UTF-8 BASH_ENV=
(
	umask 077
	build umask SOURCE_DATE_EPOCH="$epoch" TZ=America/Los_Angeles
)
for other in other umask; do
	cmp -s "$tmp/plain.dist/SHA256SUMS" "$tmp/$other.dist/SHA256SUMS" || {
		diff "$tmp/plain.dist/SHA256SUMS" "$tmp/$other.dist/SHA256SUMS" >&2 || true
		fail "the archives depend on the builder ($other)"
	}
done

# 4. a failing build leaves nothing in $TMPDIR and fails the script
mkdir -p "$tmp/fail.tmp"
if env TMPDIR="$tmp/fail.tmp" SOURCE_DATE_EPOCH="$epoch" TARGETS="linux/amd64 bogus/os" scripts/dist.sh 0.0.0-test >"$tmp/fail.log" 2>&1; then
	fail "a build for an unknown platform did not fail the script"
fi
[ -z "$(ls -A "$tmp/fail.tmp")" ] || fail "a failed build left temporary files: $(ls "$tmp/fail.tmp")"

# 5. without a commit to take the time from and without SOURCE_DATE_EPOCH it says so instead of dating everything 1970
if env GIT_DIR="$tmp/no-git" TARGETS="linux/amd64" scripts/dist.sh 0.0.0-test >"$tmp/nogit.log" 2>&1; then
	fail "no commit time and no SOURCE_DATE_EPOCH did not fail"
fi
grep -q 'SOURCE_DATE_EPOCH' "$tmp/nogit.log" || fail "the error does not name SOURCE_DATE_EPOCH: $(cat "$tmp/nogit.log")"

echo "dist.sh: ok"
