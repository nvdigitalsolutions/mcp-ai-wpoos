<?php
/**
 * Resource Manager Initialization
 *
 * Generic orchestration layer for initializing file resource managers.
 * Handles setup for video, audio, image, and document file managers.
 *
 * Part of Phase 2.1: File Management Enhancement (#1288)
 *
 * Follows separation of concerns (SoC) principles:
 * - Centralized cron setup
 * - DRY orchestration logic
 * - Easy addition of new file type managers
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base resource manager.
require_once plugin_dir_path( __FILE__ ) . 'resources/class-wp-mcp-ai-file-resource-manager.php';

// Load file type managers.
require_once plugin_dir_path( __FILE__ ) . 'resources/class-wp-mcp-ai-video-file-manager.php';

/**
 * Initialize all resource managers
 *
 * Creates and initializes resource manager instances for different file types.
 * Each manager handles its own cron setup and lifecycle management.
 *
 * @return array Array of initialized resource manager instances.
 */
function wp_mcp_ai_init_resource_managers() {
	static $managers = null;

	if ( null !== $managers ) {
		return $managers;
	}

	$managers = array();

	// Initialize video file manager.
	$video_manager = new WP_MCP_AI_Video_File_Manager();
	$video_manager->init();
	$managers['video'] = $video_manager;

	/**
	 * Filter resource managers
	 *
	 * Allows plugins to add custom file type managers.
	 *
	 * @param array $managers Array of resource manager instances keyed by type.
	 */
	$managers = apply_filters( 'wp_mcp_ai_resource_managers', $managers );

	return $managers;
}

/**
 * Get video file manager instance
 *
 * @return WP_MCP_AI_Video_File_Manager Video file manager instance.
 */
function wp_mcp_ai_get_video_file_manager() {
	$managers = wp_mcp_ai_init_resource_managers();
	return isset( $managers['video'] ) ? $managers['video'] : null;
}

/**
 * Get resource manager by type
 *
 * @param string $file_type File type (video, audio, image, document).
 * @return WP_MCP_AI_File_Resource_Manager|null Resource manager instance or null.
 */
function wp_mcp_ai_get_resource_manager( $file_type ) {
	$managers = wp_mcp_ai_init_resource_managers();
	return isset( $managers[ $file_type ] ) ? $managers[ $file_type ] : null;
}

/**
 * Get statistics for all resource managers
 *
 * @return array Statistics for each file type manager.
 */
function wp_mcp_ai_get_resource_statistics() {
	$managers   = wp_mcp_ai_init_resource_managers();
	$statistics = array();

	foreach ( $managers as $type => $manager ) {
		$statistics[ $type ] = $manager->get_statistics();
	}

	return $statistics;
}

/**
 * Cleanup all resource managers
 *
 * Runs cleanup on all file type managers.
 * Useful for manual maintenance or testing.
 *
 * @return array Cleanup results for each manager.
 */
function wp_mcp_ai_cleanup_all_resources() {
	$managers = wp_mcp_ai_init_resource_managers();
	$results  = array();

	foreach ( $managers as $type => $manager ) {
		$results[ $type ] = $manager->cleanup_old_files();
	}

	return $results;
}

// Initialize resource managers on plugin load.
add_action( 'plugins_loaded', 'wp_mcp_ai_init_resource_managers', 20 );
