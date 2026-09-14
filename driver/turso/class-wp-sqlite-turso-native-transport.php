<?php declare(strict_types = 1);

/**
 * A Turso transport backed by the native client PHP extension.
 *
 * The "wp_turso" extension pre-declares the WP_SQLite_Turso_Native_Client
 * class, which sends requests through a process-global HTTP connection pool
 * that persists across PHP requests. That amortizes the TCP and TLS handshakes
 * to the primary over every request a worker process serves, which the
 * pure-PHP cURL transport cannot do: its handle dies with the request.
 *
 * This file is loaded only when the extension is present.
 */
class WP_SQLite_Turso_Native_Transport extends WP_SQLite_Turso_Native_Client implements WP_SQLite_Turso_Transport_Interface {
	use WP_SQLite_Turso_Protocol;

	/**
	 * Constructor.
	 *
	 * @param string               $url     The base URL of the Turso database.
	 * @param string|null          $token   Optional. A bearer token.
	 * @param array<string, mixed> $options {
	 *     Optional. An array of options.
	 *
	 *     @type int    $timeout_ms         The request timeout, in milliseconds.
	 *     @type int    $connect_timeout_ms The connection timeout, in milliseconds.
	 *     @type string $pipeline_path      The pipeline endpoint path.
	 * }
	 */
	public function __construct( string $url, ?string $token = null, array $options = array() ) {
		$url = WP_SQLite_Turso_HTTP_Transport::normalize_url( $url );
		if ( '' === $url ) {
			throw new InvalidArgumentException( 'The Turso database URL must not be empty.' );
		}
		parent::__construct(
			$url,
			$token,
			(int) ( $options['timeout_ms'] ?? WP_SQLite_Turso_HTTP_Transport::DEFAULT_TIMEOUT_MS ),
			(int) ( $options['connect_timeout_ms'] ?? WP_SQLite_Turso_HTTP_Transport::DEFAULT_CONNECT_TIMEOUT_MS )
		);
		if ( isset( $options['pipeline_path'] ) ) {
			$this->pipeline_path = (string) $options['pipeline_path'];
		}
	}

	/**
	 * Send an HTTP POST request using the native client.
	 *
	 * @param  string $path The endpoint path.
	 * @param  string $json The JSON request body.
	 * @return array{0: int, 1: string} The HTTP status and response body.
	 * @throws WP_SQLite_Turso_Exception When the request fails.
	 */
	protected function send( string $path, string $json ): array {
		try {
			$response_body = $this->request( $path, $json );
		} catch ( WP_SQLite_Turso_Exception $e ) {
			throw $e;
		} catch ( Exception $e ) {
			throw WP_SQLite_Turso_Exception::from_transport_failure( $e->getMessage() );
		}
		return array( (int) $this->response_status(), $response_body );
	}
}
