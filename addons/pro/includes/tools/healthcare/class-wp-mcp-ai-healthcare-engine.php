<?php
/**
 * Healthcare Toolkit Shared Engine
 *
 * Cross-cutting helpers shared by the three healthcare sub-toolkits
 * (Medical Vitals, Health & Wellness, Medical Imaging):
 *
 *  - Unit conversions (kg/lb, cm/in, mmHg, °C/°F, mL/oz).
 *  - Member identity resolution (canonical member_id / MRN / FHIR identifier).
 *  - Reference-range lookup for vitals (age / sex / species aware).
 *  - Risk-flag heuristics (BMI category, hypertension stage, paediatric percentile).
 *  - Resolved per-toolkit settings (`wp_mcp_ai_healthcare_settings`).
 *
 * Designed to be PHP 7.4-compatible so the helpers can be reused by base or
 * Pro code without refactor.
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

/**
 * Shared healthcare engine.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Healthcare_Engine {

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION = 'wp_mcp_ai_healthcare_settings';

	/**
	 * Pounds per kilogram.
	 */
	const LB_PER_KG = 2.2046226218;

	/**
	 * Inches per centimetre.
	 */
	const IN_PER_CM = 0.3937007874;

	/**
	 * Fluid ounces per millilitre (US customary).
	 */
	const FLOZ_PER_ML = 0.0338140227;

	/*
	---------------------------------------------------------------------
	 * Unit conversions
	 * ------------------------------------------------------------------
	 */

	/**
	 * Convert kilograms to pounds.
	 *
	 * @param float $kg Mass in kilograms.
	 * @return float Mass in pounds.
	 */
	public static function kg_to_lb( $kg ) {
		return (float) $kg * self::LB_PER_KG;
	}

	/**
	 * Convert pounds to kilograms.
	 *
	 * @param float $lb Mass in pounds.
	 * @return float Mass in kilograms.
	 */
	public static function lb_to_kg( $lb ) {
		return (float) $lb / self::LB_PER_KG;
	}

	/**
	 * Convert centimetres to inches.
	 *
	 * @param float $cm Length in centimetres.
	 * @return float Length in inches.
	 */
	public static function cm_to_in( $cm ) {
		return (float) $cm * self::IN_PER_CM;
	}

	/**
	 * Convert inches to centimetres.
	 *
	 * @param float $in Length in inches.
	 * @return float Length in centimetres.
	 */
	public static function in_to_cm( $in ) {
		return (float) $in / self::IN_PER_CM;
	}

	/**
	 * Convert millilitres to US fluid ounces.
	 *
	 * @param float $ml Volume in millilitres.
	 * @return float Volume in fl oz.
	 */
	public static function ml_to_floz( $ml ) {
		return (float) $ml * self::FLOZ_PER_ML;
	}

	/**
	 * Convert US fluid ounces to millilitres.
	 *
	 * @param float $floz Volume in fl oz.
	 * @return float Volume in millilitres.
	 */
	public static function floz_to_ml( $floz ) {
		return (float) $floz / self::FLOZ_PER_ML;
	}

	/**
	 * Convert Celsius to Fahrenheit.
	 *
	 * @param float $c Temperature in degrees Celsius.
	 * @return float Temperature in degrees Fahrenheit.
	 */
	public static function c_to_f( $c ) {
		return ( (float) $c * 9.0 / 5.0 ) + 32.0;
	}

	/**
	 * Convert Fahrenheit to Celsius.
	 *
	 * @param float $f Temperature in degrees Fahrenheit.
	 * @return float Temperature in degrees Celsius.
	 */
	public static function f_to_c( $f ) {
		return ( (float) $f - 32.0 ) * 5.0 / 9.0;
	}

	/**
	 * Calculate Body Mass Index from metric units.
	 *
	 * @param float $weight_kg Weight in kilograms.
	 * @param float $height_cm Height in centimetres.
	 * @return float|null BMI value or null on invalid input.
	 */
	public static function bmi( $weight_kg, $height_cm ) {
		$weight_kg = (float) $weight_kg;
		$height_m  = (float) $height_cm / 100.0;
		if ( $weight_kg <= 0 || $height_m <= 0 ) {
			return null;
		}
		return $weight_kg / ( $height_m * $height_m );
	}

	/**
	 * Categorise a BMI value following WHO adult bands.
	 *
	 * Categories: underweight, normal, overweight, obese_1, obese_2, obese_3.
	 * Returns 'unknown' for invalid input.
	 *
	 * @param float|null $bmi BMI value.
	 * @return string Category slug.
	 */
	public static function bmi_category( $bmi ) {
		if ( null === $bmi || ! is_numeric( $bmi ) || $bmi <= 0 ) {
			return 'unknown';
		}
		$bmi = (float) $bmi;
		if ( $bmi < 18.5 ) {
			return 'underweight';
		}
		if ( $bmi < 25.0 ) {
			return 'normal';
		}
		if ( $bmi < 30.0 ) {
			return 'overweight';
		}
		if ( $bmi < 35.0 ) {
			return 'obese_1';
		}
		if ( $bmi < 40.0 ) {
			return 'obese_2';
		}
		return 'obese_3';
	}

	/**
	 * Categorise a blood pressure reading following ACC/AHA 2017 adult guidance.
	 *
	 * Returns one of: 'normal', 'elevated', 'stage_1', 'stage_2', 'crisis', 'unknown'.
	 *
	 * @param int|float $systolic_mmhg  Systolic mmHg.
	 * @param int|float $diastolic_mmhg Diastolic mmHg.
	 * @return string Stage slug.
	 */
	public static function bp_stage( $systolic_mmhg, $diastolic_mmhg ) {
		$s = (float) $systolic_mmhg;
		$d = (float) $diastolic_mmhg;
		if ( $s <= 0 || $d <= 0 ) {
			return 'unknown';
		}
		if ( $s > 180 || $d > 120 ) {
			return 'crisis';
		}
		if ( $s >= 140 || $d >= 90 ) {
			return 'stage_2';
		}
		if ( $s >= 130 || $d >= 80 ) {
			return 'stage_1';
		}
		if ( $s >= 120 && $d < 80 ) {
			return 'elevated';
		}
		return 'normal';
	}

	/*
	---------------------------------------------------------------------
	 * Reference ranges
	 * ------------------------------------------------------------------
	 */

	/**
	 * Get vitals reference ranges for a member context.
	 *
	 * The default table is conservative adult human values; veterinary
	 * deployments and paediatric practices override via the
	 * `wp_mcp_ai_healthcare_reference_ranges` filter.
	 *
	 * Each metric is an associative array of { min, max, unit }.
	 *
	 * @param array $context Member context. Recognised keys: species, sex, age_years.
	 *                       - species: 'human' (default) | 'canine' | 'feline' | …
	 *                       - sex:     'male' | 'female' | 'unknown'
	 *                       - age_years: float|null.
	 * @return array Map of metric_slug => { min, max, unit }.
	 */
	public static function reference_ranges( array $context = array() ) {
		$context = wp_parse_args(
			$context,
			array(
				'species'   => 'human',
				'sex'       => 'unknown',
				'age_years' => null,
			)
		);

		$default = array(
			'heart_rate'         => array(
				'min'  => 60,
				'max'  => 100,
				'unit' => 'bpm',
			),
			'systolic_bp'        => array(
				'min'  => 90,
				'max'  => 120,
				'unit' => 'mmHg',
			),
			'diastolic_bp'       => array(
				'min'  => 60,
				'max'  => 80,
				'unit' => 'mmHg',
			),
			'temperature_c'      => array(
				'min'  => 36.1,
				'max'  => 37.5,
				'unit' => '°C',
			),
			'respiratory_rate'   => array(
				'min'  => 12,
				'max'  => 20,
				'unit' => 'breaths/min',
			),
			'spo2'               => array(
				'min'  => 95,
				'max'  => 100,
				'unit' => '%',
			),
			'blood_glucose_mgdl' => array(
				'min'  => 70,
				'max'  => 140,
				'unit' => 'mg/dL',
			),
		);

		// Conservative canine baselines for veterinary deployments.
		if ( 'canine' === $context['species'] ) {
			$default['heart_rate']       = array(
				'min'  => 60,
				'max'  => 140,
				'unit' => 'bpm',
			);
			$default['respiratory_rate'] = array(
				'min'  => 10,
				'max'  => 30,
				'unit' => 'breaths/min',
			);
			$default['temperature_c']    = array(
				'min'  => 38.3,
				'max'  => 39.2,
				'unit' => '°C',
			);
		} elseif ( 'feline' === $context['species'] ) {
			$default['heart_rate']       = array(
				'min'  => 140,
				'max'  => 220,
				'unit' => 'bpm',
			);
			$default['respiratory_rate'] = array(
				'min'  => 20,
				'max'  => 30,
				'unit' => 'breaths/min',
			);
			$default['temperature_c']    = array(
				'min'  => 38.1,
				'max'  => 39.2,
				'unit' => '°C',
			);
		}

		/**
		 * Filter the resolved reference range table.
		 *
		 * @param array $default Default table { metric_slug => { min, max, unit } }.
		 * @param array $context Member context (species, sex, age_years).
		 */
		$ranges = apply_filters( 'wp_mcp_ai_healthcare_reference_ranges', $default, $context );

		return is_array( $ranges ) ? $ranges : $default;
	}

	/**
	 * Determine whether a vitals value is outside its reference range.
	 *
	 * Returns one of: 'low', 'high', 'in_range', 'unknown'.
	 *
	 * @param string $metric  Reference-range metric slug (e.g. 'heart_rate').
	 * @param float  $value   Measured value.
	 * @param array  $context Member context for `reference_ranges()`.
	 * @return string Flag.
	 */
	public static function flag_value( $metric, $value, array $context = array() ) {
		$ranges = self::reference_ranges( $context );
		$metric = sanitize_key( $metric );
		if ( ! isset( $ranges[ $metric ] ) ) {
			return 'unknown';
		}
		$range = $ranges[ $metric ];
		$value = (float) $value;
		if ( isset( $range['min'] ) && $value < (float) $range['min'] ) {
			return 'low';
		}
		if ( isset( $range['max'] ) && $value > (float) $range['max'] ) {
			return 'high';
		}
		return 'in_range';
	}

	/*
	---------------------------------------------------------------------
	 * Member identity
	 * ------------------------------------------------------------------
	 */

	/**
	 * Resolve a canonical member identifier from a flexible reference.
	 *
	 * Accepts a member post ID, an `mcp_ai_member` slug, an MRN stored as
	 * post-meta `_member_mrn`, or a FHIR `Patient.identifier` value stored
	 * as post-meta `_fhir_patient_identifier`.
	 *
	 * @param mixed $reference Numeric ID, slug, or external identifier.
	 * @return int Canonical member post ID, or 0 when unresolved.
	 */
	public static function resolve_member_id( $reference ) {
		if ( is_int( $reference ) || ( is_string( $reference ) && ctype_digit( $reference ) ) ) {
			$post_id = absint( $reference );
			if ( $post_id > 0 ) {
				$post = get_post( $post_id );
				if ( $post && 'mcp_ai_member' === $post->post_type ) {
					return $post_id;
				}
			}
			return 0;
		}

		if ( ! is_string( $reference ) || '' === $reference ) {
			return 0;
		}

		// Try slug first.
		$by_slug = get_posts(
			array(
				'name'           => sanitize_title( $reference ),
				'post_type'      => 'mcp_ai_member',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( ! empty( $by_slug ) ) {
			return (int) $by_slug[0];
		}

		// Then try MRN / FHIR identifier meta.
		foreach ( array( '_member_mrn', '_fhir_patient_identifier' ) as $meta_key ) {
			$by_meta = get_posts(
				array(
					'post_type'      => 'mcp_ai_member',
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => sanitize_text_field( $reference ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( ! empty( $by_meta ) ) {
				return (int) $by_meta[0];
			}
		}

		return 0;
	}

	/*
	---------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------
	 */

	/**
	 * Default healthcare-toolkit settings.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'default_unit_system'      => 'metric',
			'default_member_type'      => 'person',
			'default_code_pack'        => 'icd10-cm-2025',
			'fhir_base_url'            => '',
			'audit_retention_days'     => 365,
			'require_baa_acknowledged' => false,
			'imaging'                  => array(
				'viewer_layout'     => 'default',
				'dicomweb_endpoint' => '',
			),
			'vitals'                   => array(
				'reference_ranges' => array(),
			),
		);
	}

	/**
	 * Resolved healthcare-toolkit settings.
	 *
	 * Merges saved option values over the defaults and runs the
	 * `wp_mcp_ai_healthcare_toolkit_settings` filter.
	 *
	 * @return array
	 */
	public static function get_toolkit_settings() {
		$saved    = get_option( self::SETTINGS_OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$defaults = self::get_default_settings();
		$resolved = array_replace_recursive( $defaults, $saved );

		/**
		 * Filter the resolved healthcare toolkit settings.
		 *
		 * @param array $resolved Resolved settings array.
		 * @param array $saved    Raw option value.
		 */
		$resolved = apply_filters( 'wp_mcp_ai_healthcare_toolkit_settings', $resolved, $saved );

		return is_array( $resolved ) ? $resolved : $defaults;
	}

	/**
	 * Whether a sub-toolkit is enabled in `wp_mcp_ai_settings`.
	 *
	 * @param string $sub Sub-toolkit slug ('vitals', 'health_wellness', 'imaging').
	 * @return bool
	 */
	public static function is_subtoolkit_enabled( $sub ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		switch ( $sub ) {
			case 'vitals':
				// Vitals defaults to the same value as Health & Wellness so.
				// existing installs auto-opt-in.
				if ( array_key_exists( 'enable_medical_vitals', $settings ) ) {
					return ! empty( $settings['enable_medical_vitals'] );
				}
				return ! empty( $settings['enable_health_wellness_management'] );
			case 'health_wellness':
				return ! empty( $settings['enable_health_wellness_management'] );
			case 'imaging':
				return ! empty( $settings['enable_healthcare_imaging'] );
			default:
				return false;
		}
	}

	/**
	 * Multisite PHI-acknowledged gate.
	 *
	 * On single-site installs returns true; on multisite returns the value of
	 * the `wp_mcp_ai_phi_acknowledged` flag in `wp_mcp_ai_settings`.
	 *
	 * @return bool
	 */
	public static function phi_acknowledged() {
		if ( ! is_multisite() ) {
			return true;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['wp_mcp_ai_phi_acknowledged'] );
	}
}
