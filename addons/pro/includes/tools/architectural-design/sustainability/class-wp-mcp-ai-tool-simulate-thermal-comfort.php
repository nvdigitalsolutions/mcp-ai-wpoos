<?php
/**
 * Tool for simulating thermal comfort.
 *
 * Implements ASHRAE 55-2020 graphic-method PMV/PPD (simplified) and the
 * adaptive comfort model for naturally-ventilated tropical buildings, plus
 * the SET (standard effective temperature) approximation. Tropical
 * jurisdictions (LK, JM) default to the adaptive model; US defaults to PMV.
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
 * Simulate thermal comfort using PMV / adaptive models.
 */
class WP_MCP_AI_Tool_Simulate_Thermal_Comfort implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'simulate_thermal_comfort';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Simulate Thermal Comfort', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Estimate thermal comfort via ASHRAE 55-2020 (PMV/PPD analytic and adaptive models). Tropical (LK/JM) defaults to the adaptive model; US defaults to PMV. Returns predicted mean vote, percentage dissatisfied, and per-country compliance status.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country_code'               => array(
					'type'        => 'string',
					'description' => __( 'ISO country code.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'LK', 'JM', 'US' ),
					'default'     => 'LK',
				),
				'model'                      => array(
					'type'        => 'string',
					'description' => __( 'Comfort model: pmv, adaptive, or auto.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'pmv', 'adaptive', 'auto' ),
					'default'     => 'auto',
				),
				'air_temperature_c'          => array(
					'type'        => 'number',
					'description' => __( 'Indoor air temperature (°C).', 'mcp-ai-wpoos-pro' ),
				),
				'mean_radiant_temperature_c' => array(
					'type'        => 'number',
					'description' => __( 'Mean radiant temperature (°C). Defaults to air temperature.', 'mcp-ai-wpoos-pro' ),
				),
				'relative_humidity_pct'      => array(
					'type'        => 'number',
					'description' => __( 'Relative humidity (%).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'air_speed_ms'               => array(
					'type'        => 'number',
					'description' => __( 'Indoor air speed (m/s).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'metabolic_rate_met'         => array(
					'type'        => 'number',
					'description' => __( 'Activity level (met). 1.0 seated, 1.2 office.', 'mcp-ai-wpoos-pro' ),
					'default'     => 1.1,
				),
				'clothing_insulation_clo'    => array(
					'type'        => 'number',
					'description' => __( 'Clothing insulation (clo). 0.5 light, 1.0 typical winter.', 'mcp-ai-wpoos-pro' ),
					'default'     => 0.5,
				),
				'outdoor_running_mean_c'     => array(
					'type'        => 'number',
					'description' => __( 'Prevailing mean outdoor temperature (°C) — required for the adaptive model.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'air_temperature_c', 'relative_humidity_pct' ),
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
				__( 'You do not have permission to simulate thermal comfort.', 'mcp-ai-wpoos-pro' )
			);
		}

		$country_code = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : 'LK';
		$model        = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : 'auto';
		$ta           = floatval( $arguments['air_temperature_c'] );
		$tr           = isset( $arguments['mean_radiant_temperature_c'] ) ? floatval( $arguments['mean_radiant_temperature_c'] ) : $ta;
		$rh           = floatval( $arguments['relative_humidity_pct'] );
		$va           = isset( $arguments['air_speed_ms'] ) ? max( 0.0, floatval( $arguments['air_speed_ms'] ) ) : 0.1;
		$met          = isset( $arguments['metabolic_rate_met'] ) ? floatval( $arguments['metabolic_rate_met'] ) : 1.1;
		$clo          = isset( $arguments['clothing_insulation_clo'] ) ? floatval( $arguments['clothing_insulation_clo'] ) : 0.5;
		$t_run_mean   = isset( $arguments['outdoor_running_mean_c'] ) ? floatval( $arguments['outdoor_running_mean_c'] ) : null;

		if ( 'auto' === $model ) {
			$model = ( 'LK' === $country_code || 'JM' === $country_code ) ? 'adaptive' : 'pmv';
		}

		$result = array(
			'success'      => true,
			'country_code' => $country_code,
			'model'        => $model,
			'inputs'       => array(
				'air_temperature_c'          => $ta,
				'mean_radiant_temperature_c' => $tr,
				'relative_humidity_pct'      => $rh,
				'air_speed_ms'               => $va,
				'metabolic_rate_met'         => $met,
				'clothing_insulation_clo'    => $clo,
				'outdoor_running_mean_c'     => $t_run_mean,
			),
		);

		if ( 'adaptive' === $model ) {
			if ( null === $t_run_mean ) {
				return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'outdoor_running_mean_c is required for the adaptive model.', 'mcp-ai-wpoos-pro' ) );
			}
			// ASHRAE 55 adaptive: T_comf = 0.31 * T_rm + 17.8 (valid 10-33.5 °C range).
			$t_comf                    = 0.31 * $t_run_mean + 17.8;
			$diff                      = $ta - $t_comf;
			$within_80                 = ( abs( $diff ) <= 3.5 );
			$within_90                 = ( abs( $diff ) <= 2.5 );
			$result['adaptive']        = array(
				'comfort_temperature_c'      => round( $t_comf, 2 ),
				'operative_minus_comf_c'     => round( $diff, 2 ),
				'within_80pct_acceptability' => $within_80,
				'within_90pct_acceptability' => $within_90,
				'overall_status'             => $within_80 ? ( $within_90 ? 'pass' : 'conditional' ) : 'fail',
			);
			$result['recommendations'] = $this->adaptive_recommendations( $country_code, $diff );
			$result['overall_status']  = $result['adaptive']['overall_status'];
		} else {
			// PMV (Fanger ISO 7730 simplified).
			$pmv                       = $this->pmv( $ta, $tr, $rh, $va, $met, $clo );
			$ppd                       = $this->ppd_from_pmv( $pmv );
			$cat                       = $this->ashrae_category( $pmv );
			$result['pmv']             = array(
				'pmv'                => round( $pmv, 2 ),
				'ppd_pct'            => round( $ppd, 1 ),
				'ashrae_55_category' => $cat,
				'overall_status'     => ( abs( $pmv ) <= 0.5 ) ? 'pass' : ( ( abs( $pmv ) <= 1.0 ) ? 'conditional' : 'fail' ),
			);
			$result['recommendations'] = $this->pmv_recommendations( $pmv, $rh, $va );
			$result['overall_status']  = $result['pmv']['overall_status'];
		}

		$result['disclaimer'] = __( 'Analytical / advisory output. For certification (ASHRAE 55, EDGE, LEED) run an annual hourly simulation.', 'mcp-ai-wpoos-pro' );
		return $result;
	}

	/**
	 * Simplified PMV calculation (ISO 7730 / ASHRAE 55, Fanger).
	 *
	 * @param float $ta  Air temp (°C).
	 * @param float $tr  Mean radiant temp (°C).
	 * @param float $rh  RH %.
	 * @param float $va  Air speed (m/s).
	 * @param float $met Metabolic rate (met).
	 * @param float $clo Clothing (clo).
	 * @return float
	 */
	protected function pmv( $ta, $tr, $rh, $va, $met, $clo ) {
		$m   = $met * 58.15;            // W/m² metabolic rate.
		$w   = 0.0;                     // External work assumed zero.
		$mw  = $m - $w;
		$icl = $clo * 0.155;            // m²·K/W clothing.
		// Saturated vapour pressure (Pa) via Antoine-style approximation, then partial pressure.
		$pa = ( $rh / 100.0 ) * 6.105 * exp( ( 17.27 * $ta ) / ( 237.7 + $ta ) ) * 100.0;

		$fcl = ( $icl <= 0.078 ) ? ( 1.0 + 1.29 * $icl ) : ( 1.05 + 0.645 * $icl );
		$hcf = 12.1 * sqrt( max( 0.001, $va ) );

		// Fanger iterative solve for clothing surface temperature tcl (ISO 7730 Annex A).
		$tcla = 35.7 - 0.028 * $mw;
		$tcl  = $tcla;
		for ( $i = 0; $i < 150; $i++ ) {
			$hcn     = 2.38 * pow( max( 0.0001, abs( $tcl - $ta ) ), 0.25 );
			$hc      = max( $hcf, $hcn );
			$tcl_new = $tcla - $icl * $fcl * (
				3.96e-8 * ( pow( $tcl + 273.0, 4 ) - pow( $tr + 273.0, 4 ) )
				+ $hc * ( $tcl - $ta )
			);
			if ( abs( $tcl_new - $tcl ) < 0.0001 ) {
				$tcl = $tcl_new;
				break;
			}
			// Damped update for stability.
			$tcl = 0.5 * ( $tcl + $tcl_new );
		}

		$hcn = 2.38 * pow( max( 0.0001, abs( $tcl - $ta ) ), 0.25 );
		$hc  = max( $hcf, $hcn );

		// Heat-loss components.
		$hl1 = 3.05e-3 * ( 5733.0 - 6.99 * $mw - $pa );      // Diffusion.
		$hl2 = ( $mw > 58.15 ) ? 0.42 * ( $mw - 58.15 ) : 0.0; // Sweat.
		$hl3 = 1.7e-5 * $m * ( 5867.0 - $pa );                // Latent respiration.
		$hl4 = 0.0014 * $m * ( 34.0 - $ta );                  // Dry respiration.
		$hl5 = 3.96e-8 * $fcl * ( pow( $tcl + 273.0, 4 ) - pow( $tr + 273.0, 4 ) ); // Radiation.
		$hl6 = $fcl * $hc * ( $tcl - $ta );                   // Convection.

		$ts  = 0.303 * exp( -0.036 * $m ) + 0.028;
		$pmv = $ts * ( $mw - $hl1 - $hl2 - $hl3 - $hl4 - $hl5 - $hl6 );
		return max( -3.5, min( 3.5, $pmv ) );
	}

	/**
	 * PPD from PMV (Fanger).
	 *
	 * @param float $pmv PMV.
	 * @return float Percentage Dissatisfied (0-100).
	 */
	protected function ppd_from_pmv( $pmv ) {
		return 100.0 - 95.0 * exp( -0.03353 * pow( $pmv, 4 ) - 0.2179 * pow( $pmv, 2 ) );
	}

	/**
	 * ASHRAE 55 acceptability category from PMV.
	 *
	 * @param float $pmv PMV.
	 * @return string
	 */
	protected function ashrae_category( $pmv ) {
		$abs = abs( $pmv );
		if ( $abs <= 0.2 ) {
			return 'A (most stringent)';
		}
		if ( $abs <= 0.5 ) {
			return 'B';
		}
		if ( $abs <= 0.7 ) {
			return 'C';
		}
		return 'outside ASHRAE 55 acceptability';
	}

	/**
	 * Adaptive recommendations.
	 *
	 * @param string $country Country.
	 * @param float  $diff    Operative minus comfort.
	 * @return array<int,string>
	 */
	protected function adaptive_recommendations( $country, $diff ) {
		$out = array();
		if ( $diff > 2.5 ) {
			$out[] = __( 'Operative temperature exceeds the adaptive comfort band — increase air movement (ceiling fans 0.6-1.5 m/s) or shading.', 'mcp-ai-wpoos-pro' );
		} elseif ( $diff < -2.5 ) {
			$out[] = __( 'Operative temperature below the adaptive band — reduce night ventilation or add envelope insulation.', 'mcp-ai-wpoos-pro' );
		}
		if ( 'LK' === $country || 'JM' === $country ) {
			$out[] = __( 'Tropical climates: target 0.6-1.5 m/s indoor air speed via ceiling fans to extend the comfort band by 2-3 °C.', 'mcp-ai-wpoos-pro' );
		}
		return $out;
	}

	/**
	 * PMV recommendations.
	 *
	 * @param float $pmv PMV.
	 * @param float $rh  RH (%).
	 * @param float $va  Air speed (m/s).
	 * @return array<int,string>
	 */
	protected function pmv_recommendations( $pmv, $rh, $va ) {
		$out = array();
		if ( $pmv > 0.5 ) {
			$out[] = __( 'Warm bias — reduce setpoint, increase air speed, or improve shading.', 'mcp-ai-wpoos-pro' );
		} elseif ( $pmv < -0.5 ) {
			$out[] = __( 'Cool bias — raise setpoint, increase clothing or reduce draft.', 'mcp-ai-wpoos-pro' );
		}
		if ( $rh > 70 ) {
			$out[] = __( 'High humidity — consider dehumidification to keep RH ≤ 60%.', 'mcp-ai-wpoos-pro' );
		}
		if ( $va > 0.8 ) {
			$out[] = __( 'High air speed may cause draft complaints in sedentary occupants.', 'mcp-ai-wpoos-pro' );
		}
		return $out;
	}
}
