<?php

declare(strict_types=1);

// Constants WordPress (and its environment) define at runtime that are not part
// of php-stubs/wordpress-stubs, plus the ones the drop-in reads from
// wp-config.php. Their *values* are marked dynamic in phpstan.neon.dist so
// `defined(...)` guards are analysed both ways.
define('WPINC', 'wp-includes');

if (!defined('WP_CLI')) {
    define('WP_CLI', false);
}
