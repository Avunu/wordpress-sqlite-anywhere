<?php

/**
 * Plugin Name:       WordPress SQLite Anywhere
 * Plugin URI:        https://github.com/Avunu/wordpress-sqlite-anywhere
 * Description:       WordPress on SQLite — local, Turso, or Cloudflare D1 over the wire. Bundles the SQLite Database Integration driver.
 * x-release-please-start-version
 * Version:           1.3.0
 * x-release-please-end
 * Requires at least: 6.6
 * Tested up to:      7.1
 * Requires PHP:      8.5
 * Network:           true
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/Avunu/wordpress-sqlite-anywhere
 * Text Domain:       wordpress-sqlite-anywhere
 *
 * ============================================================================
 * CONFIGURATION (wp-config.php constants or environment variables)
 * ============================================================================
 *
 * The engine is chosen by DB_ENGINE: "sqlite" (default), "turso" or "d1".
 * Without DB_ENGINE, a configured WP_TURSO_URL selects Turso and a configured
 * WP_D1_PROXY_URL selects D1. Any other value ("mysql") makes the drop-in stand
 * aside and WordPress uses its own MySQL layer.
 *
 * --- Local SQLite --------------------------------------------------------------
 *   define('DB_DIR',  '/path/to/database/');   // default: wp-content/database/
 *   define('DB_FILE', '.ht.sqlite');
 *
 * --- Turso -------------------------------------------------------------------
 *   define('WP_TURSO_URL',   'libsql://db-org.turso.io');   // required ("turso://" and "https://" too)
 *   define('WP_TURSO_TOKEN', '...');                        // bearer token, if the server needs one
 *   define('WP_TURSO_SNAPSHOT', '/var/lib/wordpress/snapshot.db');
 *       // Read from this published snapshot and write to the primary; the first
 *       // write latches the request to the primary so it reads its own writes.
 *       // Omit for an all-primary connection (wp-admin, cron, tooling).
 *   define('WP_TURSO_SNAPSHOT_JOURNAL', 'DELETE');   // the snapshot's journal mode
 *   define('WP_TURSO_SCHEMA_CACHE', true);           // cache schema reads within a request
 *   define('WP_TURSO_HTTP_TIMEOUT_MS', 30000);
 *   define('WP_TURSO_PIPELINE_PATH', '/v2/pipeline');
 *   define('WP_TURSO_TRANSACTION_FALLBACK', 'warn'); // "warn", "error" or "ignore"
 *
 * --- Cloudflare D1 ------------------------------------------------------------
 *   define('WP_D1_PROXY_URL',   'http://d1.internal');   // required: the D1 proxy worker
 *   define('WP_D1_PROXY_TOKEN', '...');                  // bearer token of a standalone proxy
 *   define('WP_D1_SCHEMA_CACHE', true);
 *   define('WP_D1_HTTP_TIMEOUT_MS', 30000);
 *   define('WP_D1_TRANSACTION_FALLBACK', 'warn');
 */

declare(strict_types=1);

defined('WPINC') || exit;

if (!is_file(__DIR__ . '/vendor/autoload.php')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__(
                'WordPress SQLite Anywhere is missing its vendor/ directory. '
                . 'Install the release zip, or run "composer install" in the plugin directory.',
                'wordpress-sqlite-anywhere'
            )
            . '</p></div>';
    });

    return;
}

require_once __DIR__ . '/vendor/autoload.php';

// Self-update from GitHub releases. The built zip attached to each release bundles
// vendor/, so end users never need Composer.
require_once __DIR__ . '/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';

$sqliteAnywhereUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/Avunu/wordpress-sqlite-anywhere/',
    __FILE__,
    'wordpress-sqlite-anywhere'
);
// Download the built release asset, not GitHub's source tarball (which lacks vendor/
// and the assembled driver). getVcsApi() is typed as the base Api; enableReleaseAssets()
// only exists on the GitHub/GitLab implementations, so guard rather than assume.
$sqliteAnywhereVcsApi = $sqliteAnywhereUpdateChecker->getVcsApi();
if ($sqliteAnywhereVcsApi instanceof \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\GitHubApi) {
    $sqliteAnywhereVcsApi->enableReleaseAssets('/wordpress-sqlite-anywhere\.zip$/');
}

// Upstream's plugin-level code: the "install / refresh the drop-in" admin page,
// the stale-drop-in notice (keyed on SQLITE_DB_DROPIN_VERSION), Site Health and
// the activation hook that writes wp-content/db.php from db.copy.
require_once __DIR__ . '/wp-includes/database/version.php';

define('SQLITE_MAIN_FILE', __FILE__);

require_once __DIR__ . '/capabilities.php';
require_once __DIR__ . '/admin-page.php';
require_once __DIR__ . '/activate.php';
require_once __DIR__ . '/deactivate.php';
require_once __DIR__ . '/admin-notices.php';
require_once __DIR__ . '/health-check.php';
