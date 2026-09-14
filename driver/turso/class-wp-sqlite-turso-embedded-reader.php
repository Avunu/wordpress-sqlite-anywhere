<?php declare(strict_types = 1);

/*
 * The connection implements a PDO-compatible API. Enable PDO class usage:
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * A read-only connection over an embedded Turso replica inside this process.
 *
 * The "wp_turso" extension holds the replica open for the life of the PHP
 * process, pulls remote changes into it on an interval, and answers queries
 * from the local file at local-SQLite speed. This class is the driver-facing
 * side of it: the reader WP_SQLite_Turso_Replica_Connection routes reads to,
 * in place of a published snapshot.
 *
 * Compared with the snapshot, there is no publisher process, no copy, and the
 * lag is the pull interval rather than the publish interval. What stays the
 * same is the contract: reads only. Writes go to the primary through the
 * replica connection's latch, never into the local file, so nothing here is
 * ever pushed. A statement that is not a read is refused rather than run,
 * because a local write would diverge the replica from the primary for good.
 *
 * One process, one replica: the extension keeps the file open under an
 * exclusive lock, which is fine for FrankenPHP's single multi-threaded process
 * and rules out a pool of forked workers sharing one path.
 */
class WP_SQLite_Turso_Embedded_Reader implements WP_SQLite_Connection_Interface {
	/**
	 * The version reported when the replica cannot answer "SELECT sqlite_version()".
	 *
	 * @var string
	 */
	const FALLBACK_SERVER_VERSION = '3.45.0';

	/**
	 * The native replica handle.
	 *
	 * @var WP_SQLite_Turso_Native_Replica
	 */
	private WP_SQLite_Turso_Native_Replica $replica;

	/**
	 * Whether fetched values are stringified (PDO::ATTR_STRINGIFY_FETCHES).
	 *
	 * Defaults to true: the driver expects string values, as PDO SQLite
	 * returns them, while the replica hands back native types.
	 *
	 * @var bool
	 */
	private bool $stringify_fetches = true;

	/**
	 * The default fetch mode for statements (PDO::ATTR_DEFAULT_FETCH_MODE).
	 *
	 * @var int
	 */
	private int $default_fetch_mode = PDO::FETCH_BOTH;

	/**
	 * The error mode (PDO::ATTR_ERRMODE). Errors are always thrown.
	 *
	 * @var int
	 */
	private int $error_mode = PDO::ERRMODE_EXCEPTION;

	/**
	 * The SQLite version of the replica, lazily fetched.
	 *
	 * @var string|null
	 */
	private ?string $server_version = null;

	/**
	 * A query logger callback.
	 *
	 * @var SqliteQueryLogger|null
	 */
	private $query_logger;

	/**
	 * Whether the end-of-request pull is already registered.
	 *
	 * @var bool
	 */
	private bool $sync_registered = false;

	/**
	 * Constructor.
	 *
	 * @param WP_SQLite_Turso_Native_Replica $replica The open replica.
	 */
	public function __construct( WP_SQLite_Turso_Native_Replica $replica ) {
		$this->replica = $replica;
	}

	/**
	 * Get the native replica handle, for diagnostics.
	 *
	 * @return WP_SQLite_Turso_Native_Replica The replica.
	 */
	public function get_replica(): WP_SQLite_Turso_Native_Replica {
		return $this->replica;
	}

	/**
	 * Bring the replica up to date with the primary before this request ends.
	 *
	 * Called once by the replica connection when a request latches to the
	 * primary, i.e. has written. Within the request, reads already go to the
	 * primary; this is about the *next* request, which will read from the
	 * replica again. WordPress leans on that pattern constantly -- a login
	 * writes a session token and redirects to a page that must find it, a
	 * saved post redirects to its editor -- and a pull interval, however short,
	 * loses that race. So the pull happens synchronously in the request's
	 * shutdown, after the write and before the response leaves the process:
	 * one round trip (~30 ms on a WAN) added to requests that wrote, none to
	 * requests that did not.
	 *
	 * This covers the next request to *this* process. A fleet of replicas
	 * behind one load balancer would need a stickiness cookie on top.
	 */
	public function sync_after_write(): void {
		if ( $this->sync_registered ) {
			return;
		}
		$this->sync_registered = true;
		$replica               = $this->replica;
		register_shutdown_function(
			static function () use ( $replica ): void {
				try {
					$replica->pull();
				} catch ( Exception $e ) {
					// The pull loop will catch up; nothing to do here.
					unset( $e );
				}
			}
		);
	}

