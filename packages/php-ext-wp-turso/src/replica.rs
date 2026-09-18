//! `WP_SQLite_Turso_Native_Replica`: an embedded replica inside the PHP process.
//!
//! One replica per database file per process, shared by every PHP thread and
//! every request. Opening it bootstraps the file from the primary if it is
//! empty and starts a pull loop that applies remote changes on an interval;
//! queries run against the local file through a small pool of connections.
//!
//! The replica is read-only by contract: the PHP side routes only reads here
//! and writes go to the primary, so nothing in the local file ever needs to
//! be pushed. (Turso's sync engine can push local writes, but it replays them
//! on the primary with last-writer-wins semantics -- with a second writer on
//! the primary, as WordPress always has, that would let two rows minted with
//! the same ID silently overwrite each other.)

use crate::runtime::{block_on, block_on_timeout, install_crypto_provider, runtime};
use crate::value::{params_from_php, result_to_php};
use ext_php_rs::boxed::ZBox;
use ext_php_rs::exception::PhpException;
use ext_php_rs::prelude::*;
use ext_php_rs::types::ZendHashTable;
use std::collections::HashMap;
use std::path::Path;
use std::process;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex, OnceLock};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};
use turso::Value;

/// The open replicas of this process, keyed by database path.
static REPLICAS: OnceLock<Mutex<HashMap<String, Arc<Replica>>>> = OnceLock::new();

/// How many times a read is retried when the pull loop -- or another
/// thread's on-demand `pull()` after its own write -- holds the WAL.
///
/// Raised from 5: at 5ms per attempt that was a ~75ms budget, and a burst of
/// as few as 8 genuinely concurrent requests against one embedded replica
/// (`ab -c 12`-style, or just several browser tabs) reliably exhausted it,
/// surfacing "database is locked" as a fatal, uncaught error on a plain
/// `SELECT` instead of the transient condition this loop exists to smooth
/// over. Concurrent requests are the normal case for any real WordPress
/// site, not an edge case worth a 75ms allowance.
const BUSY_RETRIES: u32 = 30;

/// The backoff step's ceiling, in milliseconds.
///
/// The delay grows with the attempt number so a single brief lock resolves
/// on the first retry or two (imperceptible: 5-10ms), but capping it keeps
/// BUSY_RETRIES attempts bounded to well under a second even in the worst
/// case, rather than growing unboundedly (5ms * 30 would be 150ms on the
/// last attempt alone, and over 2s summed).
const BUSY_RETRY_STEP_CAP_MS: u64 = 30;

/// How long a pull may take before it is abandoned.
const PULL_TIMEOUT: Duration = Duration::from_secs(60);

struct Replica {
    path: String,
    url: String,
    /// The process that opened it. A replica is never usable from a fork:
    /// the file lock and the runtime threads belong to the parent.
    pid: u32,
    db: turso::sync::Database,
    /// Idle connections. A connection runs one statement at a time, so
    /// concurrent PHP threads each take their own and give it back after.
    connections: Mutex<Vec<turso::Connection>>,
    /// Pulls are serialised, never collapsed: a pull requested after a write
    /// must observe that write, so it queues behind one already running
    /// rather than reporting that one's (older) result.
    pull_lock: tokio::sync::Mutex<()>,
    pull_interval_ms: u64,
    stats: Stats,
}

/// Everything the constructor's `options` array can carry.
struct OpenOptions {
    pull_interval_ms: u64,
    bootstrap: bool,
    client_name: String,
    open_timeout_ms: u64,
}

impl Default for OpenOptions {
    fn default() -> Self {
        Self {
            pull_interval_ms: 1000,
            bootstrap: true,
            client_name: "wp-turso".to_string(),
            open_timeout_ms: 60_000,
        }
    }
}

#[derive(Default)]
struct Stats {
    opened_unix_ms: u64,
    bootstrapped: bool,
    pulls: AtomicU64,
    pulls_with_changes: AtomicU64,
    last_pull_unix_ms: AtomicU64,
    last_pull_took_us: AtomicU64,
    pull_errors: AtomicU64,
    last_error: Mutex<Option<String>>,
}

fn unix_ms() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_millis() as u64)
        .unwrap_or(0)
}

fn replica_error(message: String) -> PhpException {
    PhpException::default(format!("wp_turso: {message}"))
}

