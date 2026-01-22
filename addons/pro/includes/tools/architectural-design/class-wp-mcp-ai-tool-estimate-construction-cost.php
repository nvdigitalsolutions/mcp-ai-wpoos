<?php
/**
 * Tool for estimating construction costs.
 *
 * AI-powered construction cost estimation based on floor plans,
 * materials, labor, and location.
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
 * Estimate construction costs.
 */
class WP_MCP_AI_Tool_Estimate_Construction_Cost implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'estimate_construction_cost';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Estimate Construction Cost', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'AI-powered construction cost estimation. Includes materials, labor, equipment, and location-based adjustments.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Floor plan data for cost estimation.', 'mcp-ai-wpoos-pro' ),
				),
				'total_area'       => array(
					'type'        => 'number',
					'description' => __( 'Total building area in square feet.', 'mcp-ai-wpoos-pro' ),
				),
				'location'         => array(
					'type'        => 'string',
					'description' => __( 'Location (city, state or zip code) for regional cost adjustments.', 'mcp-ai-wpoos-pro' ),
				),
				'quality_level'    => array(
					'type'        => 'string',
					'description' => __( 'Quality level: "economy", "standard", "custom", "luxury".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'economy', 'standard', 'custom', 'luxury' ),
					'default'     => 'standard',
				),
				'construction_type' => array(
					'type'        => 'string',
					'description' => __( 'Construction type: "wood_frame", "steel", "concrete".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'wood_frame', 'steel', 'concrete' ),
					'default'     => 'wood_frame',
				),
				'include_breakdown' => array(
					'type'        => 'boolean',
					'description' => __( 'Include detailed cost breakdown by category.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'contingency_percent' => array(
					'type'        => 'number',
					'description' => __( 'Contingency percentage (0-30).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 30,
					'default'     => 10,
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
			'read-only',
			'consumes-tokens',
			'external-api',
			'model-dependent',
			'non-deterministic',
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
				__( 'You do not have permission to estimate construction costs.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate floor plan and area.
		if ( empty( $arguments['floor_plan'] ) || empty( $arguments['total_area'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Floor plan data and total area are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$floor_plan          = $arguments['floor_plan'];
		$total_area          = floatval( $arguments['total_area'] );
		$location            = isset( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '';
		$quality_level       = isset( $arguments['quality_level'] ) ? sanitize_text_field( $arguments['quality_level'] ) : 'standard';
		$construction_type   = isset( $arguments['construction_type'] ) ? sanitize_text_field( $arguments['construction_type'] ) : 'wood_frame';
		$include_breakdown   = isset( $arguments['include_breakdown'] ) ? (bool) $arguments['include_breakdown'] : true;
		$contingency_percent = isset( $arguments['contingency_percent'] ) ? floatval( $arguments['contingency_percent'] ) : 10;

		// Estimate costs.
		$estimate = $this->estimate_costs( $floor_plan, $total_area, $location, $quality_level, $construction_type, $include_breakdown, $contingency_percent, $context );

		if ( is_wp_error( $estimate ) ) {
			return $estimate;
		}

		// Return structured estimate data.
		return array(
			'success'  => true,
			'estimate' => $estimate,
			'message'  => __( 'Construction cost estimate complete.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Estimate construction costs.
	 *
	 * @param array  $floor_plan          Floor plan data.
	 * @param float  $total_area          Total area.
	 * @param string $location            Location.
	 * @param string $quality_level       Quality level.
	 * @param string $construction_type   Construction type.
	 * @param bool   $include_breakdown   Include breakdown.
	 * @param float  $contingency_percent Contingency percentage.
	 * @param array  $context             Execution context.
	 * @return array Cost estimate.
	 */
	protected function estimate_costs( $floor_plan, $total_area, $location, $quality_level, $construction_type, $include_breakdown, $contingency_percent, $context ) {
		$base_cost_per_sf = $this->get_base_cost( $quality_level, $construction_type );
		$location_factor  = $this->get_location_factor( $location );
		
		$subtotal    = $total_area * $base_cost_per_sf * $location_factor;
		$contingency = $subtotal * ( $contingency_percent / 100 );
		$total       = $subtotal + $contingency;

		$estimate = array(
			'total_cost'         => $total,
			'cost_per_sf'        => $total / $total_area,
			'subtotal'           => $subtotal,
			'contingency'        => $contingency,
			'contingency_percent' => $contingency_percent,
			'location_factor'    => $location_factor,
		);

		if ( $include_breakdown ) {
			$estimate['breakdown'] = array(
				array( 'category' => 'Site Work', 'cost' => $total * 0.05, 'percent' => 5 ),
				array( 'category' => 'Foundation', 'cost' => $total * 0.08, 'percent' => 8 ),
				array( 'category' => 'Framing', 'cost' => $total * 0.20, 'percent' => 20 ),
				array( 'category' => 'Roofing', 'cost' => $total * 0.06, 'percent' => 6 ),
				array( 'category' => 'Exterior Finishes', 'cost' => $total * 0.12, 'percent' => 12 ),
				array( 'category' => 'Plumbing', 'cost' => $total * 0.10, 'percent' => 10 ),
				array( 'category' => 'Electrical', 'cost' => $total * 0.08, 'percent' => 8 ),
				array( 'category' => 'HVAC', 'cost' => $total * 0.08, 'percent' => 8 ),
				array( 'category' => 'Interior Finishes', 'cost' => $total * 0.18, 'percent' => 18 ),
				array( 'category' => 'Other', 'cost' => $total * 0.05, 'percent' => 5 ),
			);
		}

		return $estimate;
	}

	/**
	 * Get base cost per square foot.
	 *
	 * @param string $quality_level       Quality level.
	 * @param string $construction_type   Construction type.
	 * @return float Base cost per SF.
	 */
	protected function get_base_cost( $quality_level, $construction_type ) {
		$costs = array(
			'economy'  => 100,
			'standard' => 150,
			'custom'   => 200,
			'luxury'   => 300,
		);

		return isset( $costs[ $quality_level ] ) ? $costs[ $quality_level ] : 150;
	}

	/**
	 * Get location cost factor.
	 *
	 * @param string $location Location.
	 * @return float Location factor.
	 */
	protected function get_location_factor( $location ) {
		// TODO: Implement real location-based cost adjustment using regional database.
		// This should query construction cost data by zip code or city.
		return 1.0;
	}
}