	/**
	 * Execute a read in the replica.
	 *
	 * @param  string       $sql    The query to execute.
	 * @param  SqliteParams $params The query parameters.
	 * @throws WP_SQLite_Turso_Exception When the statement is not a read, or fails.
	 * @return PDOStatement The PDO statement object.
	 */
	public function query( string $sql, array $params = array() ): PDOStatement {
		if ( $this->query_logger ) {
			( $this->query_logger )( $sql, $params );
		}
		if ( ! WP_SQLite_Turso_Replica_Connection::is_read_only( $sql ) ) {
			throw WP_SQLite_Turso_Exception::from_transport_failure(
				'The embedded replica is read-only; writes go to the primary.'
			);
		}

		try {
			$result = $this->replica->query( $sql, array_values( $params ) );
		} catch ( WP_SQLite_Turso_Exception $e ) {
			throw $e;
		} catch ( Exception $e ) {
			throw WP_SQLite_Turso_Exception::from_server_error( null, self::strip_prefix( $e->getMessage() ) );
		}

		return new WP_PDO_Array_Statement(
			$result['columns'],
			$result['rows'],
			0,
			$this->stringify_fetches,
			array(),
			$this->default_fetch_mode
		);
	}

	/**
	 * Batches are writes; the replica takes none.
	 *
	 * @param  SqliteBatch $statements The queries.
	 * @throws WP_SQLite_Turso_Exception Always.
	 * @return list<PDOStatement> Never returns.
	 */
	public function execute_batch( array $statements ): array {
		throw self::read_only();
	}

	/**
	 * Begin a transaction. Not supported: the replica is read-only.
	 *
	 * @param  string $behavior The SQLite transaction behavior.
	 * @throws WP_SQLite_Turso_Exception Always.
	 */
	public function begin_transaction( string $behavior = 'DEFERRED' ): void {
		throw self::read_only();
	}

	/**
	 * Commit the current transaction. Not supported.
	 *
	 * @throws WP_SQLite_Turso_Exception Always.
	 */
	public function commit(): void {
		throw self::read_only();
	}

	/**
	 * Roll back the current transaction. Not supported.
	 *
	 * @throws WP_SQLite_Turso_Exception Always.
	 */
	public function rollback(): void {
		throw self::read_only();
	}

	/**
	 * Create a savepoint. Not supported.
	 *
	 * @param  string $name The savepoint name.
	 * @throws WP_SQLite_Turso_Exception Always.
	 */
	public function savepoint( string $name ): void {
		throw self::read_only();
	}

	/**
	 * Release a savepoint. Not supported.
	 *
	 * @param  string $name The savepoint name.
	 * @throws WP_SQLite_Turso_Exception Always.
	 */
	public function release_savepoint( string $name ): void {
		throw self::read_only();
	}

	/**
	 * Roll back to a savepoint. Not supported.
	 *
	 * @param  string $name The savepoint name.
	 * @throws WP_SQLite_Turso_Exception Always.
	 */
	public function rollback_to_savepoint( string $name ): void {
		throw self::read_only();
	}

	/**
	 * Check if a transaction is active. Never, on a read-only replica.
	 *
	 * @return bool Always false.
	 */
	public function in_transaction(): bool {
		return false;
	}

	/**
	 * Quote a value for use in an SQLite query.
	 *
	 * @param  mixed $value The value to quote.
	 * @param  int   $type  The PDO parameter type hint (unused).
	 * @return string       The quoted value.
	 */
	public function quote( $value, int $type = PDO::PARAM_STR ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		$value = is_scalar( $value ) ? (string) $value : '';
		if ( false !== strpos( $value, "\0" ) ) {
			// SQL string literals can't contain null characters.
			return sprintf( "CAST(x'%s' AS TEXT)", bin2hex( $value ) );
		}
		return "'" . str_replace( "'", "''", $value ) . "'";
	}

	/**
	 * Quote an SQLite identifier.
	 *
	 * @param  string $unquoted_identifier The unquoted identifier value.
	 * @return string                      The quoted identifier value.
	 */
	public function quote_identifier( string $unquoted_identifier ): string {
		return '`' . str_replace( '`', '``', $unquoted_identifier ) . '`';
	}

