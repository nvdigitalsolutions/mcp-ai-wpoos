<?php
/**
 * Tool for updating tasks.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing task.
 */
class WP_MCP_AI_Tool_Update_Task implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public function get_slug() {
		return 'update_task';
	}

	public function get_name() {
		return __( 'Update Task', 'wp-mcp-ai' );
	}

	public function get_description() {
		return __( 'Updates an existing task. Provide only the fields you want to update.', 'wp-mcp-ai' );
	}

	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'task_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Task ID to update (required)', 'wp-mcp-ai' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'New task title (optional)', 'wp-mcp-ai' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'New task description (optional)', 'wp-mcp-ai' ),
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'New task status (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' ),
				),
				'priority'    => array(
					'type'        => 'string',
					'description' => __( 'New task priority (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'low', 'medium', 'high', 'urgent' ),
				),
				'due_date'    => array(
					'type'        => 'string',
					'description' => __( 'New due date (YYYY-MM-DD) (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'assigned_to' => array(
					'type'        => 'integer',
					'description' => __( 'New assigned user ID (optional)', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'task_id' ),
			'additionalProperties' => false,
		);
	}

	public function get_capability_flags() {
		return array( 'database-write' );
	}

	public static function is_available() {
		// Project management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		return (bool) get_option( 'wp_mcp_ai_enable_project_management', false );
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update tasks.', 'wp-mcp-ai' ) );
		}

		$task_id = isset( $arguments['task_id'] ) ? absint( $arguments['task_id'] ) : 0;
		
		if ( ! $task_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Task ID is required.', 'wp-mcp-ai' ) );
		}

		$task = get_post( $task_id );
		if ( ! $task || 'mcp_ai_task' !== $task->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_task', __( 'Invalid task ID.', 'wp-mcp-ai' ) );
		}

		$post_data = array( 'ID' => $task_id );

		if ( isset( $arguments['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $arguments['title'] );
		}

		if ( isset( $arguments['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $arguments['description'] );
		}

		if ( count( $post_data ) > 1 ) {
			wp_update_post( $post_data );
		}

		if ( isset( $arguments['status'] ) ) {
			update_post_meta( $task_id, '_task_status', sanitize_key( $arguments['status'] ) );
		}

		if ( isset( $arguments['priority'] ) ) {
			update_post_meta( $task_id, '_task_priority', sanitize_key( $arguments['priority'] ) );
		}

		if ( isset( $arguments['due_date'] ) ) {
			update_post_meta( $task_id, '_task_due_date', sanitize_text_field( $arguments['due_date'] ) );
		}

		if ( isset( $arguments['assigned_to'] ) ) {
			update_post_meta( $task_id, '_task_assigned_to', absint( $arguments['assigned_to'] ) );
		}

		return array(
			'success' => true,
			'message' => __( 'Task updated successfully.', 'wp-mcp-ai' ),
			'task_id' => $task_id,
		);
	}
}
