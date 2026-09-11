<?php

declare( strict_types = 1 );

/**
 * A WordPress database access class whose SQLite lives behind a network API.
 *
 * This replaces the local SQLite connection of WP_SQLite_DB with a remote one
 * (Cloudflare D1 or Turso); everything else about the MySQL-on-SQLite driver
 * applies unchanged.
 *
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */
class WP_SQLite_Remote_DB extends WP_SQLite_DB {
	/**
	 * The remote engine name: "d1" or "turso".
	 *
	 * @var string
	 */
	private string $engine;

	/**
	 * The configuration lookup: fn( string $name, mixed $default = null ): mixed.
	 *
	 * @var callable
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * The engine and configuration are set before the parent constructor
	 * runs, because the parent constructor connects.
	 *
	 * @param string   $dbname Database name.
	 * @param string   $engine The remote engine name: "d1" or "turso".
	 * @param callable $config A configuration lookup reading wp-config.php
	 *                         constants and environment variables.
	 */
	public function __construct( $dbname, string $engine, callable $config ) {
		$this->engine = $engine;
		$this->config = $config;
		parent::__construct( $dbname );
	}

	/**
	 * Connect to the remote database.
	 *
	 * @see WP_SQLite_DB::db_connect()
	 *
	 * @param  bool $allow_bail Not used.
	 * @return bool True on success, false on failure.
	 */
	public function db_connect( $allow_bail = true ) {
		if ( $this->dbh ) {
			return $this->ready;
		}

		$this->last_error = '';

		if ( null === $this->dbname || '' === $this->dbname ) {
			$this->bail(
				'The database name was not set. The SQLite driver requires a database name to be set to emulate MySQL information schema tables.',
				'db_connect_fail'
			);
			return false;
		}

		try {
			$connection = wp_sqlite_remote_create_connection( $this->engine, $this->config );

			$dbh = new WP_MySQL_On_SQLite(
				sprintf( 'mysql-on-sqlite:dbname=%s', str_replace( ';', ';;', $this->dbname ) ),
				null,
				null,
				array(
					'sqlite_connection'    => $connection,
					'transaction_fallback' => wp_sqlite_remote_transaction_fallback( $this->engine, $this->config ),
				)
			);
			$dbh->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
			$this->dbh = $dbh;
		} catch ( Throwable $e ) {
			$this->last_error = sprintf( 'Failed to connect to the %s database: %s', $this->engine, $e->getMessage() );
		}
		if ( $this->last_error ) {
			return false;
		}

		// WP_SQLite_DB::set_charset() is a no-op and wpdb's connection flag
		// is private to it; the charset was initialised by wpdb's constructor.
		$this->ready = true;
		$this->set_sql_mode();
		return true;
	}
}
