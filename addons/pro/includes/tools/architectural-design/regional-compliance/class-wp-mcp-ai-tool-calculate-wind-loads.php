<?php
/**
 * Tool for calculating regional wind loads.
 *
 * Dispatches to country-appropriate wind-load standards via the shared
 * architectural engine and code registry. Supports Sri Lanka (BS 6399-2 /
 * IS 875-3 referenced via SLS), Jamaica (ASCE 7 via JNBC 2018), and the
 * United States (ASCE 7-22).
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
 * Calculate wind loads for a given country / wind zone.
 */
class WP_MCP_AI_Tool_Calculate_Wind_Loads implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'calculate_wind_loads';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Calculate Wind Loads', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Calculate regional wind design loads using country-appropriate standards (BS 6399-2 / IS 875-3 for Sri Lanka, ASCE 7 via JNBC 2018 for Jamaica, ASCE 7-22 for the United States). Returns basic wind speed, velocity pressure, and an indicative design wind pressure for low-rise buildings. Analytical only — engage a chartered structural engineer for design.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country_code'         => array(
					'type'        => 'string',
					'description' => __( 'ISO 3166-1 alpha-2 country code.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'LK', 'JM', 'US' ),
				),
				'wind_zone'            => array(
					'type'        => 'string',
					'description' => __( 'Country-specific wind zone identifier (LK: zone1/zone2/zone3; JM: inland/standard/coastal; US: inland/standard/coastal/hurricane).', 'mcp-ai-wpoos-pro' ),
				),
				'exposure'             => array(
					'type'        => 'string',
					'description' => __( 'ASCE 7 exposure category (B, C, or D). Default C (open terrain).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'B', 'C', 'D' ),
					'default'     => 'C',
				),
				'building_height_m'    => array(
					'type'        => 'number',
					'description' => __( 'Mean roof height in metres.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 6.0,
				),
				'topographic_factor'   => array(
					'type'        => 'number',
					'description' => __( 'Kzt topographic factor (default 1.0).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 1.0,
				),
				'importance_factor'    => array(
					'type'        => 'number',
					'description' => __( 'Risk-importance factor (Iw). Default 1.0 (Risk Cat II).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 1.0,
				),
				'gust_factor'          => array(
					'type'        => 'number',
					'description' => __( 'Gust effect factor G. Default 0.85 for rigid structures.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0.85,
				),
				'pressure_coefficient' => array(
					'type'        => 'number',
					'description' => __( 'Combined external + internal pressure coefficient (Cp - Cpi). Default 1.0 for windward walls of enclosed low-rise buildings.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1.0,
				),
			),
			'required'             => array( 'country_code' ),
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to calculate wind loads.', 'mcp-ai-wpoos-pro' )
			);
		}

		$country_code      = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : '';
		$wind_zone         = isset( $arguments['wind_zone'] ) ? sanitize_text_field( $arguments['wind_zone'] ) : '';
		$exposure          = isset( $arguments['exposure'] ) ? sanitize_text_field( $arguments['exposure'] ) : 'C';
		$building_height_m = isset( $arguments['building_height_m'] ) ? floatval( $arguments['building_height_m'] ) : 6.0;
		$kzt               = isset( $arguments['topographic_factor'] ) ? floatval( $arguments['topographic_factor'] ) : 1.0;
		$iw                = isset( $arguments['importance_factor'] ) ? floatval( $arguments['importance_factor'] ) : 1.0;
		$g                 = isset( $arguments['gust_factor'] ) ? floatval( $arguments['gust_factor'] ) : 0.85;
		$cp                = isset( $arguments['pressure_coefficient'] ) ? floatval( $arguments['pressure_coefficient'] ) : 1.0;

		if ( empty( $country_code ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'country_code is required.', 'mcp-ai-wpoos-pro' )
			);
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Engine' ) ) {
			return new WP_Error(
				'wp_mcp_ai_engine_missing',
				__( 'Architectural engine is unavailable.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Velocity-pressure exposure coefficient Kz (ASCE 7 simplified).
		$kz = $this->kz_for_height( $building_height_m, $exposure );

		$wind = WP_MCP_AI_Architectural_Engine::get_wind_design_pressure( $country_code, $wind_zone );
		if ( empty( $wind['basic_wind_ms'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_unknown_zone',
				__( 'No wind table is registered for the supplied country / zone.', 'mcp-ai-wpoos-pro' )
			);
		}

		// q_z = 0.613 * Kz * Kzt * Kd * V^2.
		$qz = WP_MCP_AI_Architectural_Engine::calculate_velocity_pressure( $wind['basic_wind_ms'], $kz, $kzt );
		// Design wind pressure p = q_z * G * Cp * Iw (ASCE 7 Method 2 form).
		$design_pressure_pa = $qz * $g * $cp * $iw;

		$result = array(
			'success'              => true,
			'country_code'         => $country_code,
			'wind_zone'            => $wind_zone,
			'standard'             => $wind['standard'],
			'basic_wind_ms'        => round( (float) $wind['basic_wind_ms'], 2 ),
			'basic_wind_mph'       => round( (float) $wind['basic_wind_mph'], 2 ),
			'kz'                   => round( $kz, 3 ),
			'kzt'                  => $kzt,
			'gust_factor'          => $g,
			'importance_factor'    => $iw,
			'pressure_coefficient' => $cp,
			'velocity_pressure_pa' => round( $qz, 2 ),
			'design_pressure_pa'   => round( $design_pressure_pa, 2 ),
			'design_pressure_kpa'  => round( $design_pressure_pa / 1000.0, 3 ),
			'method'               => __( 'ASCE 7 Method 2 simplified — analytical only.', 'mcp-ai-wpoos-pro' ),
			'disclaimer'           => __( 'Analytical / advisory output only. Engage a chartered structural engineer for design.', 'mcp-ai-wpoos-pro' ),
		);

		/**
		 * Fires after a wind-load calculation completes.
		 *
		 * @since 1.3.0
		 *
		 * @param array $result   Calculated result set.
		 * @param array $arguments Original arguments.
		 */
		do_action( 'wp_mcp_ai_arch_after_wind_calculation', $result, $arguments );

		return $result;
	}

	/**
	 * ASCE 7 simplified Kz for a given height (m) and exposure.
	 *
	 * @param float  $height_m Mean roof height in metres.
	 * @param string $exposure Exposure category B/C/D.
	 * @return float Kz value (clamped to a sensible range).
	 */
	protected function kz_for_height( $height_m, $exposure ) {
		$height_m = max( 4.6, (float) $height_m ); // ASCE 7 floor 15 ft.
		$exposure = ( in_array( $exposure, array( 'B', 'C', 'D' ), true ) ) ? $exposure : 'C';

		// ASCE 7 Table 26.10-1 power-law coefficients.
		$alpha_zg = array(
			'B' => array(
				'alpha' => 7.0,
				'zg'    => 365.76,
			),
			'C' => array(
				'alpha' => 9.5,
				'zg'    => 274.32,
			),
			'D' => array(
				'alpha' => 11.5,
				'zg'    => 213.36,
			),
		);
		$entry    = $alpha_zg[ $exposure ];
		$kz       = 2.01 * pow( $height_m / $entry['zg'], 2.0 / $entry['alpha'] );
		// ASCE 7 typically clamps Kz to >= 0.85 for low buildings.
		return max( 0.85, $kz );
	}
}
