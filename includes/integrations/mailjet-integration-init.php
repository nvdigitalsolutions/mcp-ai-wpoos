<?php
/**
 * Mailjet Integration Initialization
 *
 * Loads and registers the Mailjet webhook handler.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the Mailjet webhook handler class.
if ( ! class_exists( 'WP_MCP_AI_Mailjet_Webhook_Handler' ) ) {
	require_once __DIR__ . '/class-wp-mcp-ai-mailjet-webhook-handler.php';
}

// Register the Mailjet webhook handler with the container.
add_action(
	'wp_mcp_ai_register_services',
	function ( $container ) {
		if ( ! $container->has( 'integrations.mailjet_webhook' ) ) {
			$container->singleton(
				'integrations.mailjet_webhook',
				function () {
					return new WP_MCP_AI_Mailjet_Webhook_Handler();
				}
			);
		}
	},
	10
);

// Register webhook REST API routes.
add_action(
	'rest_api_init',
	function () {
		$container = wp_mcp_ai_container();

		if ( ! $container->has( 'integrations.mailjet_webhook' ) ) {
			return;
		}

		$handler = $container->get( 'integrations.mailjet_webhook' );
		$handler->register_routes();
	}
);
