<?php
/**
 * Task Assignment Manager Tool
 *
 * Manages task assignments on legal matters including assigning, listing, completing tasks,
 * and viewing attorney workload.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages task assignments on legal matters.
 */
class WP_MCP_AI_Tool_LF_Task_Assignment_Manager implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_task_assignment_manager';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Task Assignment Manager', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Assigns, lists, completes, and tracks workload for tasks on legal matters. Supports assignees, due dates, and priority levels.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'           => array(
					'type'        => 'string',
					'description' => __( 'Task action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'assign', 'list', 'complete', 'get_workload' ),
				),
				'matter_id'        => array(
					'type'        => 'integer',
					'description' => __( 'The matter ID for the task.', 'mcp-ai-wpoos-pro' ),
				),
				'task_description' => array(
					'type'        => 'string',
					'description' => __( 'Description of the task.', 'mcp-ai-wpoos-pro' ),
				),
				'assignee_id'      => array(
					'type'        => 'integer',
					'description' => __( 'WordPress user ID of the assignee.', 'mcp-ai-wpoos-pro' ),
				),
				'due_date'         => array(
					'type'        => 'string',
					'description' => __( 'Due date for the task (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'priority'         => array(
					'type'        => 'string',
					'description' => __( 'Task priority level.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'low', 'medium', 'high', 'critical' ),
				),
				'task_id'          => array(
					'type'        => 'string',
					'description' => __( 'Task ID (for complete action).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';

		switch ( $action ) {
			case 'assign':
				return $this->assign_task( $arguments, $uid );

			case 'list':
				return $this->list_tasks( $arguments );

			case 'complete':
				return $this->complete_task( $arguments, $uid );

			case 'get_workload':
				return $this->get_workload( $arguments );

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid task action.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Assign a new task to a matter.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $uid       User ID.
	 * @return array|WP_Error
	 */
	private function assign_task( array $arguments, int $uid ) {
		$matter_id   = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$description = isset( $arguments['task_description'] ) ? sanitize_text_field( $arguments['task_description'] ) : '';
		$assignee_id = isset( $arguments['assignee_id'] ) ? absint( $arguments['assignee_id'] ) : 0;
		$due_date    = isset( $arguments['due_date'] ) ? sanitize_text_field( $arguments['due_date'] ) : '';
		$priority    = isset( $arguments['priority'] ) ? sanitize_text_field( $arguments['priority'] ) : 'medium';

		if ( ! $matter_id || empty( $description ) ) {
			return new WP_Error( 'missing_required', __( 'Matter ID and task description are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$tasks = get_post_meta( $matter_id, '_lf_tasks', true );
		if ( ! is_array( $tasks ) ) {
			$tasks = array();
		}

		$task = array(
			'id'          => wp_generate_uuid4(),
			'description' => $description,
			'assignee_id' => $assignee_id,
			'due_date'    => $due_date,
			'priority'    => $priority,
			'completed'   => false,
			'created_by'  => $uid,
			'created_at'  => current_time( 'Y-m-d H:i:s' ),
		);

		$tasks[] = $task;
		update_post_meta( $matter_id, '_lf_tasks', $tasks );

		$assignee_name = '';
		if ( $assignee_id ) {
			$user          = get_userdata( $assignee_id );
			$assignee_name = $user ? $user->display_name : '';
		}

		return array(
			'success'    => true,
			'message'    => __( 'Task assigned successfully. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
			'data'       => array(
				'task_id'       => $task['id'],
				'matter_id'     => $matter_id,
				'description'   => $description,
				'assignee_id'   => $assignee_id,
				'assignee_name' => $assignee_name,
				'due_date'      => $due_date,
				'priority'      => $priority,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * List tasks for a matter.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function list_tasks( array $arguments ) {
		$matter_id = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		if ( ! $matter_id ) {
			return new WP_Error( 'missing_required', __( 'Matter ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$tasks = get_post_meta( $matter_id, '_lf_tasks', true );
		if ( ! is_array( $tasks ) ) {
			$tasks = array();
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %d: number of tasks */
				__( '%d tasks found. ', 'mcp-ai-wpoos-pro' ),
				count( $tasks )
			) . self::DISCLAIMER,
			'data'       => array(
				'matter_id' => $matter_id,
				'tasks'     => $tasks,
				'total'     => count( $tasks ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Mark a task as complete.
	 *
	 * @param array $arguments Tool arguments.
	 * @param int   $uid       User ID.
	 * @return array|WP_Error
	 */
	private function complete_task( array $arguments, int $uid ) {
		$matter_id = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$task_id   = isset( $arguments['task_id'] ) ? sanitize_text_field( $arguments['task_id'] ) : '';

		if ( ! $matter_id || empty( $task_id ) ) {
			return new WP_Error( 'missing_required', __( 'Matter ID and task ID are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$tasks = get_post_meta( $matter_id, '_lf_tasks', true );
		if ( ! is_array( $tasks ) ) {
			return new WP_Error( 'not_found', __( 'No tasks found for this matter.', 'mcp-ai-wpoos-pro' ) );
		}

		$found = false;
		foreach ( $tasks as &$task ) {
			if ( $task['id'] === $task_id ) {
				$task['completed']    = true;
				$task['completed_at'] = current_time( 'Y-m-d H:i:s' );
				$task['completed_by'] = $uid;
				$found                = true;
				break;
			}
		}
		unset( $task );

		if ( ! $found ) {
			return new WP_Error( 'not_found', __( 'Task not found.', 'mcp-ai-wpoos-pro' ) );
		}

		update_post_meta( $matter_id, '_lf_tasks', $tasks );

		return array(
			'success'    => true,
			'message'    => __( 'Task marked as complete. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
			'data'       => array(
				'matter_id'    => $matter_id,
				'task_id'      => $task_id,
				'completed'    => true,
				'completed_at' => current_time( 'Y-m-d H:i:s' ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}

	/**
	 * Get workload for an assignee across all matters.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	private function get_workload( array $arguments ) {
		$assignee_id = isset( $arguments['assignee_id'] ) ? absint( $arguments['assignee_id'] ) : 0;
		if ( ! $assignee_id ) {
			return new WP_Error( 'missing_required', __( 'Assignee ID is required for workload view.', 'mcp-ai-wpoos-pro' ) );
		}

		$matters = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_lf_matter',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_task_assignment_manager', 0, 1000 ) : 1000,
				'fields'         => 'ids',
			)
		);

		$pending_tasks   = array();
		$completed_count = 0;

		foreach ( $matters->posts as $mid ) {
			$tasks = get_post_meta( $mid, '_lf_tasks', true );
			if ( ! is_array( $tasks ) ) {
				continue;
			}
			foreach ( $tasks as $task ) {
				if ( absint( $task['assignee_id'] ?? 0 ) !== $assignee_id ) {
					continue;
				}
				if ( ! empty( $task['completed'] ) ) {
					++$completed_count;
				} else {
					$task['matter_id']    = $mid;
					$task['matter_title'] = get_the_title( $mid );
					$pending_tasks[]      = $task;
				}
			}
		}
		wp_reset_postdata();

		// Sort pending by due date.
		usort(
			$pending_tasks,
			function ( $a, $b ) {
				$da = $a['due_date'] ?? '9999-99-99';
				$db = $b['due_date'] ?? '9999-99-99';
				return strcmp( $da, $db );
			}
		);

		$user = get_userdata( $assignee_id );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: pending count, 2: completed count */
				__( 'Workload: %1$d pending tasks, %2$d completed. ', 'mcp-ai-wpoos-pro' ),
				count( $pending_tasks ),
				$completed_count
			) . self::DISCLAIMER,
			'data'       => array(
				'assignee_id'     => $assignee_id,
				'assignee_name'   => $user ? $user->display_name : '',
				'pending_tasks'   => $pending_tasks,
				'pending_count'   => count( $pending_tasks ),
				'completed_count' => $completed_count,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
