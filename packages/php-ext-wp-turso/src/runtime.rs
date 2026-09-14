//! The process-global Tokio runtime the replica's async API is driven on.

use std::future::Future;
use std::sync::OnceLock;
use std::time::Duration;

static RUNTIME: OnceLock<tokio::runtime::Runtime> = OnceLock::new();
static CRYPTO: OnceLock<()> = OnceLock::new();

/// Pick rustls's crypto backend once for the whole process.
///
/// reqwest and turso's sync engine both use rustls, and between them both
/// the `ring` and `aws-lc-rs` backends end up compiled in; rustls then
/// panics on its first handshake unless a process-level provider was
/// installed. `ring` is what turso builds against.
pub fn install_crypto_provider() {
    CRYPTO.get_or_init(|| {
        let _ = rustls::crypto::ring::default_provider().install_default();
    });
}

/// Get the runtime, creating it on first use in this process.
///
/// PHP threads call into it with [`block_on`]; the replica's pull loop runs
/// on its worker threads. Two workers are plenty: the work is I/O waiting.
pub fn runtime() -> Result<&'static tokio::runtime::Runtime, String> {
    if let Some(runtime) = RUNTIME.get() {
        return Ok(runtime);
    }
    let runtime = tokio::runtime::Builder::new_multi_thread()
        .worker_threads(2)
        .thread_name("wp-turso")
        .enable_all()
        .build()
        .map_err(|e| format!("failed to start the async runtime: {e}"))?;
    Ok(RUNTIME.get_or_init(|| runtime))
}

/// Run a future to completion from a PHP thread.
pub fn block_on<F: Future>(future: F) -> Result<F::Output, String> {
    Ok(runtime()?.block_on(future))
}

/// Run a future to completion from a PHP thread, giving up after `timeout`.
///
/// The sync engine drives its network I/O on its own thread; should that
/// thread die, a future waiting on it would never resolve, and a PHP request
/// must not hang on it.
pub fn block_on_timeout<F: Future>(
    future: F,
    timeout: Duration,
    what: &str,
) -> Result<F::Output, String> {
    // The timeout's timer must be created inside the runtime context.
    runtime()?
        .block_on(async { tokio::time::timeout(timeout, future).await })
        .map_err(|_| format!("{what} did not complete within {} ms", timeout.as_millis()))
}
