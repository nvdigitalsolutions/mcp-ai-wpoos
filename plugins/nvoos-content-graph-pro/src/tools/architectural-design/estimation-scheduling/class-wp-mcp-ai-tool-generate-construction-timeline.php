<?php
/**
 * Tool_Generate_Construction_Timeline (ecosystem port - Wave F2, architectural-design tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/architectural-design/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * per-file seams — the base-owned interface/Logger/media-url-utils requires gain exists-check seams
 * resolving from the addon's D8-compat `src/` copies, the response/subprocess traits are
 * wave-proof-guarded, the openai/gemini client requires stay monolith-gated, and the
 * `WP_MCP_AI_PRO_PATH` refs swap to `NVOOS_CONTENT_GRAPH_PRO_PATH` with the `src/` root.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Architectural_Design
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface require gains an
// exists-check seam resolving from the addon's D8-compat copy.
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}


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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
		return __( 'Architectural Design toolkit is not enabled.', 'nvoos-content-graph-pro' );
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
		return __( 'Generate Construction Timeline', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create project schedules with task sequencing, dependencies, and duration estimates. Generate Gantt charts and critical path analysis.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Floor plan data for timeline generation.', 'nvoos-content-graph-pro' ),
				),
				'total_area'           => array(
					'type'        => 'number',
					'description' => __( 'Total building area in square feet.', 'nvoos-content-graph-pro' ),
				),
				'start_date'           => array(
					'type'        => 'string',
					'description' => __( 'Project start date (YYYY-MM-DD).', 'nvoos-content-graph-pro' ),
					'format'      => 'date',
				),
				'crew_size'            => array(
					'type'        => 'string',
					'description' => __( 'Crew size: "small", "medium", "large".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'small', 'medium', 'large' ),
					'default'     => 'medium',
				),
				'construction_type'    => array(
					'type'        => 'string',
					'description' => __( 'Construction type: "wood_frame", "steel", "concrete".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'wood_frame', 'steel', 'concrete' ),
					'default'     => 'wood_frame',
				),
				'include_milestones'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include project milestones.', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'include_dependencies' => array(
					'type'        => 'boolean',
					'description' => __( 'Include task dependencies and critical path.', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'output_format'        => array(
					'type'        => 'string',
					'description' => __( 'Output format: "gantt", "list", "calendar", "json".', 'nvoos-content-graph-pro' ),
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate construction timelines.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate floor plan and area.
		if ( empty( $arguments['floor_plan'] ) || empty( $arguments['total_area'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Floor plan data and total area are required.', 'nvoos-content-graph-pro' )
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
			'message'  => __( 'Construction timeline generated successfully.', 'nvoos-content-graph-pro' ),
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
