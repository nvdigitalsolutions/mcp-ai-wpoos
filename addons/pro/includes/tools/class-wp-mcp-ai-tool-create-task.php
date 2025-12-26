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
		return __( 'Create Task', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new task. Tasks can be associated with projects, assigned to users, and have due dates for calendar tracking.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Task title (required)', 'wp-mcp-ai' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Task description (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 5000,
				),
				'project_id'  => array(
					'type'        => 'integer',
					'description' => __( 'ID of the project this task belongs to (optional)', 'wp-mcp-ai' ),
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'Task status (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' ),
					'default'     => 'todo',
				),
				'priority'    => array(
					'type'        => 'string',
					'description' => __( 'Task priority (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'low', 'medium', 'high', 'urgent' ),
					'default'     => 'medium',
				),
				'due_date'    => array(
					'type'        => 'string',
					'description' => __( 'Task due date in ISO 8601 format (YYYY-MM-DD) (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'assigned_to' => array(
					'type'        => 'integer',
					'description' => __( 'User ID this task is assigned to (optional)', 'wp-mcp-ai' ),
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
		return array( 'database-write' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create tasks.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
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
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Task title is required.', 'wp-mcp-ai' ) );
		}

		// Validate project exists.
		if ( $project_id > 0 ) {
			$project = get_post( $project_id );
			if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
				return new WP_Error( 'wp_mcp_ai_invalid_project', __( 'Invalid project ID.', 'wp-mcp-ai' ) );
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
			return new WP_Error( 'wp_mcp_ai_invalid_due_date', __( 'Invalid due date format. Use YYYY-MM-DD.', 'wp-mcp-ai' ) );
		}

		// Validate assigned user.
		if ( $assigned_to > 0 && ! get_user_by( 'id', $assigned_to ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_user', __( 'Assigned user does not exist.', 'wp-mcp-ai' ) );
		}

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
			'message' => __( 'Task created successfully.', 'wp-mcp-ai' ),
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
		);
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
