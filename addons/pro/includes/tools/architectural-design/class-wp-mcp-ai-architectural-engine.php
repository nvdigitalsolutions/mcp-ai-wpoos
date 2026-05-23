<?php
/**
 * Architectural Design Toolkit Shared Engine
 *
 * Core architectural & building-science calculation methods used across all
 * Architectural Design toolkit modules. Implements industry-standard formulas
 * aligned with IBC, IRC, ASCE 7, ASHRAE 62.1, ASHRAE 55, IS 875-3, IS 1893,
 * BS 6399-2, JNBC 2018, and Sri Lanka UDA / SLS guidance.
 *
 * Designed to be PHP 7.4-compatible so the math can be promoted to the base
 * distribution in the future without refactor.
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
 * Shared architectural engine for the Architectural Design toolkit.
 *
 * Provides static methods for:
 * - Unit conversion (sq ft <-> m^2 <-> perches; ft <-> m).
 * - Currency conversion using filterable rate table (USD/LKR/JMD).
 * - Floor Area Ratio, site coverage and setback validation.
 * - Occupancy load and egress width (IBC-style).
 * - Wind pressure (ASCE 7 simplified, JNBC, BS 6399-2 / IS 875-3 hybrid).
 * - Seismic base shear (ASCE 7 ELF + IS 1893 zone factors).
 * - Heat-gain / cooling-load estimates (ASHRAE 62.1 ACH for tropical).
 * - Construction cost square-meter rates (LK/JM/US, by quality).
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Architectural_Engine {

	/**
	 * Square feet per square meter.
	 */
	const SQFT_PER_SQM = 10.7639104;

	/**
	 * Feet per meter.
	 */
	const FT_PER_M = 3.2808399;

	/**
	 * Square meters per Sri Lankan perch.
	 *
	 * 1 perch = 25.2929 m^2 = 272.25 sq ft (standard Sri Lankan land unit).
	 */
	const SQM_PER_PERCH = 25.2929;

	/**
	 * Convert square feet to square meters.
	 *
	 * @param float $sqft Square feet.
	 * @return float Square meters.
	 */
	public static function sqft_to_sqm( $sqft ) {
		$sqft = (float) $sqft;
		return $sqft / self::SQFT_PER_SQM;
	}

	/**
	 * Convert square meters to square feet.
	 *
	 * @param float $sqm Square meters.
	 * @return float Square feet.
	 */
	public static function sqm_to_sqft( $sqm ) {
		$sqm = (float) $sqm;
		return $sqm * self::SQFT_PER_SQM;
	}

	/**
	 * Convert Sri Lankan perches to square meters.
	 *
	 * @param float $perches Land area in perches.
	 * @return float Square meters.
	 */
	public static function perches_to_sqm( $perches ) {
		$perches = (float) $perches;
		return $perches * self::SQM_PER_PERCH;
	}

	/**
	 * Convert square meters to Sri Lankan perches.
	 *
	 * @param float $sqm Square meters.
	 * @return float Perches.
	 */
	public static function sqm_to_perches( $sqm ) {
		$sqm = (float) $sqm;
		if ( self::SQM_PER_PERCH <= 0 ) {
			return 0.0;
		}
		return $sqm / self::SQM_PER_PERCH;
	}

	/**
	 * Convert feet to meters.
	 *
	 * @param float $ft Feet.
	 * @return float Meters.
	 */
	public static function ft_to_m( $ft ) {
		$ft = (float) $ft;
		return $ft / self::FT_PER_M;
	}

	/**
	 * Convert meters to feet.
	 *
	 * @param float $m Meters.
	 * @return float Feet.
	 */
	public static function m_to_ft( $m ) {
		$m = (float) $m;
		return $m * self::FT_PER_M;
	}

	/**
	 * Get the indicative currency conversion table used by the toolkit.
	 *
	 * Rates are indicative and intended for analytical estimating only. They
	 * can be overridden per-site through the
	 * `wp_mcp_ai_arch_currency_rates` filter or via the toolkit settings page.
	 *
	 * Rates are expressed as units-per-1-USD.
	 *
	 * @return array<string,float> Rate table keyed by ISO-4217 code.
	 */
	public static function get_currency_rates() {
		$defaults = array(
			'USD' => 1.0,
			'LKR' => 305.0, // Indicative LKR/USD (approx. early-2026).
			'JMD' => 158.0, // Indicative JMD/USD.
			'EUR' => 0.92,
			'GBP' => 0.79,
		);

		// Allow override from settings.
		$settings = get_option( 'wp_mcp_ai_arch_design_settings', array() );
		if ( ! empty( $settings['currency_rates'] ) && is_array( $settings['currency_rates'] ) ) {
			foreach ( $settings['currency_rates'] as $code => $rate ) {
				if ( is_numeric( $rate ) && (float) $rate > 0 ) {
					$defaults[ strtoupper( (string) $code ) ] = (float) $rate;
				}
			}
		}

		/**
		 * Filters the architectural toolkit currency rate table.
		 *
		 * @since 1.2.0
		 *
		 * @param array $rates Rates keyed by ISO-4217 code, expressed as.
		 *                     units-per-1-USD.
		 */
		return apply_filters( 'wp_mcp_ai_arch_currency_rates', $defaults );
	}

	/**
	 * Convert an amount between currencies using the engine's rate table.
	 *
	 * @param float  $amount        Source amount.
	 * @param string $from_currency Source ISO-4217 code.
	 * @param string $to_currency   Target ISO-4217 code.
	 * @return float|null Converted amount, or null if either currency is
	 *                    unknown.
	 */
	public static function convert_currency( $amount, $from_currency, $to_currency ) {
		$amount        = (float) $amount;
		$from_currency = strtoupper( (string) $from_currency );
		$to_currency   = strtoupper( (string) $to_currency );
		$rates         = self::get_currency_rates();

		if ( ! isset( $rates[ $from_currency ] ) || ! isset( $rates[ $to_currency ] ) ) {
			return null;
		}
		if ( $rates[ $from_currency ] <= 0 ) {
			return null;
		}

		// Convert: source -> USD -> target.
		$amount_usd = $amount / $rates[ $from_currency ];
		return $amount_usd * $rates[ $to_currency ];
	}

	/**
	 * Calculate Floor Area Ratio (FAR / Plot Ratio).
	 *
	 * FAR = total built-up floor area / lot area. Both inputs must be in the
	 * same units; the result is dimensionless.
	 *
	 * @param float $built_up_area Total floor area.
	 * @param float $lot_area      Lot / site area.
	 * @return float FAR (dimensionless).
	 */
	public static function calculate_far( $built_up_area, $lot_area ) {
		$built_up_area = (float) $built_up_area;
		$lot_area      = (float) $lot_area;
		if ( $lot_area <= 0 ) {
			return 0.0;
		}
		return $built_up_area / $lot_area;
	}

	/**
	 * Calculate site coverage (%).
	 *
	 * @param float $footprint_area Ground-floor footprint area.
	 * @param float $lot_area       Lot area.
	 * @return float Coverage percentage (0-100).
	 */
	public static function calculate_site_coverage( $footprint_area, $lot_area ) {
		$footprint_area = (float) $footprint_area;
		$lot_area       = (float) $lot_area;
		if ( $lot_area <= 0 ) {
			return 0.0;
		}
		return ( $footprint_area / $lot_area ) * 100.0;
	}

	/**
	 * Validate setbacks against required minimums.
	 *
	 * @param array $proposed Proposed setbacks (keys: front, rear, left, right). Units: metres.
	 * @param array $required Required minimum setbacks (same keys, same units).
	 * @return array {
	 *     @type bool  $compliant      True if all setbacks meet or exceed required.
	 *     @type array $violations     List of violation descriptors.
	 * }
	 */
	public static function validate_setbacks( array $proposed, array $required ) {
		$violations = array();
		$sides      = array( 'front', 'rear', 'left', 'right' );

		foreach ( $sides as $side ) {
			$prop_val = isset( $proposed[ $side ] ) ? (float) $proposed[ $side ] : 0.0;
			$req_val  = isset( $required[ $side ] ) ? (float) $required[ $side ] : 0.0;
			if ( $prop_val + 1e-6 < $req_val ) {
				$violations[] = array(
					'side'      => $side,
					'proposed'  => $prop_val,
					'required'  => $req_val,
					'shortfall' => $req_val - $prop_val,
				);
			}
		}

		return array(
			'compliant'  => empty( $violations ),
			'violations' => $violations,
		);
	}

	/**
	 * Calculate occupancy load using IBC-style area-per-person factors.
	 *
	 * Default factors (m^2 per person) follow IBC Table 1004.5 in metric form:
	 * - business: 9.3
	 * - residential: 18.6
	 * - assembly_concentrated: 0.65
	 * - assembly_unconcentrated: 1.4
	 * - mercantile: 5.6
	 * - educational_classroom: 1.9
	 * - industrial: 9.3
	 * - storage: 46.5
	 *
	 * @param float  $area_sqm       Floor area in square meters.
	 * @param string $occupancy_type One of the keys above.
	 * @return int Occupant load (rounded up).
	 */
	public static function calculate_occupancy_load( $area_sqm, $occupancy_type ) {
		$area_sqm       = (float) $area_sqm;
		$occupancy_type = strtolower( (string) $occupancy_type );

		$factors = array(
			'business'                => 9.3,
			'residential'             => 18.6,
			'assembly_concentrated'   => 0.65,
			'assembly_unconcentrated' => 1.4,
			'mercantile'              => 5.6,
			'educational_classroom'   => 1.9,
			'industrial'              => 9.3,
			'storage'                 => 46.5,
		);

		/**
		 * Filters the occupancy-load area factors (m^2 per person).
		 *
		 * @since 1.2.0
		 *
		 * @param array $factors Factors keyed by occupancy type.
		 */
		$factors = apply_filters( 'wp_mcp_ai_arch_occupancy_factors', $factors );

		$factor = isset( $factors[ $occupancy_type ] ) ? (float) $factors[ $occupancy_type ] : 9.3;
		if ( $factor <= 0 || $area_sqm <= 0 ) {
			return 0;
		}

		return (int) ceil( $area_sqm / $factor );
	}

	/**
	 * Calculate required egress width (mm) for an occupant load.
	 *
	 * IBC-aligned defaults:
	 * - 5.1 mm per occupant for level egress (corridors, doorways).
	 * - 7.6 mm per occupant for stairways.
	 *
	 * @param int    $occupant_load Number of occupants.
	 * @param string $component     Either 'level' (default) or 'stair'.
	 * @return float Required width in millimetres.
	 */
	public static function calculate_egress_width( $occupant_load, $component = 'level' ) {
		$occupant_load = (int) $occupant_load;
		$component     = ( 'stair' === $component ) ? 'stair' : 'level';
		if ( $occupant_load <= 0 ) {
			return 0.0;
		}
		$factor = ( 'stair' === $component ) ? 7.6 : 5.1;
		return $occupant_load * $factor;
	}

	/**
	 * Calculate ASCE 7 simplified velocity pressure (Pa).
	 *
	 * q_z = 0.613 * Kz * Kzt * Kd * V^2  (V in m/s, q_z in Pa).
	 * This is the simplified form suitable for low-rise residential and
	 * small commercial buildings; complex projects must engage a structural
	 * engineer.
	 *
	 * @param float $velocity_ms Basic wind speed in m/s.
	 * @param float $kz          Velocity-pressure exposure coefficient (default 1.0).
	 * @param float $kzt         Topographic factor (default 1.0).
	 * @param float $kd          Wind directionality factor (default 0.85).
	 * @return float Velocity pressure q_z in Pa.
	 */
	public static function calculate_velocity_pressure( $velocity_ms, $kz = 1.0, $kzt = 1.0, $kd = 0.85 ) {
		$velocity_ms = (float) $velocity_ms;
		$kz          = (float) $kz;
		$kzt         = (float) $kzt;
		$kd          = (float) $kd;
		if ( $velocity_ms <= 0 ) {
			return 0.0;
		}
		return 0.613 * $kz * $kzt * $kd * pow( $velocity_ms, 2 );
	}

	/**
	 * Convert wind speed in mph to m/s.
	 *
	 * @param float $mph Speed in mph.
	 * @return float Speed in m/s.
	 */
	public static function mph_to_ms( $mph ) {
		return (float) $mph * 0.44704;
	}

	/**
	 * Convert wind speed in m/s to mph.
	 *
	 * @param float $ms Speed in m/s.
	 * @return float Speed in mph.
	 */
	public static function ms_to_mph( $ms ) {
		return (float) $ms / 0.44704;
	}

	/**
	 * Calculate wind base pressure for a given country and zone.
	 *
	 * Dispatches to a country-specific basic wind speed. Returns a velocity
	 * pressure (Pa) plus the basic wind speed used and the standard cited.
	 *
	 * @param string $country_code ISO 3166-1 alpha-2 ('LK','JM','US').
	 * @param string $wind_zone    Country-specific zone identifier.
	 * @return array {
	 *     @type string $standard       Standard cited (e.g. 'ASCE 7-22').
	 *     @type float  $basic_wind_ms  Basic wind speed in m/s.
	 *     @type float  $basic_wind_mph Basic wind speed in mph.
	 *     @type float  $velocity_pressure_pa  q_z in Pa using simplified factors.
	 * }
	 */
	public static function get_wind_design_pressure( $country_code, $wind_zone = '' ) {
		$country_code = strtoupper( (string) $country_code );
		$wind_zone    = strtolower( (string) $wind_zone );

		// Country-specific basic-wind-speed lookup tables (m/s).
		// Values are indicative defaults extracted from public code summaries.
		// and are filterable for jurisdictional refinement.
		$tables = array(
			'LK' => array(
				// Sri Lanka: SLS / IESL basic wind zones (post-2009 revision).
				'standard' => 'BS 6399-2 / IS 875-3 (referenced via SLS guidance)',
				'zones'    => array(
					'zone1'   => 33.0, // Coastal SW & N: ~33 m/s.
					'zone2'   => 38.0, // South & east coast.
					'zone3'   => 49.0, // High-exposure coastal (post-tsunami review).
					'default' => 38.0,
				),
			),
			'JM' => array(
				// Jamaica: JNBC 2018 references ASCE 7 with Caribbean basic.
				// wind speeds in the 60-67 m/s (~135-150 mph) range for.
				// hurricane-exposure zones.
				'standard' => 'JNBC 2018 (per ASCE 7)',
				'zones'    => array(
					'inland'   => 58.0, // ~130 mph.
					'standard' => 63.0, // ~141 mph.
					'coastal'  => 67.0, // ~150 mph.
					'default'  => 63.0,
				),
			),
			'US' => array(
				// USA: ASCE 7-22 risk category II.
				'standard' => 'ASCE 7-22',
				'zones'    => array(
					'inland'    => 50.0, // ~112 mph.
					'standard'  => 54.0, // ~120 mph.
					'coastal'   => 63.0, // ~141 mph.
					'hurricane' => 76.0, // ~170 mph.
					'default'   => 54.0,
				),
			),
		);

		/**
		 * Filters the wind-zone basic speed lookup table.
		 *
		 * @since 1.2.0
		 *
		 * @param array $tables Wind tables keyed by country code.
		 */
		$tables = apply_filters( 'wp_mcp_ai_arch_wind_tables', $tables );

		if ( ! isset( $tables[ $country_code ] ) ) {
			return array(
				'standard'             => '',
				'basic_wind_ms'        => 0.0,
				'basic_wind_mph'       => 0.0,
				'velocity_pressure_pa' => 0.0,
			);
		}

		$entry = $tables[ $country_code ];
		$zones = isset( $entry['zones'] ) && is_array( $entry['zones'] ) ? $entry['zones'] : array();
		$speed = isset( $zones[ $wind_zone ] ) ? (float) $zones[ $wind_zone ] : 0.0;
		if ( $speed <= 0 ) {
			$speed = isset( $zones['default'] ) ? (float) $zones['default'] : 0.0;
		}

		return array(
			'standard'             => isset( $entry['standard'] ) ? (string) $entry['standard'] : '',
			'basic_wind_ms'        => $speed,
			'basic_wind_mph'       => self::ms_to_mph( $speed ),
			'velocity_pressure_pa' => self::calculate_velocity_pressure( $speed ),
		);
	}

	/**
	 * Calculate seismic base shear using the ASCE 7 Equivalent Lateral Force
	 * method, simplified.
	 *
	 * V = Cs * W where Cs = SDS / (R / Ie). For low-to-moderate seismic
	 * regions (Sri Lanka via IS 1893) the seismic_coefficient input is used
	 * directly when supplied.
	 *
	 * @param float $building_weight_kn Total seismic weight (kN).
	 * @param float $sds                Design spectral response (g).
	 * @param float $r_factor           Response modification coefficient.
	 * @param float $importance_factor  Risk-importance factor (Ie).
	 * @return array {
	 *     @type float $cs               Seismic response coefficient (g).
	 *     @type float $base_shear_kn    Base shear V (kN).
	 * }
	 */
	public static function calculate_seismic_base_shear( $building_weight_kn, $sds, $r_factor, $importance_factor = 1.0 ) {
		$building_weight_kn = (float) $building_weight_kn;
		$sds                = (float) $sds;
		$r_factor           = (float) $r_factor;
		$importance_factor  = (float) $importance_factor;

		if ( $building_weight_kn <= 0 || $r_factor <= 0 ) {
			return array(
				'cs'            => 0.0,
				'base_shear_kn' => 0.0,
			);
		}
		if ( $importance_factor <= 0 ) {
			$importance_factor = 1.0;
		}

		$cs         = $sds / ( $r_factor / $importance_factor );
		$base_shear = $cs * $building_weight_kn;

		return array(
			'cs'            => $cs,
			'base_shear_kn' => $base_shear,
		);
	}

	/**
	 * Get IS 1893 / ASCE 7 indicative SDS for a country & zone.
	 *
	 * Sri Lanka has historically been treated as low-to-moderate seismicity
	 * with reference to IS 1893 zone II. Jamaica is moderate-to-high (post-
	 * 2010 Haiti review). US uses ASCE 7 SDS values which are highly site-
	 * specific; the value returned is an indicative default and must be
	 * replaced with USGS Seismic Design Maps data for design.
	 *
	 * @param string $country_code Country code.
	 * @param string $seismic_zone Zone identifier.
	 * @return array { sds: float, standard: string }
	 */
	public static function get_seismic_design_parameters( $country_code, $seismic_zone = '' ) {
		$country_code = strtoupper( (string) $country_code );
		$seismic_zone = strtolower( (string) $seismic_zone );

		$tables = array(
			'LK' => array(
				'standard' => 'IS 1893 (referenced via SLS / IESL guidance)',
				'zones'    => array(
					'zone2'   => 0.10, // Low seismicity.
					'zone3'   => 0.16, // Moderate.
					'default' => 0.10,
				),
			),
			'JM' => array(
				'standard' => 'JNBC 2018 / ASCE 7 (Caribbean seismic)',
				'zones'    => array(
					'low'      => 0.20,
					'moderate' => 0.40,
					'high'     => 0.60,
					'default'  => 0.40,
				),
			),
			'US' => array(
				'standard' => 'ASCE 7-22',
				'zones'    => array(
					'a'       => 0.10,
					'b'       => 0.20,
					'c'       => 0.30,
					'd'       => 0.50,
					'e'       => 0.75,
					'f'       => 1.00,
					'default' => 0.30,
				),
			),
		);

		/**
		 * Filters the seismic SDS lookup table.
		 *
		 * @since 1.2.0
		 *
		 * @param array $tables Seismic tables keyed by country code.
		 */
		$tables = apply_filters( 'wp_mcp_ai_arch_seismic_tables', $tables );

		if ( ! isset( $tables[ $country_code ] ) ) {
			return array(
				'sds'      => 0.0,
				'standard' => '',
			);
		}

		$entry = $tables[ $country_code ];
		$zones = isset( $entry['zones'] ) && is_array( $entry['zones'] ) ? $entry['zones'] : array();
		$sds   = isset( $zones[ $seismic_zone ] ) ? (float) $zones[ $seismic_zone ] : 0.0;
		if ( $sds <= 0 ) {
			$sds = isset( $zones['default'] ) ? (float) $zones['default'] : 0.0;
		}

		return array(
			'sds'      => $sds,
			'standard' => isset( $entry['standard'] ) ? (string) $entry['standard'] : '',
		);
	}

	/**
	 * Estimate ventilation airflow required (L/s) for a given occupancy.
	 *
	 * ASHRAE 62.1-2022 / SLS 947 / JS 35 hybrid:
	 * - Default per-occupant rate: 7.5 L/s per person.
	 * - Default area rate: 0.3 L/s per m^2.
	 *
	 * For tropical, naturally-ventilated buildings (LK / JM), the WHO and
	 * SLS 947 recommend 6-12 ACH; this method returns the larger of the
	 * ASHRAE rate and the ACH-based requirement.
	 *
	 * @param int   $occupants Number of occupants.
	 * @param float $area_sqm  Floor area (m^2).
	 * @param float $height_m  Average ceiling height (m). Default 2.7.
	 * @param float $target_ach Optional target air changes per hour. Default 8.
	 * @return array {
	 *     @type float $ashrae_lps Required L/s per ASHRAE 62.1.
	 *     @type float $ach_lps    Equivalent L/s for the target ACH.
	 *     @type float $required_lps Maximum of the two.
	 * }
	 */
	public static function calculate_ventilation_airflow( $occupants, $area_sqm, $height_m = 2.7, $target_ach = 8.0 ) {
		$occupants  = (int) $occupants;
		$area_sqm   = (float) $area_sqm;
		$height_m   = (float) $height_m;
		$target_ach = (float) $target_ach;

		if ( $occupants < 0 ) {
			$occupants = 0;
		}
		if ( $area_sqm < 0 ) {
			$area_sqm = 0.0;
		}
		if ( $height_m <= 0 ) {
			$height_m = 2.7;
		}
		if ( $target_ach < 0 ) {
			$target_ach = 0.0;
		}

		$ashrae_lps = ( $occupants * 7.5 ) + ( $area_sqm * 0.3 );

		// Volume in m^3 -> L/s for the target ACH.
		$volume_m3 = $area_sqm * $height_m;
		$ach_lps   = ( $volume_m3 * 1000.0 * $target_ach ) / 3600.0;

		return array(
			'ashrae_lps'   => $ashrae_lps,
			'ach_lps'      => $ach_lps,
			'required_lps' => max( $ashrae_lps, $ach_lps ),
		);
	}

	/**
	 * Get indicative construction-cost rates per square metre by country and quality.
	 *
	 * Rates are expressed in the country's local currency per m^2 and are
	 * intended for early-stage analytical estimating only. Override via
	 * settings or the `wp_mcp_ai_arch_cost_rates` filter for live project
	 * use.
	 *
	 * @param string $country_code   Country code.
	 * @param string $quality_level  One of: economy, standard, custom, luxury.
	 * @param string $construction_type One of: wood_frame, masonry, steel, concrete, hybrid.
	 * @return array {
	 *     @type string $currency       ISO-4217 code.
	 *     @type float  $rate_per_sqm   Local currency per m^2.
	 *     @type float  $rate_per_sqft  Local currency per sq ft.
	 *     @type string $index_source   Cost index reference.
	 * }
	 */
	public static function get_cost_rate( $country_code, $quality_level = 'standard', $construction_type = 'masonry' ) {
		$country_code      = strtoupper( (string) $country_code );
		$quality_level     = strtolower( (string) $quality_level );
		$construction_type = strtolower( (string) $construction_type );

		// Indicative LKR / m^2 costs (ICTAD/CIDA-aligned ranges, early 2026).
		// Indicative JMD / m^2 costs (BSJ / parish council guidance).
		// Indicative USD / sq ft costs converted to USD / m^2.
		$tables = array(
			'LK' => array(
				'currency'     => 'LKR',
				'index_source' => 'ICTAD/CIDA cost index (indicative)',
				'rates'        => array(
					'economy'  => 95000.0,
					'standard' => 145000.0,
					'custom'   => 220000.0,
					'luxury'   => 350000.0,
				),
			),
			'JM' => array(
				'currency'     => 'JMD',
				'index_source' => 'Bureau of Standards Jamaica / RICS Caribbean (indicative)',
				'rates'        => array(
					'economy'  => 165000.0,
					'standard' => 240000.0,
					'custom'   => 350000.0,
					'luxury'   => 520000.0,
				),
			),
			'US' => array(
				'currency'     => 'USD',
				'index_source' => 'RSMeans / national averages (indicative)',
				'rates'        => array(
					'economy'  => 1850.0,  // ~ $172/sf.
					'standard' => 2580.0,  // ~ $240/sf.
					'custom'   => 3870.0,  // ~ $360/sf.
					'luxury'   => 6450.0,  // ~ $600/sf.
				),
			),
		);

		// Construction-type multiplier on top of base rate.
		$type_multipliers = array(
			'wood_frame' => 0.92,
			'masonry'    => 1.00,
			'steel'      => 1.18,
			'concrete'   => 1.10,
			'hybrid'     => 1.05,
		);

		/**
		 * Filters the per-country construction cost rate table.
		 *
		 * @since 1.2.0
		 *
		 * @param array $tables Cost tables keyed by country code.
		 */
		$tables = apply_filters( 'wp_mcp_ai_arch_cost_rates', $tables );

		/**
		 * Filters the construction-type rate multipliers.
		 *
		 * @since 1.2.0
		 *
		 * @param array $type_multipliers Multipliers keyed by construction type.
		 */
		$type_multipliers = apply_filters( 'wp_mcp_ai_arch_cost_type_multipliers', $type_multipliers );

		if ( ! isset( $tables[ $country_code ] ) ) {
			return array(
				'currency'      => '',
				'rate_per_sqm'  => 0.0,
				'rate_per_sqft' => 0.0,
				'index_source'  => '',
			);
		}

		$entry     = $tables[ $country_code ];
		$rates     = isset( $entry['rates'] ) && is_array( $entry['rates'] ) ? $entry['rates'] : array();
		$base_rate = isset( $rates[ $quality_level ] ) ? (float) $rates[ $quality_level ] : 0.0;
		if ( $base_rate <= 0 ) {
			$base_rate = isset( $rates['standard'] ) ? (float) $rates['standard'] : 0.0;
		}
		$multiplier = isset( $type_multipliers[ $construction_type ] ) ? (float) $type_multipliers[ $construction_type ] : 1.0;
		$rate       = $base_rate * $multiplier;

		return array(
			'currency'      => isset( $entry['currency'] ) ? (string) $entry['currency'] : '',
			'rate_per_sqm'  => $rate,
			'rate_per_sqft' => $rate / self::SQFT_PER_SQM,
			'index_source'  => isset( $entry['index_source'] ) ? (string) $entry['index_source'] : '',
		);
	}

	/**
	 * Get the resolved toolkit settings with defaults applied.
	 *
	 * @return array Settings array.
	 */
	public static function get_toolkit_settings() {
		$defaults = array(
			'default_country'     => 'LK',
			'default_unit_system' => 'metric',
			'default_currency'    => 'LKR',
			'default_code_pack'   => 'lk_uda_2021',
			'ifc_export_version'  => '4.3',
			'masterformat_year'   => '2020',
			'currency_rates'      => array(),
		);

		$settings = get_option( 'wp_mcp_ai_arch_design_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$resolved = array_merge( $defaults, $settings );

		/**
		 * Filters the resolved Architectural Design toolkit settings.
		 *
		 * @since 1.2.0
		 *
		 * @param array $resolved Resolved settings array.
		 */
		return apply_filters( 'wp_mcp_ai_arch_toolkit_settings', $resolved );
	}
}