/// Open the replica at `path`, or reuse the one this process already holds.
fn open(
    path: &str,
    url: &str,
    token: Option<&str>,
    options: &OpenOptions,
) -> Result<Arc<Replica>, String> {
    install_crypto_provider();
    let registry = REPLICAS.get_or_init(|| Mutex::new(HashMap::new()));
    let mut replicas = registry
        .lock()
        .map_err(|_| "replica registry lock is poisoned".to_string())?;

    let pid = process::id();
    if let Some(replica) = replicas.get(path) {
        if replica.pid != pid {
            return Err(format!(
                "the replica at {path} was opened by process {}; it cannot be used from a forked process {pid}",
                replica.pid
            ));
        }
        if replica.url != url {
            return Err(format!(
                "the replica at {path} is already open against {}, not {url}",
                replica.url
            ));
        }
        return Ok(Arc::clone(replica));
    }

    if let Some(parent) = Path::new(path).parent() {
        if !parent.as_os_str().is_empty() {
            std::fs::create_dir_all(parent)
                .map_err(|e| format!("cannot create {}: {e}", parent.display()))?;
        }
    }
    let bootstrapped = !Path::new(path).exists();

    let mut builder = turso::sync::Builder::new_remote(path)
        .with_remote_url(url.to_string())
        .with_client_name(options.client_name.clone())
        .bootstrap_if_empty(options.bootstrap);
    if let Some(token) = token {
        builder = builder.with_auth_token(token.to_string());
    }
    let db = block_on_timeout(
        builder.build(),
        Duration::from_millis(options.open_timeout_ms),
        "opening the replica",
    )?
    .map_err(|e| format!("could not open the replica at {path}: {e}"))?;

    let replica = Arc::new(Replica {
        path: path.to_string(),
        url: url.to_string(),
        pid,
        db,
        connections: Mutex::new(Vec::new()),
        pull_lock: tokio::sync::Mutex::new(()),
        pull_interval_ms: options.pull_interval_ms,
        stats: Stats {
            opened_unix_ms: unix_ms(),
            bootstrapped,
            ..Stats::default()
        },
    });

    if options.pull_interval_ms > 0 {
        let looped = Arc::clone(&replica);
        runtime()?.spawn(async move {
            loop {
                let _ = looped.pull().await;
                tokio::time::sleep(Duration::from_millis(looped.pull_interval_ms)).await;
            }
        });
    }

    replicas.insert(path.to_string(), Arc::clone(&replica));
    Ok(replica)
}

impl Replica {
    /// Pull remote changes into the replica once; true when any were applied.
    ///
    /// A caller that arrives while a pull is running waits for it and then
    /// pulls again, so a pull requested after a write to the primary always
    /// brings that write in.
    async fn pull(&self) -> Result<bool, String> {
        let _serialised = self.pull_lock.lock().await;
        let started = Instant::now();
        let result = match tokio::time::timeout(PULL_TIMEOUT, self.db.pull()).await {
            Ok(result) => result.map_err(|e| e.to_string()),
            Err(_) => Err(format!("timed out after {} ms", PULL_TIMEOUT.as_millis())),
        };

        self.stats.pulls.fetch_add(1, Ordering::Relaxed);
        self.stats
            .last_pull_unix_ms
            .store(unix_ms(), Ordering::Relaxed);
        self.stats
            .last_pull_took_us
            .store(started.elapsed().as_micros() as u64, Ordering::Relaxed);
        match result {
            Ok(changed) => {
                if changed {
                    self.stats
                        .pulls_with_changes
                        .fetch_add(1, Ordering::Relaxed);
                }
                if let Ok(mut last_error) = self.stats.last_error.lock() {
                    *last_error = None;
                }
                Ok(changed)
            }
            Err(e) => {
                self.stats.pull_errors.fetch_add(1, Ordering::Relaxed);
                let message = format!("pull failed: {e}");
                if let Ok(mut last_error) = self.stats.last_error.lock() {
                    *last_error = Some(message.clone());
                }
                Err(message)
            }
        }
    }

    async fn take_connection(&self) -> Result<turso::Connection, String> {
        let idle = self
            .connections
            .lock()
            .map_err(|_| "connection pool lock is poisoned".to_string())?
            .pop();
        match idle {
            Some(connection) => Ok(connection),
            None => self
                .db
                .connect()
                .await
                .map_err(|e| format!("could not connect to the replica: {e}")),
        }
    }

