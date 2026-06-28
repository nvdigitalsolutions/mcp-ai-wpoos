<?php
/**
 * FlowHub Connection Resolver Trait.
 *
 * Provides shared connection resolution logic for all FlowHub tools.
 * When a connection_id is not explicitly provided in the tool arguments,
 * this trait resolves credentials from the FlowHub toolkit settings.
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
 * Provides automatic FlowHub client resolution from toolkit settings.
 *
 * @since 1.2.0
 */
trait WP_MCP_AI_FlowHub_Connection_Resolver {

	/**
	 * Resolve a FlowHub client instance from toolkit settings.
	 *
	 * If credentials are not configured, returns a WP_Error with an
	 * actionable message.
	 *
	 * @since 1.2.0
	 *
	 * @param array $arguments        Tool arguments (unused; reserved for future multi-connection).
	 * @param array $context          Execution context (unused; reserved for future capabilities).
	 * @return WP_MCP_AI_FlowHub_Client|WP_Error
	 */
	protected function resolve_flowhub_client( $arguments = array(), $context = array() ) {
		unset( $arguments, $context ); // Reserved for future multi-connection support.
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_Client' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-client.php';
		}

		return WP_MCP_AI_FlowHub_Client::from_settings();
	}

	/**
	 * Get a CCT manager instance.
	 *
	 * @since 1.2.0
	 *
	 * @return WP_MCP_AI_FlowHub_CCT_Manager
	 */
	protected function get_flowhub_cct_manager() {
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_CCT_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-flowhub-cct-manager.php';
		}

		return new WP_MCP_AI_FlowHub_CCT_Manager();
	}

	/**
	 * Check if FlowHub toolkit is properly configured.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if credentials exist.
	 */
	protected function is_flowhub_configured() {
		$settings  = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
		$client_id = isset( $settings['client_id'] ) ? trim( $settings['client_id'] ) : '';
		$api_key   = isset( $settings['api_key'] ) ? trim( $settings['api_key'] ) : '';

		return ! empty( $client_id ) && ! empty( $api_key );
	}

	/**
	 * Check if the required dependencies are active.
	 *
	 * @since 1.2.0
	 *
	 * @return true|WP_Error True if ok, WP_Error if a dependency is missing.
	 */
	protected function check_flowhub_dependencies() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error(
				'wp_mcp_ai_flowhub_no_woocommerce',
				__( 'WooCommerce is required for the FlowHub Toolkit.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! $this->is_flowhub_configured() ) {
			return new WP_Error(
				'wp_mcp_ai_flowhub_not_configured',
				__( 'FlowHub API credentials are not configured. Please set up your client ID and API key in the FlowHub Toolkit settings.', 'mcp-ai-wpoos-pro' )
			);
		}

		return true;
	}
}
