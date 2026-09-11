<?php

/**
 * PHPUnit bootstrap for the assembled driver package.
 *
 * The suites run with the tooling project's vendor/ (PHPUnit 9) linked in
 * as vendor/, and that autoloader knows nothing about the driver: upstream's
 * own composer.json loads "src/load.php" as an autoloaded file, and that is
 * the one thing upstream's bootstrap relies on the autoloader for. Load the
 * driver here, hand over to upstream's bootstrap unchanged, then add the
 * backend selection.
 */

require_once __DIR__ . '/../src/load.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/tools/backend-factory.php';
