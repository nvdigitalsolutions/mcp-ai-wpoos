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
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the Google Chat webhook handler class.
if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Handler' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/src/ChatChannels/class-wp-mcp-ai-google-chat-webhook-handler.php';
}

// Register webhook REST API routes on rest_api_init only when the full-featured
// WP_MCP_AI_Google_Chat_Webhook_Controller is NOT available. The controller
// supersedes this legacy handler: it registers the same routes with OIDC security,
// connection-specific routing, conversation history, and async AI-reply scheduling.
// Registering both for the same route causes WordPress to use the first-registered
// handler (this legacy one), which does not schedule AI replies and results in the
// bot silently not responding to messages.
add_action(
	'rest_api_init',
	function () {
		if ( class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Controller' ) ) {
			// Full controller already handles all Google Chat webhook routes.
			return;
		}
		$handler = new WP_MCP_AI_Google_Chat_Webhook_Handler();
		$handler->register_routes();
	}
);
