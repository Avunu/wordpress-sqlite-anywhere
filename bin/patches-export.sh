#!/usr/bin/env bash
#
# Regenerate patches/*.patch from the `patched` branch in upstream/ and detach
# the submodule back to its tag. Pair with bin/patches-checkout.sh.

set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT/upstream"

# The nearest tag below the branch is the pin the series was applied on.
tag="$(git describe --tags --abbrev=0 patched)"

rm -f "$ROOT"/patches/*.patch
git format-patch -o "$ROOT/patches" --no-signature --zero-commit "$tag..patched"
git checkout -q --detach "$tag"
git branch -q -D patched
echo "exported $(ls "$ROOT"/patches/*.patch | wc -l) patches over $tag; upstream/ detached at $tag"
