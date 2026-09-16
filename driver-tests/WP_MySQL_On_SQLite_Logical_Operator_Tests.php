<?php

use PHPUnit\Framework\TestCase;

/**
 * MySQL's C-style logical operators "&&" and "!", and XOR.
 *
 * SQLite has none of them; "&&" and "!" were syntax errors. A podcast plugin
 * writes "post_date > 0 && post_date <= ..." on every page view. ("||" is
 * left as the driver has always passed it: SQLite string concatenation,
 * asserted by upstream's own tests, though in MySQL it is logical OR unless
 * PIPES_AS_CONCAT is on.)
 */
class WP_MySQL_On_SQLite_Logical_Operator_Tests extends TestCase {
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
		$this->engine->query( 'CREATE TABLE posts ( ID INT PRIMARY KEY, post_date DATETIME, post_status VARCHAR(20) )' );
		$this->engine->query( "INSERT INTO posts VALUES (1, '2026-09-16 09:00:00', 'future'), (2, '2026-09-17 09:00:00', 'future'), (3, '2026-09-16 09:00:00', 'publish')" );
	}

	/**
	 * @dataProvider expressions
	 */
	public function test_logical_operators( string $expression, ?string $expected ): void {
		$this->assertSame( $expected, $this->engine->query( "SELECT $expression" )->fetchColumn() );
	}

	public function expressions(): array {
		return array(
			'&& true'          => array( '1 > 0 && 2 > 1', '1' ),
			'&& false'         => array( '1 > 0 && 2 < 1', '0' ),
			'! true'           => array( '!0', '1' ),
			'! false'          => array( '!1', '0' ),
			'XOR true'         => array( '1 XOR 0', '1' ),
			'XOR false'        => array( '1 XOR 1', '0' ),
			'XOR truthiness'   => array( '5 XOR 3', '0' ),
			'XOR null'         => array( '1 XOR NULL', null ),
			'XOR chain'        => array( '1 XOR 1 XOR 1', '1' ),
			'mixed precedence' => array( '0 OR 1 && 1', '1' ),
		);
	}

	public function test_the_scheduled_posts_query(): void {
		$ids = $this->engine->query(
			"SELECT ID FROM posts WHERE ( ( post_date > 0 && post_date <= '2026-09-16 10:17:59' ) ) AND post_status = 'future' LIMIT 0, 5"
		)->fetchAll( PDO::FETCH_COLUMN );
		$this->assertSame( array( '1' ), $ids );
	}
}
