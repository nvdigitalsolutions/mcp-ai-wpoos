<?php
/**
 * google-chat-webhook-init.php (ecosystem port — Wave F5, chat-channels data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/google-chat-webhook-init.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the Google Chat webhook handler class.
if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Handler' ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/src/ChatChannels/class-wp-mcp-ai-google-chat-webhook-handler.php';
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
