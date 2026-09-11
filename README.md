# WordPress SQLite Anywhere

WordPress on SQLite — on a local file, on [Turso](https://turso.tech), or on [Cloudflare D1](https://developers.cloudflare.com/d1/) over the wire. One plugin, one `wp-content/db.php` drop-in; the engine is chosen by `DB_ENGINE`.

It bundles the [SQLite Database Integration](https://github.com/WordPress/sqlite-database-integration) driver (the MySQL-on-SQLite translation layer WordPress Playground runs on), pinned to a release tag as a git submodule, and adds the two network backends on top of it. The driver's own local-SQLite path is used byte for byte; the remote paths replace only the connection.

| Engine | `DB_ENGINE` | Where the data lives | Best for |
| --- | --- | --- | --- |
| Local SQLite | `sqlite` (default) | `wp-content/database/.ht.sqlite` | single-host sites, Playground |
| Turso | `turso` | a Turso / libSQL primary, reads optionally served from a published local snapshot | multi-region front ends with a single writable primary |
| Cloudflare D1 | `d1` | a D1 database behind the bundled proxy worker | WordPress in Cloudflare Containers |

* * *

## Requirements

-   PHP 8.5+ with `pdo` and `pdo_sqlite` (`curl` for the remote engines)
-   WordPress 6.6+
-   For development: [Nix](https://nixos.org) (everything else comes from the flake)

* * *

## Installation

### Recommended: install the release zip

1.  Download `wordpress-sqlite-anywhere.zip` from the [latest GitHub Release](https://github.com/Avunu/wordpress-sqlite-anywhere/releases/latest)
2.  In WordPress, go to **Plugins → Add New → Upload Plugin** and upload the zip
3.  Activate it. Activation writes `wp-content/db.php` from the plugin's `db.copy`; the plugin's admin page (**Settings → SQLite integration**) shows the drop-in's state and refreshes it when the plugin updates.

Updates are delivered straight from GitHub Releases through [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) — no wordpress.org listing.

### From source (development)

```sh
git clone --recurse-submodules https://github.com/Avunu/wordpress-sqlite-anywhere
cd wordpress-sqlite-anywhere
nix develop          # or `direnv allow`
composer install
composer assemble    # upstream driver + patches + overlays → build/
```

`nix build` produces the installable plugin under `result/share/wordpress/plugins/wordpress-sqlite-anywhere`; `nix build .#zip` produces the release zip.

* * *

## Configuration

Everything is read from `wp-config.php` constants first and environment variables second, so a container can be configured entirely from its environment.

```php
define('DB_ENGINE', 'sqlite');   // "sqlite" (default), "turso", "d1"; anything else leaves WordPress on MySQL
```

Without `DB_ENGINE`, a configured `WP_TURSO_URL` selects Turso and a configured `WP_D1_PROXY_URL` selects D1.

### Local SQLite

```php
define('DB_DIR',  '/path/to/database/');   // default: wp-content/database/
define('DB_FILE', '.ht.sqlite');
```

### Turso

```php
define('WP_TURSO_URL',   'libsql://db-org.turso.io');   // required; "turso://" and "https://" are accepted
define('WP_TURSO_TOKEN', '...');                        // bearer token, if the server needs one
define('WP_TURSO_SNAPSHOT', '/var/lib/wordpress/snapshot.db');
define('WP_TURSO_SNAPSHOT_JOURNAL', 'DELETE');
define('WP_TURSO_SCHEMA_CACHE', true);
define('WP_TURSO_HTTP_TIMEOUT_MS', 30000);
define('WP_TURSO_PIPELINE_PATH', '/v2/pipeline');
define('WP_TURSO_TRANSACTION_FALLBACK', 'warn');   // "warn", "error" or "ignore"
```

Two shapes, chosen by whether a snapshot is configured:

-   **Without `WP_TURSO_SNAPSHOT`**, every statement goes to the primary over Turso's SQL-over-HTTP API. This is the control plane — wp-admin, cron, deployment tooling — which must read its own writes immediately.
-   **With `WP_TURSO_SNAPSHOT`**, reads come from that local SQLite file and writes go to the primary; the first write latches the rest of the request to the primary so it reads its own writes. This is the public front end: a page render never touches the network.

The snapshot is published by [`turso-snapshot-publisher`](packages/turso-snapshot-publisher/), a small Rust daemon that keeps a private embedded replica and periodically hands over a consistent standalone copy (`pull → VACUUM INTO → rename`). PHP cannot read a live Turso replica — Turso holds an exclusive lock on it and coordinates its WAL through a file SQLite does not know about — which is why the copy exists. The front end is stale by at most one publish interval.

### Cloudflare D1

```php
define('WP_D1_PROXY_URL',   'http://d1.internal');   // required: the D1 proxy worker
define('WP_D1_PROXY_TOKEN', '...');                  // bearer token of a standalone proxy
define('WP_D1_SCHEMA_CACHE', true);
define('WP_D1_HTTP_TIMEOUT_MS', 30000);
define('WP_D1_TRANSACTION_FALLBACK', 'warn');
```

D1 is reached through [`d1-proxy-worker`](packages/d1-proxy-worker/), either as the outbound handler of a Cloudflare Container or as a standalone Worker. An optional native transport, [`php-ext-wp-d1-client`](packages/php-ext-wp-d1-client/), keeps an HTTP connection pool across PHP requests.

### Transactions on the remote engines

Neither D1 nor Turso over HTTP can hold a transaction open across statements. The driver batches what it can (schema changes, multi-statement writes) and otherwise treats `BEGIN`/`COMMIT` according to `WP_*_TRANSACTION_FALLBACK`: `warn` (log and continue), `error` (throw), or `ignore`.

* * *

## Repository layout

```
upstream/         git submodule: WordPress/sqlite-database-integration @ a release tag
patches/          numbered patches to upstream's driver (the extension points the remote backends need)
driver/           our additive driver code, overlaid onto upstream's driver source
driver-tests/     our additive driver tests + tooling, overlaid onto upstream's test tree
plugin/           our additions to upstream's plugin directory
src/              SqliteAnywhere\ — the drop-in's engine selection (house style, PHPStan level 8)
packages/         turso-snapshot-publisher (Rust), d1-proxy-worker (TypeScript), php-ext-wp-d1-client (Rust)
bin/assemble.sh   upstream + patches + overlays → build/ (no git, no PHP: it runs inside the Nix sandbox)
```

Two code styles live here on purpose. `src/`, the main plugin file and `tests/` are house style (`strict_types`, `final`, PSR-12, camelCase). `driver/`, `driver-tests/` and `plugin/` implement upstream's interfaces in upstream's namespace-less world, so they follow the WordPress coding standard upstream uses — with complete types and PHPStan level 8 all the same. Upstream's own code is never linted or typed here, only patched minimally.

### Tracking upstream

`upstream/` is pinned to a tag, never a branch. The [upstream-bump workflow](.github/workflows/upstream-bump.yml) checks weekly for a newer release, moves the pin, assembles, and opens a `feat(driver): bump bundled driver to vX.Y.Z` PR when the patch series still applies — or an issue with the rejected hunks when it does not. To resolve one by hand:

```sh
bin/patches-checkout.sh   # `patched` branch in upstream/ with the series applied via git am --3way
# resolve conflicts, commit
bin/patches-export.sh     # regenerate patches/*.patch, detach upstream/ back to the tag
```

* * *

## Development

```sh
nix develop                                   # PHP 8.5, composer, phpstan, phpcs, cargo, node, tursodb
composer assemble                             # build/ — the patched, overlaid driver and plugin
composer phpstan                              # level 8 over src/, driver/, plugin/, tests/
composer phpcs                                # PSR-12 over the house-style paths
driver-tests/tools-project/vendor/bin/phpcs --standard=phpcs-driver.xml.dist   # WPCS over driver/
composer test                                 # the plugin's own suite (PHPUnit 12)

# the bundled driver's suites (PHPUnit 9, upstream's), against each backend
cd build/packages/mysql-on-sqlite
../../../driver-tests/tools-project/vendor/bin/phpunit --testsuite pdo
WP_SQLITE_TEST_BACKEND=d1    ../../../driver-tests/tools-project/vendor/bin/phpunit --testsuite remote
WP_SQLITE_TEST_BACKEND=turso ../../../driver-tests/tools-project/vendor/bin/phpunit --testsuite remote
```

Every check also exists as a flake check, which is what CI runs: `nix build .#checks.x86_64-linux.<name> -L` for `assembled`, `plugin-header`, `phpstan`, `phpcs`, `phpcs-driver`, `phpunit`, `driver-pdo`, `driver-d1`, `driver-turso`, `publisher`, `d1-client`, `worker` and `pre-commit`. `nix flake check` runs the sandbox-safe subset; the pre-push git hooks run PHPStan, PHPCS and the Nix linters locally.

* * *

## Releasing

Releases are fully automated from [Conventional Commits](https://www.conventionalcommits.org/) via [Release Please](https://github.com/googleapis/release-please). There is no manual version bump — just write conventional commit messages:

-   `fix: ...` → patch release (0.0.x)
-   `feat: ...` → minor release (0.x.0)
-   `feat!: ...` or a `BREAKING CHANGE:` footer → major release (x.0.0)
-   `chore: ...`, `docs: ...`, `refactor: ...` → no release on their own

On every push to `main`, the [Release workflow](.github/workflows/release.yml) opens (or updates) a **release PR** that accumulates the pending changes and previews the next version + changelog. Merging that PR:

1.  bumps the version in `composer.json` (and the `wordpress-sqlite-anywhere.php` header) and updates `CHANGELOG.md`;
2.  creates the git tag and a GitHub Release with notes generated from the commits;
3.  builds the plugin on a Nix runner (`nix build .#zip`) and attaches `wordpress-sqlite-anywhere.zip` (with `vendor/` and the assembled driver bundled) as the release asset.

Client sites then pick up the new version automatically via [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker).

The version in the `wordpress-sqlite-anywhere.php` plugin header is stamped from `composer.json` at build time, so `composer.json` is the single source of truth; the `plugin-header` check keeps the committed header in step, because plugin-update-checker reads it from the tag. The bundled driver's own `SQLITE_DRIVER_VERSION` is upstream's and is never changed here.

> **Repo setting:** Settings → Actions → General → Workflow permissions must allow "Read and write permissions" and "Allow GitHub Actions to create and approve pull requests" so Release Please can open the release PR.

## License

GPL-2.0-or-later, inherited from the bundled SQLite Database Integration driver.
