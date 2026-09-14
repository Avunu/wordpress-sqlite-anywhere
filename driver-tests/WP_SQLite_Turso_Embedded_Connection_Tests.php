<?php

require_once __DIR__ . '/../src/turso/load.php';
require_once __DIR__ . '/tools/class-wp-sqlite-connection-conformance-test-case.php';

/**
 * Tests for the embedded-replica connection: reads from a replica the
 * "wp_turso" extension holds open in this process, writes to the primary.
 *
 * These need the extension and a sync server to talk to, so they run only
 * when WP_SQLITE_TEST_TURSO_SYNC_URL is set (the Nix check starts a local
 * "tursodb --sync-server" for them). Everything else in the driver's suites
 * covers this connection's primary side through the fake transport.
 *
 * phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO
 */
class WP_SQLite_Turso_Embedded_Connection_Tests extends WP_SQLite_Connection_Conformance_Test_Case {
	/**
	 * The sync server URL, from the environment.
	 *
	 * @var string
	 */
	private static $url = '';

	/**
	 * Where this process keeps its replica.
	 *
	 * @var string
	 */
	private static $replica_path = '';

	public static function setUpBeforeClass(): void {
		$url = getenv( 'WP_SQLITE_TEST_TURSO_SYNC_URL' );
		if ( false === $url || '' === $url ) {
			self::markTestSkipped( 'Set WP_SQLITE_TEST_TURSO_SYNC_URL to a Turso sync server to run these tests.' );
		}
		if ( ! wp_sqlite_turso_has_native_replica() ) {
			self::markTestSkipped( 'The wp_turso extension is not loaded.' );
		}
		self::$url          = $url;
		self::$replica_path = sys_get_temp_dir() . '/wp-turso-tests-' . getmypid() . '/replica.db';
	}