	/**
	 * The ID of the last inserted row. The replica inserts nothing.
	 *
	 * @return string Always "0".
	 */
	public function get_last_insert_id(): string {
		return '0';
	}

	/**
	 * Get the SQLite version of the replica.
	 *
	 * @return string The SQLite engine version, e.g. "3.45.0".
	 */
	public function get_server_version(): string {
		if ( null === $this->server_version ) {
			try {
				$result               = $this->replica->query( 'SELECT sqlite_version()', array() );
				$value                = $result['rows'][0][0] ?? '';
				$this->server_version = is_scalar( $value ) ? (string) $value : '';
			} catch ( Exception $e ) {
				$this->server_version = '';
			}
			if ( '' === $this->server_version ) {
				$this->server_version = self::FALLBACK_SERVER_VERSION;
			}
		}
		return $this->server_version;
	}

	/**
	 * Set a connection attribute.
	 *
	 * @param  int   $attribute The attribute to set.
	 * @param  mixed $value     The value of the attribute.
	 * @return bool             True on success, false on failure.
	 */
	public function set_attribute( int $attribute, $value ): bool {
		if ( PDO::ATTR_STRINGIFY_FETCHES === $attribute ) {
			$this->stringify_fetches = (bool) $value;
			return true;
		}
		if ( PDO::ATTR_DEFAULT_FETCH_MODE === $attribute ) {
			$this->default_fetch_mode = (int) $value;
			return true;
		}
		if ( PDO::ATTR_ERRMODE === $attribute ) {
			if (
				! in_array(
					$value,
					array( PDO::ERRMODE_SILENT, PDO::ERRMODE_WARNING, PDO::ERRMODE_EXCEPTION ),
					true
				)
			) {
				return false;
			}
			$this->error_mode = $value;
			return true;
		}
		return false;
	}

	/**
	 * Get a connection attribute.
	 *
	 * @param  int $attribute The attribute to get.
	 * @return mixed          The value of the attribute, or null when unknown.
	 */
	public function get_attribute( int $attribute ) {
		if ( PDO::ATTR_STRINGIFY_FETCHES === $attribute ) {
			return $this->stringify_fetches;
		}
		if ( PDO::ATTR_DEFAULT_FETCH_MODE === $attribute ) {
			return $this->default_fetch_mode;
		}
		if ( PDO::ATTR_SERVER_VERSION === $attribute ) {
			return $this->get_server_version();
		}
		if ( PDO::ATTR_ERRMODE === $attribute ) {
			return $this->error_mode;
		}
		return null;
	}

	/**
	 * Register a user-defined SQL function. Not supported: PHP callbacks
	 * cannot run inside the replica's engine.
	 *
	 * @param  string   $name     The SQL function name.
	 * @param  callable $callback The PHP callback implementing the function.
	 * @return bool               Always false.
	 */
	public function create_function( string $name, callable $callback ): bool {
		return false;
	}

	/**
	 * Check whether the connection supports an optional capability.
	 *
	 * A read-only replica supports none: transactions and savepoints belong
	 * to the primary, temporary tables would be local writes, and there are
	 * no user-defined functions.
	 *
	 * @param  string $capability One of the CAPABILITY_* interface constants.
	 * @return bool               Always false.
	 */
	public function has_capability( string $capability ): bool {
		return false;
	}

	/**
	 * Set a logger for the queries.
	 *
	 * @param SqliteQueryLogger $logger A query logger callback.
	 */
	public function set_query_logger( callable $logger ): void {
		$this->query_logger = $logger;
	}

	/**
	 * The exception for a write reaching the replica.
	 *
	 * @return WP_SQLite_Turso_Exception The exception.
	 */
	private static function read_only(): WP_SQLite_Turso_Exception {
		return WP_SQLite_Turso_Exception::from_transport_failure(
			'The embedded replica is read-only; writes go to the primary.'
		);
	}

	/**
	 * Strip the extension's message prefix.
	 *
	 * @param  string $message The exception message.
	 * @return string          The message without the "wp_turso: " prefix.
	 */
	private static function strip_prefix( string $message ): string {
		return (string) preg_replace( '/^wp_turso: /', '', $message );
	}
}
