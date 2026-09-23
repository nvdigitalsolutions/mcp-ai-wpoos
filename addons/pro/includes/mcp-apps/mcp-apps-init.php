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
 * the effective tool list that build_tools_payload() sends to the LLM — and
 * registers the bridge tools in the local registry so downstream lookups
 * (payload building, tool execution, list_mcp_tools) can resolve them.
 * Capability gating is still enforced per tool downstream.
 *
 * The base plugin applies the same seam in handle_tools_list(),
 * handle_tool_request(), and execute_tool_call_internal(), each passing the
 * resolved assistant ID explicitly.
 *
 * @since 1.9.2
 * @since 1.9.4 Added $assistant_id parameter and bridge registration.
 * @param array $slugs            Effective tool slugs.
 * @param array $assistant_config Assistant configuration.
 * @param int   $assistant_id     Resolved assistant post ID (0 when unknown).
 * @return array Effective tool slugs including bridged MCP App tools.
 */
function wp_mcp_ai_mcp_apps_expose_tools( $slugs, $assistant_config, $assistant_id = 0 ) {
	if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
		return $slugs;
	}

	$slugs = is_array( $slugs ) ? $slugs : array();

	// Prefer the explicit assistant ID passed by the call site, then the
	// config keys, then the request context. The raw-body fallback only works
	// before WP_REST_Request consumes php://input, so it is a last resort.
	$assistant_id = absint( $assistant_id );

	if ( ! $assistant_id ) {
		if ( isset( $assistant_config['ID'] ) ) {
			$assistant_id = absint( $assistant_config['ID'] );
		} elseif ( isset( $assistant_config['id'] ) ) {
			$assistant_id = absint( $assistant_config['id'] );
		}
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

	// Register the bridged tools in the local registry. The bootstrap-time
	// registration attempt (wp_mcp_ai_mcp_apps_register_tools) always bails:
	// it fires before REST_REQUEST exists, so it never sees the assistant ID.
	// Without registration here, build_tools_payload() and the execution paths
	// skip the appended slugs as "missing tool". Discovery is transient-cached
	// (and failures are negatively cached), so repeat calls are cheap.
	$app_registry->register_remote_tools( $assistant_id, WP_MCP_AI_Tool_Registry::get_instance() );

	return array_values( array_unique( array_merge( $slugs, $bridged ) ) );
}
add_filter( 'wp_mcp_ai_chat_effective_tools', 'wp_mcp_ai_mcp_apps_expose_tools', 10, 3 );

/**
 * Register bridged MCP App tools before REST argument validation runs.
 *
 * The POST /tools route validates the requested slug against the registry
 * before the handler runs, so bridge tools must be registered earlier than
 * the effective-tools seam can (the seam runs inside the handler). This hook
 * only covers the timing gap for that one surface; the allow-list gate still
 * goes through the effective-tools filter in handle_tool_request().
 *
 * @since 1.9.4
 * @param mixed           $result  Pre-dispatch result.
 * @param WP_REST_Server  $server  REST server instance.
 * @param WP_REST_Request $request Current REST request.
 * @return mixed Pass-through result.
 */
function wp_mcp_ai_mcp_apps_rest_pre_dispatch( $result, $server, $request ) {
	if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) || ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
		return $result;
	}

	$namespace = class_exists( 'WP_MCP_AI_REST' ) ? WP_MCP_AI_REST::REST_NAMESPACE : 'mcp-ai/v1';

	if ( 0 !== strpos( $request->get_route(), '/' . $namespace . '/tools' ) ) {
		return $result;
	}

	$assistant_id = absint( $request->get_param( 'assistant_id' ) );
	if ( ! $assistant_id ) {
		return $result;
	}

	WP_MCP_AI_MCP_App_Registry::get_instance()->register_remote_tools(
		$assistant_id,
		WP_MCP_AI_Tool_Registry::get_instance()
	);

	return $result;
}
add_filter( 'rest_pre_dispatch', 'wp_mcp_ai_mcp_apps_rest_pre_dispatch', 10, 3 );
