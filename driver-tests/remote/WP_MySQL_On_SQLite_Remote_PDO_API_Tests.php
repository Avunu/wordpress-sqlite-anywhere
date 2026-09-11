<?php

declare( strict_types = 1 );

// The parent lives outside this suite's directory; PHPUnit registers only
// the class named like this file, so loading it here is side-effect free.
require_once __DIR__ . '/../WP_MySQL_On_SQLite_PDO_API_Tests.php';

/**
 * Upstream's WP_MySQL_On_SQLite_PDO_API_Tests against the selected remote backend.
 *
 * The parent's setUp() is unchanged: the driver's options filter, installed
 * by tests/tools/backend-factory.php, gives the in-memory DSN a connection
 * over the backend's fake transport. Tests the backend cannot satisfy are
 * skipped from the shared skip list first.
 */
class WP_MySQL_On_SQLite_Remote_PDO_API_Tests extends WP_MySQL_On_SQLite_PDO_API_Tests {
	public function setUp(): void {
		wp_sqlite_tests_skip_unsupported( $this );
		parent::setUp();
	}

	/**
	 * @dataProvider data_pdo_fetch_methods
	 */
	public function test_query_with_fetch_mode( $query, $mode, $expected ): void {
		$this->skip_fetch_named_without_a_native_statement( $mode );
		parent::test_query_with_fetch_mode( $query, $mode, $expected );
	}

	/**
	 * @dataProvider data_pdo_fetch_methods
	 */
	public function test_fetch( $query, $mode, $expected ): void {
		$this->skip_fetch_named_without_a_native_statement( $mode );
		parent::test_fetch( $query, $mode, $expected );
	}

	/**
	 * Skip PDO::FETCH_NAMED, which a pure-PHP result set cannot reproduce.
	 *
	 * PDO returns every FETCH_NAMED key as a string, including numeric column
	 * names, which it manages by building the array below the language. PHP
	 * itself converts a numeric-string key to an integer, so a backend whose
	 * statements are plain arrays -- every remote backend -- cannot produce the
	 * same keys. Only this one fetch mode is affected.
	 *
	 * @param int $mode The fetch mode under test.
	 */
	private function skip_fetch_named_without_a_native_statement( int $mode ): void {
		if ( PDO::FETCH_NAMED === $mode ) {
			$this->markTestSkipped(
				'PDO::FETCH_NAMED returns numeric column names as string array keys,'
				. ' which a pure-PHP array cannot represent.'
			);
		}
	}
}
