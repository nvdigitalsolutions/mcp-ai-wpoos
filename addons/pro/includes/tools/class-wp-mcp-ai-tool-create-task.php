<?php
/**
 * Tool for creating tasks.
 *
 * Allows AI assistants to create new tasks within projects.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new task.
 */
class WP_MCP_AI_Tool_Create_Task implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_task';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Task', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new task or updates an existing one if task_id is provided. Tasks can be associated with projects, assigned to users, and have due dates for calendar tracking.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'task_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Optional task ID. If provided, updates the existing task instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Task title (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Task description (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'project_id'  => array(
					'type'        => 'integer',
					'description' => __( 'ID of the project this task belongs to (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'Task status (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' ),
					'default'     => 'todo',
				),
				'priority'    => array(
					'type'        => 'string',
					'description' => __( 'Task priority (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'low', 'medium', 'high', 'urgent' ),
					'default'     => 'medium',
				),
				'due_date'    => array(
					'type'        => 'string',
					'description' => __( 'Task due date in ISO 8601 format (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'assigned_to' => array(
					'type'        => 'integer',
					'description' => __( 'User ID this task is assigned to (optional)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'title' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-write',
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
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create tasks.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check if this is an update operation.
		$task_id       = isset( $arguments['task_id'] ) ? absint( $arguments['task_id'] ) : 0;
		$is_update     = false;
		$existing_task = null;

		if ( $task_id ) {
			// Verify task exists and user has permission to update it.
			$existing_task = get_post( $task_id );

			if ( ! $existing_task || 'mcp_ai_task' !== $existing_task->post_type ) {
				return new WP_Error( 'wp_mcp_ai_task_not_found', __( 'Task not found.', 'mcp-ai-wpoos-pro' ) );
			}

			// Check permissions: must be author or have edit_others_posts capability.
			$is_author       = absint( $existing_task->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this task.', 'mcp-ai-wpoos-pro' ) );
			}

			$is_update = true;
		}

		// Validate and sanitize inputs.
		$title       = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$description = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$project_id  = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;
		$status      = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'todo';
		$priority    = isset( $arguments['priority'] ) ? sanitize_key( $arguments['priority'] ) : 'medium';
		$due_date    = isset( $arguments['due_date'] ) ? sanitize_text_field( $arguments['due_date'] ) : '';
		$assigned_to = isset( $arguments['assigned_to'] ) ? absint( $arguments['assigned_to'] ) : 0;

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Task title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate project exists.
		if ( $project_id > 0 ) {
			$project = get_post( $project_id );
			if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
				return new WP_Error( 'wp_mcp_ai_invalid_project', __( 'Invalid project ID.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		// Validate status.
		$valid_statuses = array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' );
		if ( ! in_array( $status, $valid_statuses, true ) ) {
			$status = 'todo';
		}

		// Validate priority.
		$valid_priorities = array( 'low', 'medium', 'high', 'urgent' );
		if ( ! in_array( $priority, $valid_priorities, true ) ) {
			$priority = 'medium';
		}

		// Validate due date.
		if ( $due_date && ! $this->validate_date( $due_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_due_date', __( 'Invalid due date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate assigned user.
		if ( $assigned_to > 0 && ! get_user_by( 'id', $assigned_to ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_user', __( 'Assigned user does not exist.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $is_update ) {
			// Update existing task.
			$post_data = array(
				'ID'           => $task_id,
				'post_title'   => $title,
				'post_content' => $description,
			);

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Update task metadata.
			update_post_meta( $task_id, '_task_status', $status );
			update_post_meta( $task_id, '_task_priority', $priority );

			if ( $project_id > 0 ) {
				update_post_meta( $task_id, '_task_project_id', $project_id );
			}

			if ( $due_date ) {
				update_post_meta( $task_id, '_task_due_date', $due_date );
			}

			if ( $assigned_to > 0 ) {
				update_post_meta( $task_id, '_task_assigned_to', $assigned_to );
			}

			$task = get_post( $task_id );

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: task title */
					__( 'Task updated: %s', 'mcp-ai-wpoos-pro' ),
					$title
				),
				'task_id' => $task_id,
				'task'    => array(
					'id'          => $task_id,
					'title'       => $title,
					'description' => $description,
					'project_id'  => $project_id ?: null,
					'status'      => $status,
					'priority'    => $priority,
					'due_date'    => $due_date,
					'assigned_to' => $assigned_to ?: null,
					'updated_at'  => $task->post_modified,
				),
				'updated' => true,
			);
		} else {
			// Create task post.
			$post_data = array(
				'post_type'    => 'mcp_ai_task',
				'post_title'   => $title,
				'post_content' => $description,
				'post_status'  => 'publish',
				'post_author'  => $current_user_id,
			);

			$task_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $task_id ) ) {
				return $task_id;
			}

			// Save task metadata.
			update_post_meta( $task_id, '_task_status', $status );
			update_post_meta( $task_id, '_task_priority', $priority );

			if ( $project_id > 0 ) {
				update_post_meta( $task_id, '_task_project_id', $project_id );
			}

			if ( $due_date ) {
				update_post_meta( $task_id, '_task_due_date', $due_date );
			}

			if ( $assigned_to > 0 ) {
				update_post_meta( $task_id, '_task_assigned_to', $assigned_to );
			}

			return array(
				'success' => true,
				'message' => __( 'Task created successfully.', 'mcp-ai-wpoos-pro' ),
				'task_id' => $task_id,
				'task'    => array(
					'id'          => $task_id,
					'title'       => $title,
					'description' => $description,
					'project_id'  => $project_id ?: null,
					'status'      => $status,
					'priority'    => $priority,
					'due_date'    => $due_date,
					'assigned_to' => $assigned_to ?: null,
					'created_at'  => current_time( 'mysql' ),
				),
				'updated' => false,
			);
		}
	}

	/**
	 * Validate date format (YYYY-MM-DD).
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function validate_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
