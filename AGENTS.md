# AGENTS.md — WordPress SQLite Anywhere

## What this is

A WordPress plugin that runs the site on SQLite — a local file, Turso, or Cloudflare D1 — with one
`wp-content/db.php` drop-in choosing the engine from `DB_ENGINE`. It bundles the upstream
`WordPress/sqlite-database-integration` driver as a git submodule pinned to a release tag and adds the
network backends on top. This is a hard divergence from upstream, not a fork: nothing here is
upstreamed, and upstream is consumed release by release.

## Layout and the one deliberate boundary

- `upstream/` — submodule, always on a `vX.Y.Z` tag (`bin/verify-upstream-pin.sh` enforces it). Never
  edit files in it directly; changes go through the patch series.
- `patches/` — `git format-patch` output applied by `bin/assemble.sh` with `patch -p1 --fuzz=0`. Edit
  with `bin/patches-checkout.sh` / `bin/patches-export.sh`. Never patch `version.php`, upstream's
  `composer.json`, README, or plugin identity files.
- `driver/`, `driver-tests/`, `plugin/` — overlays onto upstream's driver source, its tests, and its
  plugin directory. Additive only: the assembler refuses to overwrite an upstream file. This code
  implements upstream's `WP_SQLite_Connection_Interface` in upstream's namespace-less world, so it
  follows upstream's **WordPress coding standard** (`WP_` prefix, snake_case, tabs; lint with
  `phpcs-driver.xml.dist`) — but with `declare(strict_types=1)`, complete signatures, array-shape
  docblocks and PHPStan level 8.
- `src/` (`SqliteAnywhere\`), `wordpress-sqlite-anywhere.php`, `db.copy`, `tests/` — **house style**:
  `strict_types`, `final`, typed everything, enums, PSR-12, camelCase, PHPStan level 8.
- `packages/` — `turso-snapshot-publisher` (Rust), `d1-proxy-worker` (TypeScript), `php-ext-wp-d1-client`
  (Rust PHP extension).
- `bin/assemble.sh` — upstream + patches + overlays → `build/`. Coreutils and GNU `patch` only, so it
  runs inside the Nix sandbox. `vendor/` is added by the Nix package.

## Commands

```sh
nix develop                     # or direnv; PHP 8.5 + composer + phpstan + phpcs + cargo + node + tursodb
composer install                # root project: plugin-update-checker, PHPUnit 12, phpstan-wordpress
composer --working-dir=driver-tests/tools-project install   # PHPUnit 9 + WPCS for the driver suites
composer assemble               # bin/assemble.sh → build/
composer phpstan                # level 8; needs build/ (scanDirectories points at the assembled driver)
composer phpcs                  # PSR-12 for the house-style paths
driver-tests/tools-project/vendor/bin/phpcs --standard=phpcs-driver.xml.dist    # WPCS for the overlays
composer test                   # PHPUnit 12, tests/Unit
( cd build/packages/mysql-on-sqlite && ../../../driver-tests/tools-project/vendor/bin/phpunit --testsuite pdo )
( cd build/packages/mysql-on-sqlite && WP_SQLITE_TEST_BACKEND=turso ../../../driver-tests/tools-project/vendor/bin/phpunit --testsuite remote )
nix build .#checks.x86_64-linux.<name> -L   # what CI runs; see README "Development" for the names
nix build .#zip                 # result/wordpress-sqlite-anywhere.zip
```

## Conventions

- Conventional commits; release-please cuts releases from them (`composer.json` `version` is the
  single source of truth; the plugin header is stamped from it at build time and checked in CI).
  Dependency bumps are `chore` so they never force a release. A driver bump is
  `feat(driver): bump bundled driver to vX.Y.Z`.
- The upstream driver's test suites run against every backend through the thin subclasses in
  `driver-tests/remote/` and the options-filter seam (patch 0011). A test that a remote backend
  cannot satisfy is skipped through the skip list in `driver-tests/tools/backend-factory.php`, never
  by patching upstream's test file.
- PHPStan covers `driver/` at level 8 with the type aliases declared in `phpstan.neon.dist`
  (`SqliteParams`, `SqliteBatch`, `RemoteResult`, ...); use them in docblocks rather than spelling
  the shapes out. The one `ignoreErrors` entry is for upstream's connection class, analysed only so
  the trait it uses is.
- `SQLITE_DRIVER_VERSION` stays upstream's. The plugin version is independent semver.
- Two PHPUnit majors on purpose: 12 for `src/`, 9 for upstream's suites (they use PHPUnit 8/9 APIs),
  kept in separate composer projects.
- Secrets (Turso tokens, D1 proxy tokens) never go into the repo, a command line, or a test fixture.
