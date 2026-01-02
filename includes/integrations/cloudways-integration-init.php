<?php
/**
 * Cloudways Integration Initialization
 *
 * Loads and registers the Cloudways OAuth handler.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the Cloudways OAuth handler class.
if ( ! class_exists( 'WP_MCP_AI_Cloudways_OAuth_Handler' ) ) {
	require_once __DIR__ . '/class-wp-mcp-ai-cloudways-oauth-handler.php';
}

// Register the Cloudways OAuth handler with the container.
add_action(
	'wp_mcp_ai_register_services',
	function( $container ) {
		if ( ! $container->has( 'integrations.cloudways_oauth' ) ) {
			$container->singleton(
				'integrations.cloudways_oauth',
				function() {
					return new WP_MCP_AI_Cloudways_OAuth_Handler();
				}
			);
		}
	},
	10
);

// Register OAuth action hooks.
add_action(
	'admin_init',
	function() {
		$container = wp_mcp_ai_container();

		if ( ! $container->has( 'integrations.cloudways_oauth' ) ) {
			return;
		}

		$handler = $container->get( 'integrations.cloudways_oauth' );

		// Register Cloudways OAuth handlers.
		add_action( 'admin_post_wp_mcp_ai_cloudways_connect', array( $handler, 'handle_cloudways_connect' ) );
		add_action( 'admin_post_wp_mcp_ai_cloudways_disconnect', array( $handler, 'handle_cloudways_disconnect' ) );
	}
);
