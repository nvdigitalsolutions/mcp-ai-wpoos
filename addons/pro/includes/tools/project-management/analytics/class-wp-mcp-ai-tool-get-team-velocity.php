<?php
/**
 * Tool for computing team velocity.
 *
 * Calculates team velocity across periods (weeks or sprints) based
 * on completed tasks and optional story point estimation.
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
 * Computes team velocity metrics.
 */
class WP_MCP_AI_Tool_Get_Team_Velocity implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_team_velocity';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Team Velocity', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Compute team velocity across recent periods (weeks or sprints). Returns tasks completed and optional story points per period, along with a rolling average. Optionally filter by project or assignee.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Optional project ID to filter velocity by project.', 'mcp-ai-wpoos-pro' ),
				),
				'assignee_id' => array(
					'type'        => 'integer',
					'description' => __( 'Optional assignee user ID to filter velocity by person.', 'mcp-ai-wpoos-pro' ),
				),
				'periods'     => array(
					'type'        => 'integer',
					'description' => __( 'Number of past periods to include (default: 4, max: 26).', 'mcp-ai-wpoos-pro' ),
					'default'     => 4,
					'minimum'     => 1,
					'maximum'     => 26,
				),
			),
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
			'post_type'             => 'mcp_ai_task',
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view team velocity.', 'mcp-ai-wpoos-pro' ) );
		}

		$project_id  = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;
		$assignee_id = isset( $arguments['assignee_id'] ) ? absint( $arguments['assignee_id'] ) : 0;
		$periods     = isset( $arguments['periods'] ) ? min( absint( $arguments['periods'] ), 26 ) : 4;
		$periods     = max( 1, $periods );

		// Build meta query for completed tasks.
		$meta_query = array(
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
		);

		if ( $project_id ) {
			$meta_query[] = array(
				'key'     => '_task_project_id',
				'value'   => $project_id,
				'compare' => '=',
			);
		}

		if ( $assignee_id ) {
			$meta_query[] = array(
				'key'     => '_task_assigned_to',
				'value'   => $assignee_id,
				'compare' => '=',
			);
		}

		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'meta_query'     => $meta_query,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		// Check if story points estimation is used.
		$engine_settings = WP_MCP_AI_PM_Engine::get_toolkit_settings();
		$use_points      = ( 'story_points' === $engine_settings['estimation_method'] );

		// Group completed tasks by week.
		$weekly_data = array();
		foreach ( $tasks as $task ) {
			$completed_date = get_post_meta( $task->ID, '_task_completed_date', true );
			if ( ! $completed_date ) {
				continue;
			}
			$completed_ts = strtotime( $completed_date );
			$week_start   = gmdate( 'Y-m-d', strtotime( 'monday this week', $completed_ts ) );
			$points       = $use_points ? floatval( get_post_meta( $task->ID, '_task_story_points', true ) ) : 0;

			if ( ! isset( $weekly_data[ $week_start ] ) ) {
				$weekly_data[ $week_start ] = array(
					'tasks_completed'  => 0,
					'points_completed' => 0,
				);
			}
			++$weekly_data[ $week_start ]['tasks_completed'];
			if ( $use_points && $points > 0 ) {
				$weekly_data[ $week_start ]['points_completed'] += $points;
			}
		}

		// Sort weeks descending.
		krsort( $weekly_data );

		// Take only requested number of periods.
		$weeks = array_slice( $weekly_data, 0, $periods, true );

		// Build result with rolling average.
		$velocity     = array();
		$task_sum     = 0;
		$point_sum    = 0;
		$period_count = 0;

		// Iterate in chronological order for rolling average.
		$chrono_weeks = array_reverse( $weeks, true );
		foreach ( $chrono_weeks as $week_start => $data ) {
			++$period_count;
			$task_sum  += $data['tasks_completed'];
			$point_sum += $data['points_completed'];

			$entry = array(
				'period_label'     => $week_start,
				'tasks_completed'  => $data['tasks_completed'],
				'points_completed' => $use_points ? $data['points_completed'] : null,
				'rolling_average'  => round( $task_sum / $period_count, 1 ),
			);

			if ( $use_points ) {
				$entry['rolling_average_points'] = round( $point_sum / $period_count, 1 );
			}

			$velocity[] = $entry;
		}

		// Reverse back to descending for display.
		$velocity = array_reverse( $velocity );

		return array(
			'success'           => true,
			'project_id'        => $project_id ? $project_id : null,
			'assignee_id'       => $assignee_id ? $assignee_id : null,
			'periods'           => $periods,
			'estimation_method' => $use_points ? 'story_points' : 'tasks',
			'velocity'          => $velocity,
		);
	}
}