    fn give_back(&self, connection: turso::Connection) {
        if let Ok(mut idle) = self.connections.lock() {
            if idle.len() < 32 {
                idle.push(connection);
            }
        }
    }

    /// Run a statement and collect every row.
    async fn query(
        &self,
        sql: &str,
        params: Vec<Value>,
    ) -> Result<(Vec<String>, Vec<Vec<Value>>), String> {
        let connection = self.take_connection().await?;
        let mut attempt = 0;
        let result = loop {
            match Self::run(&connection, sql, params.clone()).await {
                Err(ref e) if attempt < BUSY_RETRIES && Self::is_lock_contention(e) => {
                    // The pull loop -- or another request's on-demand pull
                    // after its own write -- is applying changes; give it a
                    // moment. A fresh connection from the pool retries no
                    // better than the one that hit this, so the loop keeps
                    // the same one rather than round-tripping take/give_back.
                    attempt += 1;
                    let delay = (5 * u64::from(attempt)).min(BUSY_RETRY_STEP_CAP_MS);
                    tokio::time::sleep(Duration::from_millis(delay)).await;
                }
                other => break other,
            }
        };
        self.give_back(connection);
        result.map_err(|e| e.to_string())
    }

    /// Whether an error is transient lock contention worth retrying, rather
    /// than a real failure.
    ///
    /// `turso::Error::Busy`/`BusySnapshot` are the classified cases and are
    /// matched directly. The message fallback is defensive belt-and-suspenders
    /// for a pre-release dependency (`turso 0.8.0-pre.x`): the same "database
    /// is locked" wording this crate hands out for `Busy` is also SQLite's
    /// own canonical `SQLITE_BUSY` message, so a copy of it arriving wrapped
    /// in some other variant is retried the same way rather than surfaced as
    /// a fatal error the caller cannot do anything about.
    fn is_lock_contention(error: &turso::Error) -> bool {
        if matches!(error, turso::Error::Busy(_) | turso::Error::BusySnapshot(_)) {
            return true;
        }
        let message = error.to_string().to_ascii_lowercase();
        message.contains("database is locked") || message.contains("database table is locked")
    }

    async fn run(
        connection: &turso::Connection,
        sql: &str,
        params: Vec<Value>,
    ) -> turso::Result<(Vec<String>, Vec<Vec<Value>>)> {
        let mut rows = connection.query(sql, params).await?;
        let columns = rows.column_names();
        let column_count = columns.len();
        let mut collected = Vec::new();
        while let Some(row) = rows.next().await? {
            let mut values = Vec::with_capacity(column_count);
            for index in 0..column_count {
                values.push(row.get_value(index)?);
            }
            collected.push(values);
        }
        Ok((columns, collected))
    }
}

/// The embedded replica, as PHP sees it.
///
/// Constructing it opens (or reuses) the process-global replica for the path;
/// the object itself is per request and holds nothing but a handle.
#[php_class]
#[php(name = "WP_SQLite_Turso_Native_Replica")]
pub struct WpSqliteTursoNativeReplica {
    replica: Arc<Replica>,
}

#[php_impl]
#[php(change_method_case = "snake_case")]
impl WpSqliteTursoNativeReplica {
    /// Open the replica.
    ///
    /// `options` may carry `pull_interval_ms` (default 1000; 0 disables the
    /// pull loop, leaving pulls to `pull()`), `bootstrap` (default true:
    /// download the database when the file does not exist), `client_name`,
    /// and `open_timeout_ms` (default 60000, bounding the bootstrap: a big
    /// database takes longer to download; a dead sync thread would never
    /// finish, and a request must not wait on it for long).
    pub fn __construct(
        path: String,
        url: String,
        token: Option<String>,
        options: Option<&ZendHashTable>,
    ) -> PhpResult<Self> {
        let mut opts = OpenOptions::default();
        if let Some(options) = options {
            if let Some(value) = options.get("pull_interval_ms").and_then(|z| z.long()) {
                opts.pull_interval_ms = value.max(0) as u64;
            }
            if let Some(value) = options.get("bootstrap").and_then(|z| z.bool()) {
                opts.bootstrap = value;
            }
            if let Some(value) = options.get("client_name").and_then(|z| z.str()) {
                opts.client_name = value.to_string();
            }
            if let Some(value) = options.get("open_timeout_ms").and_then(|z| z.long()) {
                opts.open_timeout_ms = value.max(1) as u64;
            }
        }
        if path.is_empty() || url.is_empty() {
            return Err(replica_error(
                "the replica path and the database URL must not be empty".to_string(),
            ));
        }

        let replica = open(&path, &url, token.as_deref(), &opts).map_err(replica_error)?;
        Ok(Self { replica })
    }

