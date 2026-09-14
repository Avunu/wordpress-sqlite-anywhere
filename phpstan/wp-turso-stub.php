<?php

/**
 * The classes the "wp_turso" PHP extension (packages/php-ext-wp-turso)
 * declares at runtime. WP_SQLite_Turso_Native_Transport extends the client
 * and WP_SQLite_Turso_Embedded_Reader wraps the replica; these stubs give
 * PHPStan their shape. Keep in step with src/http.rs and src/replica.rs.
 */

class WP_SQLite_Turso_Native_Client
{
    public function __construct(string $endpoint, ?string $token = null, ?int $timeout_ms = null, ?int $connect_timeout_ms = null) {}

    /**
     * Send a JSON POST request through the pooled client.
     *
     * @throws Exception When the request fails.
     */
    public function request(string $path, string $json_body): string {}

    /** The HTTP status of the last response. */
    public function response_status(): int {}

    public static function version(): string {}
}

class WP_SQLite_Turso_Native_Replica
{
    /**
     * @param array{pull_interval_ms?: int, bootstrap?: bool, client_name?: string, open_timeout_ms?: int}|null $options
     * @throws Exception When the replica cannot be opened.
     */
    public function __construct(string $path, string $url, ?string $token = null, ?array $options = null) {}

    /**
     * @param list<scalar|null>|null $params
     * @return array{columns: list<string>, rows: list<list<scalar|null>>}
     * @throws Exception When the statement fails.
     */
    public function query(string $sql, ?array $params = null): array {}

    /** @throws Exception When the pull fails. */
    public function pull(): bool {}

    public function path(): string {}

    /**
     * @return array{pid: int, opened_unix_ms: int, pull_interval_ms: int, pulls: int, pulls_with_changes: int, pull_errors: int, last_pull_unix_ms: int, last_pull_took_us: int, bootstrapped: bool, last_error: string|null}
     */
    public function stats(): array {}

    public static function version(): string {}
}
