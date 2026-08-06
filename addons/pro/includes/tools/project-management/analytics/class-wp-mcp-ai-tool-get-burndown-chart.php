<?php
/**
 * Tool for generating burndown chart data.
 *
 * Computes sprint/project burndown data by comparing ideal progress
 * against actual completed tasks day-by-day.
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
 * Generates burndown chart data for a project or sprint.
 */
class WP_MCP_AI_Tool_Get_Burndown_Chart implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_burndown_chart';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Burndown Chart', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate burndown chart data for a project or sprint. Computes the ideal burndown line versus actual remaining tasks day-by-day from start to end. Requires a project_id, and optionally a sprint_id to use sprint-specific date boundaries.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_id' => array(
					'type'        => 'integer',
					'description' => __( 'Project ID (required).', 'mcp-ai-wpoos-pro' ),
				),
				'sprint_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Optional sprint CPT ID. If provided, uses sprint start/end dates instead of project dates.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'project_id' ),
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
			'post_type'             => 'mcp_ai_project',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'scrum_master', 'team_lead' ),
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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_project_management'] ) ) {
			return false;
		}
		// Analytics tools require the analytics toggle.
		if ( ! empty( $settings['enable_pm_analytics'] ) ) {
			return true;
		}
		return true; // Available by default when PM is enabled, even without explicit analytics toggle.
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view burndown charts.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$project_id = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;
		$sprint_id  = isset( $arguments['sprint_id'] ) ? absint( $arguments['sprint_id'] ) : 0;

		// Validate project exists.
		$project = get_post( $project_id );
		if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
			return new WP_Error( 'wp_mcp_ai_project_not_found', __( 'Project not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Determine date boundaries.
		if ( $sprint_id ) {
			$sprint = get_post( $sprint_id );
			if ( ! $sprint || 'mcp_ai_project' !== $sprint->post_type ) {
				return new WP_Error( 'wp_mcp_ai_sprint_not_found', __( 'Sprint not found.', 'mcp-ai-wpoos-pro' ) );
			}
			$start_date = get_post_meta( $sprint_id, '_project_start_date', true );
			$end_date   = get_post_meta( $sprint_id, '_project_end_date', true );
		} else {
			$start_date = get_post_meta( $project_id, '_project_start_date', true );
			$end_date   = get_post_meta( $project_id, '_project_end_date', true );
		}

		if ( ! $start_date ) {
			$start_date = gmdate( 'Y-m-d', strtotime( $project->post_date ) );
		}
		if ( ! $end_date ) {
			$end_date = gmdate( 'Y-m-d', strtotime( '+14 days' ) );
		}

		$start_ts = strtotime( $start_date );
		$end_ts   = strtotime( $end_date );

		if ( $start_ts >= $end_ts ) {
			return new WP_Error( 'wp_mcp_ai_invalid_dates', __( 'Project start date must be before end date.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get all tasks for this project.
		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => '_task_project_id',
				'meta_value'     => $project_id,
			)
		);

		$total_tasks = count( $tasks );

		// Build completion date map: date => cumulative completions.
		$completion_dates = array();
		foreach ( $tasks as $task ) {
			$completed_date = get_post_meta( $task->ID, '_task_completed_date', true );
			if ( $completed_date ) {
				$day_key = gmdate( 'Y-m-d', strtotime( $completed_date ) );
				if ( ! isset( $completion_dates[ $day_key ] ) ) {
					$completion_dates[ $day_key ] = 0;
				}
				++$completion_dates[ $day_key ];
			}
		}

		// Build day-by-day burndown data.
		$total_days      = max( 1, (int) ceil( ( $end_ts - $start_ts ) / DAY_IN_SECONDS ) ) + 1;
		$cumulative_done = 0;
		$sprint_days     = array();

		for ( $d = 0; $d < $total_days; $d++ ) {
			$day_ts   = $start_ts + ( $d * DAY_IN_SECONDS );
			$date_key = gmdate( 'Y-m-d', $day_ts );

			// Ideal burndown: linear from total to zero.
			$ideal_remaining = $total_days > 1
				? round( $total_tasks * ( 1 - ( $d / ( $total_days - 1 ) ) ), 1 )
				: 0;

			// Actual: track cumulative completions up to this day.
			if ( isset( $completion_dates[ $date_key ] ) ) {
				$cumulative_done += $completion_dates[ $date_key ];
			}
			$actual_remaining = max( 0, $total_tasks - $cumulative_done );

			$sprint_days[] = array(
				'date'             => $date_key,
				'ideal_remaining'  => $ideal_remaining,
				'actual_remaining' => $actual_remaining,
				'completed_count'  => $cumulative_done,
			);
		}

		return array(
			'success'     => true,
			'project_id'  => $project_id,
			'sprint_id'   => $sprint_id ? $sprint_id : null,
			'start_date'  => $start_date,
			'end_date'    => $end_date,
			'total_tasks' => $total_tasks,
			'total_days'  => $total_days,
			'sprint_days' => $sprint_days,
		);
	}
}
