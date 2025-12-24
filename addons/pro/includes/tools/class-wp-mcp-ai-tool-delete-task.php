<?php
/**
 * Tool for deleting tasks.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes a task.
 */
class WP_MCP_AI_Tool_Delete_Task implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public function get_slug() {
		return 'delete_task';
	}

	public function get_name() {
		return __( 'Delete Task', 'wp-mcp-ai' );
	}

	public function get_description() {
		return __( 'Deletes a task permanently.', 'wp-mcp-ai' );
	}

	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'task_id' => array(
					'type'        => 'integer',
					'description' => __( 'Task ID to delete (required)', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'task_id' ),
			'additionalProperties' => false,
		);
	}

	public function get_capability_flags() {
		return array( 'database-write', 'destructive' );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete tasks.', 'wp-mcp-ai' ) );
		}

		$task_id = isset( $arguments['task_id'] ) ? absint( $arguments['task_id'] ) : 0;
		
		if ( ! $task_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Task ID is required.', 'wp-mcp-ai' ) );
		}

		$task = get_post( $task_id );
		if ( ! $task || 'mcp_ai_task' !== $task->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_task', __( 'Invalid task ID.', 'wp-mcp-ai' ) );
		}

		$result = wp_delete_post( $task_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete task.', 'wp-mcp-ai' ) );
		}

		return array(
			'success' => true,
			'message' => __( 'Task deleted successfully.', 'wp-mcp-ai' ),
			'task_id' => $task_id,
		);
	}
}
