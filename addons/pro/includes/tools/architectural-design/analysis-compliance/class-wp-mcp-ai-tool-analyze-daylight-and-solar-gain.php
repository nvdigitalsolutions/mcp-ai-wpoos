<?php
/**
 * Tool for analyzing daylight and solar gain.
 *
 * Estimates daylight factor (CIE overcast-sky simplified) and solar gain
 * (per-orientation), with climate-aware advice: tropical climates favour
 * deep overhangs and reduced western glass; temperate climates favour
 * passive-solar south-facing glass with overhangs sized for noon-altitude
 * cutoff in summer.
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
 * Analyze daylight & solar gain.
 */
class WP_MCP_AI_Tool_Analyze_Daylight_And_Solar_Gain implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'analyze_daylight_and_solar_gain';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Daylight & Solar Gain', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Estimate daylight factor (CIE simplified) and per-orientation solar gain. Returns climate-aware advice for tropical (LK / JM) versus temperate (US) overhang and glazing strategies.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country_code'             => array(
					'type'        => 'string',
					'description' => __( 'ISO country code.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'LK', 'JM', 'US' ),
					'default'     => 'LK',
				),
				'latitude'                 => array(
					'type'        => 'number',
					'description' => __( 'Site latitude (degrees, positive north). Used for noon altitude.', 'mcp-ai-wpoos-pro' ),
				),
				'space'                    => array(
					'type'        => 'object',
					'description' => __( 'Space description.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'floor_area_sqm'        => array( 'type' => 'number' ),
						'window_area_sqm'       => array( 'type' => 'number' ),
						'visible_transmittance' => array( 'type' => 'number' ),
						'shgc'                  => array( 'type' => 'number' ),
					),
				),
				'orientation'              => array(
					'type'        => 'string',
					'description' => __( 'Principal facade orientation.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'N', 'S', 'E', 'W', 'NE', 'NW', 'SE', 'SW' ),
				),
				'overhang_depth_m'         => array(
					'type'        => 'number',
					'description' => __( 'Horizontal overhang depth above the window head (m).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'window_height_m'          => array(
					'type'        => 'number',
					'description' => __( 'Window head-to-sill height (m).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 1.5,
				),
				'incident_irradiance_w_m2' => array(
					'type'        => 'number',
					'description' => __( 'Optional measured / TMY incident solar irradiance on the facade (W/m²). Defaults vary by orientation and climate.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
			),
			'required'             => array( 'space', 'orientation' ),
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
				__( 'You do not have permission to analyze daylight.', 'mcp-ai-wpoos-pro' )
			);
		}

		$country_code = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : 'LK';
		$latitude     = isset( $arguments['latitude'] ) ? floatval( $arguments['latitude'] ) : $this->default_latitude( $country_code );
		$space        = isset( $arguments['space'] ) ? (array) $arguments['space'] : array();
		$orient       = isset( $arguments['orientation'] ) ? strtoupper( sanitize_text_field( $arguments['orientation'] ) ) : 'S';
		$overhang     = isset( $arguments['overhang_depth_m'] ) ? max( 0.0, floatval( $arguments['overhang_depth_m'] ) ) : 0.0;
		$wh           = isset( $arguments['window_height_m'] ) ? max( 0.1, floatval( $arguments['window_height_m'] ) ) : 1.5;

		$floor  = isset( $space['floor_area_sqm'] ) ? floatval( $space['floor_area_sqm'] ) : 0.0;
		$window = isset( $space['window_area_sqm'] ) ? floatval( $space['window_area_sqm'] ) : 0.0;
		$tvis   = isset( $space['visible_transmittance'] ) ? floatval( $space['visible_transmittance'] ) : 0.6;
		$shgc   = isset( $space['shgc'] ) ? floatval( $space['shgc'] ) : 0.4;

		if ( $floor <= 0 || $window <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'space.floor_area_sqm and space.window_area_sqm must be > 0.', 'mcp-ai-wpoos-pro' ) );
		}

		// Daylight factor — simplified CIE overcast: DF ≈ ( window_area * Tvis * theta_visible ) / ( floor_area * (1 - rho^2) ).
		// Use room reflectance rho=0.5 and visible sky angle theta=0.4 (standard sketch values).
		$df = ( $window * $tvis * 0.4 ) / ( $floor * ( 1.0 - 0.5 * 0.5 ) ) * 100.0;

		// Solar gain — irradiance defaults per facade and climate.
		$incident = isset( $arguments['incident_irradiance_w_m2'] ) ? floatval( $arguments['incident_irradiance_w_m2'] ) : $this->default_irradiance( $country_code, $orient );

		// Overhang-shaded fraction (Cosine of solar altitude * overhang ratio).
		$shade_fraction = 0.0;
		if ( $overhang > 0 && $wh > 0 ) {
			$shade_fraction = min( 1.0, $overhang / $wh );
		}
		$gain_w = $incident * $shgc * $window * ( 1.0 - $shade_fraction );

		// Targets.
		$df_target = ( 'LK' === $country_code || 'JM' === $country_code ) ? 2.0 : 2.0; // 2% generic.
		$df_pass   = ( $df + 1e-6 >= $df_target );

		// Cooling-load heuristic per m² floor.
		$gain_per_floor = $gain_w / max( 1.0, $floor );
		$gain_status    = ( 'US' === $country_code )
			? ( ( $gain_per_floor <= 30.0 ) ? 'pass' : 'fail' )
			: ( ( $gain_per_floor <= 25.0 ) ? 'pass' : 'fail' );

		// Noon solar altitude (declination 0° simplified — equinox).
		$noon_altitude_deg = 90.0 - abs( (float) $latitude );

		return array(
			'success'                   => true,
			'country_code'              => $country_code,
			'latitude'                  => $latitude,
			'orientation'               => $orient,
			'noon_altitude_deg'         => round( $noon_altitude_deg, 1 ),
			'daylight_factor_pct'       => round( $df, 2 ),
			'daylight_target_pct'       => $df_target,
			'daylight_status'           => $df_pass ? 'pass' : 'fail',
			'incident_irradiance_w_m2'  => round( $incident, 1 ),
			'overhang_shade_fraction'   => round( $shade_fraction, 2 ),
			'estimated_solar_gain_w'    => round( $gain_w, 1 ),
			'solar_gain_per_floor_w_m2' => round( $gain_per_floor, 2 ),
			'solar_gain_status'         => $gain_status,
			'overall_status'            => ( $df_pass && 'pass' === $gain_status ) ? 'pass' : ( ( $df_pass || 'pass' === $gain_status ) ? 'conditional' : 'fail' ),
			'recommendations'           => $this->recommendations( $country_code, $orient, $shade_fraction, $df, $gain_per_floor ),
			'disclaimer'                => __( 'Analytical / advisory output. Run a daylight simulation (Radiance, ClimateStudio) for design certification.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Default latitude per country.
	 *
	 * @param string $country Country code.
	 * @return float
	 */
	protected function default_latitude( $country ) {
		switch ( $country ) {
			case 'LK':
				return 7.0;  // Colombo.
			case 'JM':
				return 18.0; // Kingston.
			case 'US':
			default:
				return 38.0; // Mid-US.
		}
	}

	/**
	 * Default incident irradiance (W/m²) on a vertical facade (mid-day average).
	 *
	 * @param string $country Country code.
	 * @param string $orient  Facade orientation.
	 * @return float
	 */
	protected function default_irradiance( $country, $orient ) {
		$is_tropical = ( 'LK' === $country || 'JM' === $country );
		$tables      = $is_tropical
			? array(
				'N'  => 250.0,
				'NE' => 350.0,
				'E'  => 600.0,
				'SE' => 500.0,
				'S'  => 350.0,
				'SW' => 600.0,
				'W'  => 700.0,
				'NW' => 500.0,
			)
			: array(
				'N'  => 100.0,
				'NE' => 250.0,
				'E'  => 450.0,
				'SE' => 550.0,
				'S'  => 600.0,
				'SW' => 550.0,
				'W'  => 450.0,
				'NW' => 250.0,
			);
		return isset( $tables[ $orient ] ) ? (float) $tables[ $orient ] : 400.0;
	}

	/**
	 * Build recommendations.
	 *
	 * @param string $country        Country.
	 * @param string $orient         Orientation.
	 * @param float  $shade_fraction Shade fraction 0-1.
	 * @param float  $df             Daylight factor.
	 * @param float  $gain_per_floor Gain (W/m²).
	 * @return array<int,string>
	 */
	protected function recommendations( $country, $orient, $shade_fraction, $df, $gain_per_floor ) {
		$out         = array();
		$is_tropical = ( 'LK' === $country || 'JM' === $country );
		if ( $is_tropical ) {
			if ( in_array( $orient, array( 'W', 'SW' ), true ) && $shade_fraction < 0.5 ) {
				$out[] = __( 'Tropical west / SW facade: deepen overhangs (≥ 0.5 of window height) and add vertical fins.', 'mcp-ai-wpoos-pro' );
			}
			if ( $gain_per_floor > 25.0 ) {
				$out[] = __( 'Solar gain exceeds 25 W/m² floor area — consider reducing window-to-wall ratio or specifying low-SHGC glazing (≤ 0.30).', 'mcp-ai-wpoos-pro' );
			}
			$out[] = __( 'In LK / JM, position primary openings on the cross-ventilation axis (NE-SW) where possible.', 'mcp-ai-wpoos-pro' );
		} else {
			if ( 'S' === $orient && $shade_fraction < 0.4 ) {
				$out[] = __( 'Temperate south facade: size overhang for summer-noon cutoff while admitting low-angle winter sun.', 'mcp-ai-wpoos-pro' );
			}
			if ( $df < 2.0 ) {
				$out[] = __( 'Daylight factor below 2% — consider larger glazing, light shelves, or skylights.', 'mcp-ai-wpoos-pro' );
			}
		}
		return $out;
	}
}
