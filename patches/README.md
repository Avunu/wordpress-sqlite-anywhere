# Patches against the bundled upstream driver

Numbered `git format-patch` output applied on top of the `upstream/` submodule
(pinned to a release tag of `WordPress/sqlite-database-integration`) by
`bin/assemble.sh`, with `patch -p1 --fuzz=0`. Paths are relative to the upstream
repository root.

Editing the series:

```sh
bin/patches-checkout.sh   # branch `patched` in upstream/ with the series applied (git am --3way)
# ... edit, commit --fixup / rebase as needed ...
bin/patches-export.sh     # regenerate patches/*.patch and detach upstream/ back to the tag
```

Never patch `version.php` (the migration key `WP_SQLite_Configurator` compares),
`composer.json`, or upstream's plugin identity files.
