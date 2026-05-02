<?php
/**
 * Markup subsystem bootstrap.
 *
 * Loads the markup subsystem classes, wires the agentic-loop interceptor,
 * registers the REST controller, and schedules the cleanup cron event.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/markup/interface-wp-mcp-ai-markup-aware-tool.php';
require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-request.php';
require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-result.php';
require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-elicitation.php';
require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-store.php';
require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-validator.php';
require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-rasterizer.php';
require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-loop-interceptor.php';
require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-rest-controller.php';
require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-assets.php';
require_once WP_MCP_AI_PATH . 'includes/markup/class-wp-mcp-ai-markup-admin-page.php';

/**
 * Wire the markup subsystem hooks.
 *
 * The interceptor is registered immediately so it can short-circuit any
 * tool execution that requests markup. The REST routes are deferred to
 * the standard `rest_api_init` hook.
 */
add_action(
	'plugins_loaded',
	static function () {
		$interceptor = new WP_MCP_AI_Markup_Loop_Interceptor();
		$interceptor->register();
	},
	20
);

add_action(
	'rest_api_init',
	static function () {
		$controller = new WP_MCP_AI_Markup_REST_Controller();
		$controller->register_routes();
	}
);

/**
 * Register markup assets early so chat surfaces and the admin fallback
 * page can enqueue them on demand.
 */
add_action(
	'init',
	static function () {
		if ( class_exists( 'WP_MCP_AI_Markup_Assets' ) && function_exists( 'wp_register_script' ) ) {
			WP_MCP_AI_Markup_Assets::register();
		}
	},
	5
);

/**
 * Auto-enqueue the chat client integration shim alongside the main
 * chat bundle so SSE `markup_elicitation` events render the canvas
 * widget without modifications to the chat bundle.
 */
add_action(
	'wp_enqueue_scripts',
	static function () {
		if ( ! class_exists( 'WP_MCP_AI_Markup_Loop_Interceptor' ) ||
			! WP_MCP_AI_Markup_Loop_Interceptor::is_enabled() ) {
			return;
		}
		$chat_handle = defined( 'WP_MCP_AI_REST::REST_NAMESPACE' ) ? 'wp-mcp-ai-chat' : 'wp-mcp-ai-chat';
		if ( wp_script_is( $chat_handle, 'enqueued' ) ||
			wp_script_is( $chat_handle, 'registered' ) ) {
			WP_MCP_AI_Markup_Assets::enqueue_widget();
		}
	},
	20
);

/**
 * Mount the admin fallback page (used by URL-mode elicitation).
 */
add_action(
	'init',
	static function () {
		if ( ! is_admin() ) {
			return;
		}
		$page = new WP_MCP_AI_Markup_Admin_Page();
		$page->register();
	},
	30
);

/**
 * Daily cleanup of expired markup transients and orphan mask attachments.
 */
add_action(
	'init',
	static function () {
		if ( ! wp_next_scheduled( 'wp_mcp_ai_markup_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wp_mcp_ai_markup_cleanup' );
		}
	}
);

add_action(
	'wp_mcp_ai_markup_cleanup',
	static function () {
		$store = new WP_MCP_AI_Markup_Store();
		$store->cleanup_expired();
	}
);
