<?php

declare( strict_types = 1 );

// The parent lives outside this suite's directory; PHPUnit registers only
// the class named like this file, so loading it here is side-effect free.
require_once __DIR__ . '/../WP_MySQL_On_SQLite_Metadata_Tests.php';

/**
 * Upstream's WP_MySQL_On_SQLite_Metadata_Tests against the selected remote backend.
 *
 * The parent's setUp() is unchanged: the driver's options filter, installed
 * by tests/tools/backend-factory.php, swaps its local SQLite handle for the
 * backend's connection over a fake transport. Tests the backend cannot
 * satisfy are skipped from the shared skip list first.
 */
class WP_MySQL_On_SQLite_Remote_Metadata_Tests extends WP_MySQL_On_SQLite_Metadata_Tests {
	public function setUp(): void {
		wp_sqlite_tests_skip_unsupported( $this );
		parent::setUp();
	}
}
