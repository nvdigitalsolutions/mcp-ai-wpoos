<?php
/**
 * Tool for analyzing natural ventilation performance.
 *
 * Uses ASHRAE 62.1-2022 + SLS 947 + JS 35 to assess whether a space meets
 * fresh-air and air-change-per-hour targets via cross-flow, stack, or
 * courtyard ventilation strategies.
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
 * Analyze natural ventilation.
 */
class WP_MCP_AI_Tool_Analyze_Natural_Ventilation implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'analyze_natural_ventilation';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Natural Ventilation', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Assess natural-ventilation performance using ASHRAE 62.1-2022 (per-occupant + per-area), SLS 947:2009 (Sri Lanka) and JS 35 (Jamaica). Estimates ACH from cross-flow, stack effect or wind-driven openings against the regional minimum.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country_code' => array(
					'type'        => 'string',
					'description' => __( 'ISO country code (LK, JM, US).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'LK', 'JM', 'US' ),
					'default'     => 'LK',
				),
				'space'        => array(
					'type'        => 'object',
					'description' => __( 'Space description.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'occupants'  => array( 'type' => 'integer' ),
						'area_sqm'   => array( 'type' => 'number' ),
						'height_m'   => array( 'type' => 'number' ),
						'space_type' => array(
							'type'    => 'string',
							'enum'    => array( 'residential', 'office', 'classroom', 'kitchen', 'bathroom', 'retail' ),
							'default' => 'residential',
						),
					),
				),
				'openings'     => array(
					'type'        => 'object',
					'description' => __( 'Opening areas in m² and the strategy.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'inlet_area_sqm'  => array( 'type' => 'number' ),
						'outlet_area_sqm' => array( 'type' => 'number' ),
						'stack_height_m'  => array( 'type' => 'number' ),
						'strategy'        => array(
							'type'    => 'string',
							'enum'    => array( 'cross_flow', 'stack', 'wind_driven', 'courtyard', 'mechanical_assist' ),
							'default' => 'cross_flow',
						),
					),
				),
				'wind'         => array(
					'type'        => 'object',
					'description' => __( 'Local wind context.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'mean_speed_ms'             => array( 'type' => 'number' ),
						'pressure_coefficient_diff' => array( 'type' => 'number' ),
					),
				),
				'temperatures' => array(
					'type'        => 'object',
					'description' => __( 'Indoor / outdoor temperatures (°C) for stack-effect calc.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'indoor_c'  => array( 'type' => 'number' ),
						'outdoor_c' => array( 'type' => 'number' ),
					),
				),
			),
			'required'             => array( 'space' ),
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
				__( 'You do not have permission to analyze ventilation.', 'mcp-ai-wpoos-pro' )
			);
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Engine' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural engine is unavailable.', 'mcp-ai-wpoos-pro' ) );
		}

		$country_code = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : 'LK';
		$space        = isset( $arguments['space'] ) ? (array) $arguments['space'] : array();
		$openings     = isset( $arguments['openings'] ) ? (array) $arguments['openings'] : array();
		$wind         = isset( $arguments['wind'] ) ? (array) $arguments['wind'] : array();
		$temps        = isset( $arguments['temperatures'] ) ? (array) $arguments['temperatures'] : array();

		$occupants = isset( $space['occupants'] ) ? max( 0, absint( $space['occupants'] ) ) : 0;
		$area      = isset( $space['area_sqm'] ) ? max( 0.0, floatval( $space['area_sqm'] ) ) : 0.0;
		$height    = isset( $space['height_m'] ) ? floatval( $space['height_m'] ) : 2.7;
		$type      = isset( $space['space_type'] ) ? sanitize_text_field( $space['space_type'] ) : 'residential';

		if ( $area <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'space.area_sqm must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		// Per-country / per-type minimum ACH.
		$min_ach_table = array(
			'LK' => array(
				'residential' => 6.0,
				'office'      => 6.0,
				'classroom'   => 6.0,
				'kitchen'     => 15.0,
				'bathroom'    => 8.0,
				'retail'      => 6.0,
			),
			'JM' => array(
				'residential' => 6.0,
				'office'      => 6.0,
				'classroom'   => 6.0,
				'kitchen'     => 15.0,
				'bathroom'    => 8.0,
				'retail'      => 6.0,
			),
			'US' => array(
				'residential' => 0.35,
				'office'      => 4.0,
				'classroom'   => 4.0,
				'kitchen'     => 7.0,
				'bathroom'    => 5.0,
				'retail'      => 4.0,
			),
		);
		$min_ach       = isset( $min_ach_table[ $country_code ][ $type ] ) ? (float) $min_ach_table[ $country_code ][ $type ] : 6.0;

		// ASHRAE-based requirement.
		$ashrae = WP_MCP_AI_Architectural_Engine::calculate_ventilation_airflow( $occupants, $area, $height, $min_ach );

		// Volume in m³.
		$volume = $area * max( 0.1, $height );

		// Strategy dispatch.
		$strategy = isset( $openings['strategy'] ) ? sanitize_text_field( $openings['strategy'] ) : 'cross_flow';
		$inlet    = isset( $openings['inlet_area_sqm'] ) ? max( 0.0, floatval( $openings['inlet_area_sqm'] ) ) : 0.0;
		$outlet   = isset( $openings['outlet_area_sqm'] ) ? max( 0.0, floatval( $openings['outlet_area_sqm'] ) ) : 0.0;
		$stackh   = isset( $openings['stack_height_m'] ) ? max( 0.0, floatval( $openings['stack_height_m'] ) ) : 0.0;

		$wind_speed = isset( $wind['mean_speed_ms'] ) ? max( 0.0, floatval( $wind['mean_speed_ms'] ) ) : 0.0;
		$delta_cp   = isset( $wind['pressure_coefficient_diff'] ) ? max( 0.0, floatval( $wind['pressure_coefficient_diff'] ) ) : 0.5;

		$indoor  = isset( $temps['indoor_c'] ) ? floatval( $temps['indoor_c'] ) : 28.0;
		$outdoor = isset( $temps['outdoor_c'] ) ? floatval( $temps['outdoor_c'] ) : 30.0;

		// Effective area for cross flow Aeff = sqrt( 1 / ( 1/Ai^2 + 1/Ao^2 ) ).
		$a_eff = 0.0;
		if ( $inlet > 0 && $outlet > 0 ) {
			$a_eff = 1.0 / sqrt( ( 1.0 / ( $inlet * $inlet ) ) + ( 1.0 / ( $outlet * $outlet ) ) );
		}

		$cd      = 0.6; // discharge coefficient.
		$q_wind  = 0.0;
		$q_stack = 0.0;

		if ( in_array( $strategy, array( 'cross_flow', 'wind_driven', 'courtyard' ), true ) && $a_eff > 0 ) {
			$q_wind = $cd * $a_eff * $wind_speed * sqrt( $delta_cp ); // m³/s.
		}
		if ( ( 'stack' === $strategy || 'cross_flow' === $strategy ) && $a_eff > 0 && $stackh > 0 ) {
			// Tk = average absolute K. Q = Cd*A*sqrt( 2 g h |Ti-To| / Ti ).
			$ti      = $indoor + 273.15;
			$dt      = abs( $indoor - $outdoor );
			$q_stack = $cd * $a_eff * sqrt( ( 2.0 * 9.81 * $stackh * $dt ) / max( 1.0, $ti ) );
		}
		// Total Q (m³/s) — combine in quadrature when both apply.
		$q_total = sqrt( ( $q_wind * $q_wind ) + ( $q_stack * $q_stack ) );
		// 'mechanical_assist' adds a flat 30% boost as a placeholder.
		if ( 'mechanical_assist' === $strategy ) {
			$q_total *= 1.3;
		}

		// Convert to ACH.
		$ach = ( $volume > 0 ) ? ( ( $q_total * 3600.0 ) / $volume ) : 0.0;
		$lps = $q_total * 1000.0;

		$meets_min_ach = ( $ach + 1e-6 >= $min_ach );
		$meets_ashrae  = ( $lps + 1e-6 >= (float) $ashrae['ashrae_lps'] );

		return array(
			'success'                    => true,
			'country_code'               => $country_code,
			'space_type'                 => $type,
			'volume_m3'                  => round( $volume, 2 ),
			'min_required_ach'           => round( $min_ach, 2 ),
			'ashrae_lps'                 => round( (float) $ashrae['ashrae_lps'], 2 ),
			'design_strategy'            => $strategy,
			'effective_opening_area_sqm' => round( $a_eff, 3 ),
			'wind_driven_q_m3s'          => round( $q_wind, 4 ),
			'stack_driven_q_m3s'         => round( $q_stack, 4 ),
			'total_q_m3s'                => round( $q_total, 4 ),
			'total_lps'                  => round( $lps, 2 ),
			'achieved_ach'               => round( $ach, 2 ),
			'meets_min_ach'              => $meets_min_ach,
			'meets_ashrae_62_1'          => $meets_ashrae,
			'overall_status'             => ( $meets_min_ach && $meets_ashrae ) ? 'pass' : ( $meets_min_ach || $meets_ashrae ? 'conditional' : 'fail' ),
			'recommendations'            => $this->recommendations( $country_code, $strategy, $meets_min_ach, $meets_ashrae ),
			'disclaimer'                 => __( 'Analytical / advisory output. Confirm with CFD or measurement for critical spaces.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Build recommendations.
	 *
	 * @param string $country_code Country code.
	 * @param string $strategy     Strategy.
	 * @param bool   $meets_ach    Whether ACH target met.
	 * @param bool   $meets_ashrae Whether ASHRAE target met.
	 * @return array<int,string>
	 */
	protected function recommendations( $country_code, $strategy, $meets_ach, $meets_ashrae ) {
		$out = array();
		if ( ! $meets_ach ) {
			$out[] = __( 'Increase opening areas or add a stack/wind-tower to raise the achieved ACH.', 'mcp-ai-wpoos-pro' );
		}
		if ( ! $meets_ashrae ) {
			$out[] = __( 'Add mechanical ventilation to meet ASHRAE 62.1 outdoor-air rates when occupants are dense.', 'mcp-ai-wpoos-pro' );
		}
		if ( 'LK' === $country_code ) {
			$out[] = __( 'Sri Lanka: align principal openings with the SW monsoon (May-Sep) and NE monsoon (Dec-Feb) for cross-flow.', 'mcp-ai-wpoos-pro' );
		} elseif ( 'JM' === $country_code ) {
			$out[] = __( 'Jamaica: orient inlets to the prevailing easterly trade winds; provide louvered openings that close fast for hurricane events.', 'mcp-ai-wpoos-pro' );
		}
		if ( 'cross_flow' !== $strategy ) {
			$out[] = __( 'Confirm openings on opposite walls for true cross-flow; single-sided ventilation has limited reach.', 'mcp-ai-wpoos-pro' );
		}
		return $out;
	}
}
