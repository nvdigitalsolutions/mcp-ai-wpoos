<?php
/**
 * Shopify Connection Resolver Trait.
 *
 * Provides shared connection resolution logic for all Shopify tools.
 * When a connection_id is not explicitly provided in the tool arguments,
 * this trait automatically resolves it from the assistant's enabled
 * remote connections, eliminating the need for the AI to ask the user
 * for a connection ID.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Shopify_Connection_Resolver
 *
 * Provides automatic Shopify connection resolution from assistant context.
 *
 * @since 1.0.0
 */
trait WP_MCP_AI_Shopify_Connection_Resolver {

	/**
	 * Resolve a Shopify connection ID from arguments or assistant context.
	 *
	 * If the connection_id is provided in arguments, it is used directly.
	 * Otherwise, the method checks the assistant's enabled remote connections
	 * and automatically selects the appropriate Shopify connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array       $arguments        Tool arguments.
	 * @param array       $context          Execution context (must include assistant_id).
	 * @param string|null $required_api_mode Optional API mode requirement (e.g., 'catalog_api').
	 * @return string|WP_Error Connection ID or WP_Error if none found.
	 */
	protected function resolve_shopify_connection_id( $arguments, $context, $required_api_mode = null ) {
		// 1. If connection_id is explicitly provided, use it.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';

		if ( ! empty( $connection_id ) ) {
			return $connection_id;
		}

		// 2. Try to resolve from assistant context.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_no_manager',
				__( 'Remote Sites Manager is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		$shopify_connections = $this->get_available_shopify_connections( $context, $required_api_mode );

		if ( empty( $shopify_connections ) ) {
			return new WP_Error(
				'wp_mcp_ai_shopify_missing_connection',
				__( 'No Shopify connections are configured or enabled for this assistant. Please configure a Shopify connection in NV oOS → Remote Sites and enable it for this assistant.', 'mcp-ai-wpoos-pro' )
			);
		}

		// 3. If exactly one Shopify connection is available, use it automatically.
		if ( 1 === count( $shopify_connections ) ) {
			return $shopify_connections[0]['id'];
		}

		// 4. Multiple Shopify connections found — return an error listing them.
		$connection_list = $this->format_available_connections_message( $shopify_connections );

		return new WP_Error(
			'wp_mcp_ai_shopify_missing_connection',
			sprintf(
				/* translators: %s: list of available connections */
				__( 'Multiple Shopify connections are available. Please specify a connection_id.%s', 'mcp-ai-wpoos-pro' ),
				$connection_list
			)
		);
	}

	/**
	 * Get available Shopify connections for the current assistant.
	 *
	 * Filters connections by:
	 * - Connection type: 'shopify'
	 * - Enabled globally
	 * - Enabled for the assistant (if assistant context is provided)
	 * - Optional API mode filter
	 *
	 * @since 1.0.0
	 *
	 * @param array       $context          Execution context.
	 * @param string|null $required_api_mode Optional API mode filter (e.g., 'catalog_api', 'admin_api').
	 * @return array Array of matching Shopify connections with id, name, url, api_mode keys.
	 */
	protected function get_available_shopify_connections( $context = array(), $required_api_mode = null ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return array();
		}

		$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		$enabled_for_assistant = array();
		if ( $assistant_id ) {
			$enabled_for_assistant = get_post_meta( $assistant_id, '_wp_mcp_ai_pro_remote_connections', true );
			if ( ! is_array( $enabled_for_assistant ) ) {
				$enabled_for_assistant = array();
			}
		}

		$shopify_connections = array();

		foreach ( $all_connections as $connection ) {
			// Must be a Shopify connection.
			if ( empty( $connection['connection_type'] ) || 'shopify' !== $connection['connection_type'] ) {
				continue;
			}

			// Must be enabled globally.
			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			// If assistant context is provided and connections are configured,
			// only include connections enabled for this assistant.
			if ( $assistant_id && ! empty( $enabled_for_assistant ) && ! in_array( $connection['id'], $enabled_for_assistant, true ) ) {
				continue;
			}

			// If a specific API mode is required, filter by it.
			if ( null !== $required_api_mode ) {
				$api_mode = isset( $connection['shopify_api_mode'] ) ? $connection['shopify_api_mode'] : 'admin_api';
				if ( $required_api_mode !== $api_mode ) {
					continue;
				}
			}

			$shopify_connections[] = array(
				'id'       => $connection['id'],
				'name'     => isset( $connection['name'] ) ? $connection['name'] : '',
				'url'      => isset( $connection['url'] ) ? $connection['url'] : '',
				'api_mode' => isset( $connection['shopify_api_mode'] ) ? $connection['shopify_api_mode'] : 'admin_api',
			);
		}

		return $shopify_connections;
	}

	/**
	 * Format a list of available Shopify connections for error messages.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connections Array of connection data arrays.
	 * @return string Formatted connection list string.
	 */
	protected function format_available_connections_message( $connections ) {
		if ( empty( $connections ) ) {
			return '';
		}

		$formatted = array();
		foreach ( $connections as $conn ) {
			$label = sprintf( '%s (%s)', $conn['name'], $conn['id'] );
			if ( ! empty( $conn['url'] ) ) {
				$label .= sprintf( ' — %s', $conn['url'] );
			}
			$formatted[] = $label;
		}

		return ' Available Shopify connections: ' . implode( ', ', $formatted ) . '.';
	}

	/**
	 * Check if a Shopify connection is enabled for the current assistant.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @param array  $context       Execution context.
	 * @return bool True if enabled, false otherwise.
	 */
	protected function is_shopify_connection_enabled_for_assistant( $connection_id, $context ) {
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;

		if ( ! $assistant_id ) {
			return true;
		}

		$enabled_connections = get_post_meta( $assistant_id, '_wp_mcp_ai_pro_remote_connections', true );

		if ( ! is_array( $enabled_connections ) ) {
			return true;
		}

		return in_array( $connection_id, $enabled_connections, true );
	}
}
