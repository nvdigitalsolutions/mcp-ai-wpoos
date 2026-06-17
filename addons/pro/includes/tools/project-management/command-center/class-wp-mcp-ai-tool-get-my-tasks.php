<?php
/**
 * Tool for getting tasks assigned to the current user.
 *
 * Returns tasks assigned to the requesting user, optionally filtered
 * by status and project, grouped by project for a "My Tasks" view.
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
 * Returns tasks assigned to the current user grouped by project.
 */
class WP_MCP_AI_Tool_Get_My_Tasks implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_my_tasks';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get My Tasks', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns tasks assigned to the current user. Results are grouped by project for easy organization. Optional filters: status, project_id, and limit. Use this tool for personal task lists and daily stand-ups.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'status'     => array(
					'type'        => 'string',
					'description' => __( 'Filter by task status (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' ),
				),
				'project_id' => array(
					'type'        => 'integer',
					'description' => __( 'Filter by project ID (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'limit'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of tasks to return (default: 50, max: 200)', 'mcp-ai-wpoos-pro' ),
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
	public function get_required_capability() {
		return 'edit_posts';
	}

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
			'risk_level'            => 'info',
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
			'read-only',
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

		if ( ! $current_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to view your tasks.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to list tasks.', 'mcp-ai-wpoos-pro' ) );
		}

		$limit = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 200 ) : 50;

		// Build query args filtered by current user.
		$query_args = array(
			'post_type'      => 'mcp_ai_task',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => array(
				'meta_value' => 'ASC',
				'date'       => 'DESC',
			),
			'meta_key'       => '_task_due_date',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'   => '_task_assigned_to',
					'value' => $current_user_id,
				),
			),
		);

		// Optional status filter.
		if ( ! empty( $arguments['status'] ) ) {
			$query_args['meta_query'][] = array(
				'key'   => '_task_status',
				'value' => sanitize_key( $arguments['status'] ),
			);
		}

		// Optional project filter.
		if ( ! empty( $arguments['project_id'] ) ) {
			$project_id = absint( $arguments['project_id'] );
			$project    = get_post( $project_id );

			if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
				return new WP_Error( 'wp_mcp_ai_invalid_project', __( 'Invalid project ID.', 'mcp-ai-wpoos-pro' ) );
			}

			$query_args['meta_query'][] = array(
				'key'   => '_task_project_id',
				'value' => $project_id,
			);
		}

		$query = new WP_Query( $query_args );
		$tasks_by_project = array();
		$task_list        = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$task_id    = get_the_ID();
				$project_id = absint( get_post_meta( $task_id, '_task_project_id', true ) ) ? absint( get_post_meta( $task_id, '_task_project_id', true ) ) : 0;

				$task = array(
					'id'               => $task_id,
					'title'            => get_the_title(),
					'description'      => get_the_content(),
					'status'           => get_post_meta( $task_id, '_task_status', true ) ? get_post_meta( $task_id, '_task_status', true ) : 'todo',
					'priority'         => get_post_meta( $task_id, '_task_priority', true ) ? get_post_meta( $task_id, '_task_priority', true ) : 'medium',
					'category'         => get_post_meta( $task_id, '_task_category', true ) ? get_post_meta( $task_id, '_task_category', true ) : 'general',
					'tags'             => get_post_meta( $task_id, '_task_tags', true ) ? get_post_meta( $task_id, '_task_tags', true ) : '',
					'due_date'         => get_post_meta( $task_id, '_task_due_date', true ) ? get_post_meta( $task_id, '_task_due_date', true ) : '',
					'assigned_to'      => $current_user_id,
					'estimated_effort' => floatval( get_post_meta( $task_id, '_task_estimated_effort', true ) ) ? floatval( get_post_meta( $task_id, '_task_estimated_effort', true ) ) : null,
					'actual_effort'    => floatval( get_post_meta( $task_id, '_task_actual_effort', true ) ) ? floatval( get_post_meta( $task_id, '_task_actual_effort', true ) ) : null,
					'project_id'       => $project_id,
					'created_at'       => get_the_date( 'c' ),
					'updated_at'       => get_the_modified_date( 'c' ),
				);

				// Group by project.
				$group_key = $project_id ? $project_id : 0;
				if ( ! isset( $tasks_by_project[ $group_key ] ) ) {
					$project_title = '';
					$project_status = '';
					if ( $project_id ) {
						$project_title  = get_the_title( $project_id );
						$project_status = get_post_meta( $project_id, '_project_status', true );
						$project_status = $project_status ? $project_status : 'unknown';
					}

					$tasks_by_project[ $group_key ] = array(
						'project_id'     => $project_id ? $project_id : null,
						'project_title'  => $project_title ? $project_title : __( 'No Project', 'mcp-ai-wpoos-pro' ),
						'project_status' => $project_status,
						'tasks'          => array(),
					);
				}

				$tasks_by_project[ $group_key ]['tasks'][] = $task;
				$task_list[] = $task;
			}
			wp_reset_postdata();
		}

		// Convert grouped map to indexed array for clean output.
		$grouped = array_values( $tasks_by_project );

		return array(
			'success' => true,
			'user_id' => $current_user_id,
			'count'   => count( $task_list ),
			'total'   => $query->found_posts,
			'grouped' => $grouped,
			'tasks'   => $task_list,
		);
	}
}
