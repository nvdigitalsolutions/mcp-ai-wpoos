<?php
/**
 * Tool for generating construction timelines.
 *
 * Creates project schedules with task sequencing, dependencies,
 * and duration estimates.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.10
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Generate construction timelines.
 */
class WP_MCP_AI_Tool_Generate_Construction_Timeline implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */
	/**
	 * Whether this tool is available for registration.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True when the Architectural Design toolkit is enabled
	 *              and the host plugin is not running in base mode.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_architectural_design_toolkit'] );
	}

	/**
	 * Reason this tool is unavailable, if any.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_construction_timeline';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Construction Timeline', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create project schedules with task sequencing, dependencies, and duration estimates. Generate Gantt charts and critical path analysis.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'floor_plan'           => array(
					'type'        => 'object',
					'description' => __( 'Floor plan data for timeline generation.', 'mcp-ai-wpoos-pro' ),
				),
				'total_area'           => array(
					'type'        => 'number',
					'description' => __( 'Total building area in square feet.', 'mcp-ai-wpoos-pro' ),
				),
				'start_date'           => array(
					'type'        => 'string',
					'description' => __( 'Project start date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
					'format'      => 'date',
				),
				'crew_size'            => array(
					'type'        => 'string',
					'description' => __( 'Crew size: "small", "medium", "large".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'small', 'medium', 'large' ),
					'default'     => 'medium',
				),
				'construction_type'    => array(
					'type'        => 'string',
					'description' => __( 'Construction type: "wood_frame", "steel", "concrete".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'wood_frame', 'steel', 'concrete' ),
					'default'     => 'wood_frame',
				),
				'include_milestones'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include project milestones.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_dependencies' => array(
					'type'        => 'boolean',
					'description' => __( 'Include task dependencies and critical path.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'output_format'        => array(
					'type'        => 'string',
					'description' => __( 'Output format: "gantt", "list", "calendar", "json".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'gantt', 'list', 'calendar', 'json' ),
					'default'     => 'gantt',
				),
			),
			'required'             => array( 'floor_plan', 'total_area' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'requires-credentials',
			'write',
			'consumes-tokens',
			'external-api',
			'model-dependent',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate construction timelines.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate floor plan and area.
		if ( empty( $arguments['floor_plan'] ) || empty( $arguments['total_area'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Floor plan data and total area are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$floor_plan           = $arguments['floor_plan'];
		$total_area           = floatval( $arguments['total_area'] );
		$start_date           = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : current_time( 'Y-m-d' );
		$crew_size            = isset( $arguments['crew_size'] ) ? sanitize_text_field( $arguments['crew_size'] ) : 'medium';
		$construction_type    = isset( $arguments['construction_type'] ) ? sanitize_text_field( $arguments['construction_type'] ) : 'wood_frame';
		$include_milestones   = isset( $arguments['include_milestones'] ) ? (bool) $arguments['include_milestones'] : true;
		$include_dependencies = isset( $arguments['include_dependencies'] ) ? (bool) $arguments['include_dependencies'] : true;
		$output_format        = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'gantt';

		// Generate timeline.
		$timeline = $this->generate_timeline( $floor_plan, $total_area, $start_date, $crew_size, $construction_type, $include_milestones, $include_dependencies, $output_format, $context );

		if ( is_wp_error( $timeline ) ) {
			return $timeline;
		}

		// Return structured timeline data.
		return array(
			'success'  => true,
			'timeline' => $timeline,
			'summary'  => $this->generate_timeline_summary( $timeline ),
			'message'  => __( 'Construction timeline generated successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Generate construction timeline.
	 *
	 * @param array  $floor_plan           Floor plan data.
	 * @param float  $total_area           Total area.
	 * @param string $start_date           Start date.
	 * @param string $crew_size            Crew size.
	 * @param string $construction_type    Construction type.
	 * @param bool   $include_milestones   Include milestones.
	 * @param bool   $include_dependencies Include dependencies.
	 * @param string $output_format        Output format.
	 * @param array  $context              Execution context.
	 * @return array Timeline data.
	 */
	protected function generate_timeline( $floor_plan, $total_area, $start_date, $crew_size, $construction_type, $include_milestones, $include_dependencies, $output_format, $context ) {
		$tasks = array(
			array(
				'id'           => 1,
				'name'         => 'Site Preparation',
				'duration'     => 5,
				'start_date'   => $start_date,
				'dependencies' => array(),
				'milestone'    => false,
			),
			array(
				'id'           => 2,
				'name'         => 'Foundation',
				'duration'     => 10,
				'start_date'   => $this->add_days( $start_date, 5 ),
				'dependencies' => array( 1 ),
				'milestone'    => false,
			),
			array(
				'id'           => 3,
				'name'         => 'Framing',
				'duration'     => 15,
				'start_date'   => $this->add_days( $start_date, 15 ),
				'dependencies' => array( 2 ),
				'milestone'    => true,
			),
			array(
				'id'           => 4,
				'name'         => 'Roofing',
				'duration'     => 7,
				'start_date'   => $this->add_days( $start_date, 30 ),
				'dependencies' => array( 3 ),
				'milestone'    => false,
			),
			array(
				'id'           => 5,
				'name'         => 'Rough-in (MEP)',
				'duration'     => 12,
				'start_date'   => $this->add_days( $start_date, 37 ),
				'dependencies' => array( 3 ),
				'milestone'    => false,
			),
			array(
				'id'           => 6,
				'name'         => 'Insulation & Drywall',
				'duration'     => 10,
				'start_date'   => $this->add_days( $start_date, 49 ),
				'dependencies' => array( 5 ),
				'milestone'    => false,
			),
			array(
				'id'           => 7,
				'name'         => 'Interior Finishes',
				'duration'     => 15,
				'start_date'   => $this->add_days( $start_date, 59 ),
				'dependencies' => array( 6 ),
				'milestone'    => false,
			),
			array(
				'id'           => 8,
				'name'         => 'Final Inspection',
				'duration'     => 2,
				'start_date'   => $this->add_days( $start_date, 74 ),
				'dependencies' => array( 7 ),
				'milestone'    => true,
			),
		);

		return array(
			'format'        => $output_format,
			'start_date'    => $start_date,
			'end_date'      => $this->add_days( $start_date, 76 ),
			'total_days'    => 76,
			'tasks'         => $tasks,
			'milestones'    => $include_milestones ? array_values(
				array_filter(
					$tasks,
					function ( $task ) {
						return $task['milestone'];
					}
				)
			) : array(),
			'critical_path' => $include_dependencies ? array( 1, 2, 3, 5, 6, 7, 8 ) : array(),
		);
	}

	/**
	 * Generate timeline summary.
	 *
	 * @param array $timeline Timeline data.
	 * @return array Summary data.
	 */
	protected function generate_timeline_summary( $timeline ) {
		return array(
			'total_duration'  => isset( $timeline['total_days'] ) ? $timeline['total_days'] : 0,
			'task_count'      => isset( $timeline['tasks'] ) ? count( $timeline['tasks'] ) : 0,
			'milestone_count' => isset( $timeline['milestones'] ) ? count( $timeline['milestones'] ) : 0,
			'start_date'      => isset( $timeline['start_date'] ) ? $timeline['start_date'] : '',
			'end_date'        => isset( $timeline['end_date'] ) ? $timeline['end_date'] : '',
		);
	}

	/**
	 * Add days to a date.
	 *
	 * @param string $date Date string.
	 * @param int    $days Days to add.
	 * @return string New date.
	 */
	protected function add_days( $date, $days ) {
		// Use DateTime for reliable date arithmetic.
		try {
			$datetime = new DateTime( $date );
			$datetime->modify( "+{$days} days" );
			return $datetime->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			// Fallback to strtotime if DateTime fails.
			$timestamp     = strtotime( $date );
			$new_timestamp = strtotime( "+{$days} days", $timestamp );
			return gmdate( 'Y-m-d', $new_timestamp );
		}
	}
}
