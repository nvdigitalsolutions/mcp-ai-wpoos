<?php
/**
 * Pro Phase 6 services bootstrap.
 *
 * Loads the vector-store adapter and per-team budget manager, and schedules
 * the daily usage reset cron.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-vector-store-adapter.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-team-budget-manager.php';

add_action( 'init', array( 'WP_MCP_AI_Vector_Store_Adapter', 'get_instance' ), 25 );
add_action( 'init', array( 'WP_MCP_AI_Team_Budget_Manager', 'get_instance' ), 26 );

if ( ! wp_next_scheduled( 'wp_mcp_ai_team_budget_reset_daily' ) ) {
	wp_schedule_event( time(), 'daily', 'wp_mcp_ai_team_budget_reset_daily' );
}
add_action( 'wp_mcp_ai_team_budget_reset_daily', array( 'WP_MCP_AI_Team_Budget_Manager', 'reset_daily_usage_static' ) );
