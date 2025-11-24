<?php
/**
 * Tool for construction cost estimation.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides cost estimation and budget projections based on floor plans and specifications.
 */
class WP_MCP_AI_Tool_Cost_Estimation implements WP_MCP_AI_Tool_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'cost_estimation';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Cost Estimation Tool', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate detailed cost estimates and budget projections based on floor plans, specifications, and material selections.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'project_type'    => array(
					'type'        => 'string',
					'description' => __( 'Type of construction project.', 'wp-mcp-ai' ),
					'enum'        => array( 'new_construction', 'renovation', 'addition', 'remodel', 'landscape' ),
					'default'     => 'new_construction',
				),
				'total_area_sqm'  => array(
					'type'        => 'number',
					'description' => __( 'Total area in square meters.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'quality_level'   => array(
					'type'        => 'string',
					'description' => __( 'Overall quality level of materials and finishes.', 'wp-mcp-ai' ),
					'enum'        => array( 'economy', 'standard', 'premium', 'luxury' ),
					'default'     => 'standard',
				),
				'location_type'   => array(
					'type'        => 'string',
					'description' => __( 'Project location type (affects labor costs).', 'wp-mcp-ai' ),
					'enum'        => array( 'urban', 'suburban', 'rural' ),
					'default'     => 'suburban',
				),
				'include_labor'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include labor costs in estimate.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'include_permits' => array(
					'type'        => 'boolean',
					'description' => __( 'Include permit and fee estimates.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'currency'        => array(
					'type'        => 'string',
					'description' => __( 'Currency for cost estimates.', 'wp-mcp-ai' ),
					'default'     => 'USD',
				),
				'custom_items'    => array(
					'type'        => 'array',
					'description' => __( 'Custom line items to include in estimate.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'description' => array( 'type' => 'string' ),
							'quantity'    => array( 'type' => 'number' ),
							'unit'        => array( 'type' => 'string' ),
							'unit_cost'   => array( 'type' => 'number' ),
						),
					),
				),
			),
			'required'             => array( 'project_type', 'total_area_sqm' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate cost estimates.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Sanitize inputs.
		$project_type    = isset( $arguments['project_type'] ) ? sanitize_key( $arguments['project_type'] ) : 'new_construction';
		$area_sqm        = isset( $arguments['total_area_sqm'] ) ? floatval( $arguments['total_area_sqm'] ) : 0;
		$quality         = isset( $arguments['quality_level'] ) ? sanitize_key( $arguments['quality_level'] ) : 'standard';
		$location        = isset( $arguments['location_type'] ) ? sanitize_key( $arguments['location_type'] ) : 'suburban';
		$include_labor   = isset( $arguments['include_labor'] ) ? (bool) $arguments['include_labor'] : true;
		$include_permits = isset( $arguments['include_permits'] ) ? (bool) $arguments['include_permits'] : true;
		$currency        = isset( $arguments['currency'] ) ? sanitize_text_field( $arguments['currency'] ) : 'USD';
		$custom_items    = isset( $arguments['custom_items'] ) ? $arguments['custom_items'] : array();

		if ( $area_sqm <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_invalid_area', __( 'Total area must be greater than zero.', 'wp-mcp-ai' ) );
		}

		// Calculate base costs.
		$base_costs = $this->calculate_base_costs( $project_type, $area_sqm, $quality );

		// Calculate labor if included.
		$labor_costs = $include_labor ? $this->calculate_labor_costs( $base_costs['materials'], $location ) : array();

		// Calculate permits and fees if included.
		$permit_costs = $include_permits ? $this->calculate_permit_costs( $base_costs['total'], $project_type ) : array();

		// Process custom items.
		$custom_total      = 0;
		$custom_line_items = array();
		if ( is_array( $custom_items ) ) {
			foreach ( $custom_items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$description = isset( $item['description'] ) ? sanitize_text_field( $item['description'] ) : '';
				$quantity    = isset( $item['quantity'] ) ? floatval( $item['quantity'] ) : 0;
				$unit_cost   = isset( $item['unit_cost'] ) ? floatval( $item['unit_cost'] ) : 0;
				$item_total  = $quantity * $unit_cost;

				if ( $item_total > 0 ) {
					$custom_line_items[] = array(
						'description' => $description,
						'quantity'    => $quantity,
						'unit_cost'   => $unit_cost,
						'total'       => $item_total,
					);
					$custom_total       += $item_total;
				}
			}
		}

		// Calculate grand total.
		$grand_total = $base_costs['total'];
		if ( $include_labor && isset( $labor_costs['total'] ) ) {
			$grand_total += $labor_costs['total'];
		}
		if ( $include_permits && isset( $permit_costs['total'] ) ) {
			$grand_total += $permit_costs['total'];
		}
		$grand_total += $custom_total;

		$estimate = array(
			'estimate_id'    => wp_generate_uuid4(),
			'project_info'   => array(
				'type'     => $project_type,
				'area_sqm' => $area_sqm,
				'quality'  => $quality,
				'location' => $location,
			),
			'cost_breakdown' => array(
				'materials'    => $base_costs,
				'labor'        => $include_labor ? $labor_costs : array( 'note' => 'Labor costs not included' ),
				'permits_fees' => $include_permits ? $permit_costs : array( 'note' => 'Permits and fees not included' ),
				'custom_items' => array(
					'items' => $custom_line_items,
					'total' => $custom_total,
				),
			),
			'summary'        => array(
				'subtotal'      => $base_costs['total'],
				'labor_total'   => $include_labor && isset( $labor_costs['total'] ) ? $labor_costs['total'] : 0,
				'permits_total' => $include_permits && isset( $permit_costs['total'] ) ? $permit_costs['total'] : 0,
				'custom_total'  => $custom_total,
				'grand_total'   => $grand_total,
				'cost_per_sqm'  => $grand_total / $area_sqm,
				'currency'      => $currency,
			),
			'contingency'    => array(
				'percentage' => 10,
				'amount'     => $grand_total * 0.10,
				'total_with' => $grand_total * 1.10,
			),
			'generated_at'   => current_time( 'mysql' ),
			'disclaimer'     => __( 'This is an estimate only. Actual costs may vary based on market conditions, site conditions, and specific requirements.', 'wp-mcp-ai' ),
		);

		/**
		 * Filters cost estimate before returning.
		 *
		 * @since 1.0.0
		 *
		 * @param array $estimate Cost estimate data.
		 * @param array $arguments Tool arguments.
		 * @param int   $user_id User ID.
		 */
		$estimate = apply_filters( 'wp_mcp_ai_cost_estimate', $estimate, $arguments, $user_id );

		return $estimate;
	}

	/**
	 * Calculate base material costs.
	 *
	 * @param string $project_type Project type.
	 * @param float  $area_sqm     Area in square meters.
	 * @param string $quality      Quality level.
	 * @return array Cost breakdown.
	 */
	private function calculate_base_costs( $project_type, $area_sqm, $quality ) {
		// Base cost per sqm by quality level.
		$base_rates = array(
			'economy'  => array(
				'new_construction' => 800,
				'renovation'       => 500,
				'addition'         => 900,
				'remodel'          => 600,
				'landscape'        => 150,
			),
			'standard' => array(
				'new_construction' => 1200,
				'renovation'       => 750,
				'addition'         => 1350,
				'remodel'          => 900,
				'landscape'        => 250,
			),
			'premium'  => array(
				'new_construction' => 1800,
				'renovation'       => 1100,
				'addition'         => 2000,
				'remodel'          => 1350,
				'landscape'        => 400,
			),
			'luxury'   => array(
				'new_construction' => 2800,
				'renovation'       => 1700,
				'addition'         => 3100,
				'remodel'          => 2100,
				'landscape'        => 650,
			),
		);

		$rate = isset( $base_rates[ $quality ][ $project_type ] ) ? $base_rates[ $quality ][ $project_type ] : 1200;

		$materials_total = $area_sqm * $rate;

		// Breakdown by category (percentage of total).
		$categories = array(
			'structure'  => 0.30,
			'exterior'   => 0.20,
			'interior'   => 0.25,
			'mechanical' => 0.15,
			'electrical' => 0.10,
		);

		$breakdown = array();
		foreach ( $categories as $category => $percentage ) {
			$breakdown[ $category ] = $materials_total * $percentage;
		}

		return array(
			'breakdown' => $breakdown,
			'total'     => $materials_total,
			'rate_sqm'  => $rate,
		);
	}

	/**
	 * Calculate labor costs.
	 *
	 * @param float  $materials_total Materials total.
	 * @param string $location        Location type.
	 * @return array Labor costs.
	 */
	private function calculate_labor_costs( $materials_total, $location ) {
		// Labor multipliers by location.
		$multipliers = array(
			'urban'    => 0.65,
			'suburban' => 0.50,
			'rural'    => 0.40,
		);

		$multiplier  = isset( $multipliers[ $location ] ) ? $multipliers[ $location ] : 0.50;
		$labor_total = $materials_total * $multiplier;

		return array(
			'base'       => $labor_total,
			'multiplier' => $multiplier,
			'location'   => $location,
			'total'      => $labor_total,
		);
	}

	/**
	 * Calculate permit and fee costs.
	 *
	 * @param float  $base_total   Base total cost.
	 * @param string $project_type Project type.
	 * @return array Permit costs.
	 */
	private function calculate_permit_costs( $base_total, $project_type ) {
		// Permit fee percentages by project type.
		$permit_rates = array(
			'new_construction' => 0.015,
			'renovation'       => 0.010,
			'addition'         => 0.012,
			'remodel'          => 0.008,
			'landscape'        => 0.005,
		);

		$rate         = isset( $permit_rates[ $project_type ] ) ? $permit_rates[ $project_type ] : 0.010;
		$permit_total = $base_total * $rate;

		return array(
			'building_permit' => $permit_total * 0.60,
			'plan_review'     => $permit_total * 0.25,
			'inspection_fees' => $permit_total * 0.15,
			'total'           => $permit_total,
		);
	}
}
