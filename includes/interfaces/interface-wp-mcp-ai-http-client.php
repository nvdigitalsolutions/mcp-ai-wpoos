<?php
/**
 * Interface: HTTP Client
 *
 * Abstracts outbound HTTP calls (`wp_remote_get`, `wp_remote_post`, and streaming
 * requests) so that service-layer classes can make network requests without
 * depending on WordPress functions directly.
 *
 * Implement this interface in
 * `includes/infrastructure/http/class-wp-mcp-ai-wp-http-client.php`.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstraction for outbound HTTP requests.
 *
 * @since 1.2.0
 */
interface Interface_WP_MCP_AI_HTTP_Client {

	/**
	 * Perform a GET request.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Optional request arguments (headers, timeout, etc.).
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function get( $url, $args = array() );

	/**
	 * Perform a POST request.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args Optional request arguments (body, headers, timeout, etc.).
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function post( $url, $args = array() );

	/**
	 * Perform a streaming POST request and yield response chunks.
	 *
	 * For Server-Sent Events or chunked transfer encoding. Implementations may
	 * call a user-supplied callback for each chunk rather than returning a single
	 * response array.
	 *
	 * @param string   $url      Request URL.
	 * @param array    $args     Request arguments.
	 * @param callable $callback Called for each received chunk. Signature:
	 *                           `function( string $chunk ): void`.
	 * @return array|WP_Error Final response summary or WP_Error on failure.
	 */
	public function stream( $url, $args = array(), $callback = null );
}
