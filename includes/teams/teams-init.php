<?php
/**
 * Team System Initialization.
 *
 * Loads and initializes the team management system.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load team class.
require_once WP_MCP_AI_PATH . 'includes/teams/class-wp-mcp-ai-team-cpt.php';

// Initialize team system.
add_action(
	'init',
	function() {
		// Initialize CPT.
		new WP_MCP_AI_Team_CPT();
	},
	5
);
