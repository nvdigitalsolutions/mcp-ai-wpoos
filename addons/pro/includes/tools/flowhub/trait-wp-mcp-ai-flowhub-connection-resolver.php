<?php
/**
 * FlowHub Connection Resolver Trait.
 *
 * Provides shared connection resolution logic for all FlowHub tools.
 * When a connection_id is not explicitly provided in the tool arguments,
 * this trait resolves credentials from the FlowHub toolkit settings, the
 * configured sync connections, or the first enabled FlowHub Remote Sites
 * connection — so tools keep working when credentials live only on a
 * Remote Sites connection.
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
 * Trait WP_MCP_AI_FlowHub_Connection_Resolver
 *
 * Provides automatic FlowHub client/CCT resolution across settings and
 * Remote Sites connections.
 *
 * @since 1.2.0
 * @since 1.7.0 Resolves Remote Sites connections (explicit, sync-configured,
 *              or first enabled) when toolkit settings hold no credentials.
 */
trait WP_MCP_AI_FlowHub_Connection_Resolver {

	/**
	 * Resolve the effective FlowHub connection for a tool call.
	 *
	 * Priority: explicit connection_id argument → toolkit settings →
	 * configured sync connections → first enabled FlowHub connection.
	 *
	 * @since 1.7.0
	 *
	 * @param array $arguments Tool arguments (may include connection_id).
	 * @param array $context   Execution context.
	 * @return array|WP_Error Resolved connection array (see
	 *                        WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection)
	 *                        or WP_Error when nothing resolves.
	 */
	protected function resolve_flowhub_connection( $arguments = array(), $context = array() ) {
		unset( $context );

		if ( ! class_exists( 'WP_MCP_AI_FlowHub_Connection_Helper' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-connection-helper.php';
		}

		$explicit = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : null;

		return WP_MCP_AI_FlowHub_Connection_Helper::resolve_connection( $explicit );
	}

	/**
	 * Resolve a FlowHub client instance.
	 *
	 * Uses the Remote Sites connection when one resolves (explicit argument,
	 * sync-configured, or first enabled), otherwise falls back to toolkit
	 * settings credentials.
	 *
	 * @since 1.2.0
	 * @since 1.7.0 Supports Remote Sites connections.
	 *
	 * @param array $arguments Tool arguments (may include connection_id).
	 * @param array $context   Execution context.
	 * @return WP_MCP_AI_FlowHub_Client|WP_Error
	 */
	protected function resolve_flowhub_client( $arguments = array(), $context = array() ) {
		$resolved = $this->resolve_flowhub_connection( $arguments, $context );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		if ( ! class_exists( 'WP_MCP_AI_FlowHub_Client' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
		}

		if ( ! empty( $resolved['connection_id'] ) ) {
			return WP_MCP_AI_FlowHub_Client::from_connection( $resolved['connection_id'] );
		}

		return WP_MCP_AI_FlowHub_Client::from_settings();
	}

	/**
	 * Get a CCT manager instance scoped to the resolved connection.
	 *
	 * When a Remote Sites connection resolves, the manager is bound to that
	 * connection so sync/refresh operations use the connection credentials
	 * and per-connection freshness keys.
	 *
	 * @since 1.2.0
	 * @since 1.7.0 Accepts tool arguments and scopes to the resolved connection.
	 *
	 * @param array $arguments Tool arguments (may include connection_id).
	 * @return WP_MCP_AI_FlowHub_CCT_Manager
	 */
	protected function get_flowhub_cct_manager( $arguments = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_CCT_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-cct-manager.php';
		}

		$resolved      = $this->resolve_flowhub_connection( $arguments );
		$connection_id = ( ! is_wp_error( $resolved ) && ! empty( $resolved['connection_id'] ) )
			? $resolved['connection_id']
			: null;

		return new WP_MCP_AI_FlowHub_CCT_Manager( $connection_id );
	}

	/**
	 * Check if FlowHub toolkit is properly configured.
	 *
	 * @since 1.2.0
	 * @since 1.7.0 Also true when a usable Remote Sites connection exists.
	 *
	 * @return bool True if settings credentials or a usable connection exist.
	 */
	protected function is_flowhub_configured() {
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_Connection_Helper' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-flowhub-connection-helper.php';
		}

		return WP_MCP_AI_FlowHub_Connection_Helper::is_configured();
	}

	/**
	 * Check if the required dependencies are available.
	 *
	 * @since 1.2.0
	 * @since 1.7.0 Accepts tool arguments so the credential gate considers
	 *              Remote Sites connections.
	 *
	 * @param array $arguments Tool arguments (may include connection_id).
	 * @param array $context   Execution context.
	 * @return true|WP_Error True if ok, WP_Error if a dependency is missing.
	 */
	protected function check_flowhub_dependencies( $arguments = array(), $context = array() ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error(
				'wp_mcp_ai_flowhub_no_woocommerce',
				__( 'WooCommerce is required for the FlowHub Toolkit.', 'mcp-ai-wpoos-pro' )
			);
		}

		$resolved = $this->resolve_flowhub_connection( $arguments, $context );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		return true;
	}
}
