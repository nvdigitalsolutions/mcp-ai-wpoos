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

/**
 * Expose bridged MCP App tools in the chat tools payload.
 *
 * Bridged tools are registered dynamically during the chat request and
 * therefore never appear in the assistant's Tools metabox selection. This
 * filter appends the bridge slugs registered for the current assistant to
 * the effective tool list that build_tools_payload() sends to the LLM.
 * Capability gating is still enforced per tool downstream.
 *
 * @since 1.9.2
 * @param array $slugs            Effective tool slugs.
 * @param array $assistant_config Assistant configuration.
 * @return array Effective tool slugs including bridged MCP App tools.
 */
function wp_mcp_ai_mcp_apps_expose_tools( $slugs, $assistant_config ) {
	if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
		return $slugs;
	}

	$slugs = is_array( $slugs ) ? $slugs : array();

	$assistant_id = 0;

	// Prefer an assistant ID carried on the config, then the request context.
	if ( isset( $assistant_config['ID'] ) ) {
		$assistant_id = absint( $assistant_config['ID'] );
	} elseif ( isset( $assistant_config['id'] ) ) {
		$assistant_id = absint( $assistant_config['id'] );
	}

	if ( ! $assistant_id && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		if ( isset( $_POST['assistant_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only, no state change.
			$assistant_id = absint( $_POST['assistant_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

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
	 * Filters the assistant ID used for MCP App tool exposure.
	 *
	 * @since 1.9.2
	 * @param int $assistant_id The assistant post ID, 0 if unknown.
	 */
	$assistant_id = absint( apply_filters( 'wp_mcp_ai_mcp_apps_assistant_id', $assistant_id ) );

	if ( ! $assistant_id ) {
		return $slugs;
	}

	$app_registry = WP_MCP_AI_MCP_App_Registry::get_instance();
	$bridged      = $app_registry->get_remote_tool_slugs( $assistant_id );

	if ( empty( $bridged ) ) {
		return $slugs;
	}

	return array_values( array_unique( array_merge( $slugs, $bridged ) ) );
}
add_filter( 'wp_mcp_ai_chat_effective_tools', 'wp_mcp_ai_mcp_apps_expose_tools', 10, 2 );
