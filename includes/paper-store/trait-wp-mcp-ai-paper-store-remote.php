<?php
/**
 * Trait: Paper Store Remote Operations.
 *
 * Provides remote Paper Store execution via WordPress REST API for
 * tools that accept an optional connection_id parameter.
 *
 * @package WP_MCP_AI
 * @since   1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Paper_Store_Remote.
 *
 * Shared by all paper_store_* tool classes to proxy operations
 * to a remote WordPress site when a connection_id is supplied.
 */
trait WP_MCP_AI_Paper_Store_Remote {

	/**
	 * Resolve a remote connection and proxy the Paper Store operation.
	 *
	 * @since 1.4.0
	 *
	 * @param string $connection_id Remote connection identifier.
	 * @param string $endpoint      API endpoint path (e.g. "paper-store/my-collection").
	 * @param string $method        HTTP method (GET, POST, PUT, DELETE).
	 * @param array  $body          Optional request body.
	 * @return array|WP_Error Success envelope or error.
	 */
	private function execute_remote( $connection_id, $endpoint, $method = 'GET', $body = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error(
				'pro_required',
				__( 'Remote Paper Store operations require the NV oOS Pro addon. Please upgrade to use remote connections.', 'mcp-ai-wpoos' )
			);
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( null === $connection ) {
			return new WP_Error(
				'invalid_connection',
				sprintf(
					/* translators: %s: connection ID */
					__( 'Invalid remote connection ID "%s".', 'mcp-ai-wpoos' ),
					$connection_id
				)
			);
		}

		if ( empty( $connection['enabled'] ) ) {
			return new WP_Error(
				'disabled_connection',
				sprintf(
					/* translators: %s: connection name */
					__( 'Remote connection "%s" is disabled.', 'mcp-ai-wpoos' ),
					isset( $connection['name'] ) ? $connection['name'] : $connection_id
				)
			);
		}

		$result = WP_MCP_AI_Pro_Remote_Site_Manager::make_request( $connection, $endpoint, $method, $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Wrap remote response in success envelope for tool consistency.
		return $this->format_success_response(
			__( 'Remote Paper Store operation completed.', 'mcp-ai-wpoos' ),
			is_array( $result ) ? $result : array( 'response' => $result )
		);
	}

	/**
	 * Get the connection_id parameter schema fragment.
	 *
	 * @since 1.4.0
	 *
	 * @return array Schema for the connection_id parameter.
	 */
	private function get_connection_id_schema() {
		return array(
			'type'        => 'string',
			'description' => __( 'Optional. Remote connection ID from Remote Sites. When provided, the operation runs against the remote WordPress site\'s Paper Store instead of the local one. Call remote_wp_connection with action "list_connections" to discover available connection IDs.', 'mcp-ai-wpoos' ),
		);
	}
}
