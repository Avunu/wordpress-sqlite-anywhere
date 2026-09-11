<?php
/**
 * Load the connection abstraction the remote backends build on.
 *
 * This is the one line the patch series adds to upstream's "load.php", ahead
 * of "class-wp-sqlite-connection.php": the interface that class implements,
 * the trait carrying its implementation of the interface's extra methods, the
 * in-memory statement remote backends return results through, and the
 * translator used on connections without user-defined functions.
 */

declare( strict_types = 1 );

require_once __DIR__ . '/interface-wp-sqlite-connection.php';
require_once __DIR__ . '/trait-wp-sqlite-pdo-connection-methods.php';
require_once __DIR__ . '/class-wp-pdo-array-statement.php';
require_once __DIR__ . '/class-wp-sqlite-udf-free-translator.php';
