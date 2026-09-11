<?php

/**
 * The class the "wp_d1_client" PHP extension (packages/php-ext-wp-d1-client)
 * declares at runtime. WP_SQLite_D1_Native_Transport extends it; this stub
 * gives PHPStan its shape. Keep in step with src/lib.rs.
 */
class WP_SQLite_D1_Native_Client
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
