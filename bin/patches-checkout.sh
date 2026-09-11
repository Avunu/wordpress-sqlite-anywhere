#!/usr/bin/env bash
#
# Check out a `patched` branch in upstream/ with the patch series applied, for
# editing. Pair with bin/patches-export.sh.

set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT/upstream"

tag="$(git describe --tags --exact-match)"
git checkout -q -B patched "$tag"
# --3way: when upstream moved, the `index` lines in the patches give git the
# blobs to do a real merge; conflicts land as markers to resolve by hand.
git am --3way "$ROOT"/patches/*.patch
echo "upstream/ is on branch 'patched' ($(git rev-list --count "$tag"..patched) commits over $tag)"
