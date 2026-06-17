<?php
/**
 * Architectural Design Toolkit — Regional Code Registry
 *
 * Provides structured rule packs for building-code compliance evaluation
 * across multiple jurisdictions. Each "code pack" exposes a normalised set
 * of rules (egress, fire, accessibility, structural, energy, zoning) so the
 * `check_building_code_compliance` tool and friends can evaluate against
 * Sri Lankan UDA / SLS, Jamaica National Building Code, US IBC/IRC, etc.
 *
 * Designed PHP 7.4-compatible.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Regional code-pack registry.
 *
 * Code packs are stored as filterable PHP arrays keyed by an identifier
 * (e.g. `lk_uda_2021`, `jm_jnbc_2018`, `us_ibc_2024`). Each pack contains:
 *
 *   - country: ISO 3166-1 alpha-2 country code.
 *   - title:   Human-readable title.
 *   - authority: Authoring body / regulator.
 *   - reference: Citable reference document or gazette.
 *   - rules:   Structured rules grouped by category.
 *
 * The categories within `rules` follow a normalised vocabulary so the
 * compliance checker can iterate uniformly:
 *
 *   - egress:        min stair width, travel distance, exit count.
 *   - fire_safety:   wall ratings, sprinkler thresholds, occupant load.
 *   - accessibility: door width, ramp slope, accessible-WC requirements.
 *   - structural:    wind/seismic references and zone identifiers.
 *   - energy:        envelope U-values, ventilation rates.
 *   - zoning:        FAR, site coverage, setback minima.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Architectural_Codes {

	/**
	 * Get the full registry of code packs.
	 *
	 * @return array<string,array>
	 */
	public static function get_code_packs() {
		$packs = array(

			// -------------------------------------------------------------
			// Sri Lanka (primary).
			// -------------------------------------------------------------
			'lk_uda_2021'            => array(
				'country'   => 'LK',
				'title'     => __( 'Sri Lanka — UDA Planning & Building Regulations (2021)', 'mcp-ai-wpoos-pro' ),
				'authority' => 'Urban Development Authority',
				'reference' => 'Gazette Extraordinary 2235/53 (and predecessors)',
				'rules'     => self::lk_uda_rules( '2021' ),
			),
			'lk_uda_2025_gazette'    => array(
				'country'   => 'LK',
				'title'     => __( 'Sri Lanka — UDA Planning & Building Regulations (Gazette 2430/13, 2025)', 'mcp-ai-wpoos-pro' ),
				'authority' => 'Urban Development Authority',
				'reference' => 'Gazette 2430/13 effective 1 April 2025',
				'rules'     => self::lk_uda_rules( '2025' ),
			),
			'lk_sls_947_ventilation' => array(
				'country'   => 'LK',
				'title'     => __( 'Sri Lanka — SLS 947:2009 Code of Practice for Ventilation', 'mcp-ai-wpoos-pro' ),
				'authority' => 'Sri Lanka Standards Institution',
				'reference' => 'SLS 947:2009',
				'rules'     => self::lk_ventilation_rules(),
			),
			'lk_sls_wind'            => array(
				'country'   => 'LK',
				'title'     => __( 'Sri Lanka — Wind Loads (BS 6399-2 / IS 875-3 referenced via SLS)', 'mcp-ai-wpoos-pro' ),
				'authority' => 'Sri Lanka Standards Institution / IESL',
				'reference' => 'BS 6399-2 / IS 875-3 referenced',
				'rules'     => self::lk_structural_wind_rules(),
			),
			'lk_is_1893_seismic'     => array(
				'country'   => 'LK',
				'title'     => __( 'Sri Lanka — IS 1893 Seismic Design (referenced)', 'mcp-ai-wpoos-pro' ),
				'authority' => 'IESL / SLSI',
				'reference' => 'IS 1893 (Part 1) referenced',
				'rules'     => self::lk_structural_seismic_rules(),
			),
			'lk_nbro_landslide'      => array(
				'country'   => 'LK',
				'title'     => __( 'Sri Lanka — NBRO Landslide & Hazard Zoning', 'mcp-ai-wpoos-pro' ),
				'authority' => 'National Building Research Organisation',
				'reference' => 'NBRO Landslide Hazard Zonation',
				'rules'     => self::lk_nbro_rules(),
			),

			// -------------------------------------------------------------
			// Jamaica.
			// -------------------------------------------------------------
			'jm_jnbc_2018'           => array(
				'country'   => 'JM',
				'title'     => __( 'Jamaica — National Building Code 2018 (JNBC)', 'mcp-ai-wpoos-pro' ),
				'authority' => 'Bureau of Standards Jamaica',
				'reference' => 'Building Act 2018 / JNBC 2018',
				'rules'     => self::jm_jnbc_rules(),
			),
			'jm_asce_7_via_jnbc'     => array(
				'country'   => 'JM',
				'title'     => __( 'Jamaica — Hurricane Wind Loads (ASCE 7 via JNBC)', 'mcp-ai-wpoos-pro' ),
				'authority' => 'Bureau of Standards Jamaica',
				'reference' => 'ASCE 7 referenced by JNBC 2018 Part 7',
				'rules'     => self::jm_asce_wind_rules(),
			),
			'jm_js_35_ventilation'   => array(
				'country'   => 'JM',
				'title'     => __( 'Jamaica — JS 35 Code of Practice for Natural Ventilation', 'mcp-ai-wpoos-pro' ),
				'authority' => 'Bureau of Standards Jamaica',
				'reference' => 'JS 35:1996',
				'rules'     => self::jm_js35_rules(),
			),
			'jm_parish_council'      => array(
				'country'   => 'JM',
				'title'     => __( 'Jamaica — Parish Council Overlays', 'mcp-ai-wpoos-pro' ),
				'authority' => 'Parish councils (KSAMC, etc.)',
				'reference' => 'Local parish council development orders',
				'rules'     => self::jm_parish_rules(),
			),

			// -------------------------------------------------------------
			// United States.
			// -------------------------------------------------------------
			'us_ibc_2024'            => array(
				'country'   => 'US',
				'title'     => __( 'United States — International Building Code 2024 (IBC)', 'mcp-ai-wpoos-pro' ),
				'authority' => 'International Code Council',
				'reference' => 'IBC 2024',
				'rules'     => self::us_ibc_rules(),
			),
			'us_irc_2024'            => array(
				'country'   => 'US',
				'title'     => __( 'United States — International Residential Code 2024 (IRC)', 'mcp-ai-wpoos-pro' ),
				'authority' => 'International Code Council',
				'reference' => 'IRC 2024',
				'rules'     => self::us_irc_rules(),
			),
			'us_iecc_2024'           => array(
				'country'   => 'US',
				'title'     => __( 'United States — International Energy Conservation Code 2024', 'mcp-ai-wpoos-pro' ),
				'authority' => 'International Code Council',
				'reference' => 'IECC 2024',
				'rules'     => self::us_iecc_rules(),
			),
			'us_asce_7_22'           => array(
				'country'   => 'US',
				'title'     => __( 'United States — ASCE 7-22 Minimum Design Loads', 'mcp-ai-wpoos-pro' ),
				'authority' => 'ASCE',
				'reference' => 'ASCE 7-22',
				'rules'     => self::us_asce7_rules(),
			),
			'us_nfpa_101'            => array(
				'country'   => 'US',
				'title'     => __( 'United States — NFPA 101 Life Safety Code', 'mcp-ai-wpoos-pro' ),
				'authority' => 'National Fire Protection Association',
				'reference' => 'NFPA 101',
				'rules'     => self::us_nfpa_rules(),
			),
			'us_ada_2010'            => array(
				'country'   => 'US',
				'title'     => __( 'United States — ADA 2010 Standards for Accessible Design', 'mcp-ai-wpoos-pro' ),
				'authority' => 'US Department of Justice',
				'reference' => '28 CFR Part 36 (2010 ADA Standards)',
				'rules'     => self::us_ada_rules(),
			),
			'us_ashrae_90_1'         => array(
				'country'   => 'US',
				'title'     => __( 'United States — ASHRAE 90.1 Energy Standard', 'mcp-ai-wpoos-pro' ),
				'authority' => 'ASHRAE',
				'reference' => 'ASHRAE 90.1-2022',
				'rules'     => self::us_ashrae_90_1_rules(),
			),
		);

		/**
		 * Filters the registered architectural code packs.
		 *
		 * Partners can register additional jurisdictions (UK, India, GCC,
		 * etc.) by adding entries here.
		 *
		 * @since 1.2.0
		 *
		 * @param array $packs Code packs keyed by identifier.
		 */
		return apply_filters( 'wp_mcp_ai_arch_code_packs', $packs );
	}

	/**
	 * Get a single code pack by ID.
	 *
	 * @param string $pack_id Code pack identifier.
	 * @return array|null Pack array or null if unknown.
	 */
	public static function get_pack( $pack_id ) {
		$packs   = self::get_code_packs();
		$pack_id = (string) $pack_id;
		return isset( $packs[ $pack_id ] ) ? $packs[ $pack_id ] : null;
	}

	/**
	 * Get all code packs registered for a given country code.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2.
	 * @return array<string,array>
	 */
	public static function get_packs_for_country( $country_code ) {
		$country_code = strtoupper( (string) $country_code );
		$packs        = self::get_code_packs();
		$result       = array();
		foreach ( $packs as $id => $pack ) {
			if ( isset( $pack['country'] ) && strtoupper( (string) $pack['country'] ) === $country_code ) {
				$result[ $id ] = $pack;
			}
		}
		return $result;
	}

	/**
	 * List the supported country codes.
	 *
	 * @return array<int,string>
	 */
	public static function get_supported_countries() {
		$packs     = self::get_code_packs();
		$countries = array();
		foreach ( $packs as $pack ) {
			if ( isset( $pack['country'] ) ) {
				$countries[ strtoupper( (string) $pack['country'] ) ] = true;
			}
		}
		return array_keys( $countries );
	}

	/**
	 * Get the default code pack identifier for a country.
	 *
	 * @param string $country_code Country code.
	 * @return string Pack identifier or empty string.
	 */
	public static function get_default_pack_for_country( $country_code ) {
		$country_code = strtoupper( (string) $country_code );
		$defaults     = array(
			'LK' => 'lk_uda_2021',
			'JM' => 'jm_jnbc_2018',
			'US' => 'us_ibc_2024',
		);

		/**
		 * Filters the per-country default code pack.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $defaults     Defaults keyed by country code.
		 * @param string $country_code Country code being queried.
		 */
		$defaults = apply_filters( 'wp_mcp_ai_arch_default_code_packs', $defaults, $country_code );

		return isset( $defaults[ $country_code ] ) ? (string) $defaults[ $country_code ] : '';
	}

	// =================================================================
	// Sri Lanka — rule definitions.
	// =================================================================

	/**
	 * UDA planning & building rule snapshot.
	 *
	 * @param string $vintage '2021' or '2025'.
	 * @return array
	 */
	protected static function lk_uda_rules( $vintage ) {
		// Indicative residential single-family defaults; UDA varies by zone.
		$front = ( '2025' === (string) $vintage ) ? 2.0 : 2.0;
		$rear  = 1.0;
		$side  = 1.0;

		return array(
			'zoning'        => array(
				'far_max_residential'         => 2.0,
				'far_max_mixed_use'           => 4.0,
				'site_coverage_max'           => 65.0,
				'min_setback_front_m'         => $front,
				'min_setback_rear_m'          => $rear,
				'min_setback_side_m'          => $side,
				'min_lot_perches_residential' => 5.0,
				'eia_threshold_units'         => 40, // EIA required for projects > 40 housing units (indicative).
			),
			'egress'        => array(
				'min_corridor_width_m'  => 1.2,
				'min_stair_width_m'     => 1.1,
				'max_travel_distance_m' => 30.0,
				'min_exits'             => 2,
			),
			'fire_safety'   => array(
				'sprinkler_above_m'   => 15.0,
				'min_wall_rating_min' => 60,
				'compartment_max_m2'  => 2000.0,
			),
			'accessibility' => array(
				'min_door_clear_width_mm'                => 800.0,
				'max_ramp_slope_ratio'                   => 1.0 / 12.0,
				'accessible_wc_required_above_occupants' => 50,
			),
			'structural'    => array(
				'wind_standard'    => 'BS 6399-2 / IS 875-3 (referenced)',
				'seismic_standard' => 'IS 1893 (referenced)',
				'wind_zones'       => array( 'zone1', 'zone2', 'zone3' ),
				'seismic_zones'    => array( 'zone2', 'zone3' ),
			),
			'energy'        => array(
				'min_ach'              => 6.0,
				'recommended_ach'      => 8.0,
				'envelope_u_value_max' => 2.5, // W/m^2K, indicative for tropical residential.
			),
		);
	}

	/**
	 * SLS 947 ventilation rule subset.
	 *
	 * @return array
	 */
	protected static function lk_ventilation_rules() {
		return array(
			'energy' => array(
				'residential_ach_min' => 5.0,
				'residential_ach_max' => 10.0,
				'school_ach_min'      => 6.0,
				'office_ach_min'      => 6.0,
				'kitchen_ach_min'     => 15.0,
				'bathroom_ach_min'    => 8.0,
			),
		);
	}

	/**
	 * Sri Lankan wind structural reference.
	 *
	 * @return array
	 */
	protected static function lk_structural_wind_rules() {
		return array(
			'structural' => array(
				'wind_standard' => 'BS 6399-2 / IS 875-3 referenced via SLS',
				'wind_zones'    => array(
					'zone1' => array( 'basic_wind_ms' => 33.0 ),
					'zone2' => array( 'basic_wind_ms' => 38.0 ),
					'zone3' => array( 'basic_wind_ms' => 49.0 ),
				),
				'design_method' => 'limit_state',
			),
		);
	}

	/**
	 * Sri Lankan seismic structural reference.
	 *
	 * @return array
	 */
	protected static function lk_structural_seismic_rules() {
		return array(
			'structural' => array(
				'seismic_standard'   => 'IS 1893 referenced',
				'seismic_zones'      => array(
					'zone2' => array( 'sds' => 0.10 ),
					'zone3' => array( 'sds' => 0.16 ),
				),
				'importance_factors' => array(
					'standard'  => 1.0,
					'essential' => 1.5,
				),
			),
		);
	}

	/**
	 * NBRO landslide / hazard rule snapshot.
	 *
	 * @return array
	 */
	protected static function lk_nbro_rules() {
		return array(
			'zoning' => array(
				'landslide_clearance_required_in_zones' => array( 'high', 'moderate' ),
				'requires_geo_report_above_slope_deg'   => 15.0,
			),
		);
	}

	// =================================================================
	// Jamaica — rule definitions.
	// =================================================================

	/**
	 * JNBC 2018 rule subset.
	 *
	 * @return array
	 */
	protected static function jm_jnbc_rules() {
		return array(
			'zoning'        => array(
				'far_max_residential' => 1.5,
				'far_max_commercial'  => 3.5,
				'site_coverage_max'   => 60.0,
				'min_setback_front_m' => 6.0,
				'min_setback_rear_m'  => 3.0,
				'min_setback_side_m'  => 1.5,
			),
			'egress'        => array(
				'min_corridor_width_m'  => 1.1,
				'min_stair_width_m'     => 1.1,
				'max_travel_distance_m' => 60.0,
				'min_exits'             => 2,
			),
			'fire_safety'   => array(
				'sprinkler_above_m'   => 23.0,
				'min_wall_rating_min' => 60,
				'compartment_max_m2'  => 1860.0,
			),
			'accessibility' => array(
				'min_door_clear_width_mm' => 815.0,
				'max_ramp_slope_ratio'    => 1.0 / 12.0,
			),
			'structural'    => array(
				'wind_standard'                   => 'ASCE 7 (referenced via JNBC Part 7)',
				'seismic_standard'                => 'ASCE 7 (Caribbean seismic zones)',
				'wind_zones'                      => array( 'inland', 'standard', 'coastal' ),
				'opening_protection_required'     => true,
				'tie_down_continuous'             => true,
				'essential_facility_v_uplift_kpa' => 1.5,
			),
			'energy'        => array(
				'min_ach'         => 6.0,
				'recommended_ach' => 10.0,
			),
		);
	}

	/**
	 * Jamaica wind via ASCE 7 reference.
	 *
	 * @return array
	 */
	protected static function jm_asce_wind_rules() {
		return array(
			'structural' => array(
				'wind_standard'               => 'ASCE 7 referenced via JNBC Part 7',
				'wind_zones'                  => array(
					'inland'   => array(
						'basic_wind_ms'  => 58.0,
						'basic_wind_mph' => 130,
					),
					'standard' => array(
						'basic_wind_ms'  => 63.0,
						'basic_wind_mph' => 141,
					),
					'coastal'  => array(
						'basic_wind_ms'  => 67.0,
						'basic_wind_mph' => 150,
					),
				),
				'opening_protection_required' => true,
			),
		);
	}

	/**
	 * JS 35 ventilation reference.
	 *
	 * @return array
	 */
	protected static function jm_js35_rules() {
		return array(
			'energy' => array(
				'residential_ach_min'        => 6.0,
				'residential_ach_max'        => 12.0,
				'cross_ventilation_required' => true,
			),
		);
	}

	/**
	 * Jamaica parish council overlay placeholders.
	 *
	 * @return array
	 */
	protected static function jm_parish_rules() {
		return array(
			'zoning' => array(
				// Parish-specific overlays must be supplied via filter or.
				// settings; this placeholder allows tools to detect when.
				// no overlay has been registered.
				'overlay_registered' => false,
			),
		);
	}

	// =================================================================
	// United States — rule definitions.
	// =================================================================

	/**
	 * IBC 2024 rule subset.
	 *
	 * @return array
	 */
	protected static function us_ibc_rules() {
		return array(
			'zoning'        => array(
				// Zoning is not in IBC; left blank for jurisdictional fill.
				'overlay_registered' => false,
			),
			'egress'        => array(
				'min_corridor_width_m'             => 1.117,  // 44".
				'min_stair_width_m'                => 1.117,  // 44" with sprinklers; 1118mm.
				'max_travel_distance_m'            => 76.2,   // 250 ft sprinklered Group B.
				'min_exits'                        => 2,
				'occupant_load_factor_business_m2' => 9.3,
			),
			'fire_safety'   => array(
				'sprinkler_above_m'   => 16.764, // 55 ft trigger.
				'min_wall_rating_min' => 60,
				'compartment_max_m2'  => 4645.0, // 50,000 sq ft.
			),
			'accessibility' => array(
				'min_door_clear_width_mm' => 815.0, // 32" clear.
				'max_ramp_slope_ratio'    => 1.0 / 12.0,
			),
			'structural'    => array(
				'wind_standard'    => 'ASCE 7-22',
				'seismic_standard' => 'ASCE 7-22',
				'wind_zones'       => array( 'inland', 'standard', 'coastal', 'hurricane' ),
				'seismic_zones'    => array( 'a', 'b', 'c', 'd', 'e', 'f' ),
			),
			'energy'        => array(
				'reference_standard' => 'IECC 2024 / ASHRAE 90.1',
			),
		);
	}

	/**
	 * IRC 2024 (residential) rule subset.
	 *
	 * @return array
	 */
	protected static function us_irc_rules() {
		return array(
			'egress'      => array(
				'min_door_clear_width_mm' => 813.0,  // 32".
				'min_stair_width_m'       => 0.914,  // 36".
				'min_exits'               => 1,      // 1-2 family dwellings.
				'max_travel_distance_m'   => 0.0,    // Not applicable.
			),
			'fire_safety' => array(
				'smoke_alarms_required'    => true,
				'co_alarms_required'       => true,
				'sprinklers_above_stories' => 3,
			),
			'structural'  => array(
				'wind_standard'    => 'ASCE 7-22 (per IRC R301.2.1.1)',
				'seismic_standard' => 'ASCE 7-22 (per IRC R301.2.2)',
			),
			'energy'      => array(
				'reference_standard' => 'IECC 2024 (residential)',
			),
		);
	}

	/**
	 * IECC 2024 envelope minima (indicative for climate zone 4 mixed-humid).
	 *
	 * @return array
	 */
	protected static function us_iecc_rules() {
		return array(
			'energy' => array(
				'envelope_u_value_max_wall_w_m2k' => 0.36,
				'envelope_u_value_max_roof_w_m2k' => 0.18,
				'window_u_value_max_w_m2k'        => 1.99,
				'window_shgc_max'                 => 0.40,
			),
		);
	}

	/**
	 * ASCE 7-22 reference.
	 *
	 * @return array
	 */
	protected static function us_asce7_rules() {
		return array(
			'structural' => array(
				'wind_standard'    => 'ASCE 7-22',
				'seismic_standard' => 'ASCE 7-22',
				'wind_zones'       => array(
					'inland'    => array( 'basic_wind_ms' => 50.0 ),
					'standard'  => array( 'basic_wind_ms' => 54.0 ),
					'coastal'   => array( 'basic_wind_ms' => 63.0 ),
					'hurricane' => array( 'basic_wind_ms' => 76.0 ),
				),
				'seismic_zones'    => array(
					'a' => array( 'sds' => 0.10 ),
					'b' => array( 'sds' => 0.20 ),
					'c' => array( 'sds' => 0.30 ),
					'd' => array( 'sds' => 0.50 ),
					'e' => array( 'sds' => 0.75 ),
					'f' => array( 'sds' => 1.00 ),
				),
			),
		);
	}

	/**
	 * NFPA 101 rule subset.
	 *
	 * @return array
	 */
	protected static function us_nfpa_rules() {
		return array(
			'egress'      => array(
				'min_door_clear_width_mm' => 815.0,
				'min_corridor_width_m'    => 1.118,
				'max_travel_distance_m'   => 60.96, // 200 ft typical.
				'min_exits'               => 2,
			),
			'fire_safety' => array(
				'occupancy_classifications_referenced' => true,
				'sprinkler_chapter_referenced'         => '13/13R/13D',
			),
		);
	}

	/**
	 * ADA 2010 rule subset.
	 *
	 * @return array
	 */
	protected static function us_ada_rules() {
		return array(
			'accessibility' => array(
				'min_door_clear_width_mm'       => 815.0,  // 32".
				'max_ramp_slope_ratio'          => 1.0 / 12.0,
				'min_accessible_route_width_mm' => 915.0,
				'min_accessible_wc_stall_mm'    => array(
					'width' => 1525.0,
					'depth' => 1525.0,
				),
			),
		);
	}

	/**
	 * ASHRAE 90.1 envelope minima (indicative).
	 *
	 * @return array
	 */
	protected static function us_ashrae_90_1_rules() {
		return array(
			'energy' => array(
				'reference_standard'              => 'ASHRAE 90.1-2022',
				'envelope_u_value_max_wall_w_m2k' => 0.40,
				'envelope_u_value_max_roof_w_m2k' => 0.20,
			),
		);
	}

	// =================================================================
	// Helpers.
	// =================================================================

	/**
	 * Merge multiple code packs into a single rule set, with later packs
	 * overriding earlier ones at the leaf-key level.
	 *
	 * @param array $pack_ids Ordered list of pack IDs to merge.
	 * @return array Merged rules array.
	 */
	public static function merge_rules( array $pack_ids ) {
		$merged = array();
		foreach ( $pack_ids as $pack_id ) {
			$pack = self::get_pack( $pack_id );
			if ( null === $pack || empty( $pack['rules'] ) || ! is_array( $pack['rules'] ) ) {
				continue;
			}
			foreach ( $pack['rules'] as $category => $rules ) {
				if ( ! isset( $merged[ $category ] ) || ! is_array( $merged[ $category ] ) ) {
					$merged[ $category ] = array();
				}
				if ( is_array( $rules ) ) {
					$merged[ $category ] = array_merge( $merged[ $category ], $rules );
				}
			}
		}
		return $merged;
	}
}
