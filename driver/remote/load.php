<?php

declare( strict_types = 1 );

/**
 * The remote backends' common entry point.
 *
 * Builds a driver connection for one of the over-the-wire engines from the
 * site's configuration. The engine's own loader ("d1/load.php" or
 * "turso/load.php") must already be loaded; the plugin's drop-in does that.
 *
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */

/**
 * Create the driver connection for a remote engine.
 *
 * @param  string   $engine The engine name: "d1" or "turso".
 * @param  callable $config A configuration lookup: fn( string $name, mixed $default = null ): mixed.
 *                          It reads wp-config.php constants and environment variables.
 * @return WP_SQLite_Connection_Interface The connection.
 * @throws InvalidArgumentException When the engine is unknown or not configured.
 */
function wp_sqlite_remote_create_connection( string $engine, callable $config ): WP_SQLite_Connection_Interface {
	switch ( $engine ) {
		case 'd1':
			return wp_sqlite_remote_create_d1_connection( $config );
		case 'turso':
			return wp_sqlite_remote_create_turso_connection( $config );
		default:
			throw new InvalidArgumentException( sprintf( 'Unknown remote database engine "%s".', $engine ) );
	}
}

/**
 * The transaction fallback configured for a remote engine.
 *
 * Neither D1 nor Turso over HTTP can carry a transaction across statements,
 * so the driver has to do something with BEGIN/COMMIT: "warn" (default),
 * "error" or "ignore".
 *
 * @param  string   $engine The engine name: "d1" or "turso".
 * @param  callable $config The configuration lookup.
 * @return string           The fallback mode.
 */
function wp_sqlite_remote_transaction_fallback( string $engine, callable $config ): string {
	$name = 'd1' === $engine ? 'WP_D1_TRANSACTION_FALLBACK' : 'WP_TURSO_TRANSACTION_FALLBACK';
	return (string) $config( $name, 'warn' );
}

/**
 * Create a Cloudflare D1 connection from WP_D1_* configuration.
 *
 * @param  callable $config The configuration lookup.
 * @return WP_SQLite_Connection_Interface The connection.
 * @throws InvalidArgumentException When WP_D1_PROXY_URL is not set.
 */
function wp_sqlite_remote_create_d1_connection( callable $config ): WP_SQLite_Connection_Interface {
	$url = (string) $config( 'WP_D1_PROXY_URL', '' );
	if ( '' === $url ) {
		throw new InvalidArgumentException( 'WP_D1_PROXY_URL is not configured.' );
	}

	$token     = $config( 'WP_D1_PROXY_TOKEN' );
	$transport = wp_sqlite_d1_create_transport(
		$url,
		null === $token ? null : (string) $token,
		array(
			'timeout_ms' => (int) $config( 'WP_D1_HTTP_TIMEOUT_MS', 30000 ),
		)
	);

	return new WP_SQLite_D1_Connection(
		$transport,
		array(
			'schema_cache' => filter_var( $config( 'WP_D1_SCHEMA_CACHE', true ), FILTER_VALIDATE_BOOLEAN ),
		)
	);
}

/**
 * Create a Turso connection from WP_TURSO_* configuration.
 *
 * Three shapes, in order of preference:
 *
 *   - WP_TURSO_REPLICA set: reads come from an embedded replica the "wp_turso"
 *     extension holds open in this process, writes go to the primary. The
 *     extension must be loaded; a configured replica without it is a
 *     deployment error and fails loudly.
 *   - WP_TURSO_SNAPSHOT pointing at a readable file: reads come from that
 *     published snapshot, writes go to the primary. A configured snapshot that
 *     is not there yet means the publisher has not run: serving everything from
 *     the primary is slower but correct, which is the right way to fail.
 *   - Neither: every statement goes to the primary.
 *
 * @param  callable $config The configuration lookup.
 * @return WP_SQLite_Connection_Interface The connection.
 * @throws InvalidArgumentException When WP_TURSO_URL is not set.
 * @throws RuntimeException          When WP_TURSO_REPLICA is set without the extension.
 */
function wp_sqlite_remote_create_turso_connection( callable $config ): WP_SQLite_Connection_Interface {
	$url = (string) $config( 'WP_TURSO_URL', '' );
	if ( '' === $url ) {
		throw new InvalidArgumentException( 'WP_TURSO_URL is not configured.' );
	}

	$token   = $config( 'WP_TURSO_TOKEN' );
	$token   = null === $token ? null : (string) $token;
	$options = array(
		'timeout_ms'            => (int) $config( 'WP_TURSO_HTTP_TIMEOUT_MS', 30000 ),
		'pipeline_path'         => (string) $config( 'WP_TURSO_PIPELINE_PATH', '/v2/pipeline' ),
		'schema_cache'          => filter_var( $config( 'WP_TURSO_SCHEMA_CACHE', true ), FILTER_VALIDATE_BOOLEAN ),
		'snapshot_journal_mode' => (string) $config( 'WP_TURSO_SNAPSHOT_JOURNAL', 'DELETE' ),
	);

	$replica = $config( 'WP_TURSO_REPLICA' );
	if ( null !== $replica && '' !== $replica ) {
		if ( ! wp_sqlite_turso_has_native_replica() ) {
			throw new RuntimeException(
				'WP_TURSO_REPLICA is set, but the "wp_turso" PHP extension that holds an embedded replica is not loaded.'
			);
		}
		$options['replica_pull_ms']         = (int) $config( 'WP_TURSO_REPLICA_PULL_MS', 1000 );
		$options['replica_open_timeout_ms'] = (int) $config( 'WP_TURSO_REPLICA_OPEN_TIMEOUT_MS', 60000 );
		return wp_sqlite_turso_create_embedded_connection( $url, $token, (string) $replica, $options );
	}

	$snapshot = $config( 'WP_TURSO_SNAPSHOT' );
	$snapshot = null === $snapshot || '' === $snapshot ? null : (string) $snapshot;
	if ( null !== $snapshot && ! is_readable( $snapshot ) ) {
		$snapshot = null;
	}

	return wp_sqlite_turso_create_connection( $url, $token, $snapshot, $options );
}
