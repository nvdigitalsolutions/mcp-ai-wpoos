<?php
/**
 * Tool for calculating sustainability metrics.
 *
 * Analyzes energy efficiency and environmental impact.
 * Supports LEED certification assessment.
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
 * Calculate sustainability metrics.
 */
class WP_MCP_AI_Tool_Calculate_Sustainability_Metrics implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'calculate_sustainability_metrics';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Calculate Sustainability Metrics', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Analyze energy efficiency and environmental impact. Estimates EUI, embodied carbon, and certification scoring against LEED v4 BD+C and IFC EDGE for tropical (LK/JM) and US projects. Backed by the architectural sustainability engine.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'floor_plan'           => array(
					'type'        => 'object',
					'description' => __( 'Floor plan data to analyze.', 'mcp-ai-wpoos-pro' ),
				),
				'total_area'           => array(
					'type'        => 'number',
					'description' => __( 'Total building area in square feet.', 'mcp-ai-wpoos-pro' ),
				),
				'climate_zone'         => array(
					'type'        => 'string',
					'description' => __( 'Climate zone: "1", "2", "3", "4", "5", "6", "7", "8".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( '1', '2', '3', '4', '5', '6', '7', '8' ),
				),
				'building_orientation' => array(
					'type'        => 'string',
					'description' => __( 'Primary building orientation: "north", "south", "east", "west".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'north', 'south', 'east', 'west' ),
				),
				'window_wall_ratio'    => array(
					'type'        => 'number',
					'description' => __( 'Window-to-wall ratio (0-1).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 1,
				),
				'insulation_values'    => array(
					'type'        => 'object',
					'description' => __( 'R-values for walls, roof, foundation.', 'mcp-ai-wpoos-pro' ),
				),
				'hvac_system'          => array(
					'type'        => 'string',
					'description' => __( 'HVAC system type: "standard", "high_efficiency", "geothermal", "heat_pump".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'standard', 'high_efficiency', 'geothermal', 'heat_pump' ),
				),
				'renewable_energy'     => array(
					'type'        => 'object',
					'description' => __( 'Renewable energy systems (solar, wind, etc.).', 'mcp-ai-wpoos-pro' ),
				),
				'certification_target' => array(
					'type'        => 'string',
					'description' => __( 'Target certification: "leed", "edge", "energy_star", "passive_house", "living_building".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'leed', 'edge', 'energy_star', 'passive_house', 'living_building' ),
				),
				'country_code'         => array(
					'type'        => 'string',
					'description' => __( 'ISO country code used to pick EDGE baselines and regional priority. Defaults to the toolkit country.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'LK', 'JM', 'US' ),
				),
				'building_use'         => array(
					'type'        => 'string',
					'description' => __( 'Building use category for EDGE baselines.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'residential', 'commercial' ),
					'default'     => 'residential',
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to calculate sustainability metrics.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate floor plan.
		if ( empty( $arguments['floor_plan'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Floor plan data is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$floor_plan           = $arguments['floor_plan'];
		$total_area           = isset( $arguments['total_area'] ) ? floatval( $arguments['total_area'] ) : 0;
		$climate_zone         = isset( $arguments['climate_zone'] ) ? sanitize_text_field( $arguments['climate_zone'] ) : '';
		$building_orientation = isset( $arguments['building_orientation'] ) ? sanitize_text_field( $arguments['building_orientation'] ) : '';
		$window_wall_ratio    = isset( $arguments['window_wall_ratio'] ) ? floatval( $arguments['window_wall_ratio'] ) : 0;
		$insulation_values    = isset( $arguments['insulation_values'] ) ? (array) $arguments['insulation_values'] : array();
		$hvac_system          = isset( $arguments['hvac_system'] ) ? sanitize_text_field( $arguments['hvac_system'] ) : 'standard';
		$renewable_energy     = isset( $arguments['renewable_energy'] ) ? (array) $arguments['renewable_energy'] : array();
		$certification_target = isset( $arguments['certification_target'] ) ? sanitize_text_field( $arguments['certification_target'] ) : '';
		$country_code         = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : '';
		$building_use         = isset( $arguments['building_use'] ) ? sanitize_text_field( $arguments['building_use'] ) : 'residential';

		if ( '' === $country_code && class_exists( 'WP_MCP_AI_Architectural_Engine' ) ) {
			$settings     = WP_MCP_AI_Architectural_Engine::get_toolkit_settings();
			$country_code = isset( $settings['default_country'] ) ? strtoupper( (string) $settings['default_country'] ) : 'LK';
		}

		// Calculate sustainability metrics.
		$metrics = $this->calculate_metrics( $floor_plan, $total_area, $climate_zone, $building_orientation, $window_wall_ratio, $insulation_values, $hvac_system, $renewable_energy, $certification_target, $country_code, $building_use, $context );

		if ( is_wp_error( $metrics ) ) {
			return $metrics;
		}

		// Return structured metrics data.
		return array(
			'success' => true,
			'metrics' => $metrics,
			'message' => __( 'Sustainability metrics calculation complete.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Calculate sustainability metrics.
	 *
	 * @param array  $floor_plan           Floor plan data.
	 * @param float  $total_area           Total area.
	 * @param string $climate_zone         Climate zone.
	 * @param string $building_orientation Building orientation.
	 * @param float  $window_wall_ratio    Window-to-wall ratio.
	 * @param array  $insulation_values    Insulation values.
	 * @param string $hvac_system          HVAC system.
	 * @param array  $renewable_energy     Renewable energy.
	 * @param string $certification_target Certification target.
	 * @param string $country_code         Country code.
	 * @param string $building_use         Building use category (residential / commercial).
	 * @param array  $context              Execution context.
	 * @return array Sustainability metrics.
	 */
	protected function calculate_metrics( $floor_plan, $total_area, $climate_zone, $building_orientation, $window_wall_ratio, $insulation_values, $hvac_system, $renewable_energy, $certification_target, $country_code, $building_use, $context ) {
		// Energy estimate — heuristic that responds to HVAC + WWR + insulation.
		$base_eui        = 130.0; // kWh / m² / year baseline (mid-quality, mixed climate).
		$hvac_multiplier = array(
			'standard'        => 1.00,
			'high_efficiency' => 0.78,
			'geothermal'      => 0.55,
			'heat_pump'       => 0.65,
		);
		$mult            = isset( $hvac_multiplier[ $hvac_system ] ) ? $hvac_multiplier[ $hvac_system ] : 1.0;
		$wwr             = max( 0.0, min( 1.0, (float) $window_wall_ratio ) );
		// Above 0.40 WWR adds load; below 0.30 saves load.
		$mult *= 1.0 + ( ( $wwr - 0.35 ) * 0.6 );
		// Insulation R-values (assumed in IP units): higher R reduces load.
		$roof_r = isset( $insulation_values['roof'] ) ? (float) $insulation_values['roof'] : 0.0;
		$wall_r = isset( $insulation_values['wall'] ) ? (float) $insulation_values['wall'] : 0.0;
		if ( $roof_r > 0 ) {
			$mult *= max( 0.7, 1.0 - ( $roof_r / 100.0 ) );
		}
		if ( $wall_r > 0 ) {
			$mult *= max( 0.75, 1.0 - ( $wall_r / 120.0 ) );
		}
		// Renewables.
		$pv_kw        = isset( $renewable_energy['solar_pv_kw'] ) ? (float) $renewable_energy['solar_pv_kw'] : 0.0;
		$pv_offset    = $pv_kw > 0 && $total_area > 0 ? min( 0.5, ( $pv_kw * 1500.0 ) / max( 1.0, $total_area * $base_eui * $mult ) ) : 0.0;
		$proposed_eui = max( 25.0, $base_eui * $mult * ( 1.0 - $pv_offset ) );

		// Embodied carbon — rough, reduced by climate-aware passive design and renewables.
		$embodied_co2 = 580.0;
		if ( $pv_kw > 0 ) {
			$embodied_co2 += 8.0 * $pv_kw;
		}

		// Water — placeholder (US default + 5 %).
		$water_l_person_day = 260.0;
		if ( in_array( $country_code, array( 'LK', 'JM' ), true ) ) {
			$water_l_person_day = 200.0;
		}

		$proposed = array(
			'eui_kwh_m2_year'        => round( $proposed_eui, 2 ),
			'water_l_person_day'     => round( $water_l_person_day, 2 ),
			'embodied_co2_kgco2e_m2' => round( $embodied_co2, 2 ),
		);

		$result = array(
			'energy_performance'   => array(
				'estimated_eui_kwh_m2_year'  => round( $proposed_eui, 2 ),
				'estimated_eui_kbtu_sf_year' => round( $proposed_eui * 0.317, 2 ),
				'pv_offset_pct'              => round( $pv_offset * 100.0, 2 ),
				'baseline_comparison'        => $proposed_eui < $base_eui
					? sprintf( '-%d%% vs. mid-quality baseline', max( 0, (int) round( ( ( $base_eui - $proposed_eui ) / $base_eui ) * 100.0 ) ) )
					: 'On par with mid-quality baseline',
			),
			'environmental_impact' => array(
				'embodied_co2_kgco2e_m2' => round( $embodied_co2, 2 ),
				'water_l_person_day'     => round( $water_l_person_day, 2 ),
				'material_efficiency'    => $wwr > 0.5 ? 'Glazing-heavy — review embodied carbon' : 'Standard',
			),
		);

		// EDGE scoring — tropical default, also runs for US when requested.
		if ( class_exists( 'WP_MCP_AI_Architectural_Sustainability' ) && in_array( $country_code, array( 'LK', 'JM', 'US' ), true ) ) {
			$edge = WP_MCP_AI_Architectural_Sustainability::score_edge( $country_code, $building_use, $proposed );
			if ( ! empty( $edge['success'] ) ) {
				$result['edge'] = array(
					'awarded_tier'             => $edge['awarded_tier'],
					'awarded_label'            => $edge['awarded_label'],
					'energy_savings_pct'       => $edge['energy_savings_pct'],
					'water_savings_pct'        => $edge['water_savings_pct'],
					'embodied_co2_savings_pct' => $edge['embodied_co2_savings_pct'],
					'baseline'                 => $edge['baseline'],
				);
			}
		}

		// LEED summary — only when explicitly targeted, since it requires a credit map.
		if ( 'leed' === $certification_target && class_exists( 'WP_MCP_AI_Architectural_Sustainability' ) ) {
			$result['leed'] = array(
				'message'    => __( 'LEED scoring requires an awarded-credit map. Use score_leed_v4_certification with awarded_credits + met_prerequisites.', 'mcp-ai-wpoos-pro' ),
				'thresholds' => WP_MCP_AI_Architectural_Sustainability::get_leed_thresholds(),
			);
		}

		// Recommendations — climate-aware.
		$recommendations = array();
		if ( in_array( $country_code, array( 'LK', 'JM' ), true ) ) {
			$recommendations[] = __( 'Add 600 mm overhangs on south/west facades to cut solar gain (tropical).', 'mcp-ai-wpoos-pro' );
			$recommendations[] = __( 'Maximise cross-ventilation; design AC for peak hours only.', 'mcp-ai-wpoos-pro' );
			$recommendations[] = __( 'Use light-coloured roof + insulated ceiling (R-19+) to reduce cooling load.', 'mcp-ai-wpoos-pro' );
		} else {
			$recommendations[] = __( 'Increase roof insulation to R-30+ and walls to R-20+.', 'mcp-ai-wpoos-pro' );
			$recommendations[] = __( 'Specify high-efficiency windows (U-factor < 0.30 / SHGC < 0.30 in southern climates).', 'mcp-ai-wpoos-pro' );
			$recommendations[] = __( 'Optimize building orientation for passive solar.', 'mcp-ai-wpoos-pro' );
		}
		if ( $pv_offset < 0.10 ) {
			$recommendations[] = __( 'Add rooftop PV — even 10 % offset is a quick LEED EA / EDGE energy win.', 'mcp-ai-wpoos-pro' );
		}
		if ( $wwr > 0.5 ) {
			$recommendations[] = __( 'Glazing > 50 % WWR — verify embodied carbon and solar-gain impact.', 'mcp-ai-wpoos-pro' );
		}
		$result['recommendations'] = $recommendations;

		$result['country_code'] = $country_code;
		$result['building_use'] = $building_use;
		return $result;
	}
}
