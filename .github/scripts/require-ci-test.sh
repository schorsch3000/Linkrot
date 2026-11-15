#!/usr/bin/env bash
# Plays require-ci.sh against a fake `gh` for the situations a release meets: CI passed, CI is running, CI was cancelled
# (a newer push superseded it), CI failed, there was no run at all.
# usage: .github/scripts/require-ci-test.sh
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir "$tmp/bin"

# the fake: `gh run list` prints $FAKE_LIST, `gh run watch` exits with $FAKE_WATCH_RC
cat >"$tmp/bin/gh" <<'FAKE'
#!/usr/bin/env bash
case "$1 $2" in
"run list") printf '%s\n' "$FAKE_LIST" ;;
"run watch") exit "${FAKE_WATCH_RC:-0}" ;;
*) echo "fake gh: unexpected $*" >&2; exit 99 ;;
esac
FAKE
chmod +x "$tmp/bin/gh"

failures=0
# check <name> <expected exit: 0|nonzero> <pattern the output must contain, or ""> <FAKE_LIST> [FAKE_WATCH_RC]
check() {
	local name="$1" want="$2" pattern="$3" list="$4" watch="${5:-0}" out rc=0
	out="$(PATH="$tmp/bin:$PATH" GH_TOKEN=x GH_REPO=o/r GITHUB_SHA=abc FAKE_LIST="$list" FAKE_WATCH_RC="$watch" "$here/require-ci.sh" 2>&1)" || rc=$?
	if { [ "$want" = 0 ] && [ "$rc" -ne 0 ]; } || { [ "$want" != 0 ] && [ "$rc" -eq 0 ]; }; then
		echo "FAIL $name: exit $rc, wanted $want; output: $out" >&2
		failures=$((failures + 1))
	elif [ -n "$pattern" ] && ! grep -q -- "$pattern" <<<"$out"; then
		echo "FAIL $name: output lacks '$pattern': $out" >&2
		failures=$((failures + 1))
	fi
}

check "passed" 0 "CI passed" '[{"databaseId":1,"status":"completed","conclusion":"success"}]'
check "one of several passed" 0 "CI passed" '[{"databaseId":2,"status":"completed","conclusion":"cancelled"},{"databaseId":1,"status":"completed","conclusion":"success"}]'
check "no run at all" nonzero "no successful CI run" '[]'
check "only a cancelled run" nonzero "cancelled" '[{"databaseId":1,"status":"completed","conclusion":"cancelled"}]'
check "only a failed run" nonzero "failure" '[{"databaseId":1,"status":"completed","conclusion":"failure"}]'
check "running, then passes" 0 "waiting" '[{"databaseId":1,"status":"in_progress","conclusion":""}]' 0
check "queued, then passes" 0 "waiting" '[{"databaseId":1,"status":"queued","conclusion":""}]' 0
check "running, then fails or is cancelled" nonzero "" '[{"databaseId":1,"status":"in_progress","conclusion":""}]' 1

[ "$failures" -eq 0 ] || exit 1
echo "require-ci.sh: ok"
