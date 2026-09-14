//! wp_turso: the native pieces of the MySQL-on-SQLite driver's Turso backend.
//!
//! Two PHP classes are pre-declared; the driver's Turso backend uses each when
//! the extension is loaded (see `driver/turso/load.php`):
//!
//! * `WP_SQLite_Turso_Native_Client` -- a pooled HTTP client for the
//!   SQL-over-HTTP pipeline. PHP userland cannot keep a connection alive past
//!   the request that opened it, so every request paid a fresh TCP + TLS
//!   handshake to the primary; the pool here persists across requests.
//! * `WP_SQLite_Turso_Native_Replica` -- an embedded replica held open by the
//!   PHP process itself. The driver reads from it directly, at local-SQLite
//!   speed and without a publisher process or a snapshot lag, while writes
//!   keep going to the primary over the wire. A background task pulls remote
//!   changes into the replica on an interval.
//!
//! # Lifecycle and safety
//!
//! Everything process-global -- the HTTP pool, the Tokio runtime, the open
//! replicas -- is created lazily on first use, never at module startup: a
//! process manager that forks after MINIT would hand children threads and
//! file locks that do not survive a fork. Each global records the process ID
//! that created it; the pool is rebuilt in a forked child, and a replica
//! refuses to be used from one (an embedded replica is a single-process
//! affair, which is why this backend targets FrankenPHP's one-process,
//! many-threads model).
//!
//! Under ZTS the globals are shared by every PHP thread. The types involved
//! are `Send + Sync`: `reqwest::blocking::Client`, and turso's `Database` and
//! `Connection` (asserted in the turso crate itself).

mod http;
mod replica;
mod runtime;
mod value;

use ext_php_rs::prelude::*;

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .class::<http::WpSqliteTursoNativeClient>()
        .class::<replica::WpSqliteTursoNativeReplica>()
}
