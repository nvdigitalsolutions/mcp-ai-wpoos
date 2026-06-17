<?php
/**
 * Tool for closing a sprint.
 *
 * Allows AI assistants to close completed sprints, moving incomplete
 * tasks back to the backlog and computing sprint metrics.
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
 * Closes a sprint and computes sprint metrics.
 *
 * Moves incomplete tasks back to backlog, calculates completion rate
 * and velocity, and saves the metrics as sprint post meta.
 */
class WP_MCP_AI_Tool_Close_Sprint implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'close_sprint';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Close Sprint', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Close a sprint, moving incomplete tasks back to the backlog and computing sprint metrics including completion rate and velocity. Useful for sprint review and retrospective ceremonies.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'sprint_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the sprint to close (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'sprint_id' ),
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
			'post_type'             => 'mcp_ai_sprint',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'team_lead', 'scrum_master' ),
			'risk_level'            => 'standard',
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
			'database-write',
			'state-changing',
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
		return ! empty( $settings['enable_project_management'] );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to close sprints.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize input.
		$sprint_id = isset( $arguments['sprint_id'] ) ? absint( $arguments['sprint_id'] ) : 0;

		if ( $sprint_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_missing_sprint', __( 'A valid sprint ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify sprint exists.
		$sprint = get_post( $sprint_id );
		if ( ! $sprint || 'mcp_ai_sprint' !== $sprint->post_type ) {
			return new WP_Error( 'wp_mcp_ai_sprint_not_found', __( 'Sprint not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$sprint_status = get_post_meta( $sprint_id, '_sprint_status', true );

		// Only allow closing active sprints.
		if ( 'active' !== $sprint_status ) {
			return new WP_Error(
				'wp_mcp_ai_sprint_not_active',
				sprintf(
					/* translators: %s: current sprint status */
					__( 'Only active sprints can be closed. Current status: %s', 'mcp-ai-wpoos-pro' ),
					$sprint_status ? $sprint_status : 'unknown'
				)
			);
		}

		// Get sprint date range.
		$start_date = get_post_meta( $sprint_id, '_sprint_start_date', true );
		$end_date   = get_post_meta( $sprint_id, '_sprint_end_date', true );

		// Calculate sprint duration in days.
		$sprint_days = 1;
		if ( $start_date && $end_date ) {
			$start_dt    = new DateTime( $start_date );
			$end_dt      = new DateTime( $end_date );
			$interval    = $start_dt->diff( $end_dt );
			$sprint_days = max( 1, (int) $interval->days + 1 );
		}

		// Query all tasks assigned to this sprint.
		$query_args = array(
			'post_type'      => 'mcp_ai_task',
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'meta_query'     => array(
				array(
					'key'     => '_task_sprint_id',
					'value'   => $sprint_id,
					'compare' => '=',
				),
			),
			'fields'         => 'ids',
		);

		$query = new WP_Query( $query_args );

		$completed_count   = 0;
		$incomplete_count  = 0;
		$moved_to_backlog  = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $task_id ) {
				$task_status = get_post_meta( $task_id, '_task_status', true );

				if ( 'completed' === $task_status ) {
					$completed_count++;
					// Keep sprint assignment for completed tasks (historical record).
				} else {
					$incomplete_count++;
					// Move incomplete tasks back to backlog.
					delete_post_meta( $task_id, '_task_sprint_id' );
					update_post_meta( $task_id, '_task_status', 'backlog' );
					$moved_to_backlog[] = $task_id;
				}
			}
		}

		$total_tasks   = $completed_count + $incomplete_count;
		$completion_pct = $total_tasks > 0 ? round( ( $completed_count / $total_tasks ) * 100, 1 ) : 0;

		// Calculate velocity: completed tasks per sprint day.
		$velocity = $sprint_days > 0 ? round( $completed_count / $sprint_days, 1 ) : (float) $completed_count;

		// Build metrics.
		$sprint_result = array(
			'sprint_id'         => $sprint_id,
			'sprint_title'      => $sprint->post_title,
			'status'            => 'completed',
			'completed_count'   => $completed_count,
			'incomplete_count'  => $incomplete_count,
			'total_tasks'       => $total_tasks,
			'completion_pct'    => $completion_pct,
			'velocity'          => $velocity,
			'sprint_days'       => $sprint_days,
			'moved_to_backlog'  => $moved_to_backlog,
			'closed_at'         => current_time( 'mysql' ),
		);

		// Save metrics as sprint meta.
		update_post_meta( $sprint_id, '_sprint_status', 'completed' );
		update_post_meta( $sprint_id, '_sprint_metrics', $sprint_result );
		update_post_meta( $sprint_id, '_sprint_completed_count', $completed_count );
		update_post_meta( $sprint_id, '_sprint_incomplete_count', $incomplete_count );
		update_post_meta( $sprint_id, '_sprint_velocity', $velocity );
		update_post_meta( $sprint_id, '_sprint_completion_pct', $completion_pct );

		/**
		 * Fires after a sprint is closed.
		 *
		 * @param int   $sprint_id     The sprint post ID.
		 * @param array $sprint_result The sprint metrics and result data.
		 */
		do_action( 'wp_mcp_ai_pm_sprint_closed', $sprint_id, $sprint_result );

		return array(
			'success'       => true,
			'message'       => sprintf(
				/* translators: 1: sprint title, 2: completion percentage, 3: velocity */
				__( 'Sprint closed: %1$s — %2$s%% complete, velocity %3$.1f tasks/day', 'mcp-ai-wpoos-pro' ),
				$sprint->post_title,
				$completion_pct,
				$velocity
			),
			'sprint_result' => $sprint_result,
		);
	}
}
