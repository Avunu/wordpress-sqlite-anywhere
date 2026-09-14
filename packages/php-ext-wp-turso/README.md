# wp_turso

The native pieces of the MySQL-on-SQLite driver's Turso backend, as a PHP
extension written with ext-php-rs. It pre-declares two classes; the driver's
`turso/load.php` uses each when the extension is loaded and falls back to pure
PHP when it is not.

## `WP_SQLite_Turso_Native_Client`

A pooled HTTP client for Turso's SQL-over-HTTP pipeline. PHP userland cannot
keep a connection past the request that opened it, so the cURL transport pays
a TCP and TLS handshake to the primary on every request; this pool persists
across requests. `WP_SQLite_Turso_Native_Transport` extends it and reuses the
same protocol trait as the cURL transport — the JSON is still composed and
decoded in PHP, and only opaque strings cross the boundary.

## `WP_SQLite_Turso_Native_Replica`

An embedded Turso replica held open by the PHP process. The driver's
`WP_SQLite_Turso_Embedded_Reader` reads from it; `WP_SQLite_Turso_Replica_Connection`
routes reads there and writes to the primary, as it does with a snapshot.

- One replica per database path per process, shared by every thread and
  request; the object PHP holds is a handle. Opening bootstraps the file from
  the primary when it does not exist.
- A background task on the extension's Tokio runtime pulls the primary's
  changes into the replica every `pull_interval_ms`. Pulls are serialised, so
  `pull()` called after a write always brings that write in — the reader calls
  it in the shutdown of any request that wrote.
- Queries run through a small pool of connections; a read that lands while a
  pull holds the WAL retries briefly.
- It is read-only by contract. The sync engine could push local writes, but
  it replays them on the primary with last-writer-wins semantics, and with a
  second writer on the primary that lets two rows minted with the same ID
  overwrite each other. Writes go to the primary, full stop.

The replica file is held under an exclusive lock: one process per file. That
suits FrankenPHP (one process, many threads) and rules out sharing a path
between PHP-FPM workers. A forked child refuses to use a replica the parent
opened.

## Building

```sh
nix build .#turso-ext                       # against the flake's PHP 8.5
nix develop --command cargo build --release # in packages/php-ext-wp-turso
```

The extension is built against the exact PHP that loads it (ZTS for
FrankenPHP), which is what `lib.mkTursoExtension { pkgs, php }` is for.

## Measured

Against a Turso Cloud primary ~30 ms away, PHP's built-in server, medians of 5:

| page | all-primary, cURL | all-primary, pooled client | embedded replica |
|---|---|---|---|
| front page | 1042 ms | 862 ms | 32 ms |
| wp-admin dashboard | 1478 ms | 1312 ms | 39 ms |
| posts list | 1489 ms | 1367 ms | 44 ms |

Point reads from the replica take ~12 µs; a pull is one round trip (~27 ms);
a warm open is ~8 ms and a bootstrap of a small site ~25 s.
