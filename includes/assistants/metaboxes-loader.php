<?php
/**
 * Metaboxes loader for Assistant CPT.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base metabox class.
require_once WP_MCP_AI_PATH . 'includes/assistants/metaboxes/class-wp-mcp-ai-metabox-base.php';

// Load metabox implementations.
require_once WP_MCP_AI_PATH . 'includes/assistants/metaboxes/class-wp-mcp-ai-metabox-credentials.php';
require_once WP_MCP_AI_PATH . 'includes/assistants/metaboxes/class-wp-mcp-ai-metabox-defaults.php';
require_once WP_MCP_AI_PATH . 'includes/assistants/metaboxes/class-wp-mcp-ai-metabox-primary-roles.php';
require_once WP_MCP_AI_PATH . 'includes/assistants/metaboxes/class-wp-mcp-ai-metabox-base-knowledge.php';
require_once WP_MCP_AI_PATH . 'includes/assistants/metaboxes/class-wp-mcp-ai-metabox-mesh-routing.php';
require_once WP_MCP_AI_PATH . 'includes/assistants/metaboxes/class-wp-mcp-ai-metabox-datasets.php';
