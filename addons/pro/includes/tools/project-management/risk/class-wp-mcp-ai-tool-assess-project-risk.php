<?php
/**
 * Tool for assessing project risk.
 *
 * Scores project risk across multiple dimensions (schedule slippage,
 * scope creep, resource churn, blockers, stale tasks) and returns
 * an overall score with actionable recommendations.
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
 * Assesses project risk with dimension breakdown and recommendations.
 */
class WP_MCP_AI_Tool_Assess_Project_Risk implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'assess_project_risk';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Assess Project Risk', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Assess the risk level of a project across multiple dimensions: schedule slippage, scope creep, resource churn, blocker count, and stale task ratio. Returns an overall risk score (0-100), risk level classification, dimension breakdown, and actionable recommendations.', 'mcp-ai-wpoos-pro' );
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
			'profession_tags'       => array( 'project_manager', 'team_lead', 'risk_manager' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to assess project risk.', 'mcp-ai-wpoos-pro' ) );
		}

		$project_id = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;

		$project = get_post( $project_id );
		if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
			return new WP_Error( 'wp_mcp_ai_project_not_found', __( 'Project not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get project metadata.
		$project_status   = get_post_meta( $project_id, '_project_status', true );
		$project_start    = get_post_meta( $project_id, '_project_start_date', true );
		$project_end      = get_post_meta( $project_id, '_project_end_date', true );
		$assigned_to      = get_post_meta( $project_id, '_project_assigned_to', true );
		if ( ! is_array( $assigned_to ) ) {
			$assigned_to = array();
		}

		// Get all tasks for the project.
		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => '_task_project_id',
				'meta_value'     => $project_id,
			)
		);

		$total_tasks       = count( $tasks );
		$blocked_count     = 0;
		$stale_count       = 0;
		$overdue_count     = 0;
		$completed_count   = 0;
		$unassigned_count  = 0;
		$reassigned_count  = 0;
		$stale_threshold   = time() - ( 14 * DAY_IN_SECONDS );
		$now               = time();

		foreach ( $tasks as $task ) {
			$status     = get_post_meta( $task->ID, '_task_status', true );
			$due_date   = get_post_meta( $task->ID, '_task_due_date', true );
			$assigned   = get_post_meta( $task->ID, '_task_assigned_to', true );
			$modified   = strtotime( $task->post_modified );

			if ( 'blocked' === $status ) {
				$blocked_count++;
			}
			if ( 'completed' === $status || 'cancelled' === $status ) {
				$completed_count++;
			}
			if ( $modified < $stale_threshold && ! in_array( $status, array( 'completed', 'cancelled' ), true ) ) {
				$stale_count++;
			}
			if ( $due_date && strtotime( $due_date ) < $now && ! in_array( $status, array( 'completed', 'cancelled' ), true ) ) {
				$overdue_count++;
			}
			if ( ! $assigned && ! in_array( $status, array( 'completed', 'cancelled' ), true ) ) {
				$unassigned_count++;
			}
			// Check for reassignments via _task_previous_assignee meta.
			$previous_assignee = get_post_meta( $task->ID, '_task_previous_assignee', true );
			if ( $previous_assignee ) {
				$reassigned_count++;
			}
		}

		$open_tasks = $total_tasks - $completed_count;

		// ── Dimension 1: Schedule Slippage ──
		$schedule_slippage = 0;
		if ( $project_start && $project_end ) {
			$total_days      = max( 1, ( strtotime( $project_end ) - strtotime( $project_start ) ) / DAY_IN_SECONDS );
			$elapsed_days    = max( 0, ( $now - strtotime( $project_start ) ) / DAY_IN_SECONDS );
			$expected_progress = $total_days > 0 ? min( 1, $elapsed_days / $total_days ) : 0;
			$actual_progress   = $total_tasks > 0 ? $completed_count / $total_tasks : 0;
			$schedule_slippage  = round( max( 0, $expected_progress - $actual_progress ) * 100, 1 );
		}

		// ── Dimension 2: Scope Creep (tasks created after project start) ──
		$scope_creep = 0;
		if ( $project_start ) {
			$new_task_count = 0;
			foreach ( $tasks as $task ) {
				if ( strtotime( $task->post_date ) > strtotime( $project_start ) + ( 30 * DAY_IN_SECONDS ) ) {
					$new_task_count++;
				}
			}
			$scope_creep = $total_tasks > 0 ? round( ( $new_task_count / $total_tasks ) * 100, 1 ) : 0;
		}

		// ── Dimension 3: Resource Churn ──
		$resource_churn = $total_tasks > 0 ? round( ( $reassigned_count / $total_tasks ) * 100, 1 ) : 0;

		// ── Dimension 4: Blocker Count ──
		$blocker_count = $blocked_count;

		// ── Dimension 5: Stale Task Ratio ──
		$stale_task_ratio = $open_tasks > 0 ? round( ( $stale_count / $open_tasks ) * 100, 1 ) : 0;

		// Compute weighted composite risk score (0-100, higher = riskier).
		$dimension_breakdown = array(
			'schedule_slippage' => $schedule_slippage,
			'scope_creep'       => $scope_creep,
			'resource_churn'    => $resource_churn,
			'blocker_count'     => $blocker_count,
			'stale_task_ratio'  => $stale_task_ratio,
		);

		$weights = array(
			'schedule_slippage' => 0.30,
			'scope_creep'       => 0.15,
			'resource_churn'    => 0.15,
			'blocker_count'     => 0.25,
			'stale_task_ratio'  => 0.15,
		);

		$overall_score = 0;
		foreach ( $dimension_breakdown as $dim => $value ) {
			$overall_score += $value * $weights[ $dim ];
		}
		$overall_score = round( min( 100, max( 0, $overall_score ) ), 1 );

		// Determine risk level.
		if ( $overall_score >= 70 ) {
			$risk_level = 'critical';
		} elseif ( $overall_score >= 50 ) {
			$risk_level = 'high';
		} elseif ( $overall_score >= 30 ) {
			$risk_level = 'medium';
		} else {
			$risk_level = 'low';
		}

		// Generate recommendations.
		$recommendations = array();
		if ( $schedule_slippage > 30 ) {
			$recommendations[] = __( 'Project is significantly behind schedule. Consider scope negotiation, extending the deadline, or adding resources.', 'mcp-ai-wpoos-pro' );
		} elseif ( $schedule_slippage > 15 ) {
			$recommendations[] = __( 'Project is slightly behind schedule. Review task estimates and identify quick wins.', 'mcp-ai-wpoos-pro' );
		}
		if ( $scope_creep > 30 ) {
			$recommendations[] = __( 'High scope creep detected. Evaluate whether newly added tasks are essential or can be deferred to a future phase.', 'mcp-ai-wpoos-pro' );
		}
		if ( $resource_churn > 25 ) {
			$recommendations[] = __( 'Frequent task reassignments detected. This may indicate resource instability or unclear ownership.', 'mcp-ai-wpoos-pro' );
		}
		if ( $blocker_count > 0 ) {
			$recommendations[] = sprintf(
				/* translators: %d: number of blocked tasks */
				__( '%d blocked task(s) found. Prioritize resolving blockers to unblock dependent work.', 'mcp-ai-wpoos-pro' ),
				$blocker_count
			);
		}
		if ( $stale_task_ratio > 25 ) {
			$recommendations[] = sprintf(
				/* translators: %d: number of stale tasks */
				__( '%d stale task(s) detected with no updates in 14+ days. Review and either progress or close them.', 'mcp-ai-wpoos-pro' ),
				$stale_count
			);
		}
		if ( $unassigned_count > 0 ) {
			$recommendations[] = sprintf(
				/* translators: %d: number of unassigned tasks */
				__( '%d unassigned task(s) found. Assign owners to ensure accountability.', 'mcp-ai-wpoos-pro' ),
				$unassigned_count
			);
		}

		if ( empty( $recommendations ) ) {
			$recommendations[] = __( 'Project appears healthy. Continue monitoring progress and maintain current cadence.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success'             => true,
			'project_id'          => $project_id,
			'overall_score'       => $overall_score,
			'risk_level'          => $risk_level,
			'dimension_breakdown' => $dimension_breakdown,
			'recommendations'     => $recommendations,
		);
	}
}
