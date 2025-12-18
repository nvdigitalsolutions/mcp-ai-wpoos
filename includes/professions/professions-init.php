<?php
/**
 * Profession System Initialization.
 *
 * Loads and initializes the profession management system.
 * Follows separation of concerns pattern.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load profession classes.
require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-profession-repository.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-profession-service.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-profession-knowledge-base-loader.php';
require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-seeder.php';
require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-base-knowledge-seeder.php';

// Initialize profession system.
add_action(
	'init',
	function () {
		// Initialize CPT.
		new WP_MCP_AI_Profession_CPT();

		// Initialize seeder (only runs once).
		WP_MCP_AI_Profession_Seeder::init();

		// Initialize base knowledge seeder (runs after profession seeding).
		WP_MCP_AI_Profession_Base_Knowledge_Seeder::init();
	},
	5
);

// Clear profession cache when professions are saved/deleted.
add_action(
	'save_post_' . WP_MCP_AI_Profession_CPT::POST_TYPE,
	function ( $post_id ) {
		$repository = new WP_MCP_AI_Profession_Repository();
		$repository->clear_cache( $post_id );
	},
	10,
	1
);

add_action(
	'delete_post',
	function ( $post_id ) {
		$post = get_post( $post_id );
		if ( $post && WP_MCP_AI_Profession_CPT::POST_TYPE === $post->post_type ) {
			$repository = new WP_MCP_AI_Profession_Repository();
			$repository->clear_cache( $post_id );
		}
	},
	10,
	1
);

/**
 * Get profession service instance.
 *
 * Helper function to access the profession service.
 *
 * @return WP_MCP_AI_Profession_Service
 */
function wp_mcp_ai_get_profession_service() {
	static $service = null;

	if ( null === $service ) {
		$repository = new WP_MCP_AI_Profession_Repository();
		$service    = new WP_MCP_AI_Profession_Service( $repository );
	}

	return $service;
}
