<?php
/**
 * Tool for generating project timeline / Gantt data.
 *
 * Produces a task list sorted by start date with dependency edges,
 * milestone markers, and computed critical path for Gantt-chart
 * rendering.
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
 * Generates project timeline data including critical path.
 */
class WP_MCP_AI_Tool_Get_Project_Timeline implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_project_timeline';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Project Timeline', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate project timeline / Gantt chart data for a project. Returns tasks sorted by start date with dependency edges, milestone markers, and a computed critical path. Each task includes duration, status, and dependency relationships.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Project ID (required).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'project_id' ),
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
			'post_type'             => 'mcp_ai_project',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'team_lead', 'developer' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view project timelines.', 'mcp-ai-wpoos-pro' ) );
		}

		$project_id = isset( $arguments['project_id'] ) ? absint( $arguments['project_id'] ) : 0;

		$project = get_post( $project_id );
		if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
			return new WP_Error( 'wp_mcp_ai_project_not_found', __( 'Project not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get all tasks for the project.
		$tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_key'       => '_task_project_id',
				'meta_value'     => $project_id,
			)
		);

		if ( empty( $tasks ) ) {
			return array(
				'success'      => true,
				'project_id'   => $project_id,
				'timeline'     => array(),
				'critical_path' => array(),
			);
		}

		// Build task map and dependency graph.
		$task_map       = array();
		$task_data      = array();
		$dependencies   = array(); // task_id => array of dependency task IDs.
		$in_degree      = array(); // For topological sort / critical path.

		foreach ( $tasks as $task ) {
			$tid          = $task->ID;
			$task_map[ $tid ] = $task;

			$start_date = get_post_meta( $tid, '_task_start_date', true );
			$due_date   = get_post_meta( $tid, '_task_due_date', true );
			$status     = get_post_meta( $tid, '_task_status', true );
			$priority   = get_post_meta( $tid, '_task_priority', true );
			$is_milestone = get_post_meta( $tid, '_task_is_milestone', true );

			// Fall back start date if not set.
			if ( ! $start_date ) {
				$start_date = gmdate( 'Y-m-d', strtotime( $task->post_date ) );
			}

			$duration_days = 0;
			if ( $start_date && $due_date ) {
				$duration_days = max( 0, (int) ceil( ( strtotime( $due_date ) - strtotime( $start_date ) ) / DAY_IN_SECONDS ) );
			}

			// Read dependency meta.
			$deps = get_post_meta( $tid, '_task_dependencies', true );
			if ( ! is_array( $deps ) ) {
				$deps = array();
			}
			$deps = array_map( 'absint', $deps );
			$dependencies[ $tid ] = $deps;
			$in_degree[ $tid ]    = count( $deps );

			$task_data[ $tid ] = array(
				'id'             => $tid,
				'title'          => $task->post_title,
				'start_date'     => $start_date,
				'end_date'       => $due_date ? $due_date : '',
				'status'         => $status ? $status : 'todo',
				'priority'       => $priority ? $priority : 'medium',
				'dependencies'   => $deps,
				'is_milestone'   => ! empty( $is_milestone ),
				'duration_days'  => $duration_days,
				'is_critical_path' => false,
			);
		}

		// Compute critical path using longest-path algorithm on DAG.
		// First, compute earliest finish time for each task via topological order.
		$earliest_finish = array();
		$latest_finish   = array();

		// Kahn's algorithm for topological order.
		$queue      = array();
		$in_deg     = $in_degree;
		$topo_order = array();

		foreach ( $in_deg as $tid => $deg ) {
			if ( 0 === $deg ) {
				$queue[] = $tid;
			}
		}

		while ( ! empty( $queue ) ) {
			$tid = array_shift( $queue );
			$topo_order[] = $tid;

			// Find tasks that depend on this one.
			foreach ( $dependencies as $other_tid => $deps ) {
				if ( in_array( $tid, $deps, true ) ) {
					$in_deg[ $other_tid ]--;
					if ( 0 === $in_deg[ $other_tid ] ) {
						$queue[] = $other_tid;
					}
				}
			}
		}

		// Forward pass: compute earliest start/finish.
		foreach ( $topo_order as $tid ) {
			$td   = $task_data[ $tid ];
			$start_ts = strtotime( $td['start_date'] );

			// Earliest start depends on dependencies' earliest finish.
			$earliest_start = $start_ts;
			foreach ( $dependencies[ $tid ] as $dep_id ) {
				if ( isset( $earliest_finish[ $dep_id ] ) ) {
					$earliest_start = max( $earliest_start, $earliest_finish[ $dep_id ] );
				}
			}

			$duration_secs          = $td['duration_days'] * DAY_IN_SECONDS;
			$earliest_finish[ $tid ] = $earliest_start + $duration_secs;
		}

		// Backward pass from the end.
		$max_finish = ! empty( $earliest_finish ) ? max( $earliest_finish ) : time();
		foreach ( array_reverse( $topo_order ) as $tid ) {
			$td = $task_data[ $tid ];

			// Find tasks this one depends on (successors).
			$successor_latest = $max_finish;
			foreach ( $dependencies as $other_tid => $deps ) {
				if ( in_array( $tid, $deps, true ) && isset( $latest_finish[ $other_tid ] ) ) {
					$successor_latest = min( $successor_latest, $latest_finish[ $other_tid ] );
				}
			}

			// Default: set to max finish for end nodes.
			if ( ! isset( $latest_finish[ $tid ] ) ) {
				$latest_finish[ $tid ] = $successor_latest;
			}

			// Push back to dependencies.
			foreach ( $dependencies[ $tid ] as $dep_id ) {
				$dep_latest = $latest_finish[ $tid ] - ( $task_data[ $tid ]['duration_days'] * DAY_IN_SECONDS );
				if ( ! isset( $latest_finish[ $dep_id ] ) || $latest_finish[ $dep_id ] > $dep_latest ) {
					$latest_finish[ $dep_id ] = $dep_latest;
				}
			}
		}

		// Mark critical path: tasks where earliest_finish == latest_finish (zero slack).
		$critical_path_ids = array();
		foreach ( $topo_order as $tid ) {
			if ( isset( $earliest_finish[ $tid ] ) && isset( $latest_finish[ $tid ] ) ) {
				$slack = $latest_finish[ $tid ] - $earliest_finish[ $tid ];
				if ( abs( $slack ) <= DAY_IN_SECONDS ) { // Within 1 day tolerance.
					$task_data[ $tid ]['is_critical_path'] = true;
					$critical_path_ids[] = $tid;
				}
			}
		}

		// Build timeline sorted by start date.
		$timeline = array_values( $task_data );
		usort(
			$timeline,
			function ( $a, $b ) {
				return strtotime( $a['start_date'] ) - strtotime( $b['start_date'] );
			}
		);

		return array(
			'success'       => true,
			'project_id'    => $project_id,
			'project_title' => $project->post_title,
			'timeline'      => $timeline,
			'critical_path' => $critical_path_ids,
		);
	}
}
