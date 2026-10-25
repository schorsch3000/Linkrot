#!/usr/bin/env bash
# Prints the version of the tag being released (v1.2.3 gives 1.2.3), fails for anything that is no version tag.
set -euo pipefail

tag="${GITHUB_REF_NAME:?}"
if ! [[ "$tag" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
	echo "tag '$tag' is not of the form vMAJOR.MINOR.PATCH[-suffix]" >&2
	exit 1
fi
echo "${tag#v}"
