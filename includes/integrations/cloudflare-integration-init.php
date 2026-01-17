<?php
/**
 * Cloudflare Integration Initialization
 *
 * Loads and registers the Cloudflare connection handler.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the Cloudflare connection handler class.
if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Connection_Handler' ) ) {
	require_once __DIR__ . '/class-wp-mcp-ai-cloudflare-connection-handler.php';
}

// Register the Cloudflare connection handler with the container.
add_action(
	'wp_mcp_ai_register_services',
	function ( $container ) {
		if ( ! $container->has( 'integrations.cloudflare_connection' ) ) {
			$container->singleton(
				'integrations.cloudflare_connection',
				function () {
					return new WP_MCP_AI_Cloudflare_Connection_Handler();
				}
			);
		}
	},
	10
);

// Register connection test action hooks.
add_action(
	'admin_init',
	function () {
		$container = wp_mcp_ai_container();

		if ( ! $container->has( 'integrations.cloudflare_connection' ) ) {
			return;
		}

		$handler = $container->get( 'integrations.cloudflare_connection' );

		// Register Cloudflare connection test handlers.
		add_action( 'admin_post_wp_mcp_ai_cloudflare_test_connection', array( $handler, 'handle_cloudflare_test_connection' ) );
		add_action( 'admin_post_wp_mcp_ai_cloudflare_disconnect', array( $handler, 'handle_cloudflare_disconnect' ) );
	}
);

// Display admin notices for Cloudflare connection actions.
add_action(
	'admin_notices',
	function () {
		$notice = get_transient( 'wp_mcp_ai_cloudflare_connection_notice' );

		if ( ! $notice || ! is_array( $notice ) ) {
			return;
		}

		delete_transient( 'wp_mcp_ai_cloudflare_connection_notice' );

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
