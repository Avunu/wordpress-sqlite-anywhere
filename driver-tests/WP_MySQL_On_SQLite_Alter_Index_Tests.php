<?php

use PHPUnit\Framework\TestCase;

/**
 * ALTER TABLE statements that only add or drop secondary indexes are executed
 * as CREATE INDEX / DROP INDEX, without rebuilding the table.
 *
 * A rebuild copies every row into a new table. That is a real cost on the
 * remote backends -- and on Turso, where a multi-row insert into an
 * AUTOINCREMENT table is quadratic, a prohibitive one -- for what dbDelta()
 * runs on every plugin update whose schema gained a key.
 */
class WP_MySQL_On_SQLite_Alter_Index_Tests extends TestCase {
	/**
	 * The SQLite connection.
	 *
	 * @var PDO
	 */
	private $sqlite;

	/**
	 * The driver.
	 *
	 * @var WP_MySQL_On_SQLite
	 */
	private $engine;

	public function setUp(): void {
		$pdo_class    = PHP_VERSION_ID >= 80400 ? Pdo\Sqlite::class : PDO::class;
		$this->sqlite = new $pdo_class( 'sqlite::memory:' );
		$this->engine = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:dbname=wp',
			null,
			null,
			array( 'sqlite_pdo' => $this->sqlite )
		);
		$this->engine->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		$this->engine->query(
			'CREATE TABLE t (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
				a INT NOT NULL DEFAULT 0,
				b VARCHAR(20) NOT NULL DEFAULT "",
				c TEXT
			)'
		);
		$this->engine->query( 'INSERT INTO t (a, b) VALUES (1, "x"), (2, "y")' );
	}

	/**
	 * The SQLite indexes of table t: name => CREATE INDEX statement.
	 *
	 * @return array<string, string>
	 */
	private function sqlite_indexes(): array {
		$rows = $this->sqlite
			->query( "SELECT name, sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 't' AND sql IS NOT NULL ORDER BY name" )
			->fetchAll( PDO::FETCH_KEY_PAIR );
		return $rows;
	}

	/**
	 * The SQLite statements the driver ran for the last MySQL query.
	 *
	 * @return string[]
	 */
	private function last_sqlite_sql(): array {
		return array_column( $this->engine->get_last_sqlite_queries(), 'sql' );
	}

	private function assertNoRebuild(): void {
		foreach ( $this->last_sqlite_sql() as $sql ) {
			// The information schema tables are updated either way; a rebuild
			// copies the rows of the table itself and drops it.
			$this->assertDoesNotMatchRegularExpression( '/^\s*INSERT INTO `(?!_wp_sqlite_)/i', $sql, "Rebuilt: $sql" );
			$this->assertStringNotContainsStringIgnoringCase( 'DROP TABLE', $sql, "Rebuilt: $sql" );
		}
	}

	private function assertRebuilt(): void {
		$this->assertTrue(
			(bool) preg_grep( '/DROP TABLE/i', $this->last_sqlite_sql() ),
			'The table should have been rebuilt.'
		);
	}

	public function test_add_key_creates_an_index_in_place(): void {
		$this->engine->query( 'ALTER TABLE t ADD KEY a (a), ADD UNIQUE KEY b (b), ADD INDEX ab (a, b DESC)' );
		$this->assertNoRebuild();

		$indexes = $this->sqlite_indexes();
		$this->assertSame( array( 't__a', 't__ab', 't__b' ), array_keys( $indexes ) );
		$this->assertSame( 'CREATE INDEX `t__a` ON `t` (`a`)', $indexes['t__a'] );
		$this->assertSame( 'CREATE UNIQUE INDEX `t__b` ON `t` (`b`)', $indexes['t__b'] );
		$this->assertSame( 'CREATE INDEX `t__ab` ON `t` (`a`, `b` DESC)', $indexes['t__ab'] );

		// The rows are untouched and the information schema knows the keys.
		$this->assertSame( '2', $this->engine->query( 'SELECT COUNT(*) FROM t' )->fetchColumn() );
		$keys = $this->engine->query( 'SHOW INDEX FROM t' )->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame(
			array( 'PRIMARY', 'b', 'a', 'ab', 'ab' ),
			array_column( $keys, 'Key_name' )
		);
		$this->assertSame( '0', $keys[1]['Non_unique'] );
		$this->assertSame( '1', $keys[2]['Non_unique'] );
	}

	public function test_add_key_without_a_name_is_named_after_its_column(): void {
		$this->engine->query( 'ALTER TABLE t ADD KEY (a)' );
		$this->assertNoRebuild();
		$this->assertSame( array( 't__a' ), array_keys( $this->sqlite_indexes() ) );
	}

	public function test_add_key_with_a_prefix_length_ignores_the_prefix(): void {
		$this->engine->query( 'ALTER TABLE t ADD KEY b (b(10))' );
		$this->assertNoRebuild();
		$this->assertSame( 'CREATE INDEX `t__b` ON `t` (`b`)', $this->sqlite_indexes()['t__b'] );
	}

	public function test_drop_key_drops_the_index_in_place(): void {
		$this->engine->query( 'ALTER TABLE t ADD KEY a (a), ADD KEY b (b)' );
		$this->engine->query( 'ALTER TABLE t DROP KEY a' );
		$this->assertNoRebuild();
		$this->assertSame( array( 't__b' ), array_keys( $this->sqlite_indexes() ) );
		$this->assertSame(
			array( 'PRIMARY', 'b' ),
			array_column( $this->engine->query( 'SHOW INDEX FROM t' )->fetchAll( PDO::FETCH_ASSOC ), 'Key_name' )
		);

		$this->engine->query( 'ALTER TABLE t DROP INDEX b' );
		$this->assertNoRebuild();
		$this->assertSame( array(), $this->sqlite_indexes() );
	}

	public function test_an_index_dropped_and_re_added_in_one_statement_gets_its_new_columns(): void {
		$this->engine->query( 'ALTER TABLE t ADD KEY k (a)' );
		$this->engine->query( 'ALTER TABLE t DROP KEY k, ADD KEY k (b, a)' );
		$this->assertNoRebuild();
		$this->assertSame( 'CREATE INDEX `t__k` ON `t` (`b`, `a`)', $this->sqlite_indexes()['t__k'] );
	}

	public function test_a_unique_key_is_enforced(): void {
		$this->engine->query( 'ALTER TABLE t ADD UNIQUE KEY b (b)' );
		$this->expectException( PDOException::class );
		$this->engine->query( 'INSERT INTO t (a, b) VALUES (3, "x")' );
	}

	public function test_other_alterations_still_rebuild_the_table(): void {
		$this->engine->query( 'ALTER TABLE t ADD COLUMN d INT, ADD KEY d (d)' );
		$this->assertRebuilt();
		$this->assertSame( 'CREATE INDEX `t__d` ON `t` (`d`)', $this->sqlite_indexes()['t__d'] );

		$this->engine->query( 'ALTER TABLE t DROP PRIMARY KEY' );
		$this->assertRebuilt();

		$this->engine->query( 'ALTER TABLE t ADD PRIMARY KEY (id)' );
		$this->assertRebuilt();
	}

	public function test_a_duplicate_key_name_is_an_error_and_changes_nothing(): void {
		$this->engine->query( 'ALTER TABLE t ADD KEY a (a)' );
		try {
			$this->engine->query( 'ALTER TABLE t ADD KEY a (b)' );
			$this->fail( 'A duplicate key name should be rejected.' );
		} catch ( PDOException $e ) {
			$this->assertStringContainsString( 'Duplicate key name', $e->getMessage() );
		}
		$this->assertSame( 'CREATE INDEX `t__a` ON `t` (`a`)', $this->sqlite_indexes()['t__a'] );
	}
}
