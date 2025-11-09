<?php
/**
 * Initialize Gutenberg blocks for WP oOS.
 *
 * Loads all block registration files.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load block classes.
require_once WP_MCP_AI_PATH . 'includes/blocks/class-wp-mcp-ai-performance-blocks.php';
require_once WP_MCP_AI_PATH . 'includes/blocks/class-wp-mcp-ai-chat-blocks.php';
require_once WP_MCP_AI_PATH . 'includes/blocks/class-wp-mcp-ai-assistant-blocks.php';
require_once WP_MCP_AI_PATH . 'includes/blocks/class-wp-mcp-ai-dashboard-blocks.php';
