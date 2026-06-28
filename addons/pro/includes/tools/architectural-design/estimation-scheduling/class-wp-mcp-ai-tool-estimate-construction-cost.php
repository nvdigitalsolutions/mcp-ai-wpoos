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
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Estimate construction costs.
 */
class WP_MCP_AI_Tool_Estimate_Construction_Cost implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
				'floor_plan'          => array(
					'type'        => 'object',
					'description' => __( 'Floor plan data for cost estimation.', 'mcp-ai-wpoos-pro' ),
				),
				'total_area'          => array(
					'type'        => 'number',
					'description' => __( 'Total building area. Units controlled by `area_unit` (default sqft for US, sqm for LK/JM).', 'mcp-ai-wpoos-pro' ),
				),
				'area_unit'           => array(
					'type'        => 'string',
					'description' => __( 'Unit for total_area: "sqft" or "sqm".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'sqft', 'sqm' ),
				),
				'country_code'        => array(
					'type'        => 'string',
					'description' => __( 'ISO 3166-1 alpha-2 country code (LK, JM, US). Drives cost-rate table and default currency.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'LK', 'JM', 'US' ),
				),
				'currency'            => array(
					'type'        => 'string',
					'description' => __( 'Optional ISO-4217 currency code to override the country default (LKR, JMD, USD, EUR, GBP).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'LKR', 'JMD', 'USD', 'EUR', 'GBP' ),
				),
				'location'            => array(
					'type'        => 'string',
					'description' => __( 'Location (city, state, district or zip code) for regional cost adjustments.', 'mcp-ai-wpoos-pro' ),
				),
				'quality_level'       => array(
					'type'        => 'string',
					'description' => __( 'Quality level: "economy", "standard", "custom", "luxury".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'economy', 'standard', 'custom', 'luxury' ),
					'default'     => 'standard',
				),
				'construction_type'   => array(
					'type'        => 'string',
					'description' => __( 'Construction type: "wood_frame", "masonry", "steel", "concrete", "hybrid".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'wood_frame', 'masonry', 'steel', 'concrete', 'hybrid' ),
					'default'     => 'masonry',
				),
				'include_breakdown'   => array(
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
		$area_unit           = isset( $arguments['area_unit'] ) ? sanitize_text_field( $arguments['area_unit'] ) : '';
		$country_code        = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : '';
		$currency            = isset( $arguments['currency'] ) ? strtoupper( sanitize_text_field( $arguments['currency'] ) ) : '';
		$location            = isset( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '';
		$quality_level       = isset( $arguments['quality_level'] ) ? sanitize_text_field( $arguments['quality_level'] ) : 'standard';
		$construction_type   = isset( $arguments['construction_type'] ) ? sanitize_text_field( $arguments['construction_type'] ) : 'masonry';
		$include_breakdown   = isset( $arguments['include_breakdown'] ) ? (bool) $arguments['include_breakdown'] : true;
		$contingency_percent = isset( $arguments['contingency_percent'] ) ? floatval( $arguments['contingency_percent'] ) : 10;

		// Resolve country code from settings if missing.
		if ( '' === $country_code && class_exists( 'WP_MCP_AI_Architectural_Engine' ) ) {
			$settings     = WP_MCP_AI_Architectural_Engine::get_toolkit_settings();
			$country_code = isset( $settings['default_country'] ) ? (string) $settings['default_country'] : 'US';
		}
		// Resolve area unit default per country.
		if ( '' === $area_unit ) {
			$area_unit = ( 'US' === $country_code ) ? 'sqft' : 'sqm';
		}

		// Estimate costs.
		$estimate = $this->estimate_costs( $floor_plan, $total_area, $area_unit, $country_code, $currency, $location, $quality_level, $construction_type, $include_breakdown, $contingency_percent, $context );

		if ( is_wp_error( $estimate ) ) {
			return $estimate;
		}

		/**
		 * Fires after a cost estimate has been generated.
		 *
		 * @since 1.2.0
		 *
		 * @param array $estimate  Estimate result.
		 * @param array $arguments Original arguments.
		 */
		do_action( 'wp_mcp_ai_arch_after_cost_estimate', $estimate, $arguments );

		// Return structured estimate data.
		return array(
			'success'    => true,
			'estimate'   => $estimate,
			'disclaimer' => __( 'Indicative estimate only — confirm with quantity surveyor / cost engineer for procurement.', 'mcp-ai-wpoos-pro' ),
			'message'    => __( 'Construction cost estimate complete.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Estimate construction costs.
	 *
	 * @param array  $floor_plan          Floor plan data.
	 * @param float  $total_area          Total area.
	 * @param string $area_unit           'sqft' or 'sqm'.
	 * @param string $country_code        ISO country code.
	 * @param string $currency            Optional output currency override.
	 * @param string $location            Location.
	 * @param string $quality_level       Quality level.
	 * @param string $construction_type   Construction type.
	 * @param bool   $include_breakdown   Include breakdown.
	 * @param float  $contingency_percent Contingency percentage.
	 * @param array  $context             Execution context.
	 * @return array Cost estimate.
	 */
	protected function estimate_costs( $floor_plan, $total_area, $area_unit, $country_code, $currency, $location, $quality_level, $construction_type, $include_breakdown, $contingency_percent, $context ) {
		// Convert area to square metres for the engine.
		$area_sqm = ( 'sqft' === $area_unit && class_exists( 'WP_MCP_AI_Architectural_Engine' ) )
			? WP_MCP_AI_Architectural_Engine::sqft_to_sqm( $total_area )
			: (float) $total_area;

		$rate_info = array(
			'currency'      => '',
			'rate_per_sqm'  => 0.0,
			'rate_per_sqft' => 0.0,
			'index_source'  => '',
		);

		if ( class_exists( 'WP_MCP_AI_Architectural_Engine' ) ) {
			$rate_info = WP_MCP_AI_Architectural_Engine::get_cost_rate( $country_code, $quality_level, $construction_type );
		}

		// Fallback if country has no rate table (legacy behaviour).
		if ( empty( $rate_info['rate_per_sqm'] ) ) {
			$base_per_sf = $this->get_base_cost( $quality_level, $construction_type );
			$rate_info   = array(
				'currency'      => 'USD',
				'rate_per_sqm'  => $base_per_sf * 10.7639104,
				'rate_per_sqft' => $base_per_sf,
				'index_source'  => 'Legacy default rates (USD/SF)',
			);
		}

		$location_factor = $this->get_location_factor( $country_code, $location );
		$src_currency    = (string) $rate_info['currency'];

		$subtotal_local    = $area_sqm * (float) $rate_info['rate_per_sqm'] * $location_factor;
		$contingency_local = $subtotal_local * ( $contingency_percent / 100 );
		$total_local       = $subtotal_local + $contingency_local;

		// Optional currency conversion.
		$display_currency = '' !== $currency ? $currency : $src_currency;
		$subtotal         = $subtotal_local;
		$contingency      = $contingency_local;
		$total            = $total_local;
		if ( '' !== $display_currency && $display_currency !== $src_currency && class_exists( 'WP_MCP_AI_Architectural_Engine' ) ) {
			$converted = WP_MCP_AI_Architectural_Engine::convert_currency( $subtotal_local, $src_currency, $display_currency );
			if ( null !== $converted ) {
				$subtotal    = $converted;
				$contingency = WP_MCP_AI_Architectural_Engine::convert_currency( $contingency_local, $src_currency, $display_currency );
				$total       = WP_MCP_AI_Architectural_Engine::convert_currency( $total_local, $src_currency, $display_currency );
			} else {
				$display_currency = $src_currency;
			}
		}

		$cost_per_sqm  = $area_sqm > 0 ? $total / $area_sqm : 0.0;
		$cost_per_sqft = ( class_exists( 'WP_MCP_AI_Architectural_Engine' ) && $cost_per_sqm > 0 )
			? $cost_per_sqm / WP_MCP_AI_Architectural_Engine::SQFT_PER_SQM
			: 0.0;

		$estimate = array(
			'country_code'        => $country_code,
			'currency'            => $display_currency,
			'source_currency'     => $src_currency,
			'rate_per_sqm'        => (float) $rate_info['rate_per_sqm'],
			'rate_per_sqft'       => (float) $rate_info['rate_per_sqft'],
			'index_source'        => (string) $rate_info['index_source'],
			'area_sqm'            => $area_sqm,
			'area_sqft'           => class_exists( 'WP_MCP_AI_Architectural_Engine' ) ? WP_MCP_AI_Architectural_Engine::sqm_to_sqft( $area_sqm ) : $area_sqm * 10.7639104,
			'total_cost'          => $total,
			'cost_per_sqm'        => $cost_per_sqm,
			'cost_per_sqft'       => $cost_per_sqft,
			'subtotal'            => $subtotal,
			'contingency'         => $contingency,
			'contingency_percent' => $contingency_percent,
			'location_factor'     => $location_factor,
			'construction_type'   => $construction_type,
			'quality_level'       => $quality_level,
		);

		if ( $include_breakdown ) {
			$breakdown_pcts = array(
				array(
					'category' => 'Site Work',
					'percent'  => 5,
				),
				array(
					'category' => 'Foundation',
					'percent'  => 8,
				),
				array(
					'category' => 'Framing',
					'percent'  => 20,
				),
				array(
					'category' => 'Roofing',
					'percent'  => 6,
				),
				array(
					'category' => 'Exterior Finishes',
					'percent'  => 12,
				),
				array(
					'category' => 'Plumbing',
					'percent'  => 10,
				),
				array(
					'category' => 'Electrical',
					'percent'  => 8,
				),
				array(
					'category' => 'HVAC',
					'percent'  => 8,
				),
				array(
					'category' => 'Interior Finishes',
					'percent'  => 18,
				),
				array(
					'category' => 'Other',
					'percent'  => 5,
				),
			);
			$breakdown      = array();
			foreach ( $breakdown_pcts as $row ) {
				$breakdown[] = array(
					'category' => $row['category'],
					'cost'     => $total * ( $row['percent'] / 100 ),
					'percent'  => $row['percent'],
				);
			}
			$estimate['breakdown'] = $breakdown;
		}

		return $estimate;
	}

	/**
	 * Get base cost per square foot (legacy fallback only).
	 *
	 * @param string $quality_level     Quality level.
	 * @param string $construction_type Construction type.
	 * @return float Base cost per sq ft (USD).
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
	 * Returns a multiplier for location-based cost adjustment. Filterable via
	 * `wp_mcp_ai_arch_location_factor` so partners can integrate a regional
	 * cost-index data source. Default is 1.0 (national average).
	 *
	 * @param string $country_code ISO country code.
	 * @param string $location     Location string (city, state, zip).
	 * @return float Location factor.
	 */
	protected function get_location_factor( $country_code, $location ) {
		/**
		 * Filters the per-location cost factor.
		 *
		 * @since 1.2.0
		 *
		 * @param float  $factor       Default 1.0.
		 * @param string $country_code Country code.
		 * @param string $location     Location string.
		 */
		return (float) apply_filters( 'wp_mcp_ai_arch_location_factor', 1.0, $country_code, $location );
	}
}
