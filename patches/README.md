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
`composer.json`, upstream's plugin identity files, or upstream's tests (the
remote suites in `driver-tests/remote/` subclass them instead; patch 0011 is
the seam that makes that possible).

## The series

| # | concern | upstream candidate |
|---|---|---|
| 0001 | `load.php` loads the overlay's connection interface, trait, statement and translator | |
| 0002 | `WP_SQLite_Connection` implements the interface through the overlay trait | |
| 0003 | the driver talks to `WP_SQLite_Connection_Interface`, never to PDO; `sqlite_connection` option | yes, as is |
| 0004 | generated result sets are built in memory (`WP_PDO_Array_Statement`) | yes |
| 0005 | optional capabilities: `transaction_fallback`, no temporary tables, no UDFs | |
| 0006 | six call sites delegate to the overlay's UDF-free translator | |
| 0007 | CREATE TABLE and table recreation run as batches | |
| 0008 | `CHECK TABLE`: an empty `integrity_check` answer is no errors | yes |
| 0009 | information schema reconstruction skips D1's `_cf_*` tables | |
| 0010 | column-key sync as two COALESCE'd assignments (Turso returns no row for the row-value form) | yes |
| 0011 | `WP_MySQL_On_SQLite::$options_filter`, the test seam; inert in production | |

The bulk of what the remote backends need lives in `driver/` as additive
files; the patches are the extension points upstream has no seam for.
