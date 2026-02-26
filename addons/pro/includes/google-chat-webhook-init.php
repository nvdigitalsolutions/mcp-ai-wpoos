<?php
/**
 * Google Chat Webhook Integration Initialization
 *
 * Loads and registers the Google Chat incoming webhook handler so that
 * the bot can respond to MESSAGE events (DMs and @mentions in Spaces),
 * ADDED_TO_SPACE, and REMOVED_FROM_SPACE lifecycle events.
 *
 * The REST endpoint registered is:
 *   POST /wp-json/mcp-ai/v1/webhooks/google-chat
 *
 * Configure this URL in Google Cloud Console → Google Chat API → Configuration
 * as the App URL (HTTP endpoint) for your Chat app.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the Google Chat webhook handler class.
if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Handler' ) ) {
	require_once __DIR__ . '/src/ChatChannels/class-wp-mcp-ai-google-chat-webhook-handler.php';
}

// Register webhook REST API routes on rest_api_init.
add_action(
	'rest_api_init',
	function () {
		$handler = new WP_MCP_AI_Google_Chat_Webhook_Handler();
		$handler->register_routes();
	}
);
