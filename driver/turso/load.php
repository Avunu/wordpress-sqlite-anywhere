<?php

/**
 * Load the Turso connection backend for the MySQL-on-SQLite driver.
 *
 * This loader is not part of the default driver loading. It is required
 * explicitly by applications using the Turso backend, after (or instead of)
 * the main "src/load.php" loader.
 */
require_once __DIR__ . '/../load.php';

require_once __DIR__ . '/interface-wp-sqlite-turso-transport.php';
require_once __DIR__ . '/class-wp-sqlite-turso-exception.php';
require_once __DIR__ . '/class-wp-sqlite-turso-response.php';
require_once __DIR__ . '/trait-wp-sqlite-turso-protocol.php';
require_once __DIR__ . '/class-wp-sqlite-turso-http-transport.php';
require_once __DIR__ . '/class-wp-sqlite-turso-connection.php';
require_once __DIR__ . '/class-wp-sqlite-turso-replica-connection.php';

/*
 * The "wp_turso" extension (packages/php-ext-wp-turso) pre-declares two
 * classes when loaded: WP_SQLite_Turso_Native_Client, a pooled HTTP client
 * that outlives PHP requests, and WP_SQLite_Turso_Native_Replica, an embedded
 * replica the process holds open. Each has a driver-side counterpart here.
 */
if ( class_exists( 'WP_SQLite_Turso_Native_Client', false ) ) {
	require_once __DIR__ . '/class-wp-sqlite-turso-native-transport.php';
}
if ( class_exists( 'WP_SQLite_Turso_Native_Replica', false ) ) {
	require_once __DIR__ . '/class-wp-sqlite-turso-embedded-reader.php';
}

/**
 * Whether the native extension's embedded replica is available.
 *
 * @return bool True when the "wp_turso" extension is loaded.
 */
function wp_sqlite_turso_has_native_replica(): bool {
	return class_exists( 'WP_SQLite_Turso_Native_Replica', false );
}

/**
 * Create the best available Turso transport.
 *
 * The native client is preferred when its extension is loaded: its HTTP
 * connection pool persists across PHP requests, where a cURL handle dies
 * with the request that opened it.
 *
 * @param  string               $url     The base URL of the Turso database.
 * @param  string|null          $token   Optional. A bearer token.
 * @param  array<string, mixed> $options Optional. Transport options.
 * @return WP_SQLite_Turso_Transport_Interface The transport.
 */
function wp_sqlite_turso_create_transport( string $url, ?string $token = null, array $options = array() ): WP_SQLite_Turso_Transport_Interface {
	if ( class_exists( 'WP_SQLite_Turso_Native_Transport', false ) ) {
		return new WP_SQLite_Turso_Native_Transport( $url, $token, $options );
	}
	return new WP_SQLite_Turso_HTTP_Transport( $url, $token, $options );
}

/**
 * Create a connection that reads from an embedded replica and writes to the primary.
 *
 * The "wp_turso" extension holds the replica open in this process and pulls
 * remote changes into it on an interval; reads never touch the network, and
 * the first write latches the request to the primary so it reads its own
 * writes. No publisher process and no snapshot copy are involved.
 *
 * @param  string               $url          The base URL of the Turso database.
 * @param  string|null          $token        Optional. A bearer token.
 * @param  string               $replica_path Where the replica lives on disk.
 * @param  array<string, mixed> $options      Optional. Transport, connection and
 *                                            replica options ("replica_pull_ms",
 *                                            "replica_open_timeout_ms").
 * @return WP_SQLite_Turso_Replica_Connection The connection.
 * @throws RuntimeException When the extension is not loaded.
 */
function wp_sqlite_turso_create_embedded_connection(
	string $url,
	?string $token,
	string $replica_path,
	array $options = array()
): WP_SQLite_Turso_Replica_Connection {
	if ( ! wp_sqlite_turso_has_native_replica() ) {
		throw new RuntimeException(
			'An embedded Turso replica needs the "wp_turso" PHP extension, which is not loaded.'
		);
	}

	$primary = new WP_SQLite_Turso_Connection(
		wp_sqlite_turso_create_transport( $url, $token, $options ),
		$options
	);

	$replica_options = array(
		'client_name' => 'wp-turso',
	);
	if ( isset( $options['replica_pull_ms'] ) ) {
		$replica_options['pull_interval_ms'] = (int) $options['replica_pull_ms'];
	}
	if ( isset( $options['replica_open_timeout_ms'] ) ) {
		$replica_options['open_timeout_ms'] = (int) $options['replica_open_timeout_ms'];
	}

	$replica = new WP_SQLite_Turso_Native_Replica(
		$replica_path,
		WP_SQLite_Turso_HTTP_Transport::normalize_url( $url ),
		$token,
		$replica_options
	);

	return new WP_SQLite_Turso_Replica_Connection( new WP_SQLite_Turso_Embedded_Reader( $replica ), $primary );
}

/**
 * Create a Turso connection for the driver.
 *
 * With a snapshot path, the result reads from that file and writes to the
 * primary, latching to the primary after the first write. Without one, every
 * statement goes to the primary.
 *
 * @param  string      $url           The base URL of the Turso database.
 * @param  string|null $token         Optional. A bearer token.
 * @param  string|null $snapshot_path Optional. Path to a published snapshot.
 * @param  array<string, mixed> $options Optional. Transport and connection options.
 * @return WP_SQLite_Connection_Interface The connection.
 */
function wp_sqlite_turso_create_connection(
	string $url,
	?string $token = null,
	?string $snapshot_path = null,
	array $options = array()
): WP_SQLite_Connection_Interface {
	$primary = new WP_SQLite_Turso_Connection(
		wp_sqlite_turso_create_transport( $url, $token, $options ),
		$options
	);

	if ( null === $snapshot_path || '' === $snapshot_path ) {
		return $primary;
	}

	/*
	 * The snapshot is opened read-only. Verified against a real front end: with
	 * the file chmod 444 every public path still renders, because the driver
	 * only writes while changing the schema and the front end does not.
	 *
	 * The publisher is expected to hand over a rollback-journal database. A
	 * WAL-mode file would make SQLite create "-wal"/"-shm" beside it, and those
	 * are keyed by path rather than inode, so they would outlive the publisher's
	 * rename and describe the previous snapshot.
	 */
	$reader = new WP_SQLite_Connection(
		array(
			'path'         => $snapshot_path,
			// Match what the publisher wrote. WP_SQLite_Connection *sets* this
			// pragma, and setting WAL on a read-only rollback-journal file is a
			// write it cannot make.
			'journal_mode' => $options['snapshot_journal_mode'] ?? 'DELETE',
		)
	);

	return new WP_SQLite_Turso_Replica_Connection( $reader, $primary );
}
