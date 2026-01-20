<?php
/**
 * QuickBooks Integration Initialization
 *
 * Loads and registers the QuickBooks OAuth handler.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the QuickBooks OAuth handler class.
if ( ! class_exists( 'WP_MCP_AI_QuickBooks_OAuth_Handler' ) ) {
	require_once __DIR__ . '/class-wp-mcp-ai-quickbooks-oauth-handler.php';
}

// Register the QuickBooks OAuth handler with the container.
add_action(
	'wp_mcp_ai_register_services',
	function ( $container ) {
		if ( ! $container->has( 'integrations.quickbooks_oauth' ) ) {
			$container->singleton(
				'integrations.quickbooks_oauth',
				function () {
					return new WP_MCP_AI_QuickBooks_OAuth_Handler();
				}
			);
		}
	},
	10
);

// Register OAuth action hooks.
add_action(
	'admin_init',
	function () {
		$container = wp_mcp_ai_container();

		if ( ! $container->has( 'integrations.quickbooks_oauth' ) ) {
			return;
		}

		$handler = $container->get( 'integrations.quickbooks_oauth' );

		// Register QuickBooks OAuth handlers.
		add_action( 'admin_post_wp_mcp_ai_quickbooks_oauth_start', array( $handler, 'handle_quickbooks_oauth_start' ) );
		add_action( 'admin_post_wp_mcp_ai_quickbooks_oauth_callback', array( $handler, 'handle_quickbooks_oauth_callback' ) );
		add_action( 'admin_post_wp_mcp_ai_quickbooks_disconnect', array( $handler, 'handle_quickbooks_disconnect' ) );
	}
);

// Display admin notices for QuickBooks OAuth actions.
add_action(
	'admin_notices',
	function () {
		$notice = get_transient( 'wp_mcp_ai_quickbooks_oauth_notice' );

		if ( ! $notice || ! is_array( $notice ) ) {
			return;
		}

		delete_transient( 'wp_mcp_ai_quickbooks_oauth_notice' );

		$type    = isset( $notice['type'] ) ? sanitize_key( $notice['type'] ) : 'info';
		$message = isset( $notice['message'] ) ? $notice['message'] : '';

		if ( empty( $message ) ) {
			return;
		}

		$class = 'notice notice-' . $type . ' is-dismissible';
		printf(
			'<div class="%s"><p>%s</p></div>',
			esc_attr( $class ),
			wp_kses_post( $message )
		);
	}
);
