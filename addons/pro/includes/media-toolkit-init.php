<?php
/**
 * Media Toolkit Initialization.
 *
 * Loads and initializes the Media Toolkit system for managing
 * reusable graphic editor templates.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load Media Template CPT class.
require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-media-template-cpt.php';

// Initialize Media Toolkit system.
add_action(
	'init',
	function () {
		// Initialize Media Template CPT.
		WP_MCP_AI_Media_Template_CPT::init();
	},
	5
);
