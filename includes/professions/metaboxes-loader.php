<?php
/**
 * Metaboxes loader for Profession CPT.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base metabox class.
require_once WP_MCP_AI_PATH . 'includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-base.php';

// Load metabox implementations.
require_once WP_MCP_AI_PATH . 'includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-details.php';
require_once WP_MCP_AI_PATH . 'includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-expertise.php';
require_once WP_MCP_AI_PATH . 'includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-base-knowledge.php';
require_once WP_MCP_AI_PATH . 'includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-defaults.php';
require_once WP_MCP_AI_PATH . 'includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-playbook.php';
require_once WP_MCP_AI_PATH . 'includes/professions/metaboxes/class-wp-mcp-ai-profession-metabox-datasets.php';
