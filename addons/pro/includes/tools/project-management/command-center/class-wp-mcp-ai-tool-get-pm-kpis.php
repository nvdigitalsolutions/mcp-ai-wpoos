<?php
/**
 * Tool for getting Project Management KPIs.
 *
 * Returns key performance indicators across the project portfolio including
 * active projects, open/overdue/blocked tasks, upcoming events, and
 * portfolio health scores.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns PM KPIs for the command center dashboard.
 */
class WP_MCP_AI_Tool_Get_PM_KPIs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_pm_kpis';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get PM KPIs', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns key performance indicators for the project portfolio: active projects, open tasks, upcoming events (next 7 days), overdue tasks, blocked tasks, portfolio health score and breakdown, and tasks completed this week.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'project_management',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'team_lead', 'executive' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Project management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_project_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments (none required).
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view KPIs.', 'mcp-ai-wpoos-pro' ) );
		}

		// Ensure PM Engine is available.
		if ( ! class_exists( 'WP_MCP_AI_PM_Engine' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Project Management Engine is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get portfolio health from the engine.
		$health = WP_MCP_AI_PM_Engine::calculate_portfolio_health();

		// Count active projects.
		$active_projects = WP_MCP_AI_PM_Engine::count_projects();

		// Count open tasks.
		$open_tasks = WP_MCP_AI_PM_Engine::count_tasks( 0, '' );
		$blocked_tasks = WP_MCP_AI_PM_Engine::count_tasks( 0, 'blocked' );

		// Get upcoming events (next 7 days).
		$upcoming_deadlines = array();
		if ( method_exists( 'WP_MCP_AI_PM_Engine', 'get_upcoming_deadlines' ) ) {
			$upcoming_deadlines = WP_MCP_AI_PM_Engine::get_upcoming_deadlines( 7, 10 );
		}
		$upcoming_events = count( $upcoming_deadlines );

		// Count overdue tasks (due date before today, not completed).
		$overdue_args = array(
			'post_type'      => 'mcp_ai_task',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_task_due_date',
					'value'   => gmdate( 'Y-m-d' ),
					'compare' => '<',
					'type'    => 'DATE',
				),
				array(
					'key'     => '_task_status',
					'value'   => array( 'completed', 'cancelled' ),
					'compare' => 'NOT IN',
				),
			),
		);
		$overdue_query = new WP_Query( $overdue_args );
		$overdue_tasks = $overdue_query->found_posts;

		// Count tasks completed this week.
		$week_start = gmdate( 'Y-m-d', strtotime( 'monday this week' ) );
		$week_end   = gmdate( 'Y-m-d', strtotime( 'sunday this week' ) );
		$completed_week_args = array(
			'post_type'      => 'mcp_ai_task',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_task_status',
					'value'   => 'completed',
				),
			),
			'date_query' => array(
				array(
					'column' => 'post_modified',
					'after'  => $week_start,
					'before' => $week_end . ' 23:59:59',
				),
			),
		);
		$completed_week_query = new WP_Query( $completed_week_args );
		$completed_this_week = $completed_week_query->found_posts;

		// Determine portfolio health label.
		$score = isset( $health['score'] ) ? (float) $health['score'] : 100;
		if ( $score >= 80 ) {
			$health_label = __( 'Healthy', 'mcp-ai-wpoos-pro' );
		} elseif ( $score >= 50 ) {
			$health_label = __( 'At Risk', 'mcp-ai-wpoos-pro' );
		} else {
			$health_label = __( 'Critical', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'             => true,
			'active_projects'     => $active_projects,
			'open_tasks'          => $open_tasks,
			'upcoming_events'     => $upcoming_events,
			'overdue_tasks'       => $overdue_tasks,
			'blocked_tasks'       => $blocked_tasks,
			'completed_this_week' => $completed_this_week,
			'portfolio_health'    => array(
				'score'               => $score,
				'label'               => $health_label,
				'total_projects'      => isset( $health['total_projects'] ) ? absint( $health['total_projects'] ) : 0,
				'at_risk_count'       => isset( $health['at_risk_count'] ) ? absint( $health['at_risk_count'] ) : 0,
				'total_open_tasks'    => isset( $health['total_open_tasks'] ) ? absint( $health['total_open_tasks'] ) : 0,
				'total_overdue_tasks' => isset( $health['total_overdue_tasks'] ) ? absint( $health['total_overdue_tasks'] ) : 0,
				'total_blocked_tasks' => isset( $health['total_blocked_tasks'] ) ? absint( $health['total_blocked_tasks'] ) : 0,
				'schedule_variance'   => isset( $health['schedule_variance'] ) ? (float) $health['schedule_variance'] : 100,
				'completion_rate'     => isset( $health['completion_rate'] ) ? (float) $health['completion_rate'] : 100,
			),
		);
	}
}
