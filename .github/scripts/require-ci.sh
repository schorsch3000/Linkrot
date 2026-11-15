#!/usr/bin/env bash
# Succeeds if the CI workflow passed for the commit that is released. CI runs on every push to main and on pull
# requests, so the release does not repeat it. A run that is still going is waited for.
# needs: GH_TOKEN, GH_REPO, GITHUB_SHA
set -euo pipefail

runs() { gh run list --workflow ci.yml --commit "$GITHUB_SHA" --json databaseId,status,conclusion; }

if [ "$(runs | jq '[.[] | select(.conclusion == "success")] | length')" -gt 0 ]; then
	echo "CI passed for $GITHUB_SHA"
	exit 0
fi

running="$(runs | jq -r '[.[] | select(.status != "completed")][0].databaseId // empty')"
if [ -n "$running" ]; then
	echo "CI for $GITHUB_SHA is still running, waiting"
	gh run watch "$running" --exit-status --interval 10
	exit 0
fi

found="$(runs | jq -r '[.[].conclusion | select(. != "")] | unique | join(", ")')"
if [ -n "$found" ]; then
	# e.g. a run cancelled because a newer push replaced it (cancel-in-progress): it proves nothing about this commit
	echo "no successful CI run for $GITHUB_SHA, only: $found; re-run the CI workflow for this commit (or push a new one) and tag once it is green" >&2
else
	echo "no successful CI run for $GITHUB_SHA: merge to main or open a pull request first, and tag once CI is green" >&2
fi
exit 1
