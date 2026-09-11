<?php

declare(strict_types=1);

namespace SqliteAnywhere;

/**
 * What wp-content/db.php does once it has found the plugin folder.
 *
 * WordPress loads the drop-in before any plugin and before Composer's
 * autoloader is available, so db.copy requires this file and its two
 * dependencies by path; nothing here may rely on vendor/.
 */
final class DropIn
{
    /**
     * Boot the database layer for the resolved engine.
     *
     * @param string $pluginDir The plugin's install directory (absolute, no trailing slash).
     * @return Engine|null The engine that was booted, or null when the drop-in stood aside.
     */
    public static function boot(string $pluginDir): ?Engine
    {
        $engine = Engine::resolve();
        if ($engine === null) {
            return null;
        }

        self::defineEngineConstants($engine);

        if ($engine === Engine::Sqlite) {
            // Byte for byte upstream's path: the installer, WP_SQLite_DB and the
            // Query Monitor integration.
            require_once $pluginDir . '/wp-includes/sqlite/db.php';

            return $engine;
        }

        self::bootRemote($pluginDir, $engine);

        return $engine;
    }

    private static function defineEngineConstants(Engine $engine): void
    {
        /**
         * Legacy database type marker.
         *
         * @deprecated Use DB_ENGINE instead.
         */
        if (!defined('DATABASE_TYPE')) {
            define('DATABASE_TYPE', $engine->value);
        }
        if (!defined('DB_ENGINE')) {
            define('DB_ENGINE', $engine->value);
        }
    }

    /**
     * Boot a remote backend: the driver, the backend's own loader, and a
     * wpdb subclass whose connection goes over the wire.
     *
     * Upstream's install-functions.php is intentionally NOT loaded: its
     * installer creates the WordPress tables over a separate local SQLite
     * connection. Here the installer must run through $wpdb (core's
     * wp_install() and dbDelta() path), so the schema lands on the primary.
     */
    private static function bootRemote(string $pluginDir, Engine $engine): void
    {
        $database = $pluginDir . '/wp-includes/database';

        require_once $database . '/version.php';
        require_once $pluginDir . '/constants.php';
        require_once $database . '/load.php';
        require_once $database . '/' . $engine->backendDirectory() . '/load.php';
        require_once $database . '/remote/load.php';
        require_once $pluginDir . '/wp-includes/sqlite/class-wp-sqlite-db.php';
        require_once $pluginDir . '/wp-includes/sqlite/class-wp-sqlite-remote-db.php';

        $dbName = Config::string('DB_NAME');
        $GLOBALS['wpdb'] = new \WP_SQLite_Remote_DB($dbName, $engine->value, Config::get(...));
    }
}
