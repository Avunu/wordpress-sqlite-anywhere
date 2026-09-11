<?php

declare(strict_types=1);

namespace SqliteAnywhere;

/**
 * Configuration lookup: a wp-config.php constant wins, then a non-empty
 * environment variable, then the default.
 *
 * Every backend setting (WP_TURSO_*, WP_D1_*, DB_ENGINE) goes through here so
 * a containerised site can be configured entirely from its environment while a
 * classic install keeps using constants.
 */
final class Config
{
    public static function get(string $name, mixed $default = null): mixed
    {
        if (defined($name)) {
            return constant($name);
        }

        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return $value;
        }

        return $default;
    }

    public static function string(string $name, string $default = ''): string
    {
        $value = self::get($name, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function nullableString(string $name): ?string
    {
        $value = self::get($name);
        if ($value === null || !is_scalar($value)) {
            return null;
        }
        $value = (string) $value;

        return $value === '' ? null : $value;
    }

    public static function int(string $name, int $default): int
    {
        $value = self::get($name, $default);
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    public static function bool(string $name, bool $default): bool
    {
        $value = self::get($name, $default);
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
