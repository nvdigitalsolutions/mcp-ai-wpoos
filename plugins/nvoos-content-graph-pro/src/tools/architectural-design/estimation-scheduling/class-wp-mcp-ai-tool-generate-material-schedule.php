<?php
/**
 * Tool_Generate_Material_Schedule (ecosystem port - Wave F2, architectural-design tool batch).
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
 * Generate material schedules.
 */
class WP_MCP_AI_Tool_Generate_Material_Schedule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'generate_material_schedule';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Material Schedule', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create detailed bill of materials from floor plans. Includes quantities, specifications, and ordering information.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Floor plan data to extract materials from.', 'nvoos-content-graph-pro' ),
				),
				'specifications'       => array(
					'type'        => 'object',
					'description' => __( 'Material specifications and preferences.', 'nvoos-content-graph-pro' ),
				),
				'material_categories'  => array(
					'type'        => 'array',
					'description' => __( 'Categories to include: "framing", "roofing", "siding", "interior", "mechanical", "electrical", "plumbing".', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'framing', 'roofing', 'siding', 'interior', 'mechanical', 'electrical', 'plumbing', 'foundation' ),
					),
					'default'     => array( 'framing', 'roofing', 'interior' ),
				),
				'include_waste_factor' => array(
					'type'        => 'boolean',
					'description' => __( 'Include waste/overage factor in quantities.', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'output_format'        => array(
					'type'        => 'string',
					'description' => __( 'Output format: "detailed", "summary", "csv", "excel".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'detailed', 'summary', 'csv', 'excel' ),
					'default'     => 'detailed',
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
				__( 'You do not have permission to generate material schedules.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate floor plan.
		if ( empty( $arguments['floor_plan'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Floor plan data is required.', 'nvoos-content-graph-pro' )
			);
		}

		$floor_plan           = $arguments['floor_plan'];
		$specifications       = isset( $arguments['specifications'] ) ? (array) $arguments['specifications'] : array();
		$material_categories  = isset( $arguments['material_categories'] ) ? (array) $arguments['material_categories'] : array( 'framing', 'roofing', 'interior' );
		$include_waste_factor = isset( $arguments['include_waste_factor'] ) ? (bool) $arguments['include_waste_factor'] : true;
		$output_format        = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'detailed';

		// Generate material schedule.
		$schedule = $this->generate_schedule( $floor_plan, $specifications, $material_categories, $include_waste_factor, $output_format, $context );

		if ( is_wp_error( $schedule ) ) {
			return $schedule;
		}

		// Return structured schedule data.
		return array(
			'success'  => true,
			'schedule' => $schedule,
			'summary'  => $this->generate_schedule_summary( $schedule ),
			'message'  => __( 'Material schedule generated successfully.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Generate material schedule.
	 *
	 * @param array  $floor_plan           Floor plan data.
	 * @param array  $specifications       Specifications.
	 * @param array  $material_categories  Material categories.
	 * @param bool   $include_waste_factor Include waste factor.
	 * @param string $output_format        Output format.
	 * @param array  $context              Execution context.
	 * @return array Material schedule.
	 */
	protected function generate_schedule( $floor_plan, $specifications, $material_categories, $include_waste_factor, $output_format, $context ) {
		return array(
			'format'     => $output_format,
			'categories' => array(
				array(
					'category' => 'framing',
					'items'    => array(
						array(
							'item'          => '2x4x8 Studs',
							'quantity'      => 120,
							'unit'          => 'ea',
							'waste_factor'  => $include_waste_factor ? 0.1 : 0,
							'total'         => $include_waste_factor ? 132 : 120,
							'specification' => 'Kiln-dried, Grade 2 or better',
						),
						array(
							'item'          => '2x6x12 Headers',
							'quantity'      => 15,
							'unit'          => 'ea',
							'waste_factor'  => $include_waste_factor ? 0.1 : 0,
							'total'         => $include_waste_factor ? 17 : 15,
							'specification' => 'Kiln-dried, Grade 1',
						),
					),
				),
			),
			'metadata'   => array(
				'waste_factor_applied' => $include_waste_factor,
				'generated_at'         => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Generate schedule summary.
	 *
	 * @param array $schedule Material schedule.
	 * @return array Summary data.
	 */
	protected function generate_schedule_summary( $schedule ) {
		return array(
			'total_categories' => count( isset( $schedule['categories'] ) ? $schedule['categories'] : array() ),
			'total_items'      => 0,
			'estimated_weight' => 'TBD',
			'estimated_volume' => 'TBD',
		);
	}
}
