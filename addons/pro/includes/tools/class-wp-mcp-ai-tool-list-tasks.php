<?php
/**
 * Tool for listing tasks.
 *
 * Allows AI assistants to list and filter tasks.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists tasks with filtering options.
 */
class WP_MCP_AI_Tool_List_Tasks implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_tasks';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Tasks', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists tasks with optional filtering by project, status, priority, due date, or assigned user. Supports calendar view by filtering tasks with due dates.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Filter by project ID (optional)', 'wp-mcp-ai' ),
				),
				'status'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by task status (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' ),
				),
				'priority'    => array(
					'type'        => 'string',
					'description' => __( 'Filter by priority (optional)', 'wp-mcp-ai' ),
					'enum'        => array( 'low', 'medium', 'high', 'urgent' ),
				),
				'assigned_to' => array(
					'type'        => 'integer',
					'description' => __( 'Filter by assigned user ID (optional)', 'wp-mcp-ai' ),
				),
				'due_after'   => array(
					'type'        => 'string',
					'description' => __( 'Filter tasks due after this date (YYYY-MM-DD) for calendar views (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'due_before'  => array(
					'type'        => 'string',
					'description' => __( 'Filter tasks due before this date (YYYY-MM-DD) for calendar views (optional)', 'wp-mcp-ai' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of tasks to return (default: 50, max: 200)', 'wp-mcp-ai' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'read-only' );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list tasks.', 'wp-mcp-ai' ) );
		}

		// Build query args.
		$query_args = array(
			'post_type'      => 'mcp_ai_task',
			'post_status'    => 'publish',
			'posts_per_page' => isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 200 ) : 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$meta_query = array();

		// Filter by project.
		if ( ! empty( $arguments['project_id'] ) ) {
			$meta_query[] = array(
				'key'     => '_task_project_id',
				'value'   => absint( $arguments['project_id'] ),
				'compare' => '=',
			);
		}

		// Filter by status.
		if ( ! empty( $arguments['status'] ) ) {
			$meta_query[] = array(
				'key'     => '_task_status',
				'value'   => sanitize_key( $arguments['status'] ),
				'compare' => '=',
			);
		}

		// Filter by priority.
		if ( ! empty( $arguments['priority'] ) ) {
			$meta_query[] = array(
				'key'     => '_task_priority',
				'value'   => sanitize_key( $arguments['priority'] ),
				'compare' => '=',
			);
		}

		// Filter by assigned user.
		if ( ! empty( $arguments['assigned_to'] ) ) {
			$meta_query[] = array(
				'key'     => '_task_assigned_to',
				'value'   => absint( $arguments['assigned_to'] ),
				'compare' => '=',
			);
		}

		// Filter by due date range (for calendar views).
		if ( ! empty( $arguments['due_after'] ) ) {
			$meta_query[] = array(
				'key'     => '_task_due_date',
				'value'   => sanitize_text_field( $arguments['due_after'] ),
				'compare' => '>=',
				'type'    => 'DATE',
			);
		}

		if ( ! empty( $arguments['due_before'] ) ) {
			$meta_query[] = array(
				'key'     => '_task_due_date',
				'value'   => sanitize_text_field( $arguments['due_before'] ),
				'compare' => '<=',
				'type'    => 'DATE',
			);
		}

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		$query = new WP_Query( $query_args );
		$tasks = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$task_id = get_the_ID();

				$tasks[] = array(
					'id'          => $task_id,
					'title'       => get_the_title(),
					'description' => get_the_content(),
					'project_id'  => absint( get_post_meta( $task_id, '_task_project_id', true ) ) ?: null,
					'status'      => get_post_meta( $task_id, '_task_status', true ) ?: 'todo',
					'priority'    => get_post_meta( $task_id, '_task_priority', true ) ?: 'medium',
					'due_date'    => get_post_meta( $task_id, '_task_due_date', true ) ?: '',
					'assigned_to' => absint( get_post_meta( $task_id, '_task_assigned_to', true ) ) ?: null,
					'created_at'  => get_the_date( 'c' ),
					'updated_at'  => get_the_modified_date( 'c' ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success' => true,
			'count'   => count( $tasks ),
			'total'   => $query->found_posts,
			'tasks'   => $tasks,
		);
	}
}
