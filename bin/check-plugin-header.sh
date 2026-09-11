#!/usr/bin/env bash
#
# The committed plugin header must agree with composer.json: plugin-update-checker
# reads the header from the git tag, not from the built zip, so the build-time
# stamp cannot rescue a wrong commit.

set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN="$ROOT/wordpress-sqlite-anywhere.php"

header() {
	sed -n -E "s|^[[:space:]]*\* $1:[[:space:]]*(.*[^[:space:]])[[:space:]]*$|\1|p" "$MAIN"
}

want="$(sed -n -E 's|^[[:space:]]*"version":[[:space:]]*"([^"]+)".*|\1|p' "$ROOT/composer.json")"
have="$(header Version)"
fail=0
if [ "$have" != "$want" ]; then
	echo "wordpress-sqlite-anywhere.php 'Version: $have' does not match composer.json ($want)" >&2
	fail=1
fi
if [ "$(header 'Requires PHP')" != "8.5" ]; then
	echo "wordpress-sqlite-anywhere.php 'Requires PHP' must be 8.5" >&2
	fail=1
fi
if [ "$(header 'Update URI')" != "https://github.com/Avunu/wordpress-sqlite-anywhere" ]; then
	echo "wordpress-sqlite-anywhere.php 'Update URI' must point at the GitHub repository" >&2
	fail=1
fi
exit $fail
