<?php
/**
 * Tool for forecasting project completion.
 *
 * Computes an estimated completion date range based on historical
 * team velocity and remaining open tasks.
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
 * Forecasts project completion date with confidence range.
 */
class WP_MCP_AI_Tool_Forecast_Completion implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'forecast_completion';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Forecast Completion', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Forecast a project completion date range (optimistic, expected, pessimistic) based on historical team velocity and remaining open tasks. Useful for stakeholders and sprint planning.', 'mcp-ai-wpoos-pro' );
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
			'profession_tags'       => array( 'project_manager', 'scrum_master', 'executive' ),
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
		return true;
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to forecast completion.', 'mcp-ai-wpoos-pro' ) );
		}

		$project_id = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;

		$project = get_post( $project_id );
		if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
			return new WP_Error( 'wp_mcp_ai_project_not_found', __( 'Project not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Count remaining open tasks.
		$remaining_tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_task_project_id',
						'value'   => $project_id,
						'compare' => '=',
					),
					array(
						'key'     => '_task_status',
						'value'   => array( 'completed', 'cancelled' ),
						'compare' => 'NOT IN',
					),
				),
			)
		);
		$remaining_count = count( $remaining_tasks );

		if ( 0 === $remaining_count ) {
			return array(
				'success'          => true,
				'project_id'       => $project_id,
				'remaining_tasks'  => 0,
				'optimistic_date'  => gmdate( 'Y-m-d' ),
				'expected_date'    => gmdate( 'Y-m-d' ),
				'pessimistic_date' => gmdate( 'Y-m-d' ),
				'weekly_velocity'  => 0,
				'confidence_note'  => __( 'No remaining tasks. Project is complete or empty.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Calculate historical weekly velocity from completed tasks across all projects.
		$completed_tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'meta_query'     => array(
					array(
						'key'     => '_task_status',
						'value'   => 'completed',
						'compare' => '=',
					),
					array(
						'key'     => '_task_completed_date',
						'value'   => '',
						'compare' => '!=',
					),
				),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		// Group completed tasks by week to find weekly velocity.
		$weekly_completed = array();
		foreach ( $completed_tasks as $task ) {
			$completed_date = get_post_meta( $task->ID, '_task_completed_date', true );
			if ( ! $completed_date ) {
				continue;
			}
			$completed_ts = strtotime( $completed_date );
			$week_start   = gmdate( 'Y-m-d', strtotime( 'monday this week', $completed_ts ) );
			if ( ! isset( $weekly_completed[ $week_start ] ) ) {
				$weekly_completed[ $week_start ] = 0;
			}
			++$weekly_completed[ $week_start ];
		}

		// Calculate average weekly velocity (last 4 weeks).
		krsort( $weekly_completed );
		$recent_weeks    = array_slice( $weekly_completed, 0, 4, true );
		$velocity_sum    = array_sum( $recent_weeks );
		$week_count      = count( $recent_weeks );
		$weekly_velocity = $week_count > 0 ? round( $velocity_sum / $week_count, 1 ) : 0;

		// If no velocity data, fall back to a conservative estimate.
		if ( $weekly_velocity <= 0 ) {
			$weekly_velocity = max( 1, round( $remaining_count / 4, 1 ) );
			$confidence_note = __( 'Insufficient historical velocity data. Using conservative estimate of 4-week completion.', 'mcp-ai-wpoos-pro' );
		} else {
			$confidence_note = sprintf(
				/* translators: %1$d: number of weeks of data, %2$.1f: weekly velocity */
				__( 'Based on %1$d weeks of historical data with average weekly velocity of %2$.1f tasks.', 'mcp-ai-wpoos-pro' ),
				$week_count,
				$weekly_velocity
			);
		}

		// Calculate dates.
		$weeks_needed     = $remaining_count / max( 1, $weekly_velocity );
		$days_expected    = (int) ceil( $weeks_needed * 7 );
		$days_optimistic  = (int) ceil( $weeks_needed * 5 ); // 5-day optimistic weeks.
		$days_pessimistic = (int) ceil( $weeks_needed * 10 ); // 10-day pessimistic weeks.

		$today            = time();
		$expected_date    = gmdate( 'Y-m-d', $today + ( $days_expected * DAY_IN_SECONDS ) );
		$optimistic_date  = gmdate( 'Y-m-d', $today + ( $days_optimistic * DAY_IN_SECONDS ) );
		$pessimistic_date = gmdate( 'Y-m-d', $today + ( $days_pessimistic * DAY_IN_SECONDS ) );

		return array(
			'success'          => true,
			'project_id'       => $project_id,
			'optimistic_date'  => $optimistic_date,
			'expected_date'    => $expected_date,
			'pessimistic_date' => $pessimistic_date,
			'remaining_tasks'  => $remaining_count,
			'weekly_velocity'  => $weekly_velocity,
			'confidence_note'  => $confidence_note,
		);
	}
}