	public static function tearDownAfterClass(): void {
		$dir = dirname( self::$replica_path );
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				@unlink( $file );
			}
			@rmdir( $dir );
		}
	}

	/**
	 * A fresh connection over an empty "t": the conformance tests each create
	 * it, and the primary persists across them.
	 */
	protected function create_connection(): WP_SQLite_Connection_Interface {
		$connection = $this->create_embedded_connection();
		// Through the primary, and the replica is told about it right away.
		$connection->query( 'DROP TABLE IF EXISTS t' );
		$this->reader( $connection )->get_replica()->pull();
		return $this->create_embedded_connection();
	}

	protected function expected_capabilities(): array {
		// The primary governs, and Turso over HTTP has none of them.
		return array();
	}

	private function create_embedded_connection(): WP_SQLite_Turso_Replica_Connection {
		$connection = wp_sqlite_turso_create_embedded_connection(
			self::$url,
			null,
			self::$replica_path,
			array(
				'timeout_ms'      => 10000,
				// Pulls are explicit in these tests, so their results are exact.
				'replica_pull_ms' => 0,
			)
		);
		$connection->set_attribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		return $connection;
	}

	private function reader( WP_SQLite_Turso_Replica_Connection $connection ): WP_SQLite_Turso_Embedded_Reader {
		$property = new ReflectionProperty( WP_SQLite_Turso_Replica_Connection::class, 'reader' );
		$reader   = $property->getValue( $connection );
		$this->assertInstanceOf( WP_SQLite_Turso_Embedded_Reader::class, $reader );
		return $reader;
	}

	public function test_reads_come_from_the_replica_and_a_write_does_not_latch(): void {
		$connection = $this->create_connection();
		$connection->query( 'SELECT 1' );
		$connection->query( 'PRAGMA user_version' );
		$this->assertFalse( $connection->is_latched() );
		$this->assertSame( 2, $connection->get_counters()['snapshot'] );
		$this->assertSame( 0, $connection->get_counters()['primary'] );

		// The write goes to the primary; the connection stays unlatched.
		$connection->query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT )' );
		$this->assertFalse( $connection->is_latched() );
		$this->assertSame( 1, $connection->get_counters()['primary'] );
		$this->assertSame( 0, $connection->get_counters()['pulls'] );

		// The next read pulls the replica once, then reads from it.
		$connection->query( 'SELECT 1' );
		$connection->query( 'SELECT 2' );
		$this->assertFalse( $connection->is_latched() );
		$this->assertSame( 4, $connection->get_counters()['snapshot'] );
		$this->assertSame( 1, $connection->get_counters()['primary'] );
		$this->assertSame( 1, $connection->get_counters()['pulls'] );
	}

	public function test_a_read_after_a_write_sees_it_through_the_replica(): void {
		$connection = $this->create_connection();
		$connection->query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT )' );
		$connection->query( 'INSERT INTO t (name) VALUES (?)', array( 'Alice' ) );
		$this->assertSame( '1', $connection->query( 'SELECT COUNT(*) FROM t' )->fetchColumn() );
		$this->assertSame( 'Alice', $connection->query( 'SELECT name FROM t' )->fetchColumn() );
		$this->assertFalse( $connection->is_latched() );
		$this->assertSame( 2, $connection->get_counters()['primary'] );
		$this->assertSame( 2, $connection->get_counters()['snapshot'] );
		$this->assertSame( 1, $connection->get_counters()['pulls'], 'Two consecutive writes cost one pull.' );

		// Write, read, write, read: a pull per read that follows a write.
		$connection->query( 'INSERT INTO t (name) VALUES (?)', array( 'Bob' ) );
		$this->assertSame( '2', $connection->query( 'SELECT COUNT(*) FROM t' )->fetchColumn() );
		$connection->execute_batch(
			array(
				array( 'INSERT INTO t (name) VALUES (?)', array( 'Carol' ) ),
				array( 'INSERT INTO t (name) VALUES (?)', array( 'Dave' ) ),
			)
		);
		$this->assertSame( '4', $connection->query( 'SELECT COUNT(*) FROM t' )->fetchColumn() );
		$this->assertSame( 3, $connection->get_counters()['pulls'] );
		$this->assertFalse( $connection->is_latched() );
	}

	public function test_a_transaction_still_latches(): void {
		$connection = $this->create_connection();
		$connection->query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT )' );
		$connection->begin_transaction();
		$this->assertTrue( $connection->is_latched() );
		$connection->query( 'INSERT INTO t (name) VALUES (?)', array( 'Alice' ) );
		$connection->commit();
		$connection->query( 'SELECT COUNT(*) FROM t' );
		$this->assertTrue( $connection->is_latched(), 'Latched for the rest of the request.' );
		// CREATE, BEGIN, INSERT, COMMIT, SELECT: all on the primary.
		$this->assertSame( 5, $connection->get_counters()['primary'] );
		$this->assertSame( 0, $connection->get_counters()['pulls'] );
	}

	public function test_a_write_becomes_visible_to_the_replica_after_a_pull(): void {
		$writer = $this->create_connection();
		$writer->query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT )' );
		$writer->query( 'INSERT INTO t (name) VALUES (?)', array( 'Alice' ) );

		// A connection that has not written reads the replica, which has not pulled.
		$reader = $this->create_embedded_connection();
		try {
			$before = $reader->query( 'SELECT COUNT(*) FROM t' )->fetchColumn();
		} catch ( WP_SQLite_Turso_Exception $e ) {
			// The table itself may not have reached the replica yet.
			$before = '0';
		}
		$this->assertFalse( $reader->is_latched() );

		$this->reader( $reader )->get_replica()->pull();
		$this->assertSame( '1', $reader->query( 'SELECT COUNT(*) FROM t' )->fetchColumn() );
		$this->assertContains( $before, array( '0', '1' ) );
		$this->assertFalse( $reader->is_latched(), 'Reads after the pull still come from the replica.' );
	}

	public function test_replica_values_keep_their_types_unless_stringified(): void {
		$connection = $this->create_connection();
		$connection->query( 'CREATE TABLE t ( id INTEGER PRIMARY KEY, name TEXT, score REAL, data BLOB )' );
		$connection->query( 'INSERT INTO t (name, score, data) VALUES (?, ?, ?)', array( 'Alice', 1.5, "\x00\xff" ) );
		$this->reader( $connection )->get_replica()->pull();

		$reader = $this->create_embedded_connection();
		$reader->set_attribute( PDO::ATTR_STRINGIFY_FETCHES, false );
		$row = $reader->query( 'SELECT id, name, score, data FROM t' )->fetch( PDO::FETCH_NUM );
		$this->assertSame( array( 1, 'Alice', 1.5, "\x00\xff" ), $row );

		$reader->set_attribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		$row = $reader->query( 'SELECT id, name, score FROM t' )->fetch( PDO::FETCH_NUM );
		$this->assertSame( array( '1', 'Alice', '1.5' ), $row );
		$this->assertFalse( $reader->is_latched() );
	}

	public function test_the_reader_refuses_writes(): void {
		$connection = $this->create_connection();
		$reader     = $this->reader( $connection );

		$this->expectException( WP_SQLite_Turso_Exception::class );
		$this->expectExceptionMessage( 'read-only' );
		$reader->query( 'CREATE TABLE never (id INTEGER)' );
	}

	public function test_the_reader_reports_the_replica_engine_version(): void {
		$connection = $this->create_connection();
		$this->assertMatchesRegularExpression( '/^\d+\.\d+/', $this->reader( $connection )->get_server_version() );
		$this->assertNull( $this->reader( $connection )->get_replica()->stats()['last_error'] );
	}

	public function test_the_replica_is_shared_within_the_process(): void {
		$first  = $this->reader( $this->create_embedded_connection() )->get_replica()->stats();
		$second = $this->reader( $this->create_embedded_connection() )->get_replica()->stats();
		$this->assertSame( $first['opened_unix_ms'], $second['opened_unix_ms'] );
		$this->assertSame( getmypid(), $first['pid'] );
	}

	public function test_the_driver_runs_on_it(): void {
		$connection = $this->create_connection();
		$driver     = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:dbname=wp',
			null,
			null,
			array(
				'sqlite_connection'    => $connection,
				'transaction_fallback' => 'ignore',
			)
		);
		$driver->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		$driver->exec( 'CREATE TABLE posts ( id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) )' );
		$driver->exec( "INSERT INTO posts (title) VALUES ('Hello')" );
		// A fresh database: the configurator set it up inside an EXCLUSIVE
		// transaction, and a transaction latches. That happens once per
		// database (and per driver upgrade), never on an ordinary request.
		$this->assertTrue( $connection->is_latched() );
		$this->reader( $connection )->get_replica()->pull();

		// A fresh request: unlatched, so the driver's reads go to the replica,
		// information schema included.
		$connection = $this->create_embedded_connection();
		$driver     = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:dbname=wp',
			null,
			null,
			array(
				'sqlite_connection'    => $connection,
				'transaction_fallback' => 'ignore',
			)
		);
		$driver->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		$rows = $driver->query( 'SELECT id, title FROM posts' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array(
				array(
					'id'    => '1',
					'title' => 'Hello',
				),
			),
			$rows
		);
		$this->assertFalse( $connection->is_latched(), 'A plain SELECT through the driver stays on the replica.' );
		$this->assertGreaterThan( 0, $connection->get_counters()['snapshot'] );

		// A write on a configured database does not latch: the driver's own
		// read-backs and the next query pull the replica instead.
		$driver->exec( "INSERT INTO posts (title) VALUES ('World')" );
		$this->assertSame( '2', $driver->lastInsertId() );
		$this->assertSame( '2', $driver->query( 'SELECT COUNT(*) FROM posts' )->fetchColumn() );
		$this->assertFalse( $connection->is_latched(), 'A write through the driver does not latch on an embedded replica.' );
		$this->assertGreaterThan( 0, $connection->get_counters()['pulls'] );

		$driver->exec( 'DROP TABLE posts' );
	}
}
