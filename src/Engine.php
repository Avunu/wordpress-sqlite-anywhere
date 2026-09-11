<?php

declare(strict_types=1);

namespace SqliteAnywhere;

/**
 * The database engines the drop-in can boot.
 *
 * The string values are what DB_ENGINE (and the deprecated DATABASE_TYPE
 * alias that upstream's constants.php still keys off) carries.
 */
enum Engine: string
{
    case Sqlite = 'sqlite';
    case D1 = 'd1';
    case Turso = 'turso';

    /**
     * Decide which engine the site runs on.
     *
     * An explicit DB_ENGINE wins. Without one, a configured backend URL
     * selects its engine, and a plain install falls back to local SQLite.
     * Anything else ("mysql", an unknown name) is null: the drop-in then
     * returns and leaves WordPress to its own database layer.
     */
    public static function resolve(): ?self
    {
        $explicit = Config::nullableString('DB_ENGINE');
        if ($explicit !== null) {
            return self::tryFrom(strtolower($explicit));
        }

        if (Config::nullableString('WP_TURSO_URL') !== null) {
            return self::Turso;
        }

        if (Config::nullableString('WP_D1_PROXY_URL') !== null) {
            return self::D1;
        }

        return self::Sqlite;
    }

    public function isRemote(): bool
    {
        return $this !== self::Sqlite;
    }

    /**
     * The driver's backend directory under wp-includes/database, for the
     * remote engines.
     */
    public function backendDirectory(): string
    {
        return $this->value;
    }
}
