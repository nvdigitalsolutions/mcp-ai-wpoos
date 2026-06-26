<?php
/**
 * Tool for checking building code compliance.
 *
 * Validates designs against building codes and regulations.
 * Supports international building codes (IBC, IRC, etc.).
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
 * Check building code compliance.
 */
class WP_MCP_AI_Tool_Check_Building_Code_Compliance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'check_building_code_compliance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Building Code Compliance', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validate designs against building codes and regulations. Supports IBC, IRC, and local building codes.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Floor plan data to validate. Should include built-up area, footprint, lot area, occupancy and setbacks where available.', 'mcp-ai-wpoos-pro' ),
				),
				'country_code'     => array(
					'type'        => 'string',
					'description' => __( 'ISO 3166-1 alpha-2 country code (e.g. LK, JM, US). Drives default code-pack selection.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'LK', 'JM', 'US' ),
					'default'     => 'LK',
				),
				'code_packs'       => array(
					'type'        => 'array',
					'description' => __( 'Optional list of code-pack identifiers to evaluate against (e.g. "lk_uda_2021", "jm_jnbc_2018", "us_ibc_2024"). Defaults to the canonical pack for the country.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
					'default'     => array(),
				),
				'building_code'    => array(
					'type'        => 'string',
					'description' => __( 'Legacy code identifier ("ibc", "irc", "nfpa", "ada", "custom"). Used only if code_packs is empty.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'ibc', 'irc', 'nfpa', 'ada', 'custom' ),
					'default'     => 'ibc',
				),
				'jurisdiction'     => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction or location for local code requirements.', 'mcp-ai-wpoos-pro' ),
				),
				'building_type'    => array(
					'type'        => 'string',
					'description' => __( 'Building type: "residential", "commercial", "industrial", "mixed-use".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'residential', 'commercial', 'industrial', 'mixed-use' ),
					'default'     => 'residential',
				),
				'occupancy_type'   => array(
					'type'        => 'string',
					'description' => __( 'Occupancy classification (e.g., "R-2", "B", "A-2", "business", "residential").', 'mcp-ai-wpoos-pro' ),
				),
				'check_categories' => array(
					'type'        => 'array',
					'description' => __( 'Categories to check: "egress", "fire_safety", "accessibility", "structural", "energy", "zoning".', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'egress', 'fire_safety', 'accessibility', 'structural', 'energy', 'zoning' ),
					),
					'default'     => array( 'egress', 'fire_safety', 'accessibility' ),
				),
			),
			'required'             => array( 'floor_plan' ),
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to check code compliance.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate floor plan.
		if ( empty( $arguments['floor_plan'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Floor plan data is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$floor_plan       = $arguments['floor_plan'];
		$country_code     = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : '';
		$code_packs       = isset( $arguments['code_packs'] ) ? array_map( 'sanitize_text_field', (array) $arguments['code_packs'] ) : array();
		$building_code    = isset( $arguments['building_code'] ) ? sanitize_text_field( $arguments['building_code'] ) : 'ibc';
		$jurisdiction     = isset( $arguments['jurisdiction'] ) ? sanitize_text_field( $arguments['jurisdiction'] ) : '';
		$building_type    = isset( $arguments['building_type'] ) ? sanitize_text_field( $arguments['building_type'] ) : 'residential';
		$occupancy_type   = isset( $arguments['occupancy_type'] ) ? sanitize_text_field( $arguments['occupancy_type'] ) : '';
		$check_categories = isset( $arguments['check_categories'] ) ? array_map( 'sanitize_text_field', (array) $arguments['check_categories'] ) : array( 'egress', 'fire_safety', 'accessibility' );

		// Resolve code packs from country_code when none supplied.
		if ( empty( $country_code ) ) {
			if ( class_exists( 'WP_MCP_AI_Architectural_Engine' ) ) {
				$settings     = WP_MCP_AI_Architectural_Engine::get_toolkit_settings();
				$country_code = isset( $settings['default_country'] ) ? (string) $settings['default_country'] : 'LK';
			} else {
				$country_code = 'LK';
			}
		}

		if ( empty( $code_packs ) && class_exists( 'WP_MCP_AI_Architectural_Codes' ) ) {
			$default_pack = WP_MCP_AI_Architectural_Codes::get_default_pack_for_country( $country_code );
			if ( $default_pack ) {
				$code_packs = array( $default_pack );
			}
		}

		/**
		 * Fires before a building-code compliance check is evaluated.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $floor_plan       Floor plan input.
		 * @param string $country_code     ISO country code.
		 * @param array  $code_packs       Resolved code-pack IDs.
		 * @param array  $check_categories Categories being evaluated.
		 */
		do_action( 'wp_mcp_ai_arch_before_compliance_check', $floor_plan, $country_code, $code_packs, $check_categories );

		// Perform code compliance check.
		$compliance_results = $this->check_compliance( $floor_plan, $country_code, $code_packs, $building_code, $jurisdiction, $building_type, $occupancy_type, $check_categories, $context );

		if ( is_wp_error( $compliance_results ) ) {
			return $compliance_results;
		}

		$summary = $this->generate_compliance_summary( $compliance_results );

		/**
		 * Fires after a building-code compliance check has been evaluated.
		 *
		 * @since 1.2.0
		 *
		 * @param array $compliance_results Detailed result set.
		 * @param array $summary            Summary metrics.
		 * @param array $arguments          Original arguments.
		 */
		do_action( 'wp_mcp_ai_arch_after_compliance_check', $compliance_results, $summary, $arguments );

		// Return structured compliance results.
		return array(
			'success'    => true,
			'compliance' => $compliance_results,
			'summary'    => $summary,
			'disclaimer' => __( 'Analytical output only — engage a registered architect / chartered engineer for any submission.', 'mcp-ai-wpoos-pro' ),
			'message'    => __( 'Building code compliance check complete.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Check code compliance against the merged rules from the chosen code packs.
	 *
	 * Evaluates floor plan inputs against the egress / fire / accessibility /
	 * structural / energy / zoning rule categories defined in the regional
	 * code registry. Each rule maps to one or more `checks` entries with a
	 * deterministic pass/warning/fail status. Rules whose required input is
	 * absent are returned as `warning` so reviewers know the data is missing
	 * rather than defaulting to a misleading `pass`.
	 *
	 * @param array  $floor_plan       Floor plan data.
	 * @param string $country_code     ISO 3166-1 country code.
	 * @param array  $code_packs       Resolved code-pack IDs.
	 * @param string $building_code    Legacy building code identifier (fallback).
	 * @param string $jurisdiction     Jurisdiction string.
	 * @param string $building_type    Building type.
	 * @param string $occupancy_type   Occupancy type.
	 * @param array  $check_categories Active check categories.
	 * @param array  $context          Execution context.
	 * @return array Compliance results.
	 */
	protected function check_compliance( $floor_plan, $country_code, $code_packs, $building_code, $jurisdiction, $building_type, $occupancy_type, $check_categories, $context ) {
		$checks       = array();
		$packs        = array();
		$standards    = array();
		$merged_rules = array();

		if ( class_exists( 'WP_MCP_AI_Architectural_Codes' ) && ! empty( $code_packs ) ) {
			$merged_rules = WP_MCP_AI_Architectural_Codes::merge_rules( $code_packs );
			foreach ( $code_packs as $pack_id ) {
				$pack = WP_MCP_AI_Architectural_Codes::get_pack( $pack_id );
				if ( null === $pack ) {
					continue;
				}
				$packs[] = array(
					'id'        => $pack_id,
					'title'     => isset( $pack['title'] ) ? $pack['title'] : $pack_id,
					'authority' => isset( $pack['authority'] ) ? $pack['authority'] : '',
					'reference' => isset( $pack['reference'] ) ? $pack['reference'] : '',
				);
				if ( ! empty( $pack['reference'] ) ) {
					$standards[] = $pack['reference'];
				}
			}
		}

		// Egress checks.
		if ( in_array( 'egress', $check_categories, true ) && ! empty( $merged_rules['egress'] ) ) {
			$rules      = $merged_rules['egress'];
			$min_exits  = isset( $rules['min_exits'] ) ? (int) $rules['min_exits'] : 0;
			$plan_exits = isset( $floor_plan['exits'] ) ? (int) $floor_plan['exits'] : -1;
			if ( $min_exits > 0 ) {
				if ( $plan_exits < 0 ) {
					/* translators: %d: minimum number of egress exits required */
					$checks[] = $this->mk_check( 'egress', sprintf( __( 'Minimum %d means of egress required.', 'mcp-ai-wpoos-pro' ), $min_exits ), 'warning', __( 'Floor plan does not declare exit count.', 'mcp-ai-wpoos-pro' ) );
				} elseif ( $plan_exits >= $min_exits ) {
					/* translators: 1: minimum exits required, 2: actual exits provided */
					$checks[] = $this->mk_check( 'egress', sprintf( __( 'Minimum %d means of egress required.', 'mcp-ai-wpoos-pro' ), $min_exits ), 'pass', sprintf( __( 'Plan provides %d exits.', 'mcp-ai-wpoos-pro' ), $plan_exits ) );
				} else {
					/* translators: 1: minimum exits required, 2: actual exits provided */
					$checks[] = $this->mk_check( 'egress', sprintf( __( 'Minimum %d means of egress required.', 'mcp-ai-wpoos-pro' ), $min_exits ), 'fail', sprintf( __( 'Plan provides only %d exits.', 'mcp-ai-wpoos-pro' ), $plan_exits ) );
				}
			}

			$min_corridor  = isset( $rules['min_corridor_width_m'] ) ? (float) $rules['min_corridor_width_m'] : 0.0;
			$plan_corridor = isset( $floor_plan['corridor_width_m'] ) ? (float) $floor_plan['corridor_width_m'] : 0.0;
			if ( $min_corridor > 0 ) {
				if ( $plan_corridor <= 0 ) {
					/* translators: %.2f: minimum corridor width in meters */
					$checks[] = $this->mk_check( 'egress', sprintf( __( 'Minimum corridor width %.2f m required.', 'mcp-ai-wpoos-pro' ), $min_corridor ), 'warning', __( 'Corridor width not provided.', 'mcp-ai-wpoos-pro' ) );
				} elseif ( $plan_corridor + 1e-6 >= $min_corridor ) {
					/* translators: 1: required corridor width, 2: provided corridor width */
					$checks[] = $this->mk_check( 'egress', sprintf( __( 'Minimum corridor width %.2f m required.', 'mcp-ai-wpoos-pro' ), $min_corridor ), 'pass', sprintf( __( 'Provided: %.2f m.', 'mcp-ai-wpoos-pro' ), $plan_corridor ) );
				} else {
					/* translators: 1: required corridor width, 2: provided corridor width */
					$checks[] = $this->mk_check( 'egress', sprintf( __( 'Minimum corridor width %.2f m required.', 'mcp-ai-wpoos-pro' ), $min_corridor ), 'fail', sprintf( __( 'Provided: %.2f m.', 'mcp-ai-wpoos-pro' ), $plan_corridor ) );
				}
			}

			$min_stair  = isset( $rules['min_stair_width_m'] ) ? (float) $rules['min_stair_width_m'] : 0.0;
			$plan_stair = isset( $floor_plan['stair_width_m'] ) ? (float) $floor_plan['stair_width_m'] : 0.0;
			if ( $min_stair > 0 ) {
				if ( $plan_stair <= 0 ) {
					/* translators: %.2f: minimum stair width in meters */
					$checks[] = $this->mk_check( 'egress', sprintf( __( 'Minimum stair width %.2f m required.', 'mcp-ai-wpoos-pro' ), $min_stair ), 'warning', __( 'Stair width not provided.', 'mcp-ai-wpoos-pro' ) );
				} elseif ( $plan_stair + 1e-6 >= $min_stair ) {
					/* translators: 1: required stair width, 2: provided stair width */
					$checks[] = $this->mk_check( 'egress', sprintf( __( 'Minimum stair width %.2f m required.', 'mcp-ai-wpoos-pro' ), $min_stair ), 'pass', sprintf( __( 'Provided: %.2f m.', 'mcp-ai-wpoos-pro' ), $plan_stair ) );
				} else {
					/* translators: 1: required stair width, 2: provided stair width */
					$checks[] = $this->mk_check( 'egress', sprintf( __( 'Minimum stair width %.2f m required.', 'mcp-ai-wpoos-pro' ), $min_stair ), 'fail', sprintf( __( 'Provided: %.2f m.', 'mcp-ai-wpoos-pro' ), $plan_stair ) );
				}
			}

			$max_travel  = isset( $rules['max_travel_distance_m'] ) ? (float) $rules['max_travel_distance_m'] : 0.0;
			$plan_travel = isset( $floor_plan['travel_distance_m'] ) ? (float) $floor_plan['travel_distance_m'] : 0.0;
			if ( $max_travel > 0 ) {
				if ( $plan_travel <= 0 ) {
					/* translators: %.1f: maximum travel distance in meters */
					$checks[] = $this->mk_check( 'egress', sprintf( __( 'Maximum travel distance %.1f m.', 'mcp-ai-wpoos-pro' ), $max_travel ), 'warning', __( 'Travel distance not provided.', 'mcp-ai-wpoos-pro' ) );
				} elseif ( $plan_travel <= $max_travel + 1e-6 ) {
					/* translators: 1: maximum travel distance, 2: provided travel distance */
					$checks[] = $this->mk_check( 'egress', sprintf( __( 'Maximum travel distance %.1f m.', 'mcp-ai-wpoos-pro' ), $max_travel ), 'pass', sprintf( __( 'Provided: %.1f m.', 'mcp-ai-wpoos-pro' ), $plan_travel ) );
				} else {
					/* translators: 1: maximum travel distance, 2: provided travel distance */
					$checks[] = $this->mk_check( 'egress', sprintf( __( 'Maximum travel distance %.1f m.', 'mcp-ai-wpoos-pro' ), $max_travel ), 'fail', sprintf( __( 'Provided: %.1f m.', 'mcp-ai-wpoos-pro' ), $plan_travel ) );
				}
			}
		}

		// Accessibility checks.
		if ( in_array( 'accessibility', $check_categories, true ) && ! empty( $merged_rules['accessibility'] ) ) {
			$rules     = $merged_rules['accessibility'];
			$min_door  = isset( $rules['min_door_clear_width_mm'] ) ? (float) $rules['min_door_clear_width_mm'] : 0.0;
			$plan_door = isset( $floor_plan['min_door_clear_width_mm'] ) ? (float) $floor_plan['min_door_clear_width_mm'] : 0.0;
			if ( $min_door > 0 ) {
				if ( $plan_door <= 0 ) {
					/* translators: %.0f: minimum door clear width in mm */
					$checks[] = $this->mk_check( 'accessibility', sprintf( __( 'Minimum door clear width %.0f mm required.', 'mcp-ai-wpoos-pro' ), $min_door ), 'warning', __( 'Minimum door width not provided.', 'mcp-ai-wpoos-pro' ) );
				} elseif ( $plan_door + 1e-6 >= $min_door ) {
					/* translators: 1: required door width, 2: provided door width */
					$checks[] = $this->mk_check( 'accessibility', sprintf( __( 'Minimum door clear width %.0f mm required.', 'mcp-ai-wpoos-pro' ), $min_door ), 'pass', sprintf( __( 'Provided: %.0f mm.', 'mcp-ai-wpoos-pro' ), $plan_door ) );
				} else {
					/* translators: 1: required door width, 2: provided door width */
					$checks[] = $this->mk_check( 'accessibility', sprintf( __( 'Minimum door clear width %.0f mm required.', 'mcp-ai-wpoos-pro' ), $min_door ), 'fail', sprintf( __( 'Provided: %.0f mm.', 'mcp-ai-wpoos-pro' ), $plan_door ) );
				}
			}

			$max_slope  = isset( $rules['max_ramp_slope_ratio'] ) ? (float) $rules['max_ramp_slope_ratio'] : 0.0;
			$plan_slope = isset( $floor_plan['ramp_slope_ratio'] ) ? (float) $floor_plan['ramp_slope_ratio'] : 0.0;
			if ( $max_slope > 0 && $plan_slope > 0 ) {
				if ( $plan_slope <= $max_slope + 1e-9 ) {
					/* translators: 1: maximum ramp slope ratio, 2: provided ramp slope ratio */
					$checks[] = $this->mk_check( 'accessibility', sprintf( __( 'Maximum ramp slope 1:%.0f.', 'mcp-ai-wpoos-pro' ), 1.0 / $max_slope ), 'pass', sprintf( __( 'Provided: 1:%.1f.', 'mcp-ai-wpoos-pro' ), 1.0 / $plan_slope ) );
				} else {
					/* translators: 1: maximum ramp slope ratio, 2: provided ramp slope ratio */
					$checks[] = $this->mk_check( 'accessibility', sprintf( __( 'Maximum ramp slope 1:%.0f.', 'mcp-ai-wpoos-pro' ), 1.0 / $max_slope ), 'fail', sprintf( __( 'Provided: 1:%.1f.', 'mcp-ai-wpoos-pro' ), 1.0 / $plan_slope ) );
				}
			}
		}

		// Fire safety checks.
		if ( in_array( 'fire_safety', $check_categories, true ) && ! empty( $merged_rules['fire_safety'] ) ) {
			$rules        = $merged_rules['fire_safety'];
			$plan_height  = isset( $floor_plan['building_height_m'] ) ? (float) $floor_plan['building_height_m'] : 0.0;
			$sprinkler_at = isset( $rules['sprinkler_above_m'] ) ? (float) $rules['sprinkler_above_m'] : 0.0;
			if ( $sprinkler_at > 0 && $plan_height > 0 ) {
				if ( $plan_height >= $sprinkler_at ) {
					$has      = ! empty( $floor_plan['sprinklered'] );
					/* translators: %.1f: sprinkler trigger height in meters */
					$checks[] = $this->mk_check( 'fire_safety', sprintf( __( 'Sprinklers required for buildings ≥ %.1f m.', 'mcp-ai-wpoos-pro' ), $sprinkler_at ), $has ? 'pass' : 'fail', $has ? __( 'Plan declares sprinkler system.', 'mcp-ai-wpoos-pro' ) : __( 'Plan does not declare a sprinkler system.', 'mcp-ai-wpoos-pro' ) );
				} else {
					/* translators: %.1f: sprinkler trigger height in meters */
					$checks[] = $this->mk_check( 'fire_safety', sprintf( __( 'Sprinklers required for buildings ≥ %.1f m.', 'mcp-ai-wpoos-pro' ), $sprinkler_at ), 'pass', __( 'Below sprinkler-trigger height.', 'mcp-ai-wpoos-pro' ) );
				}
			}
			$min_rating = isset( $rules['min_wall_rating_min'] ) ? (int) $rules['min_wall_rating_min'] : 0;
			if ( $min_rating > 0 ) {
				/* translators: %d: minimum wall fire rating in minutes */
				$checks[] = $this->mk_check( 'fire_safety', sprintf( __( 'Minimum wall fire rating %d minutes.', 'mcp-ai-wpoos-pro' ), $min_rating ), 'warning', __( 'Verify wall ratings against jurisdictional schedule.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		// Zoning checks (FAR, coverage, setbacks).
		if ( in_array( 'zoning', $check_categories, true ) && ! empty( $merged_rules['zoning'] ) ) {
			$rules     = $merged_rules['zoning'];
			$lot_area  = isset( $floor_plan['lot_area_m2'] ) ? (float) $floor_plan['lot_area_m2'] : 0.0;
			$built_up  = isset( $floor_plan['built_up_area_m2'] ) ? (float) $floor_plan['built_up_area_m2'] : 0.0;
			$footprint = isset( $floor_plan['footprint_area_m2'] ) ? (float) $floor_plan['footprint_area_m2'] : 0.0;

			if ( $lot_area > 0 && $built_up > 0 && class_exists( 'WP_MCP_AI_Architectural_Engine' ) ) {
				$far_max = isset( $rules['far_max_residential'] ) ? (float) $rules['far_max_residential'] : 0.0;
				if ( 'commercial' === $building_type && isset( $rules['far_max_commercial'] ) ) {
					$far_max = (float) $rules['far_max_commercial'];
				} elseif ( 'mixed-use' === $building_type && isset( $rules['far_max_mixed_use'] ) ) {
					$far_max = (float) $rules['far_max_mixed_use'];
				}
				$far_actual = WP_MCP_AI_Architectural_Engine::calculate_far( $built_up, $lot_area );
				if ( $far_max > 0 ) {
					if ( $far_actual <= $far_max + 1e-6 ) {
						/* translators: 1: maximum FAR value, 2: calculated FAR value */
						$checks[] = $this->mk_check( 'zoning', sprintf( __( 'Maximum FAR %.2f.', 'mcp-ai-wpoos-pro' ), $far_max ), 'pass', sprintf( __( 'Calculated: %.2f.', 'mcp-ai-wpoos-pro' ), $far_actual ) );
					} else {
						/* translators: 1: maximum FAR value, 2: calculated FAR value */
						$checks[] = $this->mk_check( 'zoning', sprintf( __( 'Maximum FAR %.2f.', 'mcp-ai-wpoos-pro' ), $far_max ), 'fail', sprintf( __( 'Calculated: %.2f — reduce floor area or increase lot.', 'mcp-ai-wpoos-pro' ), $far_actual ) );
					}
				}
			}

			if ( $lot_area > 0 && $footprint > 0 && class_exists( 'WP_MCP_AI_Architectural_Engine' ) ) {
				$cov_max    = isset( $rules['site_coverage_max'] ) ? (float) $rules['site_coverage_max'] : 0.0;
				$cov_actual = WP_MCP_AI_Architectural_Engine::calculate_site_coverage( $footprint, $lot_area );
				if ( $cov_max > 0 ) {
					if ( $cov_actual <= $cov_max + 1e-6 ) {
						/* translators: 1: maximum site coverage, 2: calculated site coverage */
						$checks[] = $this->mk_check( 'zoning', sprintf( __( 'Maximum site coverage %.0f%%.', 'mcp-ai-wpoos-pro' ), $cov_max ), 'pass', sprintf( __( 'Calculated: %.1f%%.', 'mcp-ai-wpoos-pro' ), $cov_actual ) );
					} else {
						/* translators: 1: maximum site coverage, 2: calculated site coverage */
						$checks[] = $this->mk_check( 'zoning', sprintf( __( 'Maximum site coverage %.0f%%.', 'mcp-ai-wpoos-pro' ), $cov_max ), 'fail', sprintf( __( 'Calculated: %.1f%%.', 'mcp-ai-wpoos-pro' ), $cov_actual ) );
					}
				}
			}

			// Setbacks.
			if ( ! empty( $floor_plan['setbacks_m'] ) && is_array( $floor_plan['setbacks_m'] ) && class_exists( 'WP_MCP_AI_Architectural_Engine' ) ) {
				$req    = array(
					'front' => isset( $rules['min_setback_front_m'] ) ? (float) $rules['min_setback_front_m'] : 0.0,
					'rear'  => isset( $rules['min_setback_rear_m'] ) ? (float) $rules['min_setback_rear_m'] : 0.0,
					'left'  => isset( $rules['min_setback_side_m'] ) ? (float) $rules['min_setback_side_m'] : 0.0,
					'right' => isset( $rules['min_setback_side_m'] ) ? (float) $rules['min_setback_side_m'] : 0.0,
				);
				$result = WP_MCP_AI_Architectural_Engine::validate_setbacks( $floor_plan['setbacks_m'], $req );
				if ( $result['compliant'] ) {
					$checks[] = $this->mk_check( 'zoning', __( 'Setbacks meet minimum requirements.', 'mcp-ai-wpoos-pro' ), 'pass', '' );
				} else {
					foreach ( $result['violations'] as $v ) {
						$checks[] = $this->mk_check(
							'zoning',
							/* translators: %1$s: setback side (front/rear/left/right), %2$.2f: required setback in meters */
							sprintf( __( 'Minimum %1$s setback %2$.2f m.', 'mcp-ai-wpoos-pro' ), $v['side'], $v['required'] ),
							'fail',
							/* translators: %1$.2f: provided setback in meters, %2$.2f: shortfall in meters */
							sprintf( __( 'Provided: %1$.2f m (short by %2$.2f m).', 'mcp-ai-wpoos-pro' ), $v['proposed'], $v['shortfall'] )
						);
					}
				}
			}
		}

		// Structural checks (informational — engages engineer-of-record).
		if ( in_array( 'structural', $check_categories, true ) && ! empty( $merged_rules['structural'] ) ) {
			$rules = $merged_rules['structural'];
			if ( ! empty( $rules['wind_standard'] ) ) {
				/* translators: %s: wind design standard name */
				$checks[] = $this->mk_check( 'structural', sprintf( __( 'Wind design per %s.', 'mcp-ai-wpoos-pro' ), $rules['wind_standard'] ), 'warning', __( 'Engage structural engineer for wind-load verification.', 'mcp-ai-wpoos-pro' ) );
			}
			if ( ! empty( $rules['seismic_standard'] ) ) {
				/* translators: %s: seismic design standard name */
				$checks[] = $this->mk_check( 'structural', sprintf( __( 'Seismic design per %s.', 'mcp-ai-wpoos-pro' ), $rules['seismic_standard'] ), 'warning', __( 'Engage structural engineer for seismic-load verification.', 'mcp-ai-wpoos-pro' ) );
			}
			if ( ! empty( $rules['opening_protection_required'] ) ) {
				$has      = ! empty( $floor_plan['opening_protection'] );
				$checks[] = $this->mk_check( 'structural', __( 'Hurricane-resistant opening protection required.', 'mcp-ai-wpoos-pro' ), $has ? 'pass' : 'fail', $has ? __( 'Plan declares impact-rated openings or shutters.', 'mcp-ai-wpoos-pro' ) : __( 'Provide impact-rated glazing or hurricane shutters on all openings.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		// Energy checks.
		if ( in_array( 'energy', $check_categories, true ) && ! empty( $merged_rules['energy'] ) ) {
			$rules    = $merged_rules['energy'];
			$plan_ach = isset( $floor_plan['ach'] ) ? (float) $floor_plan['ach'] : 0.0;
			$min_ach  = isset( $rules['min_ach'] ) ? (float) $rules['min_ach'] : 0.0;
			if ( $min_ach > 0 ) {
				if ( $plan_ach <= 0 ) {
					/* translators: %.1f: minimum air changes per hour */
					$checks[] = $this->mk_check( 'energy', sprintf( __( 'Minimum %.1f air changes per hour.', 'mcp-ai-wpoos-pro' ), $min_ach ), 'warning', __( 'ACH not provided.', 'mcp-ai-wpoos-pro' ) );
				} elseif ( $plan_ach + 1e-6 >= $min_ach ) {
					/* translators: 1: minimum ACH, 2: provided ACH */
					$checks[] = $this->mk_check( 'energy', sprintf( __( 'Minimum %.1f air changes per hour.', 'mcp-ai-wpoos-pro' ), $min_ach ), 'pass', sprintf( __( 'Provided: %.1f ACH.', 'mcp-ai-wpoos-pro' ), $plan_ach ) );
				} else {
					/* translators: 1: minimum ACH, 2: provided ACH */
					$checks[] = $this->mk_check( 'energy', sprintf( __( 'Minimum %.1f air changes per hour.', 'mcp-ai-wpoos-pro' ), $min_ach ), 'fail', sprintf( __( 'Provided: %.1f ACH.', 'mcp-ai-wpoos-pro' ), $plan_ach ) );
				}
			}
		}

		// Compute overall status.
		$overall = 'pass';
		foreach ( $checks as $c ) {
			if ( 'fail' === $c['status'] ) {
				$overall = 'fail';
				break;
			}
			if ( 'warning' === $c['status'] && 'fail' !== $overall ) {
				$overall = 'conditional';
			}
		}

		return array(
			'country_code'   => $country_code,
			'code_packs'     => $packs,
			'standards'      => array_values( array_unique( $standards ) ),
			'jurisdiction'   => $jurisdiction,
			'building_type'  => $building_type,
			'occupancy_type' => $occupancy_type,
			'building_code'  => $building_code,
			'checks'         => $checks,
			'overall_status' => $overall,
		);
	}

	/**
	 * Build a check entry.
	 *
	 * @param string $category    Category.
	 * @param string $requirement Human description.
	 * @param string $status      Pass|warning|fail.
	 * @param string $details     Free-text details.
	 * @return array
	 */
	protected function mk_check( $category, $requirement, $status, $details ) {
		return array(
			'category'    => (string) $category,
			'requirement' => (string) $requirement,
			'status'      => in_array( $status, array( 'pass', 'fail', 'warning' ), true ) ? $status : 'warning',
			'details'     => (string) $details,
		);
	}

	/**
	 * Generate compliance summary.
	 *
	 * @param array $compliance_results Compliance results.
	 * @return array Summary data.
	 */
	protected function generate_compliance_summary( $compliance_results ) {
		$checks   = isset( $compliance_results['checks'] ) ? $compliance_results['checks'] : array();
		$total    = count( $checks );
		$passed   = count(
			array_filter(
				$checks,
				function ( $check ) {
					return 'pass' === $check['status'];
				}
			)
		);
		$failed   = count(
			array_filter(
				$checks,
				function ( $check ) {
					return 'fail' === $check['status'];
				}
			)
		);
		$warnings = count(
			array_filter(
				$checks,
				function ( $check ) {
					return 'warning' === $check['status'];
				}
			)
		);

		return array(
			'total_checks'    => $total,
			'passed'          => $passed,
			'failed'          => $failed,
			'warnings'        => $warnings,
			'compliance_rate' => $total > 0 ? round( ( $passed / $total ) * 100, 1 ) : 0,
		);
	}
}
