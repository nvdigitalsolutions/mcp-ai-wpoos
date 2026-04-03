<?php
/**
 * Backward-compatible loader for the assistant custom post type class.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load metaboxes first.
require_once WP_MCP_AI_PATH . 'includes/assistants/metaboxes-loader.php';

// Load the CPT class.
require_once WP_MCP_AI_PATH . 'includes/assistants/class-wp-mcp-ai-assistant-cpt.php';
