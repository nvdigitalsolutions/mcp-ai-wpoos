<?php
/**
 * Initialize the chat continuation subsystem.
 *
 * Wires up `WP_MCP_AI_Chat_Continuation_Store` and
 * `WP_MCP_AI_Chat_Continuation_Dispatcher`. See
 * `docs/features/chat/async-continuation.md` for the full architecture.
 *
 * @package WP_MCP_AI
 * @since   1.9.4
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-chat-continuation-store.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-chat-continuation-dispatcher.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-chat-session-frame-buffer.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-chat-continuation-llm-re-entry.php';

// Register cron + completion handlers.
WP_MCP_AI_Chat_Continuation_Dispatcher::init();

// Register LLM re-entry listener (hooks onto wp_mcp_ai_chat_continuation_ready).
WP_MCP_AI_Chat_Continuation_LLM_Re_Entry::init();
