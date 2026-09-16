<?php

use PHPUnit\Framework\TestCase;

/**
 * MySQL date arithmetic with the INTERVAL operator forms -- "date + INTERVAL
 * n unit", "date - INTERVAL n unit", "INTERVAL n unit + date" -- translates
 * like DATE_ADD() / DATE_SUB(). Before this, the operator forms were emitted
 * verbatim and SQLite failed to parse them; Patchstack's ban check is one
 * real-world query written that way.
 */
class WP_MySQL_On_SQLite_Interval_Tests extends TestCase {
	/**
	 * The driver.
	 *
	 * @var WP_MySQL_On_SQLite
	 */
	private $engine;

	public function setUp(): void {
		$pdo_class    = PHP_VERSION_ID >= 80400 ? Pdo\Sqlite::class : PDO::class;
		$this->engine = new WP_MySQL_On_SQLite(
			'mysql-on-sqlite:dbname=wp',
			null,
			null,
			array( 'sqlite_pdo' => new $pdo_class( 'sqlite::memory:' ) )
		);
		$this->engine->setAttribute( PDO::ATTR_STRINGIFY_FETCHES, true );
		$this->engine->query( 'CREATE TABLE log ( id INT PRIMARY KEY, ip VARCHAR(45), log_date DATETIME, days INT )' );
		$this->engine->query( "INSERT INTO log VALUES (1, '127.0.0.1', '2026-09-16 09:14:00', 2), (2, '127.0.0.1', '2026-09-16 09:00:00', 1)" );
	}

	/**
	 * @dataProvider expressions
	 */
	public function test_interval_arithmetic( string $expression, string $expected ): void {
		$this->assertSame( $expected, $this->engine->query( "SELECT $expression" )->fetchColumn() );
	}

	public function expressions(): array {
		return array(
			'minus minutes'        => array( "'2026-09-16 09:15:08' - INTERVAL 2 MINUTE", '2026-09-16 09:13:08' ),
			'plus hours'           => array( "'2026-09-16 09:15:08' + INTERVAL 3 HOUR", '2026-09-16 12:15:08' ),
			'interval first'       => array( "INTERVAL 1 DAY + '2026-09-16 09:15:08'", '2026-09-17 09:15:08' ),
			'weeks become days'    => array( "'2026-09-16' + INTERVAL 2 WEEK", '2026-09-30 00:00:00' ),
			'date_sub, for parity' => array( "DATE_SUB('2026-09-16 09:15:08', INTERVAL 2 MINUTE)", '2026-09-16 09:13:08' ),
			'parenthesised'        => array( "('2026-09-16 09:15:08' - INTERVAL 2 MINUTE)", '2026-09-16 09:13:08' ),
			'chained'              => array( "'2026-09-16 09:15:08' - INTERVAL 1 HOUR + INTERVAL 5 MINUTE", '2026-09-16 08:20:08' ),
		);
	}

	public function test_interval_value_can_be_an_expression(): void {
		$rows = $this->engine
			->query( 'SELECT id, log_date + INTERVAL days DAY AS later, log_date + INTERVAL days WEEK AS much_later FROM log ORDER BY id' )
			->fetchAll( PDO::FETCH_ASSOC );
		$this->assertSame( '2026-09-18 09:14:00', $rows[0]['later'] );
		$this->assertSame( '2026-09-30 09:14:00', $rows[0]['much_later'] );
		$this->assertSame( '2026-09-17 09:00:00', $rows[1]['later'] );
	}

	public function test_the_patchstack_ban_query(): void {
		$count = $this->engine->query(
			"SELECT COUNT(*) AS blockedCount FROM log
			WHERE ip = '127.0.0.1' AND log_date >= ('2026-09-16 09:15:08' - INTERVAL 2 MINUTE)"
		)->fetchColumn();
		$this->assertSame( '1', $count );
	}
}
