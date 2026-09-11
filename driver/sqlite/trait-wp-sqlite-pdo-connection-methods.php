<?php

declare( strict_types = 1 );

/*
 * The SQLite driver uses PDO. Enable PDO function calls:
 *   phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * The connection interface methods of the local PDO SQLite connection.
 *
 * WP_SQLite_Connection is upstream's class; this trait carries everything the
 * WP_SQLite_Connection_Interface asks of it beyond what upstream already has
 * (batches, transaction control, attributes, user-defined functions and the
 * capability report), so that the change to upstream's file is one line.
 *
 * A trait rather than a subclass because these methods use the private "$pdo"
 * property, and because upstream's compatibility tests construct
 * WP_SQLite_Connection directly.
 *
 * @access private
 */
trait WP_SQLite_PDO_Connection_Methods {
	/**
	 * Execute a batch of queries in SQLite.
	 *
	 * The queries are executed sequentially, one at a time. Batches are
	 * usually executed within a transaction managed by the caller, which
	 * is what makes the batch execution atomic.
	 *
	 * @param  SqliteBatch $statements
	 *                       The queries to execute, each being an array of
	 *                       a query string and optional query parameters.
	 * @throws PDOException  When the execution of any of the queries fails.
	 * @return list<PDOStatement> The PDO statement objects, one for each query.
	 */
	public function execute_batch( array $statements ): array {
		$results = array();
		foreach ( $statements as $statement ) {
			$results[] = $this->query( $statement[0], $statement[1] ?? array() );
		}
		return $results;
	}

	/**
	 * Begin a transaction.
	 *
	 * @param  string $behavior The SQLite transaction behavior.
	 *                          One of "DEFERRED", "IMMEDIATE", or "EXCLUSIVE".
	 * @throws PDOException             When the transaction cannot be started.
	 * @throws InvalidArgumentException When the transaction behavior is invalid.
	 */
	public function begin_transaction( string $behavior = 'DEFERRED' ): void {
		$behavior = strtoupper( $behavior );
		if ( 'DEFERRED' === $behavior ) {
			// A plain "BEGIN" is equivalent to "BEGIN DEFERRED".
			$this->query( 'BEGIN' );
		} elseif ( 'IMMEDIATE' === $behavior || 'EXCLUSIVE' === $behavior ) {
			$this->query( 'BEGIN ' . $behavior );
		} else {
			throw new InvalidArgumentException(
				sprintf( 'Invalid SQLite transaction behavior: %s.', $behavior )
			);
		}
	}

	/**
	 * Commit the current transaction.
	 *
	 * @throws PDOException When the transaction cannot be committed.
	 */
	public function commit(): void {
		$this->query( 'COMMIT' );
	}

	/**
	 * Roll back the current transaction.
	 *
	 * @throws PDOException When the transaction cannot be rolled back.
	 */
	public function rollback(): void {
		$this->query( 'ROLLBACK' );
	}

	/**
	 * Create a savepoint.
	 *
	 * @param  string $name The savepoint name, unquoted.
	 * @throws PDOException When the savepoint cannot be created.
	 */
	public function savepoint( string $name ): void {
		$this->query( 'SAVEPOINT ' . $this->quote_identifier( $name ) );
	}

	/**
	 * Release a savepoint.
	 *
	 * @param  string $name The savepoint name, unquoted.
	 * @throws PDOException When the savepoint cannot be released.
	 */
	public function release_savepoint( string $name ): void {
		$this->query( 'RELEASE SAVEPOINT ' . $this->quote_identifier( $name ) );
	}

	/**
	 * Roll back to a savepoint.
	 *
	 * @param  string $name The savepoint name, unquoted.
	 * @throws PDOException When the rollback fails.
	 */
	public function rollback_to_savepoint( string $name ): void {
		$this->query( 'ROLLBACK TO SAVEPOINT ' . $this->quote_identifier( $name ) );
	}

	/**
	 * Check if a transaction is currently active.
	 *
	 * @return bool True when a transaction is active, false otherwise.
	 */
	public function in_transaction(): bool {
		return $this->pdo->inTransaction();
	}

	/**
	 * Get the version of the SQLite engine serving this connection.
	 *
	 * @return string The SQLite engine version, e.g. "3.45.1".
	 */
	public function get_server_version(): string {
		return (string) $this->pdo->getAttribute( PDO::ATTR_SERVER_VERSION );
	}

	/**
	 * Set a connection attribute.
	 *
	 * @param  int   $attribute The attribute to set.
	 * @param  mixed $value     The value of the attribute.
	 * @return bool             True on success, false on failure.
	 */
	public function set_attribute( int $attribute, $value ): bool {
		return $this->pdo->setAttribute( $attribute, $value );
	}

	/**
	 * Get a connection attribute.
	 *
	 * @param  int   $attribute The attribute to get.
	 * @return mixed            The value of the attribute.
	 */
	public function get_attribute( int $attribute ) {
		return $this->pdo->getAttribute( $attribute );
	}

	/**
	 * Register a user-defined SQL function implemented as a PHP callback.
	 *
	 * @param  string   $name     The SQL function name.
	 * @param  callable $callback The PHP callback implementing the function.
	 * @return bool               True on success, false on failure.
	 */
	public function create_function( string $name, callable $callback ): bool {
		if ( $this->pdo instanceof PDO\SQLite ) {
			return $this->pdo->createFunction( $name, $callback );
		}
		// A PDO instance created as "new PDO( 'sqlite:...' )" rather than
		// "new PDO\SQLite( ... )" is the base class and only has the legacy method.
		// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
		return $this->pdo->sqliteCreateFunction( $name, $callback );
	}

	/**
	 * Check whether the connection supports an optional capability.
	 *
	 * A local PDO SQLite connection supports all optional capabilities.
	 *
	 * @param  string $capability One of the CAPABILITY_* interface constants.
	 * @return bool               True when the capability is supported.
	 */
	public function has_capability( string $capability ): bool {
		return in_array(
			$capability,
			array(
				self::CAPABILITY_TRANSACTIONS,
				self::CAPABILITY_SAVEPOINTS,
				self::CAPABILITY_TEMPORARY_TABLES,
				self::CAPABILITY_USER_DEFINED_FUNCTIONS,
			),
			true
		);
	}
}
