<?php
/**
 * Initialize video file management system.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load required classes.
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-video-file-manager.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-file-service.php';

// Schedule cleanup cron job (runs twice daily).
if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_video_files' ) ) {
	wp_schedule_event( time(), 'twicedaily', 'wp_mcp_ai_cleanup_video_files' );
}

/**
 * Cleanup expired video files from Gemini API.
 *
 * This function is called by the cron job to clean up video files
 * that have expired (older than 48 hours by default).
 *
 * @return void
 */
function wp_mcp_ai_cleanup_video_files_handler() {
	$file_service = new WP_MCP_AI_Gemini_File_Service();
	$file_manager = new WP_MCP_AI_Video_File_Manager( $file_service );

	$results = $file_manager->cleanup_expired_files();

	// Log cleanup results.
	if ( $results['deleted'] > 0 || $results['failed'] > 0 ) {
		WP_MCP_AI_Logger::log_event(
			'video_file_cleanup_cron',
			'Scheduled video file cleanup completed.',
			array(
				'deleted' => $results['deleted'],
				'failed'  => $results['failed'],
				'total'   => $results['total_checked'],
			)
		);
	}
}

add_action( 'wp_mcp_ai_cleanup_video_files', 'wp_mcp_ai_cleanup_video_files_handler' );
