<?php
/**
 * Mock HTTP Client for tests.
 *
 * Implements `Interface_WP_MCP_AI_HTTP_Client` so that tools accepting an
 * HTTP client via constructor DI can be tested without relying on the global
 * `pre_http_request` filter.
 *
 * ## Usage
 *
 * ```php
 * $mock = new WP_MCP_AI_Mock_HTTP_Client();
 * $mock->queue_success(
 *     'https://api.example.com/v1/search',
 *     200,
 *     array( 'results' => array() )
 * );
 * $tool = new WP_MCP_AI_Tool_Web_Search( $mock );
 * $tool->execute( $args, $context );
 * ```
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.ValidVariableName -- Private properties match trait convention.

/**
 * Mock HTTP client for unit testing tools with DI.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Mock_HTTP_Client implements Interface_WP_MCP_AI_HTTP_Client {

	/**
	 * Queued responses keyed by URL substring.
	 *
	 * @var array<string,array|WP_Error>
	 */
	private $responses = array();

	/**
	 * History of requests made through this client.
	 *
	 * Each entry contains 'method', 'url', and 'args'.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $request_history = array();

	/**
	 * Queue a response for a URL pattern.
	 *
	 * The first queued response whose key is a substring of the requested URL
	 * will be returned.  Queued entries are consumed in insertion order.
	 *
	 * @param string         $url_substring Substring to match in the request URL.
	 * @param array|WP_Error $response      Either a response array (`status`, `body`, `headers`)
	 *                                       or a `WP_Error` instance.
	 */
	public function queue_response( $url_substring, $response ) {
		$this->responses[ $url_substring ] = $response;
	}

	/**
	 * Queue a successful JSON response.
	 *
	 * Convenience wrapper around {@see self::queue_response()}.
	 *
	 * @param string $url_substring Substring to match in the request URL.
	 * @param int    $status        HTTP status code.
	 * @param mixed  $body          Response body (arrays are JSON-encoded).
	 * @param array  $headers       Optional response headers.
	 */
	public function queue_success( $url_substring, $status = 200, $body = null, array $headers = array() ) {
		if ( is_array( $body ) || is_object( $body ) ) {
			$body = wp_json_encode( $body );
		}

		$this->queue_response(
			$url_substring,
			array(
				'status'  => $status,
				'body'    => (string) $body,
				'headers' => $headers,
			)
		);
	}

	/**
	 * Queue a WP_Error response.
	 *
	 * @param string $url_substring Substring to match.
	 * @param string $error_code    WP_Error code.
	 * @param string $error_message WP_Error message.
	 */
	public function queue_error( $url_substring, $error_code, $error_message ) {
		$this->queue_response( $url_substring, new WP_Error( $error_code, $error_message ) );
	}

	/**
	 * Retrieve the request history.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_request_history() {
		return $this->request_history;
	}

	/**
	 * Get the number of requests made.
	 *
	 * @return int
	 */
	public function get_request_count() {
		return count( $this->request_history );
	}

	/**
	 * Reset all queued responses and request history.
	 */
	public function reset() {
		$this->responses       = array();
		$this->request_history = array();
	}

	// -------------------------------------------------------------------------
	// Interface_WP_MCP_AI_HTTP_Client
	// -------------------------------------------------------------------------

	/**
	 * Perform a GET request.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Optional request arguments.
	 * @return array|WP_Error Mapped response or WP_Error.
	 */
	public function get( $url, $args = array() ) {
		return $this->dispatch( 'GET', $url, $args );
	}

	/**
	 * Perform a POST request.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Optional request arguments.
	 * @return array|WP_Error Mapped response or WP_Error.
	 */
	public function post( $url, $args = array() ) {
		return $this->dispatch( 'POST', $url, $args );
	}

	/**
	 * Perform a streaming POST request.
	 *
	 * For the mock, streaming is emulated by passing the full response body
	 * line-by-line through the callback (if provided).
	 *
	 * @param string   $url      Request URL.
	 * @param array    $args     Request arguments.
	 * @param callable $callback Called for each line of the response body.
	 * @return array|WP_Error Response summary or WP_Error.
	 */
	public function stream( $url, $args = array(), $callback = null ) {
		$response = $this->dispatch( 'POST', $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( is_callable( $callback ) && isset( $response['body'] ) ) {
			$lines = explode( "\n", $response['body'] );
			foreach ( $lines as $line ) {
				if ( '' !== trim( $line ) ) {
					call_user_func( $callback, $line );
				}
			}
		}

		return $response;
	}

	// -------------------------------------------------------------------------
	// Internal
	// -------------------------------------------------------------------------

	/**
	 * Match a URL against queued responses and return the result.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Request URL.
	 * @param array  $args   Request arguments.
	 * @return array|WP_Error
	 */
	private function dispatch( $method, $url, $args ) {
		$this->request_history[] = array(
			'method' => $method,
			'url'    => $url,
			'args'   => $args,
		);

		if ( '' === $url ) {
			return new WP_Error( 'http_client_error', 'Invalid URL: empty string.' );
		}

		foreach ( $this->responses as $needle => $response ) {
			if ( '' === $needle || false !== strpos( $url, $needle ) ) {
				if ( is_wp_error( $response ) ) {
					return $response;
				}

				// Map from the service shape (status/body/headers) to the
				// wp_remote_* shape expected by most consumer code.
				if ( isset( $response['status'] ) ) {
					$desc = get_status_header_desc( $response['status'] );
					return array(
						'response' => array(
							'code'    => $response['status'],
							'message' => $desc,
						),
						'body'     => isset( $response['body'] ) ? $response['body'] : '',
						'headers'  => isset( $response['headers'] ) ? $response['headers'] : array(),
					);
				}

				// Already in wp_remote_* shape — pass through.
				return $response;
			}
		}

		// Fallback: generic 200.
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => '{}',
			'headers'  => array(),
		);
	}
}
