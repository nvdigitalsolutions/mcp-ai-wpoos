<?php
/**
 * Tool for adding a dependency between tasks.
 *
 * Records that one task must be completed before another can begin,
 * creating a "blocks / is-blocked-by" relationship in post meta.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a dependency link between two tasks.
 *
 * After calling this tool, `blocked_task_id` is blocked by `blocking_task_id`
 * (i.e. the blocking task must finish first).
 */
class WP_MCP_AI_Tool_Add_Task_Dependency implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Meta key: list of task IDs that must finish before this task can start.
	 */
	const META_DEPENDS_ON = '_task_depends_on';

	/**
	 * Meta key: list of task IDs that this task is blocking.
	 */
	const META_BLOCKS = '_task_blocks';

	/**
	 * Maximum number of graph nodes visited during cycle detection.
	 *
	 * Bounds the BFS traversal to prevent runaway execution on very large
	 * (but non-circular) dependency graphs. Each "visited" increment counts
	 * one BFS iteration (node dequeued), not one graph level.
	 */
	const MAX_NODES_VISITED = 50;

	/**
	 * Get the unique slug identifier for this tool.
	 *
	 * @return string Tool slug identifier.
	 */
	public function get_slug() {
		return 'add_task_dependency';
	}

	/**
	 * Get the human-readable name of this tool.
	 *
	 * @return string Tool name.
	 */
	public function get_name() {
		return __( 'Add Task Dependency', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the description of what this tool does.
	 *
	 * @return string Tool description.
	 */
	public function get_description() {
		return __( 'Creates a dependency between two tasks so that the blocking task must be completed before the blocked task can start. Use get_task_dependencies to view existing links.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'ID of the task that must be completed first (the blocker).', 'mcp-ai-wpoos-pro' ),
				),
				'blocked_task_id'  => array(
					'type'        => 'integer',
					'description' => __( 'ID of the task that cannot start until the blocking task is done.', 'mcp-ai-wpoos-pro' ),
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

		if ( $blocking_id === $blocked_id ) {
			return new WP_Error( 'wp_mcp_ai_self_dependency', __( 'A task cannot depend on itself.', 'mcp-ai-wpoos-pro' ) );
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

		// Cycle detection: adding blocked_id → blocking_id must not create a cycle.
		// i.e. blocking_id must not already (transitively) depend on blocked_id.
		if ( $this->would_create_cycle( $blocking_id, $blocked_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_dependency_cycle',
				__( 'Cannot add this dependency because it would create a circular dependency chain.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if the dependency already exists.
		$existing_depends = $this->get_depends_on( $blocked_id );
		if ( in_array( $blocking_id, $existing_depends, true ) ) {
			return array(
				'success'          => true,
				'message'          => __( 'Dependency already exists.', 'mcp-ai-wpoos-pro' ),
				'blocking_task_id' => $blocking_id,
				'blocked_task_id'  => $blocked_id,
				'already_existed'  => true,
			);
		}

		// Save: blocked_task depends_on blocking_task.
		$existing_depends[] = $blocking_id;
		update_post_meta( $blocked_id, self::META_DEPENDS_ON, array_values( array_unique( $existing_depends ) ) );

		// Save inverse: blocking_task blocks blocked_task.
		$existing_blocks   = $this->get_blocks( $blocking_id );
		$existing_blocks[] = $blocked_id;
		update_post_meta( $blocking_id, self::META_BLOCKS, array_values( array_unique( $existing_blocks ) ) );

		return array(
			'success'          => true,
			'message'          => sprintf(
				/* translators: 1: blocking task title, 2: blocked task title */
				__( 'Dependency added: "%1$s" must be completed before "%2$s".', 'mcp-ai-wpoos-pro' ),
				$blocking_task->post_title,
				$blocked_task->post_title
			),
			'blocking_task_id' => $blocking_id,
			'blocked_task_id'  => $blocked_id,
		);
	}

	/**
	 * Detect whether adding blocked_id → blocking_id would create a cycle.
	 *
	 * Returns true if blocking_id is already (transitively) reachable
	 * from blocked_id via the "depends_on" graph.
	 *
	 * @param int $blocking_id The proposed new blocking task.
	 * @param int $blocked_id  The proposed new blocked task (the start of the search).
	 * @return bool True if a cycle would be created, false otherwise.
	 */
	private function would_create_cycle( $blocking_id, $blocked_id ) {
		// BFS / iterative DFS from blocking_id upward through its own dependencies.
		// If we reach blocked_id, adding blocked_id → blocking_id would close the loop.
		$visited       = array();
		$queue         = array( $blocking_id );
		$nodes_visited = 0;

		while ( ! empty( $queue ) && $nodes_visited < self::MAX_NODES_VISITED ) {
			$current = array_shift( $queue );

			if ( isset( $visited[ $current ] ) ) {
				continue;
			}
			$visited[ $current ] = true;
			++$nodes_visited;

			if ( $current === $blocked_id ) {
				return true; // Cycle detected.
			}

			$upstream = $this->get_depends_on( $current );
			foreach ( $upstream as $upstream_id ) {
				if ( ! isset( $visited[ $upstream_id ] ) ) {
					$queue[] = $upstream_id;
				}
			}
		}

		return false;
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
