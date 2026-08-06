<?php
/**
 * Tool for getting the project pipeline.
 *
 * Returns projects grouped by lifecycle stage for pipeline and Kanban
 * visualization.
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
 * Returns projects grouped by pipeline stage.
 */
class WP_MCP_AI_Tool_Get_Project_Pipeline implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_project_pipeline';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Project Pipeline', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns all projects grouped by lifecycle stage (idea, planning, active, at-risk, on-hold, completed, cancelled, archived). Each stage includes a count and list of projects with their ID, title, status, and task count. Optionally filter by a specific stage slug.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'status' => array(
					'type'        => 'string',
					'description' => __( 'Optional stage slug to filter by (e.g. "active", "planning", "at-risk"). If omitted, returns all stages.', 'mcp-ai-wpoos-pro' ),
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
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'team_lead', 'executive' ),
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view the project pipeline.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get pipeline stage definitions.
		$stages = array();
		if ( class_exists( 'WP_MCP_AI_PM_Pipeline_Stages' ) ) {
			$stages = WP_MCP_AI_PM_Pipeline_Stages::get_stages();
		}

		// Filter by specific stage if requested.
		$filter_status = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';

		// Get all projects.
		$query_args = array(
			'post_type'      => 'mcp_ai_project',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( '' !== $filter_status ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_project_status',
					'value' => $filter_status,
				),
			);
		}

		$projects_query = new WP_Query( $query_args );
		$all_projects   = array();

		if ( $projects_query->have_posts() ) {
			while ( $projects_query->have_posts() ) {
				$projects_query->the_post();
				$project_id = get_the_ID();
				$status     = get_post_meta( $project_id, '_project_status', true );
				$status     = $status ? sanitize_key( $status ) : 'planning';

				if ( ! isset( $all_projects[ $status ] ) ) {
					$all_projects[ $status ] = array();
				}

				$all_projects[ $status ][] = array(
					'id'         => $project_id,
					'title'      => get_the_title(),
					'status'     => $status,
					'start_date' => get_post_meta( $project_id, '_project_start_date', true ) ? get_post_meta( $project_id, '_project_start_date', true ) : '',
					'end_date'   => get_post_meta( $project_id, '_project_end_date', true ) ? get_post_meta( $project_id, '_project_end_date', true ) : '',
				);
			}
			wp_reset_postdata();
		}

		// Build pipeline response grouped by stage.
		$pipeline = array();

		foreach ( $stages as $stage_slug => $stage_def ) {
			// If filtering by a specific status, skip non-matching stages.
			if ( '' !== $filter_status && $stage_slug !== $filter_status ) {
				continue;
			}

			$projects_in_stage = isset( $all_projects[ $stage_slug ] ) ? $all_projects[ $stage_slug ] : array();

			// For each project, get task count.
			$projects_with_tasks = array();
			foreach ( $projects_in_stage as $proj ) {
				$task_count = 0;
				if ( class_exists( 'WP_MCP_AI_PM_Engine' ) ) {
					$task_count = WP_MCP_AI_PM_Engine::count_tasks( $proj['id'] );
				}
				$proj['task_count']    = $task_count;
				$projects_with_tasks[] = $proj;
			}

			$pipeline[] = array(
				'stage'        => $stage_slug,
				'label'        => isset( $stage_def['label'] ) ? $stage_def['label'] : $stage_slug,
				'count'        => count( $projects_with_tasks ),
				'probability'  => isset( $stage_def['probability'] ) ? (float) $stage_def['probability'] : 0.0,
				'is_completed' => isset( $stage_def['is_completed'] ) ? (bool) $stage_def['is_completed'] : false,
				'is_cancelled' => isset( $stage_def['is_cancelled'] ) ? (bool) $stage_def['is_cancelled'] : false,
				'is_archived'  => isset( $stage_def['is_archived'] ) ? (bool) $stage_def['is_archived'] : false,
				'color'        => isset( $stage_def['color'] ) ? $stage_def['color'] : '',
				'projects'     => $projects_with_tasks,
			);
		}

		$total_projects = array_sum( array_map( 'count', $all_projects ) );

		return array(
			'success'        => true,
			'total_projects' => $total_projects,
			'pipeline'       => $pipeline,
		);
	}
}