    /// Run a statement against the replica.
    ///
    /// Returns `array{columns: string[], rows: array[]}` with rows as lists of
    /// positional values in their native types.
    pub fn query(
        &self,
        sql: String,
        params: Option<&ZendHashTable>,
    ) -> PhpResult<ZBox<ZendHashTable>> {
        if self.replica.pid != process::id() {
            return Err(replica_error(
                "the replica belongs to another process; it cannot be used after a fork"
                    .to_string(),
            ));
        }
        let params = params_from_php(params).map_err(replica_error)?;
        let (columns, rows) = block_on(self.replica.query(&sql, params))
            .map_err(replica_error)?
            .map_err(replica_error)?;
        result_to_php(columns, rows).map_err(replica_error)
    }

    /// Pull remote changes now; true when any were applied.
    pub fn pull(&self) -> PhpResult<bool> {
        block_on(self.replica.pull())
            .map_err(replica_error)?
            .map_err(replica_error)
    }

    /// The replica's path.
    pub fn path(&self) -> String {
        self.replica.path.clone()
    }

    /// Diagnostics: pull counts and timings, the last error, and whether
    /// this process bootstrapped the file.
    pub fn stats(&self) -> PhpResult<ZBox<ZendHashTable>> {
        let stats = &self.replica.stats;
        let mut table = ZendHashTable::new();
        let insert = |table: &mut ZendHashTable, key: &str, value: i64| {
            table
                .insert(key, value)
                .map_err(|e| replica_error(format!("failed to build the stats: {e:?}")))
        };
        insert(&mut table, "pid", i64::from(self.replica.pid))?;
        // Which registry and which replica this handle refers to, for
        // debugging a process whose threads disagree about them.
        table
            .insert(
                "registry",
                format!(
                    "{:p}",
                    REPLICAS.get().map_or(std::ptr::null(), |m| m as *const _)
                ),
            )
            .map_err(|e| replica_error(format!("failed to build the stats: {e:?}")))?;
        table
            .insert("replica", format!("{:p}", Arc::as_ptr(&self.replica)))
            .map_err(|e| replica_error(format!("failed to build the stats: {e:?}")))?;
        table
            .insert("thread", format!("{:?}", std::thread::current().id()))
            .map_err(|e| replica_error(format!("failed to build the stats: {e:?}")))?;
        insert(
            &mut table,
            "handles",
            Arc::strong_count(&self.replica) as i64,
        )?;
        insert(&mut table, "opened_unix_ms", stats.opened_unix_ms as i64)?;
        insert(
            &mut table,
            "pull_interval_ms",
            self.replica.pull_interval_ms as i64,
        )?;
        insert(
            &mut table,
            "pulls",
            stats.pulls.load(Ordering::Relaxed) as i64,
        )?;
        insert(
            &mut table,
            "pulls_with_changes",
            stats.pulls_with_changes.load(Ordering::Relaxed) as i64,
        )?;
        insert(
            &mut table,
            "pull_errors",
            stats.pull_errors.load(Ordering::Relaxed) as i64,
        )?;
        insert(
            &mut table,
            "last_pull_unix_ms",
            stats.last_pull_unix_ms.load(Ordering::Relaxed) as i64,
        )?;
        insert(
            &mut table,
            "last_pull_took_us",
            stats.last_pull_took_us.load(Ordering::Relaxed) as i64,
        )?;
        table
            .insert("bootstrapped", stats.bootstrapped)
            .map_err(|e| replica_error(format!("failed to build the stats: {e:?}")))?;
        let last_error = stats
            .last_error
            .lock()
            .map(|e| e.clone())
            .unwrap_or_else(|_| Some("stats lock is poisoned".to_string()));
        table
            .insert("last_error", last_error)
            .map_err(|e| replica_error(format!("failed to build the stats: {e:?}")))?;
        Ok(table)
    }

    /// The extension version.
    pub fn version() -> String {
        env!("CARGO_PKG_VERSION").to_string()
    }
}
