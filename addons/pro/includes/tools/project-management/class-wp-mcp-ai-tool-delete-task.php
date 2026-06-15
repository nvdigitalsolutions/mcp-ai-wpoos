<?php
/**
 * Tool for deleting tasks.
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
 * Deletes a task.
 */
class WP_MCP_AI_Tool_Delete_Task implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get the unique slug identifier for this tool.
	 *
	 * @return string Tool slug identifier.
	 */
	public function get_slug() {
		return 'delete_task';
	}

	/**
	 * Get the human-readable name of this tool.
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return __( 'Delete Task', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the description of what this tool does.
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return __( 'Deletes a task permanently.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the JSON schema for the tool's parameters.
	 *
	 * @return array JSON schema array defining the parameters.
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'task_id' => array(
					'type'        => 'integer',
					'description' => __( 'Task ID to delete (required)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'task_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get the capability flags required for this tool.
	 *
	 * @return array Array of capability flag strings.
	 */

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
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'developer' ),
			'risk_level'            => 'high',
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
			'destructive',
		);
	}

	/**
	 * Check if this tool is available for use.
	 *
	 * @return bool True if tool is available, false otherwise.
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
	 * Execute the tool with the given arguments and context.
	 *
	 * @param array $arguments The arguments passed to the tool.
	 * @param array $context   The context in which the tool is being executed.
	 * @return array|WP_Error Array with success status and task ID, or WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete tasks.', 'mcp-ai-wpoos-pro' ) );
		}

		$task_id = isset( $arguments['task_id'] ) ? absint( $arguments['task_id'] ) : 0;

		if ( ! $task_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Task ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$task = get_post( $task_id );
		if ( ! $task || 'mcp_ai_task' !== $task->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_task', __( 'Invalid task ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$result = wp_delete_post( $task_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete task.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success' => true,
			'message' => __( 'Task deleted successfully.', 'mcp-ai-wpoos-pro' ),
			'task_id' => $task_id,
		);
	}
}
