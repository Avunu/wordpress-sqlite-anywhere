#!/usr/bin/env bash
#
# Assemble the plugin from the pinned upstream driver, the patch series, and
# the overlays in this repository.
#
#   bin/assemble.sh [--out DIR]     (default: ./build)
#
# Produces:
#   $OUT/packages/mysql-on-sqlite/                  upstream driver + patches + driver/ and driver-tests/ overlays
#   $OUT/packages/plugin-sqlite-database-integration/   the WordPress plugin, ready for `composer install --no-dev`
#
# Deterministic and dependency-light on purpose: only coreutils and GNU patch,
# no git and no PHP, so it runs unchanged inside the Nix build sandbox. vendor/
# is added by the Nix package, not here.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT="$ROOT/build"

while [ $# -gt 0 ]; do
	case "$1" in
		--out) OUT="$2"; shift 2 ;;
		*) echo "usage: $0 [--out DIR]" >&2; exit 2 ;;
	esac
done

UPSTREAM="$ROOT/upstream/packages"
if [ ! -f "$UPSTREAM/mysql-on-sqlite/src/version.php" ]; then
	echo "assemble: upstream/ is empty -- run 'git submodule update --init'" >&2
	exit 1
fi

PLUGIN_SLUG=wordpress-sqlite-anywhere
DRIVER="$OUT/packages/mysql-on-sqlite"
PLUGIN="$OUT/packages/plugin-sqlite-database-integration"

# Overlay every file of $1 onto $2. A file that already exists in the target
# would silently mask an upstream file, which is exactly the failure mode the
# patch series exists to make visible -- so refuse.
overlay() {
	local from="$1" to="$2" rel
	while IFS= read -r -d '' f; do
		rel="${f#"$from"/}"
		if [ -e "$to/$rel" ]; then
			echo "assemble: overlay $from/$rel would overwrite an upstream file" >&2
			exit 1
		fi
		mkdir -p "$to/$(dirname "$rel")"
		cp "$f" "$to/$rel"
	done < <(find "$from" -type f -print0)
}

rm -rf "$OUT"
mkdir -p "$OUT/packages"

# 1. The two upstream packages, without their vendor trees.
cp -R "$UPSTREAM/mysql-on-sqlite" "$DRIVER"
cp -R "$UPSTREAM/plugin-sqlite-database-integration" "$PLUGIN"
rm -rf "$DRIVER/vendor" "$PLUGIN/vendor" "$PLUGIN/node_modules"
chmod -R u+w "$OUT"

# 2. The patch series. --fuzz=0: a hunk that no longer matches exactly is a
#    real conflict to resolve, not something to paper over. Any rejected hunk
#    fails the build.
for p in "$ROOT"/patches/*.patch; do
	if ! patch -p1 --fuzz=0 --batch --silent --no-backup-if-mismatch -d "$OUT" < "$p"; then
		echo "assemble: $(basename "$p") does not apply cleanly against the pinned upstream" >&2
		find "$OUT" -name '*.rej' -exec sh -c 'echo "--- $1"; cat "$1"' _ {} \; >&2
		exit 1
	fi
done
if find "$OUT" \( -name '*.rej' -o -name '*.orig' \) -print -quit | grep -q .; then
	echo "assemble: rejected hunks left behind" >&2
	exit 1
fi

# 3. Our additive driver code and tests.
overlay "$ROOT/driver" "$DRIVER/src"
overlay "$ROOT/driver-tests" "$DRIVER/tests"
if [ -f "$DRIVER/tests/phpunit.xml" ]; then
	mv "$DRIVER/tests/phpunit.xml" "$DRIVER/phpunit.xml"
fi
rm -rf "$DRIVER/tests/tools-project"

# 4. The plugin. Upstream's wp-includes/database is a symlink into the driver
#    package; replace it with the patched, overlaid driver source.
rm "$PLUGIN/wp-includes/database"
cp -R "$DRIVER/src" "$PLUGIN/wp-includes/database"
overlay "$ROOT/plugin" "$PLUGIN"

# Upstream's identity files are replaced by ours.
rm -f "$PLUGIN/load.php" "$PLUGIN/readme.txt" "$PLUGIN/db.copy" "$PLUGIN/composer.json"
cp "$ROOT/$PLUGIN_SLUG.php" "$ROOT/db.copy" "$ROOT/README.md" "$ROOT/LICENSE" "$PLUGIN/"
cp -R "$ROOT/src" "$PLUGIN/src"

# 5. Invariants. version.php is the DB-migration key WP_SQLite_Configurator
#    compares; the patch series must never touch it.
if ! cmp -s "$PLUGIN/wp-includes/database/version.php" "$UPSTREAM/mysql-on-sqlite/src/version.php"; then
	echo "assemble: wp-includes/database/version.php differs from upstream -- the patch series must not touch it" >&2
	exit 1
fi

echo "assembled: $OUT"
