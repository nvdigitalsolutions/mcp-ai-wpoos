<?php
/**
 * PM tool (ecosystem port — Wave F2, PM core CRUD + dependency batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/project-management/class-wp-mcp-ai-tool-update-task.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing task.
 */
class WP_MCP_AI_Tool_Update_Task implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return 'update_task';
	}

	/**
	 * Get the human-readable name of this tool.
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return __( 'Update Task', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the description of what this tool does.
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return __( 'Updates an existing task. Provide only the fields you want to update.', 'nvoos-content-graph-pro' );
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
				'task_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Task ID to update (required)', 'nvoos-content-graph-pro' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'New task title (optional)', 'nvoos-content-graph-pro' ),
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'New task description (optional)', 'nvoos-content-graph-pro' ),
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'New task status (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' ),
				),
				'priority'    => array(
					'type'        => 'string',
					'description' => __( 'New task priority (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'low', 'medium', 'high', 'urgent' ),
				),
				'due_date'    => array(
					'type'        => 'string',
					'description' => __( 'New due date (YYYY-MM-DD) (optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'assigned_to' => array(
					'type'        => 'integer',
					'description' => __( 'New assigned user ID (optional)', 'nvoos-content-graph-pro' ),
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
			'profession_tags'       => array( 'project_manager', 'developer', 'team_lead' ),
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
	 * @return array|WP_Error Array with success status and task details, or WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update tasks.', 'nvoos-content-graph-pro' ) );
		}

		$task_id = isset( $arguments['task_id'] ) ? absint( $arguments['task_id'] ) : 0;

		if ( ! $task_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Task ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$task = get_post( $task_id );
		if ( ! $task || 'mcp_ai_task' !== $task->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_task', __( 'Invalid task ID.', 'nvoos-content-graph-pro' ) );
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
			'message' => __( 'Task updated successfully.', 'nvoos-content-graph-pro' ),
			'task_id' => $task_id,
		);
	}
}
