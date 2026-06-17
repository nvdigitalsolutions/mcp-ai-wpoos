<?php
/**
 * Tool for checking Sri Lanka UDA Planning & Building compliance.
 *
 * Wraps the generic compliance checker with Sri Lanka-specific rules: UDA
 * 2021 / 2025 gazette setbacks & FAR, EIA threshold for housing-unit count,
 * NBRO landslide-zone clearance, monsoon orientation hint and registered
 * architect (SLIA) signoff reminder.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Check UDA planning compliance.
 */
class WP_MCP_AI_Tool_Check_UDA_Planning_Compliance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */
	/**
	 * Whether this tool is available for registration.
	 *
	 * @since 1.3.0
	 * @return bool
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
	 * @since 1.3.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_uda_planning_compliance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Sri Lanka UDA Planning Compliance', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validate a project against the Sri Lanka UDA Planning & Building Regulations: setbacks, FAR, site coverage, minimum perches per dwelling, EIA threshold, NBRO landslide-zone clearance, and the SLIA registered-architect signoff requirement.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'gazette_vintage' => array(
					'type'        => 'string',
					'description' => __( 'UDA gazette vintage to evaluate against: "2021" or "2025" (Gazette 2430/13).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( '2021', '2025' ),
					'default'     => '2025',
				),
				'lot'             => array(
					'type'        => 'object',
					'description' => __( 'Lot description: lot_area_m2 or lot_perches.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'lot_area_m2' => array( 'type' => 'number' ),
						'lot_perches' => array( 'type' => 'number' ),
					),
				),
				'building'        => array(
					'type'        => 'object',
					'description' => __( 'Building description.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'built_up_area_m2'   => array( 'type' => 'number' ),
						'footprint_area_m2'  => array( 'type' => 'number' ),
						'num_storeys'        => array( 'type' => 'integer' ),
						'building_type'      => array(
							'type' => 'string',
							'enum' => array( 'residential', 'commercial', 'industrial', 'mixed-use' ),
						),
						'num_dwelling_units' => array( 'type' => 'integer' ),
						'setbacks_m'         => array(
							'type'       => 'object',
							'properties' => array(
								'front' => array( 'type' => 'number' ),
								'rear'  => array( 'type' => 'number' ),
								'left'  => array( 'type' => 'number' ),
								'right' => array( 'type' => 'number' ),
							),
						),
					),
				),
				'site'            => array(
					'type'        => 'object',
					'description' => __( 'Optional site context.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'nbro_landslide_zone' => array(
							'type'        => 'string',
							'description' => __( 'NBRO landslide hazard zone classification (high, moderate, low, none).', 'mcp-ai-wpoos-pro' ),
							'enum'        => array( 'high', 'moderate', 'low', 'none' ),
						),
						'slope_deg'           => array( 'type' => 'number' ),
						'monsoon_orientation' => array(
							'type'        => 'string',
							'description' => __( 'Building principal facade orientation (N, S, E, W, NE, NW, SE, SW).', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'professional'    => array(
					'type'        => 'object',
					'description' => __( 'Professional/SLIA signoff state.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'slia_registered_architect' => array( 'type' => 'boolean' ),
					),
				),
			),
			'required'             => array( 'lot', 'building' ),
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
			'read-only',
			'cacheable',
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
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to check UDA compliance.', 'mcp-ai-wpoos-pro' )
			);
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Engine' ) || ! class_exists( 'WP_MCP_AI_Architectural_Codes' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural engine is unavailable.', 'mcp-ai-wpoos-pro' ) );
		}

		$gazette_vintage = isset( $arguments['gazette_vintage'] ) ? sanitize_text_field( $arguments['gazette_vintage'] ) : '2025';
		$lot             = isset( $arguments['lot'] ) ? (array) $arguments['lot'] : array();
		$building        = isset( $arguments['building'] ) ? (array) $arguments['building'] : array();
		$site            = isset( $arguments['site'] ) ? (array) $arguments['site'] : array();
		$pro             = isset( $arguments['professional'] ) ? (array) $arguments['professional'] : array();

		$pack_id = ( '2025' === $gazette_vintage ) ? 'lk_uda_2025_gazette' : 'lk_uda_2021';
		$packs   = array( $pack_id, 'lk_nbro_landslide' );
		$rules   = WP_MCP_AI_Architectural_Codes::merge_rules( $packs );
		$zoning  = isset( $rules['zoning'] ) ? $rules['zoning'] : array();

		$lot_area_m2 = isset( $lot['lot_area_m2'] ) ? floatval( $lot['lot_area_m2'] ) : 0.0;
		if ( $lot_area_m2 <= 0 && isset( $lot['lot_perches'] ) ) {
			$lot_area_m2 = WP_MCP_AI_Architectural_Engine::perches_to_sqm( floatval( $lot['lot_perches'] ) );
		}
		$built_up_m2   = isset( $building['built_up_area_m2'] ) ? floatval( $building['built_up_area_m2'] ) : 0.0;
		$footprint_m2  = isset( $building['footprint_area_m2'] ) ? floatval( $building['footprint_area_m2'] ) : 0.0;
		$building_type = isset( $building['building_type'] ) ? sanitize_text_field( $building['building_type'] ) : 'residential';
		$units         = isset( $building['num_dwelling_units'] ) ? absint( $building['num_dwelling_units'] ) : 0;
		$setbacks      = isset( $building['setbacks_m'] ) ? array_map( 'floatval', (array) $building['setbacks_m'] ) : array();

		$checks = array();

		// Lot perches.
		$min_perch = isset( $zoning['min_lot_perches_residential'] ) ? floatval( $zoning['min_lot_perches_residential'] ) : 0.0;
		if ( $min_perch > 0 && $lot_area_m2 > 0 ) {
			$lot_perches = WP_MCP_AI_Architectural_Engine::sqm_to_perches( $lot_area_m2 );
			$pass        = ( $lot_perches + 1e-6 >= $min_perch );
			/* translators: 1: minimum perches per lot, 2: provided perches */
			$checks[]    = $this->mk( 'zoning', sprintf( __( 'Minimum %.1f perches per residential lot.', 'mcp-ai-wpoos-pro' ), $min_perch ), $pass ? 'pass' : 'fail', sprintf( __( 'Provided: %.2f perches.', 'mcp-ai-wpoos-pro' ), $lot_perches ) );
		}

		// FAR.
		if ( $lot_area_m2 > 0 && $built_up_m2 > 0 ) {
			$far_max = isset( $zoning['far_max_residential'] ) ? floatval( $zoning['far_max_residential'] ) : 0.0;
			if ( 'commercial' === $building_type && isset( $zoning['far_max_commercial'] ) ) {
				$far_max = floatval( $zoning['far_max_commercial'] );
			} elseif ( 'mixed-use' === $building_type && isset( $zoning['far_max_mixed_use'] ) ) {
				$far_max = floatval( $zoning['far_max_mixed_use'] );
			}
			$far_actual = WP_MCP_AI_Architectural_Engine::calculate_far( $built_up_m2, $lot_area_m2 );
			if ( $far_max > 0 ) {
				$pass     = ( $far_actual <= $far_max + 1e-6 );
				/* translators: %1$.2f: maximum FAR value, %2$s: building type */
				$checks[] = $this->mk( 'zoning', sprintf( __( 'Maximum FAR %1$.2f for %2$s.', 'mcp-ai-wpoos-pro' ), $far_max, $building_type ), $pass ? 'pass' : 'fail', sprintf( __( 'Calculated: %.2f.', 'mcp-ai-wpoos-pro' ), $far_actual ) );
			}
		}

		// Site coverage.
		if ( $lot_area_m2 > 0 && $footprint_m2 > 0 ) {
			$cov_max = isset( $zoning['site_coverage_max'] ) ? floatval( $zoning['site_coverage_max'] ) : 0.0;
			if ( $cov_max > 0 ) {
				$cov_actual = WP_MCP_AI_Architectural_Engine::calculate_site_coverage( $footprint_m2, $lot_area_m2 );
				$pass       = ( $cov_actual <= $cov_max + 1e-6 );
				/* translators: 1: maximum site coverage, 2: calculated site coverage */
				$checks[]   = $this->mk( 'zoning', sprintf( __( 'Maximum site coverage %.0f%%.', 'mcp-ai-wpoos-pro' ), $cov_max ), $pass ? 'pass' : 'fail', sprintf( __( 'Calculated: %.1f%%.', 'mcp-ai-wpoos-pro' ), $cov_actual ) );
			}
		}

		// Setbacks.
		if ( ! empty( $setbacks ) ) {
			$req        = array(
				'front' => isset( $zoning['min_setback_front_m'] ) ? floatval( $zoning['min_setback_front_m'] ) : 0.0,
				'rear'  => isset( $zoning['min_setback_rear_m'] ) ? floatval( $zoning['min_setback_rear_m'] ) : 0.0,
				'left'  => isset( $zoning['min_setback_side_m'] ) ? floatval( $zoning['min_setback_side_m'] ) : 0.0,
				'right' => isset( $zoning['min_setback_side_m'] ) ? floatval( $zoning['min_setback_side_m'] ) : 0.0,
			);
			$validation = WP_MCP_AI_Architectural_Engine::validate_setbacks( $setbacks, $req );
			if ( $validation['compliant'] ) {
				$checks[] = $this->mk( 'zoning', __( 'Setbacks meet UDA minima.', 'mcp-ai-wpoos-pro' ), 'pass', '' );
			} else {
				foreach ( $validation['violations'] as $v ) {
					/* translators: %1$s: setback side, %2$.2f: required setback in meters, 3: provided setback, 4: shortfall in meters */
					$checks[] = $this->mk( 'zoning', sprintf( __( 'Minimum %1$s setback %2$.2f m.', 'mcp-ai-wpoos-pro' ), $v['side'], $v['required'] ), 'fail', sprintf( __( 'Provided: %1$.2f m (short by %2$.2f m).', 'mcp-ai-wpoos-pro' ), $v['proposed'], $v['shortfall'] ) );
				}
			}
		}

		// EIA threshold.
		$eia_threshold = isset( $zoning['eia_threshold_units'] ) ? absint( $zoning['eia_threshold_units'] ) : 0;
		if ( $eia_threshold > 0 && $units > 0 ) {
			$triggered = ( $units > $eia_threshold );
			/* translators: 1: EIA threshold units, 2: project dwelling units, 3: project dwelling units */
			$checks[]  = $this->mk( 'planning', sprintf( __( 'EIA review required for housing projects > %d units.', 'mcp-ai-wpoos-pro' ), $eia_threshold ), $triggered ? 'fail' : 'pass', $triggered ? sprintf( __( 'Project has %d units — EIA submission required.', 'mcp-ai-wpoos-pro' ), $units ) : sprintf( __( 'Project has %d units — below EIA threshold.', 'mcp-ai-wpoos-pro' ), $units ) );
		}

		// NBRO landslide.
		$zone      = isset( $site['nbro_landslide_zone'] ) ? sanitize_text_field( $site['nbro_landslide_zone'] ) : '';
		$slope     = isset( $site['slope_deg'] ) ? floatval( $site['slope_deg'] ) : 0.0;
		$req_zones = isset( $zoning['landslide_clearance_required_in_zones'] ) ? (array) $zoning['landslide_clearance_required_in_zones'] : array();
		if ( $zone && in_array( $zone, $req_zones, true ) ) {
			$checks[] = $this->mk( 'planning', __( 'NBRO landslide clearance required in high/moderate hazard zones.', 'mcp-ai-wpoos-pro' ), 'fail', __( 'Site is in a hazard zone — NBRO clearance required.', 'mcp-ai-wpoos-pro' ) );
		}
		$slope_threshold = isset( $zoning['requires_geo_report_above_slope_deg'] ) ? floatval( $zoning['requires_geo_report_above_slope_deg'] ) : 0.0;
		if ( $slope_threshold > 0 && $slope > $slope_threshold ) {
			/* translators: 1: slope threshold in degrees, 2: site slope in degrees */
			$checks[] = $this->mk( 'planning', sprintf( __( 'Geotechnical report required for slopes > %.0f°.', 'mcp-ai-wpoos-pro' ), $slope_threshold ), 'fail', sprintf( __( 'Site slope: %.1f°.', 'mcp-ai-wpoos-pro' ), $slope ) );
		}

		// Monsoon orientation hint.
		$orient = isset( $site['monsoon_orientation'] ) ? strtoupper( sanitize_text_field( $site['monsoon_orientation'] ) ) : '';
		if ( $orient && in_array( $orient, array( 'SW', 'W', 'NW' ), true ) ) {
			$checks[] = $this->mk( 'planning', __( 'Monsoon-exposed orientation.', 'mcp-ai-wpoos-pro' ), 'warning', __( 'Principal facade exposed to SW monsoon (May-Sep) — provide deep overhangs and rain protection.', 'mcp-ai-wpoos-pro' ) );
		}

		// SLIA signoff.
		$slia     = ! empty( $pro['slia_registered_architect'] );
		$checks[] = $this->mk( 'planning', __( 'SLIA registered architect signoff required.', 'mcp-ai-wpoos-pro' ), $slia ? 'pass' : 'fail', $slia ? __( 'Registered architect engaged.', 'mcp-ai-wpoos-pro' ) : __( 'Engage a SLIA-registered architect before submission.', 'mcp-ai-wpoos-pro' ) );

		$overall = 'pass';
		foreach ( $checks as $c ) {
			if ( 'fail' === $c['status'] ) {
				$overall = 'fail';
				break; }
			if ( 'warning' === $c['status'] && 'fail' !== $overall ) {
				$overall = 'conditional'; }
		}

		return array(
			'success'         => true,
			'country_code'    => 'LK',
			'gazette_vintage' => $gazette_vintage,
			'code_packs'      => $packs,
			'lot_area_m2'     => round( $lot_area_m2, 2 ),
			'lot_perches'     => round( WP_MCP_AI_Architectural_Engine::sqm_to_perches( $lot_area_m2 ), 2 ),
			'checks'          => $checks,
			'overall_status'  => $overall,
			'disclaimer'      => __( 'Analytical / advisory output only. Confirm with the local UDA office and engage a SLIA-registered architect before submission.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Build a check entry.
	 *
	 * @param string $category    Category.
	 * @param string $requirement Requirement text.
	 * @param string $status      Pass|warning|fail.
	 * @param string $details     Detail text.
	 * @return array
	 */
	protected function mk( $category, $requirement, $status, $details ) {
		return array(
			'category'    => (string) $category,
			'requirement' => (string) $requirement,
			'status'      => in_array( $status, array( 'pass', 'fail', 'warning' ), true ) ? $status : 'warning',
			'details'     => (string) $details,
		);
	}
}
