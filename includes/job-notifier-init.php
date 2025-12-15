<?php
/**
 * Initialize job notification system.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load required classes.
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-sse-stream.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-job-notifier-rest.php';

// Initialize job notifier.
WP_MCP_AI_Job_Notifier::init();

// Initialize REST endpoints.
WP_MCP_AI_Job_Notifier_REST::init();

/**
 * Schedule cleanup cron job on init hook.
 *
 * This must run on 'init' or later to ensure WordPress cron functions are available.
 * Running at file level causes fatal errors during plugin activation.
 */
function wp_mcp_ai_schedule_job_notifier_cleanup() {
	if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_job_cache' ) ) {
		wp_schedule_event( time(), 'hourly', 'wp_mcp_ai_cleanup_job_cache' );
	}
}
add_action( 'init', 'wp_mcp_ai_schedule_job_notifier_cleanup' );

add_action( 'wp_mcp_ai_cleanup_job_cache', array( 'WP_MCP_AI_Job_Notifier', 'cleanup_expired_jobs' ) );
