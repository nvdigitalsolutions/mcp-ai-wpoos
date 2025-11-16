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

// Load team classes.
require_once WP_MCP_AI_PATH . 'includes/teams/class-wp-mcp-ai-team-cpt.php';
require_once WP_MCP_AI_PATH . 'includes/teams/class-wp-mcp-ai-team-seeder.php';

// Initialize team system.
add_action(
	'init',
	function () {
		// Initialize CPT.
		new WP_MCP_AI_Team_CPT();
		
		// Initialize seeder.
		WP_MCP_AI_Team_Seeder::init();
	},
	5
);
