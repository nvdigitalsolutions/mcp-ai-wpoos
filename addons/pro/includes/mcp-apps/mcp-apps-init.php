<?php
/**
 * MCP Apps initializer.
 *
 * Bootstraps the MCP Apps subsystem: loads classes, registers REST routes,
 * and hooks into the tool registry for per-assistant remote tool bridging.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load all MCP Apps classes.
 */
$mcp_apps_dir = __DIR__ . '/';

require_once $mcp_apps_dir . 'class-wp-mcp-ai-mcp-app-client.php';
require_once $mcp_apps_dir . 'class-wp-mcp-ai-mcp-app-registry.php';
require_once $mcp_apps_dir . 'class-wp-mcp-ai-mcp-app-tool-bridge.php';
require_once $mcp_apps_dir . 'class-wp-mcp-ai-mcp-app-oauth-client.php';
require_once $mcp_apps_dir . 'class-wp-mcp-ai-rest-mcp-apps-controller.php';

/**
 * Register the MCP Apps REST API routes.
 *
 * @since 1.8.0
 */
function wp_mcp_ai_mcp_apps_register_rest_routes() {
	$controller = new WP_MCP_AI_REST_MCP_Apps_Controller();
	$controller->register_routes();
}
add_action( 'rest_api_init', 'wp_mcp_ai_mcp_apps_register_rest_routes' );

/**
 * Register remote MCP App tools for an assistant during chat requests.
 *
 * Hooks into the tool registry to dynamically add bridged tools
 * from configured MCP Apps when an assistant is being used.
 *
 * @since 1.8.0
 * @param WP_MCP_AI_Tool_Registry $registry Tool registry instance.
 */
function wp_mcp_ai_mcp_apps_register_tools( $registry ) {
	// Only register when we have an assistant context.
	// The assistant ID is typically set via the current request.
	$assistant_id = 0;

	// Try to get assistant_id from the current REST request.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		if ( isset( $_GET['assistant_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, no state change.
			$assistant_id = absint( $_GET['assistant_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( ! $assistant_id && isset( $_POST['assistant_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only, no state change.
			$assistant_id = absint( $_POST['assistant_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		// Check JSON body if available.
		if ( ! $assistant_id ) {
			$raw_body = file_get_contents( 'php://input' );
			if ( $raw_body ) {
				$body_data = json_decode( $raw_body, true );
				if ( isset( $body_data['assistant_id'] ) ) {
					$assistant_id = absint( $body_data['assistant_id'] );
				}
			}
		}
	}

	/**
	 * Filters the assistant ID used for MCP App tool registration.
	 *
	 * Allows other components to provide the assistant ID when it's not
	 * available through the standard request parameters.
	 *
	 * @since 1.8.0
	 * @param int $assistant_id The assistant post ID, 0 if unknown.
	 */
	$assistant_id = apply_filters( 'wp_mcp_ai_mcp_apps_assistant_id', $assistant_id );

	if ( ! $assistant_id ) {
		return;
	}

	$app_registry = WP_MCP_AI_MCP_App_Registry::get_instance();
	$app_registry->register_remote_tools( $assistant_id, $registry );
}
add_action( 'wp_mcp_ai_register_tools', 'wp_mcp_ai_mcp_apps_register_tools', 50 );
