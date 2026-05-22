<?php
/**
 * Tool for checking Jamaica JNBC hurricane compliance.
 *
 * Audits a building against Jamaica National Building Code 2018 hurricane
 * provisions: ASCE 7 wind zone selection, opening protection (impact-rated
 * glazing or shutters), continuous load path / hurricane tie-downs, and
 * essential-facility uplift criteria.
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
 * Audit JNBC 2018 hurricane provisions.
 */
class WP_MCP_AI_Tool_Check_JNBC_Hurricane_Compliance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'check_jnbc_hurricane_compliance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Jamaica JNBC Hurricane Compliance', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Audit a Jamaica building against JNBC 2018 hurricane provisions: ASCE 7 wind-zone basic speed, impact-rated opening protection, continuous load path / hurricane tie-downs, and essential-facility uplift.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'wind_zone' => array(
					'type'        => 'string',
					'description' => __( 'JNBC wind zone classification.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'inland', 'standard', 'coastal' ),
					'default'     => 'standard',
				),
				'parish'    => array(
					'type'        => 'string',
					'description' => __( 'Jamaica parish for record (e.g. "St. Andrew", "St. Thomas").', 'mcp-ai-wpoos-pro' ),
				),
				'building'  => array(
					'type'        => 'object',
					'description' => __( 'Building geometry and detail.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'building_height_m'    => array( 'type' => 'number' ),
						'occupancy_category'   => array(
							'type'        => 'string',
							'description' => __( 'Risk category: standard or essential (essential = hospitals, fire stations, shelters).', 'mcp-ai-wpoos-pro' ),
							'enum'        => array( 'standard', 'essential' ),
							'default'     => 'standard',
						),
						'opening_protection'   => array(
							'type'        => 'boolean',
							'description' => __( 'Whether all openings have impact-rated glazing or hurricane shutters.', 'mcp-ai-wpoos-pro' ),
						),
						'continuous_load_path' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether continuous tie-down (foundation -> roof) is provided.', 'mcp-ai-wpoos-pro' ),
						),
						'roof_attachment'      => array(
							'type'        => 'string',
							'description' => __( 'Roof-to-wall attachment system (e.g. "h2.5_clip", "strap", "toenail").', 'mcp-ai-wpoos-pro' ),
						),
						'roof_pitch_deg'       => array( 'type' => 'number' ),
					),
				),
			),
			'required'             => array( 'building' ),
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
				__( 'You do not have permission to check JNBC hurricane compliance.', 'mcp-ai-wpoos-pro' )
			);
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Engine' ) || ! class_exists( 'WP_MCP_AI_Architectural_Codes' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural engine is unavailable.', 'mcp-ai-wpoos-pro' ) );
		}

		$wind_zone = isset( $arguments['wind_zone'] ) ? sanitize_text_field( $arguments['wind_zone'] ) : 'standard';
		$parish    = isset( $arguments['parish'] ) ? sanitize_text_field( $arguments['parish'] ) : '';
		$building  = isset( $arguments['building'] ) ? (array) $arguments['building'] : array();

		$height       = isset( $building['building_height_m'] ) ? floatval( $building['building_height_m'] ) : 0.0;
		$occupancy    = isset( $building['occupancy_category'] ) ? sanitize_text_field( $building['occupancy_category'] ) : 'standard';
		$opening_prot = ! empty( $building['opening_protection'] );
		$continuous   = ! empty( $building['continuous_load_path'] );
		$attachment   = isset( $building['roof_attachment'] ) ? sanitize_text_field( $building['roof_attachment'] ) : '';
		$roof_pitch   = isset( $building['roof_pitch_deg'] ) ? floatval( $building['roof_pitch_deg'] ) : 0.0;

		$wind       = WP_MCP_AI_Architectural_Engine::get_wind_design_pressure( 'JM', $wind_zone );
		$rules      = WP_MCP_AI_Architectural_Codes::merge_rules( array( 'jm_jnbc_2018', 'jm_asce_7_via_jnbc' ) );
		$structural = isset( $rules['structural'] ) ? $rules['structural'] : array();

		$checks = array();

		// Wind zone basic speed.
		$checks[] = array(
			'category'    => 'structural',
			'requirement' => sprintf( __( 'JNBC 2018 wind zone "%s" basic wind speed.', 'mcp-ai-wpoos-pro' ), $wind_zone ),
			'status'      => 'pass',
			'details'     => sprintf( __( 'Basic wind speed: %.0f mph (%.1f m/s) — %s.', 'mcp-ai-wpoos-pro' ), (float) $wind['basic_wind_mph'], (float) $wind['basic_wind_ms'], $wind['standard'] ),
		);

		// Opening protection.
		$req_open = ! empty( $structural['opening_protection_required'] );
		if ( $req_open ) {
			$checks[] = array(
				'category'    => 'structural',
				'requirement' => __( 'Impact-rated glazing or hurricane shutters on all openings (JNBC 2018 Part 7).', 'mcp-ai-wpoos-pro' ),
				'status'      => $opening_prot ? 'pass' : 'fail',
				'details'     => $opening_prot ? __( 'Plan declares impact-rated openings.', 'mcp-ai-wpoos-pro' ) : __( 'Provide impact-rated glazing or hurricane shutters on all openings.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Continuous load path / tie-downs.
		$req_tiedown = ! empty( $structural['tie_down_continuous'] );
		if ( $req_tiedown ) {
			$checks[] = array(
				'category'    => 'structural',
				'requirement' => __( 'Continuous load path (foundation to roof) with hurricane tie-downs.', 'mcp-ai-wpoos-pro' ),
				'status'      => $continuous ? 'pass' : 'fail',
				'details'     => $continuous ? __( 'Continuous tie-down path declared.', 'mcp-ai-wpoos-pro' ) : __( 'Provide continuous tie-down straps and anchors connecting roof to foundation.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Roof-to-wall attachment.
		if ( $attachment ) {
			$adequate = in_array( strtolower( $attachment ), array( 'h2.5_clip', 'h2.5', 'strap', 'h-strap', 'h10', 'h10a', 'engineered_strap' ), true );
			$checks[] = array(
				'category'    => 'structural',
				'requirement' => __( 'Roof-to-wall attachment must resist hurricane uplift (engineered strap or H-clip preferred).', 'mcp-ai-wpoos-pro' ),
				'status'      => $adequate ? 'pass' : 'fail',
				'details'     => sprintf( __( 'Declared: %s. Toe-nail-only attachment is not adequate for Jamaica wind zones.', 'mcp-ai-wpoos-pro' ), $attachment ),
			);
		}

		// Essential-facility uplift criterion.
		if ( 'essential' === $occupancy ) {
			$req_uplift = isset( $structural['essential_facility_v_uplift_kpa'] ) ? floatval( $structural['essential_facility_v_uplift_kpa'] ) : 0.0;
			if ( $req_uplift > 0 ) {
				$checks[] = array(
					'category'    => 'structural',
					'requirement' => sprintf( __( 'Essential facility — design for uplift ≥ %.2f kPa.', 'mcp-ai-wpoos-pro' ), $req_uplift ),
					'status'      => 'warning',
					'details'     => __( 'Essential facilities (hospitals, fire stations, shelters) must use Risk Category IV with Iw ≥ 1.15.', 'mcp-ai-wpoos-pro' ),
				);
			}
		}

		// Roof pitch heuristic — flat / shallow roofs experience higher uplift.
		if ( $roof_pitch > 0 && $roof_pitch < 14 ) {
			$checks[] = array(
				'category'    => 'structural',
				'requirement' => __( 'Low-slope roof.', 'mcp-ai-wpoos-pro' ),
				'status'      => 'warning',
				'details'     => sprintf( __( 'Roof pitch %.1f° experiences elevated wind uplift in hurricanes — verify membrane attachment and roof edge securement.', 'mcp-ai-wpoos-pro' ), $roof_pitch ),
			);
		}

		// Building height heuristic — > 23 m triggers JNBC sprinkler requirement.
		if ( $height > 23.0 ) {
			$checks[] = array(
				'category'    => 'fire_safety',
				'requirement' => __( 'Sprinklers required per JNBC for buildings > 23 m.', 'mcp-ai-wpoos-pro' ),
				'status'      => 'warning',
				'details'     => sprintf( __( 'Building height %.1f m exceeds 23 m — confirm sprinkler design.', 'mcp-ai-wpoos-pro' ), $height ),
			);
		}

		$overall = 'pass';
		foreach ( $checks as $c ) {
			if ( 'fail' === $c['status'] ) {
				$overall = 'fail';
				break; }
			if ( 'warning' === $c['status'] && 'fail' !== $overall ) {
				$overall = 'conditional'; }
		}

		return array(
			'success'            => true,
			'country_code'       => 'JM',
			'parish'             => $parish,
			'wind_zone'          => $wind_zone,
			'wind'               => $wind,
			'occupancy_category' => $occupancy,
			'checks'             => $checks,
			'overall_status'     => $overall,
			'disclaimer'         => __( 'Analytical / advisory output only. Engage a chartered structural engineer; parish councils and the BSJ may impose additional requirements.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
