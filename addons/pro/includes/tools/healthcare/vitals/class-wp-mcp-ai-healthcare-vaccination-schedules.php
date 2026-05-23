<?php
/**
 * Vaccination schedule registry.
 *
 * Provides a small set of canonical schedule packs that match the public
 * recommendations of the U.S. CDC (paediatric & adult), the WHO Expanded
 * Programme on Immunization, and the AAFP/AAHA small-animal core/non-core
 * guidelines.  Only milestones / age windows are stored — this registry is
 * a convenience reference and is not a substitute for clinical judgement.
 *
 * Partners can register additional packs via
 * `wp_mcp_ai_healthcare_vaccination_schedules`.
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
 * Vaccination schedule registry (CDC / WHO / AAFP / AAHA small-animal).
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Healthcare_Vaccination_Schedules {

	/**
	 * Get all known schedule packs keyed by slug.
	 *
	 * @return array
	 */
	public static function all() {
		$packs = array(
			'cdc-pediatric-2025' => self::cdc_pediatric_2025(),
			'cdc-adult-2025'     => self::cdc_adult_2025(),
			'who-epi-routine'    => self::who_epi_routine(),
			'aafp-feline-core'   => self::aafp_feline_core(),
			'aaha-canine-core'   => self::aaha_canine_core(),
		);

		/**
		 * Filter the registered vaccination schedule packs.
		 *
		 * @param array $packs Schedule packs keyed by slug.
		 */
		$packs = apply_filters( 'wp_mcp_ai_healthcare_vaccination_schedules', $packs );

		return is_array( $packs ) ? $packs : array();
	}

	/**
	 * Get a single schedule pack by slug.
	 *
	 * @param string $slug Pack slug.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$slug  = sanitize_key( $slug );
		$packs = self::all();
		return isset( $packs[ $slug ] ) ? $packs[ $slug ] : null;
	}

	/**
	 * List packs available for a given species.
	 *
	 * @param string $species Species ('human', 'canine', 'feline').
	 * @return array Slug => pack pairs.
	 */
	public static function for_species( $species ) {
		$species = sanitize_key( $species );
		$out     = array();
		foreach ( self::all() as $slug => $pack ) {
			if ( isset( $pack['species'] ) && $pack['species'] === $species ) {
				$out[ $slug ] = $pack;
			}
		}
		return $out;
	}

	/**
	 * Determine which doses in a pack are due / overdue / upcoming for a
	 * subject of the given age, accounting for already-given vaccines.
	 *
	 * @param array $pack        Schedule pack.
	 * @param int   $age_days    Subject age in days.
	 * @param array $given_codes Optional list of vaccine codes already given (CVX or short slug, lowercase compared).
	 * @return array{due: array, overdue: array, upcoming: array, given: array}
	 */
	public static function evaluate( array $pack, $age_days, array $given_codes = array() ) {
		$age_days = max( 0, (int) $age_days );
		$given    = array_map( 'strtolower', array_map( 'strval', $given_codes ) );
		$result   = array(
			'due'      => array(),
			'overdue'  => array(),
			'upcoming' => array(),
			'given'    => array(),
		);

		if ( empty( $pack['doses'] ) || ! is_array( $pack['doses'] ) ) {
			return $result;
		}

		foreach ( $pack['doses'] as $dose ) {
			$key = strtolower( (string) ( isset( $dose['cvx_code'] ) ? $dose['cvx_code'] : $dose['vaccine'] ) );
			if ( in_array( $key, $given, true ) ) {
				$result['given'][] = $dose;
				continue;
			}

			$min = isset( $dose['min_age_days'] ) ? (int) $dose['min_age_days'] : null;
			$max = isset( $dose['max_age_days'] ) ? (int) $dose['max_age_days'] : null;

			if ( null !== $min && $age_days < $min ) {
				$result['upcoming'][] = $dose;
				continue;
			}
			if ( null !== $max && $age_days > $max ) {
				$result['overdue'][] = $dose;
				continue;
			}
			$result['due'][] = $dose;
		}

		return $result;
	}

	/*
	---------------------------------------------------------------------
	 * Built-in packs (abridged — milestones only).
	 * ------------------------------------------------------------------
	 */

	/**
	 * CDC paediatric immunisation schedule (abridged, 0–18 years).
	 *
	 * @return array
	 */
	private static function cdc_pediatric_2025() {
		return array(
			'name'     => __( 'CDC Pediatric Immunization Schedule', 'mcp-ai-wpoos-pro' ),
			'source'   => 'CDC',
			'species'  => 'human',
			'audience' => 'pediatric',
			'doses'    => array(
				array(
					'vaccine'      => 'HepB',
					'cvx_code'     => '08',
					'dose'         => 1,
					'min_age_days' => 0,
					'max_age_days' => 30,
				),
				array(
					'vaccine'      => 'HepB',
					'cvx_code'     => '08',
					'dose'         => 2,
					'min_age_days' => 30,
					'max_age_days' => 90,
				),
				array(
					'vaccine'      => 'DTaP',
					'cvx_code'     => '20',
					'dose'         => 1,
					'min_age_days' => 60,
					'max_age_days' => 120,
				),
				array(
					'vaccine'      => 'IPV',
					'cvx_code'     => '10',
					'dose'         => 1,
					'min_age_days' => 60,
					'max_age_days' => 120,
				),
				array(
					'vaccine'      => 'Hib',
					'cvx_code'     => '17',
					'dose'         => 1,
					'min_age_days' => 60,
					'max_age_days' => 120,
				),
				array(
					'vaccine'      => 'PCV13',
					'cvx_code'     => '133',
					'dose'         => 1,
					'min_age_days' => 60,
					'max_age_days' => 120,
				),
				array(
					'vaccine'      => 'MMR',
					'cvx_code'     => '03',
					'dose'         => 1,
					'min_age_days' => 365,
					'max_age_days' => 547,
				),
				array(
					'vaccine'      => 'Varicella',
					'cvx_code'     => '21',
					'dose'         => 1,
					'min_age_days' => 365,
					'max_age_days' => 547,
				),
				array(
					'vaccine'      => 'HPV',
					'cvx_code'     => '62',
					'dose'         => 1,
					'min_age_days' => 4015,
					'max_age_days' => 4747,
				),
				array(
					'vaccine'      => 'MenACWY',
					'cvx_code'     => '136',
					'dose'         => 1,
					'min_age_days' => 4015,
					'max_age_days' => 4747,
				),
			),
		);
	}

	/**
	 * CDC adult immunisation schedule (abridged).
	 *
	 * @return array
	 */
	private static function cdc_adult_2025() {
		return array(
			'name'     => __( 'CDC Adult Immunization Schedule', 'mcp-ai-wpoos-pro' ),
			'source'   => 'CDC',
			'species'  => 'human',
			'audience' => 'adult',
			'doses'    => array(
				array(
					'vaccine'      => 'Influenza (annual)',
					'cvx_code'     => '141',
					'dose'         => 1,
					'min_age_days' => 6570,
					'max_age_days' => null,
				),
				array(
					'vaccine'      => 'Tdap booster (every 10 yr)',
					'cvx_code'     => '115',
					'dose'         => 1,
					'min_age_days' => 6570,
					'max_age_days' => null,
				),
				array(
					'vaccine'      => 'Zoster (Shingrix)',
					'cvx_code'     => '187',
					'dose'         => 1,
					'min_age_days' => 18250,
					'max_age_days' => null,
				),
				array(
					'vaccine'      => 'Pneumococcal (PCV20)',
					'cvx_code'     => '215',
					'dose'         => 1,
					'min_age_days' => 23725,
					'max_age_days' => null,
				),
			),
		);
	}

	/**
	 * WHO Expanded Programme on Immunization core schedule (abridged).
	 *
	 * @return array
	 */
	private static function who_epi_routine() {
		return array(
			'name'     => __( 'WHO Expanded Programme on Immunization (Routine)', 'mcp-ai-wpoos-pro' ),
			'source'   => 'WHO',
			'species'  => 'human',
			'audience' => 'pediatric',
			'doses'    => array(
				array(
					'vaccine'      => 'BCG',
					'cvx_code'     => '19',
					'dose'         => 1,
					'min_age_days' => 0,
					'max_age_days' => 30,
				),
				array(
					'vaccine'      => 'OPV',
					'cvx_code'     => '02',
					'dose'         => 1,
					'min_age_days' => 0,
					'max_age_days' => 30,
				),
				array(
					'vaccine'      => 'DTP-HepB-Hib (Penta)',
					'cvx_code'     => '120',
					'dose'         => 1,
					'min_age_days' => 42,
					'max_age_days' => 120,
				),
				array(
					'vaccine'      => 'Measles',
					'cvx_code'     => '05',
					'dose'         => 1,
					'min_age_days' => 270,
					'max_age_days' => 365,
				),
			),
		);
	}

	/**
	 * AAFP/AAHA feline core vaccine schedule (abridged).
	 *
	 * @return array
	 */
	private static function aafp_feline_core() {
		return array(
			'name'     => __( 'AAFP Feline Core Vaccines', 'mcp-ai-wpoos-pro' ),
			'source'   => 'AAFP',
			'species'  => 'feline',
			'audience' => 'companion-animal',
			'doses'    => array(
				array(
					'vaccine'      => 'FVRCP (kitten series)',
					'dose'         => 1,
					'min_age_days' => 42,
					'max_age_days' => 84,
				),
				array(
					'vaccine'      => 'FVRCP (kitten series)',
					'dose'         => 2,
					'min_age_days' => 84,
					'max_age_days' => 112,
				),
				array(
					'vaccine'      => 'FVRCP (kitten series)',
					'dose'         => 3,
					'min_age_days' => 112,
					'max_age_days' => 140,
				),
				array(
					'vaccine'      => 'Rabies (1-yr)',
					'dose'         => 1,
					'min_age_days' => 84,
					'max_age_days' => 180,
				),
				array(
					'vaccine'      => 'FVRCP booster',
					'dose'         => 1,
					'min_age_days' => 365,
					'max_age_days' => 547,
				),
			),
		);
	}

	/**
	 * AAHA canine core vaccine schedule (abridged, small-animal).
	 *
	 * @return array
	 */
	private static function aaha_canine_core() {
		return array(
			'name'     => __( 'AAHA Canine Core Vaccines', 'mcp-ai-wpoos-pro' ),
			'source'   => 'AAHA',
			'species'  => 'canine',
			'audience' => 'companion-animal',
			'doses'    => array(
				array(
					'vaccine'      => 'DAPP (puppy series)',
					'dose'         => 1,
					'min_age_days' => 42,
					'max_age_days' => 70,
				),
				array(
					'vaccine'      => 'DAPP (puppy series)',
					'dose'         => 2,
					'min_age_days' => 70,
					'max_age_days' => 98,
				),
				array(
					'vaccine'      => 'DAPP (puppy series)',
					'dose'         => 3,
					'min_age_days' => 98,
					'max_age_days' => 126,
				),
				array(
					'vaccine'      => 'Rabies (1-yr)',
					'dose'         => 1,
					'min_age_days' => 84,
					'max_age_days' => 180,
				),
				array(
					'vaccine'      => 'DAPP booster',
					'dose'         => 1,
					'min_age_days' => 365,
					'max_age_days' => 547,
				),
			),
		);
	}
}
