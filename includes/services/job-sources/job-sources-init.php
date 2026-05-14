<?php
/**
 * Job-Sources Init — base-plugin adapter registrations
 *
 * Loads the base-plugin job-source adapter classes and wires each one into
 * the `wp_mcp_ai_cron_status_job_sources` filter so the Tasks Drawer / REST
 * cron-status endpoint discovers them automatically.
 *
 * PR-E follow-on sources (sub-agent dispatcher, durable runs, harness eval)
 * will be added here once their backing classes are available.
 *
 * @package   WP_MCP_AI
 * @since     1.9.3
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/services/job-sources/class-wp-mcp-ai-job-source-transcript-mining.php';
require_once WP_MCP_AI_PATH . 'includes/services/job-sources/class-wp-mcp-ai-job-source-crawl4ai.php';
require_once WP_MCP_AI_PATH . 'includes/services/job-sources/class-wp-mcp-ai-job-source-hitl-approvals.php';

/**
 * Register base-plugin job sources for the cron-status Tasks Drawer.
 *
 * @since 1.9.3
 *
 * @param array $sources Existing slug → source map.
 * @return array
 */
function wp_mcp_ai_register_base_job_sources( array $sources ) {
	$sources['transcript_mining'] = new WP_MCP_AI_Job_Source_Transcript_Mining();
	$sources['crawl4ai']          = new WP_MCP_AI_Job_Source_Crawl4AI();
	$sources['hitl_approvals']    = new WP_MCP_AI_Job_Source_Hitl_Approvals();
	return $sources;
}
add_filter( 'wp_mcp_ai_cron_status_job_sources', 'wp_mcp_ai_register_base_job_sources', 10, 1 );
