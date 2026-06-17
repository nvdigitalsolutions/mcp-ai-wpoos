<?php
/**
 * Tool: compute_bmi_and_growth_percentile
 *
 * Computes BMI from weight + height, derives the WHO/CDC adult BMI category
 * via the shared engine, and for paediatric inputs returns a coarse growth
 * band (underweight / healthy / overweight / obesity) using BMI-for-age
 * cut-offs.  Full LMS-based percentile curves are intentionally out of scope
 * for this phase — partners that need exact percentiles can replace the
 * banding logic via `wp_mcp_ai_healthcare_growth_percentile`.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compute BMI and growth percentile tool.
 */
class WP_MCP_AI_Tool_Compute_BMI_And_Growth_Percentile implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( class_exists( 'WP_MCP_AI_Healthcare_Engine' ) ) {
			return WP_MCP_AI_Healthcare_Engine::is_subtoolkit_enabled( 'vitals' );
		}
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'compute_bmi_and_growth_percentile';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Compute BMI & Growth Percentile', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Compute BMI from weight and height (any common unit), classify into adult or paediatric categories, and return a coarse growth band for ages 2-20.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'weight'      => array(
					'type'        => 'number',
					'description' => __( 'Body weight.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0.5,
				),
				'weight_unit' => array(
					'type'        => 'string',
					'description' => __( 'Weight unit ("kg" or "lbs").', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'kg', 'lbs' ),
					'default'     => 'kg',
				),
				'height'      => array(
					'type'        => 'number',
					'description' => __( 'Height value.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'height_unit' => array(
					'type'        => 'string',
					'description' => __( 'Height unit ("cm" or "in").', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'cm', 'in' ),
					'default'     => 'cm',
				),
				'age_years'   => array(
					'type'        => 'number',
					'description' => __( 'Age in years (used to choose adult vs paediatric banding).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 150,
				),
				'sex'         => array(
					'type'        => 'string',
					'description' => __( 'Biological sex ("male", "female", "unknown").', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'male', 'female', 'unknown' ),
					'default'     => 'unknown',
				),
			),
			'required'   => array( 'weight', 'height' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'local-only', 'cacheable', 'idempotent' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_Healthcare_Engine' ) ) {
			return new WP_Error( 'wp_mcp_ai_unavailable', __( 'Healthcare engine not loaded.', 'mcp-ai-wpoos-pro' ) );
		}

		$weight = isset( $arguments['weight'] ) ? floatval( $arguments['weight'] ) : 0.0;
		$height = isset( $arguments['height'] ) ? floatval( $arguments['height'] ) : 0.0;
		if ( $weight <= 0 || $height <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_invalid_input', __( 'A positive weight and height are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$weight_unit = isset( $arguments['weight_unit'] ) ? strtolower( sanitize_text_field( $arguments['weight_unit'] ) ) : 'kg';
		$height_unit = isset( $arguments['height_unit'] ) ? strtolower( sanitize_text_field( $arguments['height_unit'] ) ) : 'cm';
		$age_years   = isset( $arguments['age_years'] ) ? floatval( $arguments['age_years'] ) : null;
		$sex         = isset( $arguments['sex'] ) ? sanitize_key( $arguments['sex'] ) : 'unknown';

		// Normalise to kg / cm.
		$weight_kg = ( 'lbs' === $weight_unit )
			? $weight / WP_MCP_AI_Healthcare_Engine::LB_PER_KG
			: $weight;
		$height_cm = ( 'in' === $height_unit )
			? $height / WP_MCP_AI_Healthcare_Engine::IN_PER_CM
			: $height;

		$bmi        = WP_MCP_AI_Healthcare_Engine::bmi( $weight_kg, $height_cm );
		$adult_band = WP_MCP_AI_Healthcare_Engine::bmi_category( $bmi );
		$pediatric  = null;
		$band       = $adult_band;

		if ( null !== $age_years && $age_years >= 2 && $age_years < 20 ) {
			$pediatric = self::pediatric_band( $bmi, $age_years, $sex );
			$band      = $pediatric['band'];
		}

		/**
		 * Filter to override the resolved growth band.
		 *
		 * @param string     $band       Resolved band slug.
		 * @param float      $bmi        Computed BMI.
		 * @param float|null $age_years  Age in years.
		 * @param string     $sex        Sex.
		 */
		$band = apply_filters( 'wp_mcp_ai_healthcare_growth_percentile', $band, $bmi, $age_years, $sex );

		return array(
			'success'    => true,
			'bmi'        => round( $bmi, 2 ),
			'weight_kg'  => round( $weight_kg, 2 ),
			'height_cm'  => round( $height_cm, 2 ),
			'adult_band' => $adult_band,
			'pediatric'  => $pediatric,
			'band'       => $band,
		);
	}

	/**
	 * Coarse paediatric BMI banding (CDC-aligned cut-offs).
	 *
	 * Returns the band along with approximate BMI thresholds used.  This is a
	 * simplification; partners needing per-month LMS curves can override via
	 * the `wp_mcp_ai_healthcare_growth_percentile` filter.
	 *
	 * @param float  $bmi        Computed BMI.
	 * @param float  $age_years  Age in years (assumed >=2 and <20).
	 * @param string $sex        Biological sex.
	 * @return array{band:string, thresholds:array}
	 */
	private static function pediatric_band( $bmi, $age_years, $sex ) {
		// Boys and girls use slightly different reference curves; we approximate.
		// with single age-banded thresholds derived from the CDC/WHO charts.
		$age_int = (int) floor( $age_years );
		// thresholds[ age ] = { underweight_max, healthy_max, overweight_max }.
		$table = array(
			2  => array( 14.0, 18.0, 19.0 ),
			3  => array( 13.8, 17.5, 18.6 ),
			4  => array( 13.6, 17.0, 18.2 ),
			5  => array( 13.5, 16.8, 18.0 ),
			6  => array( 13.5, 17.0, 18.5 ),
			7  => array( 13.6, 17.5, 19.5 ),
			8  => array( 13.8, 18.0, 20.5 ),
			9  => array( 14.0, 18.5, 21.5 ),
			10 => array( 14.2, 19.5, 22.5 ),
			11 => array( 14.4, 20.5, 23.5 ),
			12 => array( 14.7, 21.5, 24.5 ),
			13 => array( 15.0, 22.5, 25.5 ),
			14 => array( 15.5, 23.5, 26.0 ),
			15 => array( 16.0, 24.0, 26.5 ),
			16 => array( 16.5, 24.5, 27.0 ),
			17 => array( 17.0, 25.0, 27.5 ),
			18 => array( 17.5, 25.0, 28.0 ),
			19 => array( 18.0, 25.0, 28.5 ),
		);

		$cuts = isset( $table[ $age_int ] ) ? $table[ $age_int ] : array( 17.5, 25.0, 28.5 );
		$band = 'healthy';
		if ( $bmi < $cuts[0] ) {
			$band = 'underweight';
		} elseif ( $bmi >= $cuts[2] ) {
			$band = 'obesity';
		} elseif ( $bmi >= $cuts[1] ) {
			$band = 'overweight';
		}

		return array(
			'band'       => $band,
			'thresholds' => array(
				'underweight_max' => $cuts[0],
				'healthy_max'     => $cuts[1],
				'overweight_max'  => $cuts[2],
			),
			'sex'        => $sex,
			'age_years'  => $age_years,
		);
	}
}
