#!/usr/bin/env bash
#
# The upstream/ submodule must sit exactly on a release tag: a scratch commit
# (a `patched` branch left checked out, say) must never be recorded as the
# gitlink.

set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if ! tag="$(git -C "$ROOT/upstream" describe --tags --exact-match 2>/dev/null)"; then
	echo "verify-upstream-pin: upstream/ is at $(git -C "$ROOT/upstream" rev-parse --short HEAD), which is not a tag" >&2
	exit 1
fi
case "$tag" in
	v[0-9]*) ;;
	*) echo "verify-upstream-pin: '$tag' is not a release tag (vX.Y.Z)" >&2; exit 1 ;;
esac
echo "upstream pinned at $tag"
