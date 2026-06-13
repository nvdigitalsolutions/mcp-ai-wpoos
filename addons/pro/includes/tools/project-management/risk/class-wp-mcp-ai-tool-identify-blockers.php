<?php
/**
 * Tool for identifying blockers.
 *
 * Detects explicitly blocked tasks (status=blocked) and implicitly
 * blocked tasks (unmet dependencies) grouped by project.
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
 * Identifies explicit and implicit blockers across projects.
 */
class WP_MCP_AI_Tool_Identify_Blockers implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'identify_blockers';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Identify Blockers', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Identify all blocked tasks across projects. Detects both explicitly blocked tasks (status=blocked) and implicitly blocked tasks (unmet dependencies). Each blocker includes duration, project context, and dependency chain information.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_id' => array(
					'type'        => 'integer',
					'description' => __( 'Optional project ID to scope detection to a single project.', 'mcp-ai-wpoos-pro' ),
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
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'scrum_master', 'team_lead' ),
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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_project_management'] ) ) {
			return false;
		}
		return true;
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to identify blockers.', 'mcp-ai-wpoos-pro' ) );
		}

		$project_id = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;

		// Validate project if specified.
		if ( $project_id ) {
			$project = get_post( $project_id );
			if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
				return new WP_Error( 'wp_mcp_ai_project_not_found', __( 'Project not found.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		// Build query args for tasks.
		$query_args = array(
			'post_type'      => 'mcp_ai_task',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array(
				array(
					'key'     => '_task_status',
					'value'   => array( 'completed', 'cancelled' ),
					'compare' => 'NOT IN',
				),
			),
		);

		if ( $project_id ) {
			$query_args['meta_query'][] = array(
				'key'     => '_task_project_id',
				'value'   => $project_id,
				'compare' => '=',
			);
		}

		$tasks = get_posts( $query_args );

		$explicit_blockers  = array();
		$implicit_blockers  = array();
		$now                = time();

		// First pass: collect all tasks and their dependencies.
		$all_task_deps  = array();
		$all_task_status = array();

		foreach ( $tasks as $task ) {
			$tid    = $task->ID;
			$status = get_post_meta( $tid, '_task_status', true );
			$all_task_status[ $tid ] = $status;

			$deps = get_post_meta( $tid, '_task_dependencies', true );
			if ( ! is_array( $deps ) ) {
				$deps = array();
			}
			$all_task_deps[ $tid ] = array_map( 'absint', $deps );
		}

		// Second pass: classify blockers.
		foreach ( $tasks as $task ) {
			$tid         = $task->ID;
			$status      = isset( $all_task_status[ $tid ] ) ? $all_task_status[ $tid ] : '';
			$priority    = get_post_meta( $tid, '_task_priority', true );
			$task_project_id = absint( get_post_meta( $tid, '_task_project_id', true ) );
			$project_title    = $task_project_id ? get_the_title( $task_project_id ) : '';

			// Explicit blockers: status=blocked.
			if ( 'blocked' === $status ) {
				$block_date_meta = get_post_meta( $tid, '_task_blocked_date', true );
				$block_since     = $block_date_meta ? strtotime( $block_date_meta ) : strtotime( $task->post_modified );
				$block_duration  = max( 0, (int) ceil( ( $now - $block_since ) / DAY_IN_SECONDS ) );

				$explicit_blockers[] = array(
					'task_id'            => $tid,
					'title'              => $task->post_title,
					'project_id'         => $task_project_id ? $task_project_id : null,
					'project_title'      => $project_title ? $project_title : '',
					'is_explicit'        => true,
					'is_implicit'        => false,
					'block_duration_days' => $block_duration,
					'blocking_task_ids'  => array(),
					'status'             => $status,
					'priority'           => $priority ? $priority : 'medium',
				);
			}

			// Implicit blockers: tasks with unmet dependencies.
			$deps = isset( $all_task_deps[ $tid ] ) ? $all_task_deps[ $tid ] : array();
			if ( ! empty( $deps ) && 'blocked' !== $status ) {
				$unmet_deps = array();
				foreach ( $deps as $dep_id ) {
					$dep_status = isset( $all_task_status[ $dep_id ] ) ? $all_task_status[ $dep_id ] : '';
					if ( 'completed' !== $dep_status && 'cancelled' !== $dep_status ) {
						$unmet_deps[] = $dep_id;
					}
				}

				if ( ! empty( $unmet_deps ) ) {
					$implicit_blockers[] = array(
						'task_id'            => $tid,
						'title'              => $task->post_title,
						'project_id'         => $task_project_id ? $task_project_id : null,
						'project_title'      => $project_title ? $project_title : '',
						'is_explicit'        => false,
						'is_implicit'        => true,
						'block_duration_days' => null,
						'blocking_task_ids'  => $unmet_deps,
						'status'             => $status,
						'priority'           => $priority ? $priority : 'medium',
					);
				}
			}
		}

		// Combine all blockers.
		$all_blockers = array_merge( $explicit_blockers, $implicit_blockers );

		// Group by project.
		$by_project = array();
		foreach ( $all_blockers as $blocker ) {
			$pid = $blocker['project_id'] ? $blocker['project_id'] : 0;
			if ( ! isset( $by_project[ $pid ] ) ) {
				$by_project[ $pid ] = array(
					'project_id'    => $pid ? $pid : null,
					'project_title' => $blocker['project_title'],
					'blockers'      => array(),
				);
			}
			$by_project[ $pid ]['blockers'][] = $blocker;
		}

		return array(
			'success'             => true,
			'project_id'          => $project_id ? $project_id : null,
			'total_blockers'      => count( $all_blockers ),
			'explicit_count'      => count( $explicit_blockers ),
			'implicit_count'      => count( $implicit_blockers ),
			'blockers_by_project' => array_values( $by_project ),
			'all_blockers'        => $all_blockers,
		);
	}
}
