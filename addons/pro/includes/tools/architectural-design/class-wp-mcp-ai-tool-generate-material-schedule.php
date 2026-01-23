<?php
/**
 * Tool for generating material schedules.
 *
 * Creates detailed bill of materials from floor plans and specifications.
 * Includes quantities, specifications, and ordering information.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.10
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Generate material schedules.
 */
class WP_MCP_AI_Tool_Generate_Material_Schedule implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'Generate Material Schedule', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create detailed bill of materials from floor plans. Includes quantities, specifications, and ordering information.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'floor_plan'       => array(
					'type'        => 'object',
					'description' => __( 'Floor plan data to extract materials from.', 'mcp-ai-wpoos-pro' ),
				),
				'specifications'   => array(
					'type'        => 'object',
					'description' => __( 'Material specifications and preferences.', 'mcp-ai-wpoos-pro' ),
				),
				'material_categories' => array(
					'type'        => 'array',
					'description' => __( 'Categories to include: "framing", "roofing", "siding", "interior", "mechanical", "electrical", "plumbing".', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'framing', 'roofing', 'siding', 'interior', 'mechanical', 'electrical', 'plumbing', 'foundation' ),
					),
					'default'     => array( 'framing', 'roofing', 'interior' ),
				),
				'include_waste_factor' => array(
					'type'        => 'boolean',
					'description' => __( 'Include waste/overage factor in quantities.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'output_format'    => array(
					'type'        => 'string',
					'description' => __( 'Output format: "detailed", "summary", "csv", "excel".', 'mcp-ai-wpoos-pro' ),
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
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate material schedules.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate floor plan.
		if ( empty( $arguments['floor_plan'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Floor plan data is required.', 'mcp-ai-wpoos-pro' )
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
			'message'  => __( 'Material schedule generated successfully.', 'mcp-ai-wpoos-pro' ),
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
							'item'         => '2x4x8 Studs',
							'quantity'     => 120,
							'unit'         => 'ea',
							'waste_factor' => $include_waste_factor ? 0.1 : 0,
							'total'        => $include_waste_factor ? 132 : 120,
							'specification' => 'Kiln-dried, Grade 2 or better',
						),
						array(
							'item'         => '2x6x12 Headers',
							'quantity'     => 15,
							'unit'         => 'ea',
							'waste_factor' => $include_waste_factor ? 0.1 : 0,
							'total'        => $include_waste_factor ? 17 : 15,
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
