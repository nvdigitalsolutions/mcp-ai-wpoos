<?php
/**
 * Mailjet Integration Initialization
 *
 * Loads and registers the Mailjet webhook handler.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the Mailjet webhook handler class.
if ( ! class_exists( 'WP_MCP_AI_Mailjet_Webhook_Handler' ) ) {
	require_once __DIR__ . '/class-wp-mcp-ai-mailjet-webhook-handler.php';
}

// Load the Mailjet OAuth handler class.
if ( ! class_exists( 'WP_MCP_AI_Mailjet_OAuth_Handler' ) ) {
	require_once __DIR__ . '/class-wp-mcp-ai-mailjet-oauth-handler.php';
}

// Allow the Mailjet authorize host so wp_safe_redirect() reaches the
// consent page instead of falling back to admin_url().
add_filter(
	'allowed_redirect_hosts',
	array( new WP_MCP_AI_Mailjet_OAuth_Handler(), 'allow_mailjet_oauth_redirect_host' ),
	10,
	2
);

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
