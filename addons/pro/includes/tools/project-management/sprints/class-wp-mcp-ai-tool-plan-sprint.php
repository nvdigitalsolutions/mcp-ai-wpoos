<?php
/**
 * Tool for planning a sprint.
 *
 * Allows AI assistants to move tasks from the project backlog into a
 * sprint, with optional auto-selection based on priority and velocity.
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
 * Plans a sprint by moving tasks from backlog into it.
 *
 * Supports both manual task selection and auto-selection from the project
 * backlog sorted by priority, respecting the sprint's velocity target.
 */
class WP_MCP_AI_Tool_Plan_Sprint implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Priority ordering for auto-selection (highest first).
	 *
	 * @var string[]
	 */
	const PRIORITY_ORDER = array( 'urgent', 'high', 'medium', 'low' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'plan_sprint';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Plan Sprint', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Plan a sprint by moving tasks from the project backlog into it. You can specify task IDs manually or let the tool auto-select tasks based on priority and velocity target. Useful for sprint planning ceremonies.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'ID of the sprint to plan (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'task_ids'  => array(
					'type'        => 'array',
					'description' => __( 'List of task IDs to add to the sprint. If not provided, tasks are auto-selected from the project backlog based on priority.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to plan sprints.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize inputs.
		$sprint_id = isset( $arguments['sprint_id'] ) ? absint( $arguments['sprint_id'] ) : 0;

		if ( $sprint_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_missing_sprint', __( 'A valid sprint ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify sprint exists.
		$sprint = get_post( $sprint_id );
		if ( ! $sprint || 'mcp_ai_sprint' !== $sprint->post_type ) {
			return new WP_Error( 'wp_mcp_ai_sprint_not_found', __( 'Sprint not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get sprint metadata.
		$project_id      = absint( get_post_meta( $sprint_id, '_sprint_project_id', true ) );
		$velocity_target = absint( get_post_meta( $sprint_id, '_sprint_velocity_target', true ) );
		$sprint_status   = get_post_meta( $sprint_id, '_sprint_status', true );

		// Only allow planning for sprints in 'planning' status.
		if ( $sprint_status && 'planning' !== $sprint_status ) {
			return new WP_Error( 'wp_mcp_ai_sprint_not_plannable', sprintf(
				/* translators: %s: current sprint status */
				__( 'Sprint cannot be planned because its status is "%s". Only sprints in "planning" status can be planned.', 'mcp-ai-wpoos-pro' ),
				$sprint_status
			) );
		}

		$planned_task_ids = array();

		if ( isset( $arguments['task_ids'] ) && is_array( $arguments['task_ids'] ) && ! empty( $arguments['task_ids'] ) ) {
			// Manual task selection.
			$manual_ids = array_map( 'absint', $arguments['task_ids'] );

			foreach ( $manual_ids as $task_id ) {
				$task = get_post( $task_id );
				if ( ! $task || 'mcp_ai_task' !== $task->post_type ) {
					continue;
				}

				// Verify task belongs to the same project or is in backlog.
				$task_project_id = absint( get_post_meta( $task_id, '_task_project_id', true ) );
				$task_status     = get_post_meta( $task_id, '_task_status', true );
				$task_sprint_id  = absint( get_post_meta( $task_id, '_task_sprint_id', true ) );

				// Skip already-assigned tasks.
				if ( $task_sprint_id > 0 ) {
					continue;
				}

				// Skip completed or cancelled tasks.
				if ( in_array( $task_status, array( 'completed', 'cancelled' ), true ) ) {
					continue;
				}

				// Assign to sprint.
				update_post_meta( $task_id, '_task_sprint_id', $sprint_id );
				update_post_meta( $task_id, '_task_status', 'todo' );
				$planned_task_ids[] = $task_id;
			}
		} else {
			// Auto-selection from backlog.
			$backlog_tasks = $this->get_backlog_tasks( $project_id, $velocity_target );

			foreach ( $backlog_tasks as $task ) {
				update_post_meta( $task->ID, '_task_sprint_id', $sprint_id );
				update_post_meta( $task->ID, '_task_status', 'todo' );
				$planned_task_ids[] = $task->ID;
			}
		}

		// Update sprint status to active if tasks were planned.
		if ( ! empty( $planned_task_ids ) ) {
			update_post_meta( $sprint_id, '_sprint_status', 'active' );
		}

		// Calculate remaining capacity.
		$remaining_capacity = $velocity_target > 0 ? max( 0, $velocity_target - count( $planned_task_ids ) ) : null;

		return array(
			'success'           => true,
			'message'           => sprintf(
				/* translators: 1: planned count, 2: sprint title */
				__( 'Planned %1$d task(s) into sprint: %2$s', 'mcp-ai-wpoos-pro' ),
				count( $planned_task_ids ),
				$sprint->post_title
			),
			'sprint_id'         => $sprint_id,
			'planned_count'     => count( $planned_task_ids ),
			'planned_task_ids'  => $planned_task_ids,
			'velocity_target'   => $velocity_target > 0 ? $velocity_target : null,
			'remaining_capacity' => $remaining_capacity,
		);
	}

	/**
	 * Get backlog tasks for a project, sorted by priority.
	 *
	 * @param int $project_id      Project ID.
	 * @param int $velocity_target Maximum number of tasks to return (0 = unlimited).
	 * @return WP_Post[] Array of task post objects.
	 */
	private function get_backlog_tasks( $project_id, $velocity_target = 0 ) {
		// First, try to get tasks with no sprint and matching project.
		$args = array(
			'post_type'      => 'mcp_ai_task',
			'post_status'    => 'publish',
			'posts_per_page' => $velocity_target > 0 ? $velocity_target : 50,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_task_project_id',
					'value'   => $project_id,
					'compare' => '=',
				),
				array(
					'relation' => 'OR',
					array(
						'key'     => '_task_sprint_id',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_task_sprint_id',
						'value'   => '0',
						'compare' => '=',
					),
				),
				array(
					'key'     => '_task_status',
					'value'   => array( 'completed', 'cancelled' ),
					'compare' => 'NOT IN',
				),
			),
		);

		$query = new WP_Query( $args );
		$tasks = $query->posts;

		// Sort by priority (urgent > high > medium > low).
		usort( $tasks, function ( $a, $b ) {
			$priority_a = get_post_meta( $a->ID, '_task_priority', true ) ?: 'medium';
			$priority_b = get_post_meta( $b->ID, '_task_priority', true ) ?: 'medium';

			$rank_a = array_search( $priority_a, self::PRIORITY_ORDER, true );
			$rank_b = array_search( $priority_b, self::PRIORITY_ORDER, true );

			if ( false === $rank_a ) {
				$rank_a = 2;
			}
			if ( false === $rank_b ) {
				$rank_b = 2;
			}

			return $rank_a - $rank_b;
		} );

		return $tasks;
	}
}
