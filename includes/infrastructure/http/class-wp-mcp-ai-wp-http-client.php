<?php
/**
 * WordPress HTTP Client Adapter
 *
 * Implements Interface_WP_MCP_AI_HTTP_Client using WordPress's native
 * `wp_remote_get`, `wp_remote_post`, and streaming capabilities.
 *
 * Register this in the DI container as the canonical implementation:
 *
 *   $container->bind(
 *       'Interface_WP_MCP_AI_HTTP_Client',
 *       'WP_MCP_AI_WP_HTTP_Client'
 *   );
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-http-client.php';

/**
 * WordPress implementation of the HTTP Client interface.
 *
 * Delegates all outbound HTTP calls to the WordPress HTTP API functions so
 * that consumer classes remain dependency-free from WordPress globals while
 * tests can swap in a stub implementation.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_WP_HTTP_Client implements Interface_WP_MCP_AI_HTTP_Client {

	/**
	 * Default request timeout in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 30;

	/**
	 * Perform a GET request via wp_remote_get.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Optional request arguments (headers, timeout, etc.).
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function get( $url, $args = array() ) {
		$url = esc_url_raw( (string) $url );

		if ( '' === $url ) {
			return new WP_Error( 'wp_mcp_ai_invalid_url', __( 'A valid URL is required for GET requests.', 'mcp-ai-wpoos' ) );
		}

		$args = $this->apply_defaults( $args );
		$args = apply_filters( 'wp_mcp_ai_http_client_get_args', $args, $url );

		return wp_remote_get( $url, $args );
	}

	/**
	 * Perform a POST request via wp_remote_post.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Optional request arguments (body, headers, timeout, etc.).
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function post( $url, $args = array() ) {
		$url = esc_url_raw( (string) $url );

		if ( '' === $url ) {
			return new WP_Error( 'wp_mcp_ai_invalid_url', __( 'A valid URL is required for POST requests.', 'mcp-ai-wpoos' ) );
		}

		$args = $this->apply_defaults( $args );
		$args = apply_filters( 'wp_mcp_ai_http_client_post_args', $args, $url );

		return wp_remote_post( $url, $args );
	}

	/**
	 * Perform a streaming POST request and invoke a callback for each chunk.
	 *
	 * WordPress's HTTP API buffers the full response body, so streaming is
	 * implemented by setting `stream => true` (which writes the body to a
	 * temp file) and splitting on newlines to deliver logical chunks to the
	 * caller's callback.  The callback signature is:
	 *
	 *   function( string $chunk, bool $done ): void
	 *
	 * @param string   $url      Request URL.
	 * @param array    $args     Request arguments.
	 * @param callable $callback Called for each received chunk.
	 * @return array|WP_Error Final response summary or WP_Error on failure.
	 */
	public function stream( $url, $args = array(), $callback = null ) {
		$url = esc_url_raw( (string) $url );

		if ( '' === $url ) {
			return new WP_Error( 'wp_mcp_ai_invalid_url', __( 'A valid URL is required for streaming requests.', 'mcp-ai-wpoos' ) );
		}

		$args = $this->apply_defaults( $args );

		// Ensure the request blocks so we receive the full response body.
		// `stream => true` instructs WordPress to write the body to a temp
		// file (memory-efficient for large SSE payloads), but requires that
		// `blocking` remains true so wp_remote_post waits for the response.
		$args['stream']   = true;
		$args['blocking'] = true;

		$args = apply_filters( 'wp_mcp_ai_http_client_stream_args', $args, $url );

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );

		if ( is_callable( $callback ) ) {
			// Split on all common line endings (CRLF, LF, CR) for SSE compatibility.
			$lines      = preg_split( '/\r\n|\n|\r/', $body );
			$non_empty  = array_values( array_filter( $lines, 'strlen' ) );
			$last_index = count( $non_empty ) - 1;

			foreach ( $non_empty as $index => $line ) {
				$line = trim( $line );
				$done = ( $index === $last_index ) || ( 'data: [DONE]' === $line );
				call_user_func( $callback, $line, $done );
			}
		}

		$code = wp_remote_retrieve_response_code( $response );

		return array(
			'status' => $code,
			'body'   => $body,
		);
	}

	/**
	 * Apply default arguments to an outbound request.
	 *
	 * @param array $args User-supplied request arguments.
	 * @return array Merged arguments with defaults applied.
	 */
	private function apply_defaults( array $args ) {
		$defaults = array(
			'timeout' => self::DEFAULT_TIMEOUT,
		);

		return array_merge( $defaults, $args );
	}
}
