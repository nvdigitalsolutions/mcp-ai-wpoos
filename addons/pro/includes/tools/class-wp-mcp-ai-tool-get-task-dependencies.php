<?php
/**
 * Tool for retrieving task dependencies.
 *
 * Returns both "blocks" (tasks this task is blocking) and
 * "depends_on" (tasks this task is waiting for) relationships.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves dependency information for a task.
 */
class WP_MCP_AI_Tool_Get_Task_Dependencies implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'get_task_dependencies';
	}

	/**
	 * Get the human-readable name of this tool.
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return __( 'Get Task Dependencies', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the description of what this tool does.
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return __( 'Returns the full dependency graph for a task: which tasks it is waiting for (depends_on) and which tasks it is blocking (blocks). Includes task titles and statuses.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'ID of the task to retrieve dependencies for.', 'mcp-ai-wpoos-pro' ),
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
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view task dependencies.', 'mcp-ai-wpoos-pro' ) );
		}

		$task_id = isset( $arguments['task_id'] ) ? absint( $arguments['task_id'] ) : 0;

		if ( ! $task_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'task_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$task = get_post( $task_id );
		if ( ! $task || 'mcp_ai_task' !== $task->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_task', __( 'Task not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$depends_on_ids = $this->get_task_id_list( $task_id, self::META_DEPENDS_ON );
		$blocks_ids     = $this->get_task_id_list( $task_id, self::META_BLOCKS );

		$depends_on = $this->enrich_task_list( $depends_on_ids );
		$blocks     = $this->enrich_task_list( $blocks_ids );

		$can_start = empty( $depends_on_ids ) || $this->all_completed( $depends_on );

		return array(
			'success'      => true,
			'task_id'      => $task_id,
			'task_title'   => $task->post_title,
			'task_status'  => get_post_meta( $task_id, '_task_status', true ) ?: 'todo',
			'can_start'    => $can_start,
			'depends_on'   => $depends_on,
			'blocks'       => $blocks,
			'summary'      => sprintf(
				/* translators: 1: count of tasks this task depends on, 2: count of tasks this task blocks */
				__( 'This task depends on %1$d task(s) and blocks %2$d task(s).', 'mcp-ai-wpoos-pro' ),
				count( $depends_on ),
				count( $blocks )
			),
		);
	}

	/**
	 * Read a list of task IDs from a post meta key.
	 *
	 * @param int    $task_id  Task post ID.
	 * @param string $meta_key The meta key to read.
	 * @return int[] Array of task IDs.
	 */
	private function get_task_id_list( $task_id, $meta_key ) {
		$raw = get_post_meta( $task_id, $meta_key, true );
		return is_array( $raw ) ? array_values( array_map( 'absint', array_filter( $raw ) ) ) : array();
	}

	/**
	 * Add title and status information to a list of task IDs.
	 *
	 * @param int[] $ids Array of task post IDs.
	 * @return array[] Array of enriched task data arrays.
	 */
	private function enrich_task_list( array $ids ) {
		$result = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'mcp_ai_task' !== $post->post_type ) {
				continue;
			}
			$result[] = array(
				'task_id'    => $id,
				'title'      => $post->post_title,
				'status'     => get_post_meta( $id, '_task_status', true ) ?: 'todo',
				'due_date'   => get_post_meta( $id, '_task_due_date', true ) ?: null,
				'priority'   => get_post_meta( $id, '_task_priority', true ) ?: 'medium',
			);
		}
		return $result;
	}

	/**
	 * Check whether all tasks in the list have completed status.
	 *
	 * @param array[] $tasks Enriched task data (output of enrich_task_list()).
	 * @return bool True if all tasks are completed, false otherwise.
	 */
	private function all_completed( array $tasks ) {
		foreach ( $tasks as $task ) {
			if ( 'completed' !== $task['status'] ) {
				return false;
			}
		}
		return true;
	}
}
