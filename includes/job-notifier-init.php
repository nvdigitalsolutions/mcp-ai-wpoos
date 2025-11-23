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

// Schedule cleanup cron job.
// Schedule 2 minutes in the future to account for any delays.
if ( ! wp_next_scheduled( 'wp_mcp_ai_cleanup_job_cache' ) ) {
	wp_schedule_event( time() + ( 2 * MINUTE_IN_SECONDS ), 'hourly', 'wp_mcp_ai_cleanup_job_cache' );
}

add_action( 'wp_mcp_ai_cleanup_job_cache', array( 'WP_MCP_AI_Job_Notifier', 'cleanup_expired_jobs' ) );
