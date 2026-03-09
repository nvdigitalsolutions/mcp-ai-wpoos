<?php
/**
 * Tool for removing a dependency between tasks.
 *
 * Deletes an existing "blocks / is-blocked-by" relationship between two tasks.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes a dependency link between two tasks.
 */
class WP_MCP_AI_Tool_Remove_Task_Dependency implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Meta key: list of task IDs that must finish before this task can start.
	 */
	const META_DEPENDS_ON = '_task_depends_on';

	/**
	 * Meta key: list of task IDs that this task is blocking.
	 */
	const META_BLOCKS = '_task_blocks';

	/**
	 * Get the unique slug identifier for this tool.
	 *
	 * @return string Tool slug identifier.
	 */
	public function get_slug() {
		return 'remove_task_dependency';
	}

	/**
	 * Get the human-readable name of this tool.
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return __( 'Remove Task Dependency', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the description of what this tool does.
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return __( 'Removes an existing dependency between two tasks. After calling this tool the blocked task can proceed regardless of the blocking task status.', 'mcp-ai-wpoos-pro' );
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
				'blocking_task_id' => array(
					'type'        => 'integer',
					'description' => __( 'ID of the task that was acting as the blocker.', 'mcp-ai-wpoos-pro' ),
				),
				'blocked_task_id'  => array(
					'type'        => 'integer',
					'description' => __( 'ID of the task that was being blocked.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'blocking_task_id', 'blocked_task_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Get the capability flags required for this tool.
	 *
	 * @return array Array of capability flag strings.
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
	 * @return array|WP_Error Result array on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage task dependencies.', 'mcp-ai-wpoos-pro' ) );
		}

		$blocking_id = isset( $arguments['blocking_task_id'] ) ? absint( $arguments['blocking_task_id'] ) : 0;
		$blocked_id  = isset( $arguments['blocked_task_id'] ) ? absint( $arguments['blocked_task_id'] ) : 0;

		if ( ! $blocking_id || ! $blocked_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_ids', __( 'Both blocking_task_id and blocked_task_id are required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate both tasks exist.
		$blocking_task = get_post( $blocking_id );
		if ( ! $blocking_task || 'mcp_ai_task' !== $blocking_task->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_task', __( 'Blocking task not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$blocked_task = get_post( $blocked_id );
		if ( ! $blocked_task || 'mcp_ai_task' !== $blocked_task->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_task', __( 'Blocked task not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Remove blocking_id from blocked_task's depends_on list.
		$depends_on = $this->get_depends_on( $blocked_id );
		$found      = in_array( $blocking_id, $depends_on, true );

		if ( $found ) {
			$updated_depends = array_values( array_filter( $depends_on, fn( $id ) => $id !== $blocking_id ) );
			update_post_meta( $blocked_id, self::META_DEPENDS_ON, $updated_depends );
		}

		// Remove blocked_id from blocking_task's blocks list.
		$blocks         = $this->get_blocks( $blocking_id );
		$updated_blocks = array_values( array_filter( $blocks, fn( $id ) => $id !== $blocked_id ) );
		update_post_meta( $blocking_id, self::META_BLOCKS, $updated_blocks );

		if ( ! $found ) {
			return array(
				'success'          => true,
				'message'          => __( 'No such dependency existed. Nothing was changed.', 'mcp-ai-wpoos-pro' ),
				'blocking_task_id' => $blocking_id,
				'blocked_task_id'  => $blocked_id,
				'was_removed'      => false,
			);
		}

		return array(
			'success'          => true,
			'message'          => sprintf(
				/* translators: 1: blocking task title, 2: blocked task title */
				__( 'Dependency removed: "%1$s" no longer blocks "%2$s".', 'mcp-ai-wpoos-pro' ),
				$blocking_task->post_title,
				$blocked_task->post_title
			),
			'blocking_task_id' => $blocking_id,
			'blocked_task_id'  => $blocked_id,
			'was_removed'      => true,
		);
	}

	/**
	 * Get the list of task IDs that $task_id depends on (direct).
	 *
	 * @param int $task_id Task post ID.
	 * @return int[] Array of task IDs.
	 */
	private function get_depends_on( $task_id ) {
		$raw = get_post_meta( $task_id, self::META_DEPENDS_ON, true );
		return is_array( $raw ) ? array_map( 'absint', $raw ) : array();
	}

	/**
	 * Get the list of task IDs that $task_id blocks (direct).
	 *
	 * @param int $task_id Task post ID.
	 * @return int[] Array of task IDs.
	 */
	private function get_blocks( $task_id ) {
		$raw = get_post_meta( $task_id, self::META_BLOCKS, true );
		return is_array( $raw ) ? array_map( 'absint', $raw ) : array();
	}
}
