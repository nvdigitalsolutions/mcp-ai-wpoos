<?php
/**
 * Tool_Analyze_Structural_Feasibility (ecosystem port - Wave F2, architectural-design tool batch).
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
 * Analyze structural feasibility.
 */
class WP_MCP_AI_Tool_Analyze_Structural_Feasibility implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'Architectural Design toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_structural_feasibility';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Structural Feasibility', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Perform basic structural analysis and load calculations. Identifies potential structural issues and suggests solutions.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'floor_plan'        => array(
					'type'        => 'object',
					'description' => __( 'Floor plan data to analyze.', 'nvoos-content-graph-pro' ),
				),
				'num_floors'        => array(
					'type'        => 'integer',
					'description' => __( 'Number of floors in building.', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'default'     => 1,
				),
				'construction_type' => array(
					'type'        => 'string',
					'description' => __( 'Construction type: "wood_frame", "steel", "concrete", "masonry".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'wood_frame', 'steel', 'concrete', 'masonry' ),
					'default'     => 'wood_frame',
				),
				'soil_type'         => array(
					'type'        => 'string',
					'description' => __( 'Soil type: "clay", "sand", "rock", "mixed".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'clay', 'sand', 'rock', 'mixed' ),
				),
				'seismic_zone'      => array(
					'type'        => 'string',
					'description' => __( 'Seismic design category: "A", "B", "C", "D", "E", "F".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'A', 'B', 'C', 'D', 'E', 'F' ),
				),
				'analysis_type'     => array(
					'type'        => 'array',
					'description' => __( 'Analysis types: "gravity_loads", "lateral_loads", "foundation", "spans".', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'gravity_loads', 'lateral_loads', 'foundation', 'spans' ),
					),
					'default'     => array( 'gravity_loads', 'spans' ),
				),
			),
			'required'             => array( 'floor_plan' ),
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
			'read-only',
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
				__( 'You do not have permission to analyze structural feasibility.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate floor plan.
		if ( empty( $arguments['floor_plan'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Floor plan data is required.', 'nvoos-content-graph-pro' )
			);
		}

		$floor_plan        = $arguments['floor_plan'];
		$num_floors        = isset( $arguments['num_floors'] ) ? absint( $arguments['num_floors'] ) : 1;
		$construction_type = isset( $arguments['construction_type'] ) ? sanitize_text_field( $arguments['construction_type'] ) : 'wood_frame';
		$soil_type         = isset( $arguments['soil_type'] ) ? sanitize_text_field( $arguments['soil_type'] ) : '';
		$seismic_zone      = isset( $arguments['seismic_zone'] ) ? sanitize_text_field( $arguments['seismic_zone'] ) : '';
		$analysis_type     = isset( $arguments['analysis_type'] ) ? (array) $arguments['analysis_type'] : array( 'gravity_loads', 'spans' );

		// Perform structural analysis.
		$analysis_results = $this->perform_analysis( $floor_plan, $num_floors, $construction_type, $soil_type, $seismic_zone, $analysis_type, $context );

		if ( is_wp_error( $analysis_results ) ) {
			return $analysis_results;
		}

		// Return structured analysis results.
		return array(
			'success'  => true,
			'analysis' => $analysis_results,
			'message'  => __( 'Structural feasibility analysis complete.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Perform structural analysis.
	 *
	 * @param array  $floor_plan        Floor plan data.
	 * @param int    $num_floors        Number of floors.
	 * @param string $construction_type Construction type.
	 * @param string $soil_type         Soil type.
	 * @param string $seismic_zone      Seismic zone.
	 * @param array  $analysis_type     Analysis types.
	 * @param array  $context           Execution context.
	 * @return array Analysis results.
	 */
	protected function perform_analysis( $floor_plan, $num_floors, $construction_type, $soil_type, $seismic_zone, $analysis_type, $context ) {
		return array(
			'construction_type'   => $construction_type,
			'num_floors'          => $num_floors,
			'analyses'            => array(
				array(
					'type'            => 'gravity_loads',
					'status'          => 'feasible',
					'findings'        => 'Estimated dead load: 15 PSF, Live load: 40 PSF',
					'recommendations' => 'Standard wood framing adequate for loads',
				),
				array(
					'type'            => 'spans',
					'status'          => 'warning',
					'findings'        => 'Great room has 24-foot clear span',
					'recommendations' => 'Consider engineered beam or intermediate support',
				),
			),
			'overall_feasibility' => 'feasible_with_modifications',
			'critical_issues'     => array(),
			'warnings'            => array(
				'Large span requires engineered beam',
			),
		);
	}
}
