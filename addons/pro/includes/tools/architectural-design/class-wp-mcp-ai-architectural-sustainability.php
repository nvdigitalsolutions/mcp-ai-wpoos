<?php
/**
 * Architectural Sustainability & Costing Backend
 *
 * Phase C extension to the Architectural Design toolkit. Provides static helpers
 * for:
 *
 * - **EDGE** (Excellence in Design for Greater Efficiencies, IFC) — energy,
 *   water and embodied-carbon savings vs. a regional baseline. EDGE certifies a
 *   project at three tiers: ≥ 20 % savings (EDGE Certified), ≥ 40 % energy
 *   savings + 20 % water + 20 % embodied carbon (EDGE Advanced) and net-zero
 *   carbon (EDGE Zero Carbon).
 * - **LEED v4 / v4.1 BD+C** — full credit catalogue across LT, SS, WE, EA, MR,
 *   EQ, IN and RP categories with prerequisites and certification thresholds
 *   (Certified / Silver / Gold / Platinum).
 * - **BoQ format catalogues** — POMI (Sri Lanka, ICTAD/CIDA), SMM7 / NRM2
 *   (Caribbean / UK), CSI MasterFormat 2020 (United States).
 * - **Value engineering option library** — common cost-saving substitutions
 *   with indicative savings, schedule impact, design impact, and lifecycle
 *   notes.
 *
 * The numbers here are deliberately structured but indicative; the toolkit is
 * advisory and any final scoring or BoQ should be verified by an accredited
 * professional (LEED AP, EDGE Auditor, chartered quantity surveyor, etc.).
 *
 * PHP 7.4 compatible — no enums, readonly, or named arguments.
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
 * Sustainability & Costing engine for the Architectural Design toolkit.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Architectural_Sustainability {

	/**
	 * EDGE certification tiers.
	 *
	 * Sources: IFC EDGE technical methodology (2023), edgebuildings.com.
	 *
	 * @return array
	 */
	public static function get_edge_tiers() {
		return array(
			'edge_certified' => array(
				'label'                  => 'EDGE Certified',
				'min_energy_savings_pct' => 20.0,
				'min_water_savings_pct'  => 20.0,
				'min_embodied_co2_pct'   => 20.0,
				'logic'                  => 'all',
			),
			'edge_advanced'  => array(
				'label'                  => 'EDGE Advanced',
				'min_energy_savings_pct' => 40.0,
				'min_water_savings_pct'  => 20.0,
				'min_embodied_co2_pct'   => 20.0,
				'logic'                  => 'all',
			),
			'edge_zero'      => array(
				'label'                  => 'EDGE Zero Carbon',
				'min_energy_savings_pct' => 100.0,
				'min_water_savings_pct'  => 20.0,
				'min_embodied_co2_pct'   => 20.0,
				'logic'                  => 'all',
				'requires_offsets'       => true,
			),
		);
	}

	/**
	 * Per-country EDGE baseline annual EUI / water use intensity.
	 *
	 * Indicative baselines used to compute % savings when the caller passes the
	 * proposed values only. Sources: EDGE country baseline tables (residential
	 * + commercial, indicative averages, 2025 update).
	 *
	 * @return array
	 */
	public static function get_edge_baselines() {
		$baselines = array(
			'LK' => array(
				'residential' => array(
					'eui_kwh_m2_year'        => 60.0,
					'water_l_person_day'     => 200.0,
					'embodied_co2_kgco2e_m2' => 500.0,
				),
				'commercial'  => array(
					'eui_kwh_m2_year'        => 180.0,
					'water_l_person_day'     => 80.0,
					'embodied_co2_kgco2e_m2' => 650.0,
				),
			),
			'JM' => array(
				'residential' => array(
					'eui_kwh_m2_year'        => 90.0,
					'water_l_person_day'     => 220.0,
					'embodied_co2_kgco2e_m2' => 520.0,
				),
				'commercial'  => array(
					'eui_kwh_m2_year'        => 220.0,
					'water_l_person_day'     => 100.0,
					'embodied_co2_kgco2e_m2' => 680.0,
				),
			),
			'US' => array(
				'residential' => array(
					'eui_kwh_m2_year'        => 130.0,
					'water_l_person_day'     => 260.0,
					'embodied_co2_kgco2e_m2' => 580.0,
				),
				'commercial'  => array(
					'eui_kwh_m2_year'        => 260.0,
					'water_l_person_day'     => 110.0,
					'embodied_co2_kgco2e_m2' => 720.0,
				),
			),
		);

		/**
		 * Filters EDGE baselines per country and use type.
		 *
		 * @since 1.4.0
		 *
		 * @param array $baselines Baseline tables.
		 */
		return apply_filters( 'wp_mcp_ai_arch_edge_baselines', $baselines );
	}

	/**
	 * Score an EDGE certification candidate.
	 *
	 * Inputs may either supply absolute proposed performance values
	 * (`eui_kwh_m2_year`, `water_l_person_day`, `embodied_co2_kgco2e_m2`) or
	 * direct savings percentages (`energy_savings_pct`, `water_savings_pct`,
	 * `embodied_co2_savings_pct`). Direct percentages take precedence.
	 *
	 * @since 1.4.0
	 *
	 * @param string $country_code   ISO country code (LK / JM / US).
	 * @param string $building_use   `residential` or `commercial`.
	 * @param array  $proposed       Proposed performance values or savings percentages.
	 * @return array Score result.
	 */
	public static function score_edge( $country_code, $building_use, array $proposed ) {
		$country_code = strtoupper( (string) $country_code );
		$building_use = in_array( strtolower( (string) $building_use ), array( 'residential', 'commercial' ), true )
			? strtolower( (string) $building_use )
			: 'residential';

		$baselines = self::get_edge_baselines();
		$baseline  = isset( $baselines[ $country_code ][ $building_use ] )
			? $baselines[ $country_code ][ $building_use ]
			: null;

		if ( null === $baseline ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'No EDGE baseline registered for country=%s use=%s.', $country_code, $building_use ),
			);
		}

		$energy_pct = isset( $proposed['energy_savings_pct'] )
			? max( 0.0, min( 100.0, (float) $proposed['energy_savings_pct'] ) )
			: self::pct_savings(
				isset( $proposed['eui_kwh_m2_year'] ) ? (float) $proposed['eui_kwh_m2_year'] : 0.0,
				(float) $baseline['eui_kwh_m2_year']
			);

		$water_pct = isset( $proposed['water_savings_pct'] )
			? max( 0.0, min( 100.0, (float) $proposed['water_savings_pct'] ) )
			: self::pct_savings(
				isset( $proposed['water_l_person_day'] ) ? (float) $proposed['water_l_person_day'] : 0.0,
				(float) $baseline['water_l_person_day']
			);

		$embodied_pct = isset( $proposed['embodied_co2_savings_pct'] )
			? max( 0.0, min( 100.0, (float) $proposed['embodied_co2_savings_pct'] ) )
			: self::pct_savings(
				isset( $proposed['embodied_co2_kgco2e_m2'] ) ? (float) $proposed['embodied_co2_kgco2e_m2'] : 0.0,
				(float) $baseline['embodied_co2_kgco2e_m2']
			);

		$awarded = '';
		$tiers   = self::get_edge_tiers();
		// Tiers ordered from highest to lowest so the strictest passing tier wins.
		foreach ( array( 'edge_zero', 'edge_advanced', 'edge_certified' ) as $tier_id ) {
			$tier = $tiers[ $tier_id ];
			if (
				$energy_pct >= $tier['min_energy_savings_pct']
				&& $water_pct >= $tier['min_water_savings_pct']
				&& $embodied_pct >= $tier['min_embodied_co2_pct']
			) {
				$awarded = $tier_id;
				break;
			}
		}

		return array(
			'success'                  => true,
			'country_code'             => $country_code,
			'building_use'             => $building_use,
			'baseline'                 => $baseline,
			'energy_savings_pct'       => round( $energy_pct, 2 ),
			'water_savings_pct'        => round( $water_pct, 2 ),
			'embodied_co2_savings_pct' => round( $embodied_pct, 2 ),
			'awarded_tier'             => $awarded,
			'awarded_label'            => '' !== $awarded ? $tiers[ $awarded ]['label'] : 'Not certifying',
			'tiers'                    => $tiers,
		);
	}

	/**
	 * LEED v4 BD+C credit catalogue (New Construction).
	 *
	 * Each credit lists max points and prerequisite flag. This is a structural
	 * reference table; tools score the project by accepting `awarded_credits`
	 * and prerequisites and computing the certification level.
	 *
	 * Sources: USGBC LEED v4 BD+C: New Construction rating system (2019
	 * v4.1 update).
	 *
	 * @return array
	 */
	public static function get_leed_v4_bdc_catalog() {
		$catalog = array(
			'LT' => array(
				'label'   => 'Location & Transportation',
				'credits' => array(
					'LT_c1' => array(
						'label' => 'LEED for Neighborhood Development Location',
						'max'   => 16,
					),
					'LT_c2' => array(
						'label' => 'Sensitive Land Protection',
						'max'   => 1,
					),
					'LT_c3' => array(
						'label' => 'High Priority Site',
						'max'   => 2,
					),
					'LT_c4' => array(
						'label' => 'Surrounding Density and Diverse Uses',
						'max'   => 5,
					),
					'LT_c5' => array(
						'label' => 'Access to Quality Transit',
						'max'   => 5,
					),
					'LT_c6' => array(
						'label' => 'Bicycle Facilities',
						'max'   => 1,
					),
					'LT_c7' => array(
						'label' => 'Reduced Parking Footprint',
						'max'   => 1,
					),
					'LT_c8' => array(
						'label' => 'Green Vehicles',
						'max'   => 1,
					),
				),
			),
			'SS' => array(
				'label'         => 'Sustainable Sites',
				'prerequisites' => array(
					'SS_p1' => 'Construction Activity Pollution Prevention',
				),
				'credits'       => array(
					'SS_c1' => array(
						'label' => 'Site Assessment',
						'max'   => 1,
					),
					'SS_c2' => array(
						'label' => 'Site Development - Protect/Restore Habitat',
						'max'   => 2,
					),
					'SS_c3' => array(
						'label' => 'Open Space',
						'max'   => 1,
					),
					'SS_c4' => array(
						'label' => 'Rainwater Management',
						'max'   => 3,
					),
					'SS_c5' => array(
						'label' => 'Heat Island Reduction',
						'max'   => 2,
					),
					'SS_c6' => array(
						'label' => 'Light Pollution Reduction',
						'max'   => 1,
					),
				),
			),
			'WE' => array(
				'label'         => 'Water Efficiency',
				'prerequisites' => array(
					'WE_p1' => 'Outdoor Water Use Reduction',
					'WE_p2' => 'Indoor Water Use Reduction',
					'WE_p3' => 'Building-Level Water Metering',
				),
				'credits'       => array(
					'WE_c1' => array(
						'label' => 'Outdoor Water Use Reduction',
						'max'   => 2,
					),
					'WE_c2' => array(
						'label' => 'Indoor Water Use Reduction',
						'max'   => 6,
					),
					'WE_c3' => array(
						'label' => 'Cooling Tower Water Use',
						'max'   => 2,
					),
					'WE_c4' => array(
						'label' => 'Water Metering',
						'max'   => 1,
					),
				),
			),
			'EA' => array(
				'label'         => 'Energy & Atmosphere',
				'prerequisites' => array(
					'EA_p1' => 'Fundamental Commissioning and Verification',
					'EA_p2' => 'Minimum Energy Performance',
					'EA_p3' => 'Building-Level Energy Metering',
					'EA_p4' => 'Fundamental Refrigerant Management',
				),
				'credits'       => array(
					'EA_c1' => array(
						'label' => 'Enhanced Commissioning',
						'max'   => 6,
					),
					'EA_c2' => array(
						'label' => 'Optimize Energy Performance',
						'max'   => 18,
					),
					'EA_c3' => array(
						'label' => 'Advanced Energy Metering',
						'max'   => 1,
					),
					'EA_c4' => array(
						'label' => 'Grid Harmonization',
						'max'   => 2,
					),
					'EA_c5' => array(
						'label' => 'Renewable Energy',
						'max'   => 5,
					),
					'EA_c6' => array(
						'label' => 'Enhanced Refrigerant Management',
						'max'   => 1,
					),
				),
			),
			'MR' => array(
				'label'         => 'Materials & Resources',
				'prerequisites' => array(
					'MR_p1' => 'Storage and Collection of Recyclables',
					'MR_p2' => 'Construction and Demolition Waste Management Planning',
				),
				'credits'       => array(
					'MR_c1' => array(
						'label' => 'Building Life-Cycle Impact Reduction',
						'max'   => 5,
					),
					'MR_c2' => array(
						'label' => 'EPDs (Building Product Disclosure - EPDs)',
						'max'   => 2,
					),
					'MR_c3' => array(
						'label' => 'Sourcing of Raw Materials',
						'max'   => 2,
					),
					'MR_c4' => array(
						'label' => 'Material Ingredients',
						'max'   => 2,
					),
					'MR_c5' => array(
						'label' => 'Construction and Demolition Waste Management',
						'max'   => 2,
					),
				),
			),
			'EQ' => array(
				'label'         => 'Indoor Environmental Quality',
				'prerequisites' => array(
					'EQ_p1' => 'Minimum Indoor Air Quality Performance',
					'EQ_p2' => 'Environmental Tobacco Smoke Control',
				),
				'credits'       => array(
					'EQ_c1' => array(
						'label' => 'Enhanced Indoor Air Quality Strategies',
						'max'   => 2,
					),
					'EQ_c2' => array(
						'label' => 'Low-Emitting Materials',
						'max'   => 3,
					),
					'EQ_c3' => array(
						'label' => 'Construction Indoor Air Quality Plan',
						'max'   => 1,
					),
					'EQ_c4' => array(
						'label' => 'Indoor Air Quality Assessment',
						'max'   => 2,
					),
					'EQ_c5' => array(
						'label' => 'Thermal Comfort',
						'max'   => 1,
					),
					'EQ_c6' => array(
						'label' => 'Interior Lighting',
						'max'   => 2,
					),
					'EQ_c7' => array(
						'label' => 'Daylight',
						'max'   => 3,
					),
					'EQ_c8' => array(
						'label' => 'Quality Views',
						'max'   => 1,
					),
					'EQ_c9' => array(
						'label' => 'Acoustic Performance',
						'max'   => 1,
					),
				),
			),
			'IN' => array(
				'label'   => 'Innovation',
				'credits' => array(
					'IN_c1' => array(
						'label' => 'Innovation',
						'max'   => 5,
					),
					'IN_c2' => array(
						'label' => 'LEED Accredited Professional',
						'max'   => 1,
					),
				),
			),
			'RP' => array(
				'label'   => 'Regional Priority',
				'credits' => array(
					'RP_c1' => array(
						'label' => 'Regional Priority Credits',
						'max'   => 4,
					),
				),
			),
		);

		/**
		 * Filters the LEED v4 BD+C catalog.
		 *
		 * @since 1.4.0
		 *
		 * @param array $catalog Credit catalog.
		 */
		return apply_filters( 'wp_mcp_ai_arch_leed_v4_bdc_catalog', $catalog );
	}

	/**
	 * LEED certification thresholds.
	 *
	 * @return array
	 */
	public static function get_leed_thresholds() {
		return array(
			'certified' => array(
				'label' => 'Certified',
				'min'   => 40,
				'max'   => 49,
			),
			'silver'    => array(
				'label' => 'Silver',
				'min'   => 50,
				'max'   => 59,
			),
			'gold'      => array(
				'label' => 'Gold',
				'min'   => 60,
				'max'   => 79,
			),
			'platinum'  => array(
				'label' => 'Platinum',
				'min'   => 80,
				'max'   => 110,
			),
		);
	}

	/**
	 * Score a LEED v4 BD+C submission.
	 *
	 * @since 1.4.0
	 *
	 * @param array $awarded_credits  Credit-id => awarded points map.
	 * @param array $met_prerequisites Prerequisite-id => bool map.
	 * @return array Score result.
	 */
	public static function score_leed_v4_bdc( array $awarded_credits, array $met_prerequisites = array() ) {
		$catalog    = self::get_leed_v4_bdc_catalog();
		$thresholds = self::get_leed_thresholds();

		$total_points    = 0;
		$category_totals = array();
		$missing_prereqs = array();
		$invalid_credits = array();
		$over_max        = array();

		// Validate prerequisites.
		foreach ( $catalog as $cat_id => $category ) {
			if ( empty( $category['prerequisites'] ) ) {
				continue;
			}
			foreach ( $category['prerequisites'] as $prereq_id => $prereq_label ) {
				$is_met = isset( $met_prerequisites[ $prereq_id ] )
					? (bool) $met_prerequisites[ $prereq_id ]
					: false;
				if ( ! $is_met ) {
					$missing_prereqs[] = array(
						'id'       => $prereq_id,
						'category' => $cat_id,
						'label'    => $prereq_label,
					);
				}
			}
		}

		// Tally credits.
		foreach ( $catalog as $cat_id => $category ) {
			$category_totals[ $cat_id ] = 0;
			$credits                    = isset( $category['credits'] ) ? $category['credits'] : array();
			foreach ( $credits as $credit_id => $credit ) {
				if ( ! isset( $awarded_credits[ $credit_id ] ) ) {
					continue;
				}
				$awarded = max( 0, (int) $awarded_credits[ $credit_id ] );
				$max     = (int) $credit['max'];
				if ( $awarded > $max ) {
					$over_max[] = array(
						'id'      => $credit_id,
						'awarded' => $awarded,
						'max'     => $max,
					);
					$awarded    = $max;
				}
				$category_totals[ $cat_id ] += $awarded;
				$total_points               += $awarded;
			}
		}

		// Detect credit IDs that aren't in the catalog.
		$known = array();
		foreach ( $catalog as $category ) {
			foreach ( (array) ( isset( $category['credits'] ) ? $category['credits'] : array() ) as $credit_id => $credit ) {
				$known[] = $credit_id;
			}
		}
		foreach ( array_keys( $awarded_credits ) as $credit_id ) {
			if ( ! in_array( $credit_id, $known, true ) ) {
				$invalid_credits[] = $credit_id;
			}
		}

		// Resolve certification level (only if prerequisites met).
		$awarded_level       = '';
		$awarded_level_label = 'Not certifying';
		if ( empty( $missing_prereqs ) ) {
			foreach ( array( 'platinum', 'gold', 'silver', 'certified' ) as $tier_id ) {
				if ( $total_points >= $thresholds[ $tier_id ]['min'] ) {
					$awarded_level       = $tier_id;
					$awarded_level_label = $thresholds[ $tier_id ]['label'];
					break;
				}
			}
		}

		return array(
			'success'               => true,
			'total_points'          => $total_points,
			'max_points'            => 110,
			'category_totals'       => $category_totals,
			'awarded_level'         => $awarded_level,
			'awarded_level_label'   => $awarded_level_label,
			'missing_prerequisites' => $missing_prereqs,
			'over_max_credits'      => $over_max,
			'invalid_credit_ids'    => $invalid_credits,
			'thresholds'            => $thresholds,
		);
	}

	/**
	 * BoQ format catalogues.
	 *
	 * Each format lists primary classification groups. Tools use these as
	 * deterministic skeletons when assembling a Bill of Quantities.
	 *
	 * Sources:
	 * - POMI — Principles of Measurement (International) for Works of
	 *   Construction, RICS / ICTAD-CIDA usage in Sri Lanka.
	 * - SMM7 — Standard Method of Measurement (RICS, 7th ed.), still common
	 *   in Caribbean / Commonwealth practice; NRM2 is the modern successor.
	 * - CSI MasterFormat 2020 — Construction Specifications Institute
	 *   divisions used in the United States and Canada.
	 *
	 * @return array
	 */
	public static function get_boq_format_catalog() {
		$catalog = array(
			'pomi'                  => array(
				'label'           => 'POMI (Principles of Measurement International)',
				'standard_source' => 'RICS / ICTAD-CIDA Sri Lanka practice',
				'unit_system'     => 'metric',
				'sections'        => array(
					'A' => 'Preliminaries & General',
					'B' => 'Demolition & Site Preparation',
					'C' => 'Excavation & Earthworks',
					'D' => 'Concrete Works',
					'E' => 'Masonry & Brickwork',
					'F' => 'Roof Covering & Rainwater Goods',
					'G' => 'Carpentry & Joinery',
					'H' => 'Doors, Windows & Glazing',
					'J' => 'Plastering, Tiling & Internal Finishes',
					'K' => 'Painting & Decoration',
					'L' => 'Plumbing & Sanitary Installation',
					'M' => 'Electrical Installation',
					'N' => 'External Works & Drainage',
					'P' => 'Provisional & Prime Cost Sums',
				),
			),
			'smm7'                  => array(
				'label'           => 'SMM7 / NRM2 (Standard Method of Measurement)',
				'standard_source' => 'RICS — common in Caribbean and Commonwealth jurisdictions',
				'unit_system'     => 'metric',
				'sections'        => array(
					'A' => 'Preliminaries / General Conditions',
					'C' => 'Demolition / Alterations',
					'D' => 'Groundwork',
					'E' => 'In-situ Concrete / Large Precast Concrete',
					'F' => 'Masonry',
					'G' => 'Structural / Carcassing Metal / Timber',
					'H' => 'Cladding / Covering',
					'J' => 'Waterproofing',
					'K' => 'Linings / Sheathing / Dry Partitioning',
					'L' => 'Windows / Doors / Stairs',
					'M' => 'Surface Finishes',
					'N' => 'Furniture / Equipment',
					'P' => 'Building Fabric Sundries',
					'Q' => 'Paving / Planting / Fencing / Site Furniture',
					'R' => 'Disposal Systems',
					'S' => 'Piped Supply Systems',
					'T' => 'Mechanical Heating / Cooling / Refrigeration',
					'V' => 'Electrical Supply / Power / Lighting',
					'W' => 'Communications / Security / Control',
				),
			),
			'csi_masterformat_2020' => array(
				'label'           => 'CSI MasterFormat 2020',
				'standard_source' => 'Construction Specifications Institute (US / Canada)',
				'unit_system'     => 'imperial',
				'sections'        => array(
					'00' => 'Procurement and Contracting Requirements',
					'01' => 'General Requirements',
					'02' => 'Existing Conditions',
					'03' => 'Concrete',
					'04' => 'Masonry',
					'05' => 'Metals',
					'06' => 'Wood, Plastics, and Composites',
					'07' => 'Thermal and Moisture Protection',
					'08' => 'Openings',
					'09' => 'Finishes',
					'10' => 'Specialties',
					'11' => 'Equipment',
					'12' => 'Furnishings',
					'13' => 'Special Construction',
					'14' => 'Conveying Equipment',
					'21' => 'Fire Suppression',
					'22' => 'Plumbing',
					'23' => 'HVAC',
					'25' => 'Integrated Automation',
					'26' => 'Electrical',
					'27' => 'Communications',
					'28' => 'Electronic Safety and Security',
					'31' => 'Earthwork',
					'32' => 'Exterior Improvements',
					'33' => 'Utilities',
				),
			),
		);

		/**
		 * Filters the BoQ format catalog.
		 *
		 * @since 1.4.0
		 *
		 * @param array $catalog BoQ format catalog.
		 */
		return apply_filters( 'wp_mcp_ai_arch_boq_format_catalog', $catalog );
	}

	/**
	 * Map a country code to its preferred BoQ format.
	 *
	 * @param string $country_code Country code.
	 * @return string BoQ format key.
	 */
	public static function preferred_boq_format( $country_code ) {
		$country_code = strtoupper( (string) $country_code );
		switch ( $country_code ) {
			case 'LK':
				return 'pomi';
			case 'JM':
				return 'smm7';
			case 'US':
				return 'csi_masterformat_2020';
			default:
				return 'pomi';
		}
	}

	/**
	 * Common value-engineering options.
	 *
	 * Each option carries indicative impact ranges; the caller can override per
	 * project. Sources: RICS NRM2 cost-management practice, AGC / AIA VE
	 * guidance, regional QS handbooks.
	 *
	 * @return array
	 */
	public static function get_value_engineering_library() {
		$library = array(
			array(
				'id'                  => 've_substitute_marble_with_porcelain',
				'category'            => 'finishes',
				'label'               => 'Substitute imported marble flooring with porcelain tile',
				'savings_pct_range'   => array( 4.0, 7.0 ),
				'schedule_days_delta' => 0,
				'design_impact'       => 'low',
				'lifecycle_note'      => 'Comparable life cycle; reduces embodied energy and import duty.',
				'applies_to'          => array( 'LK', 'JM', 'US' ),
			),
			array(
				'id'                  => 've_use_local_red_brick',
				'category'            => 'structure',
				'label'               => 'Substitute imported facing brick with local red brick + plaster',
				'savings_pct_range'   => array( 3.0, 6.0 ),
				'schedule_days_delta' => 0,
				'design_impact'       => 'low',
				'lifecycle_note'      => 'Reduces transport carbon; consistent with vernacular tropical detailing.',
				'applies_to'          => array( 'LK', 'JM' ),
			),
			array(
				'id'                  => 've_pv_roof_phase',
				'category'            => 'mep',
				'label'               => 'Phase rooftop PV — install conduit/structure now, panels in year 2',
				'savings_pct_range'   => array( 1.5, 3.5 ),
				'schedule_days_delta' => -3,
				'design_impact'       => 'low',
				'lifecycle_note'      => 'Defers capex without losing roof structural readiness.',
				'applies_to'          => array( 'LK', 'JM', 'US' ),
			),
			array(
				'id'                  => 've_simplify_curtain_wall',
				'category'            => 'envelope',
				'label'               => 'Replace unitised curtain wall with stick-system + thermally broken aluminium',
				'savings_pct_range'   => array( 2.5, 5.5 ),
				'schedule_days_delta' => 0,
				'design_impact'       => 'medium',
				'lifecycle_note'      => 'Marginal energy delta — verify against ASHRAE 90.1 / IECC envelope.',
				'applies_to'          => array( 'US', 'JM' ),
			),
			array(
				'id'                  => 've_reduce_pile_depth',
				'category'            => 'foundation',
				'label'               => 'Reduce pile depth using site-specific geotech testing',
				'savings_pct_range'   => array( 1.5, 4.5 ),
				'schedule_days_delta' => 5,
				'design_impact'       => 'low',
				'lifecycle_note'      => 'Requires PE / chartered structural engineer sign-off; do not apply without geotech.',
				'applies_to'          => array( 'LK', 'JM', 'US' ),
			),
			array(
				'id'                  => 've_lighting_led_retrofit',
				'category'            => 'mep',
				'label'               => 'Use LED-only fixture spec with daylight + occupancy sensors',
				'savings_pct_range'   => array( 0.8, 2.0 ),
				'schedule_days_delta' => 0,
				'design_impact'       => 'low',
				'lifecycle_note'      => 'Improves EUI by 5-15 % and supports LEED EA-c2 / EDGE energy savings.',
				'applies_to'          => array( 'LK', 'JM', 'US' ),
			),
			array(
				'id'                  => 've_passive_ventilation_first',
				'category'            => 'mep',
				'label'               => 'Add cross-ventilation + ceiling fans before AC sizing — design AC for peaks only',
				'savings_pct_range'   => array( 1.5, 4.0 ),
				'schedule_days_delta' => 0,
				'design_impact'       => 'medium',
				'lifecycle_note'      => 'Cuts annual cooling load 15-30 % in tropical climates; aligns with EDGE.',
				'applies_to'          => array( 'LK', 'JM' ),
			),
			array(
				'id'                  => 've_bulk_steel_procurement',
				'category'            => 'structure',
				'label'               => 'Bulk procurement of reinforcement steel via single supplier',
				'savings_pct_range'   => array( 1.0, 2.5 ),
				'schedule_days_delta' => -5,
				'design_impact'       => 'none',
				'lifecycle_note'      => 'Procurement-only change; verify CIDA/ASTM grade compliance.',
				'applies_to'          => array( 'LK', 'JM', 'US' ),
			),
		);

		/**
		 * Filters the value-engineering library.
		 *
		 * @since 1.4.0
		 *
		 * @param array $library VE library.
		 */
		return apply_filters( 'wp_mcp_ai_arch_ve_library', $library );
	}

	/**
	 * Helper — % savings of proposed vs baseline (clamped to [0, 100]).
	 *
	 * Returns 0 when baseline is non-positive or when proposed >= baseline.
	 *
	 * @param float $proposed Proposed value.
	 * @param float $baseline Baseline value.
	 * @return float Percentage savings (0-100).
	 */
	private static function pct_savings( $proposed, $baseline ) {
		$proposed = (float) $proposed;
		$baseline = (float) $baseline;
		if ( $baseline <= 0.0 || $proposed <= 0.0 ) {
			return 0.0;
		}
		if ( $proposed >= $baseline ) {
			return 0.0;
		}
		$pct = ( ( $baseline - $proposed ) / $baseline ) * 100.0;
		return max( 0.0, min( 100.0, $pct ) );
	}
}
