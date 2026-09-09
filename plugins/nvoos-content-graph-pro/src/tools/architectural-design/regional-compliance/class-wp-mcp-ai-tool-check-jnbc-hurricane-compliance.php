<?php
/**
 * Tool_Check_Jnbc_Hurricane_Compliance (ecosystem port - Wave F2, architectural-design tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/architectural-design/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * per-file seams — the base-owned interface/Logger/media-url-utils requires gain exists-check seams
 * resolving from the addon's D8-compat `src/` copies, the response/subprocess traits are
 * wave-proof-guarded, the openai/gemini client requires stay monolith-gated, and the
 * `WP_MCP_AI_PRO_PATH` refs swap to `NVOOS_CONTENT_GRAPH_PRO_PATH` with the `src/` root.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Architectural_Design
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface require gains an
// exists-check seam resolving from the addon's D8-compat copy.
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}


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
		return __( 'Architectural Design toolkit is not enabled.', 'nvoos-content-graph-pro' );
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
		return __( 'Check Jamaica JNBC Hurricane Compliance', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Audit a Jamaica building against JNBC 2018 hurricane provisions: ASCE 7 wind-zone basic speed, impact-rated opening protection, continuous load path / hurricane tie-downs, and essential-facility uplift.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'JNBC wind zone classification.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'inland', 'standard', 'coastal' ),
					'default'     => 'standard',
				),
				'parish'    => array(
					'type'        => 'string',
					'description' => __( 'Jamaica parish for record (e.g. "St. Andrew", "St. Thomas").', 'nvoos-content-graph-pro' ),
				),
				'building'  => array(
					'type'        => 'object',
					'description' => __( 'Building geometry and detail.', 'nvoos-content-graph-pro' ),
					'properties'  => array(
						'building_height_m'    => array( 'type' => 'number' ),
						'occupancy_category'   => array(
							'type'        => 'string',
							'description' => __( 'Risk category: standard or essential (essential = hospitals, fire stations, shelters).', 'nvoos-content-graph-pro' ),
							'enum'        => array( 'standard', 'essential' ),
							'default'     => 'standard',
						),
						'opening_protection'   => array(
							'type'        => 'boolean',
							'description' => __( 'Whether all openings have impact-rated glazing or hurricane shutters.', 'nvoos-content-graph-pro' ),
						),
						'continuous_load_path' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether continuous tie-down (foundation -> roof) is provided.', 'nvoos-content-graph-pro' ),
						),
						'roof_attachment'      => array(
							'type'        => 'string',
							'description' => __( 'Roof-to-wall attachment system (e.g. "h2.5_clip", "strap", "toenail").', 'nvoos-content-graph-pro' ),
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to check JNBC hurricane compliance.', 'nvoos-content-graph-pro' )
			);
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Engine' ) || ! class_exists( 'WP_MCP_AI_Architectural_Codes' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural engine is unavailable.', 'nvoos-content-graph-pro' ) );
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
			/* translators: %s: hurricane wind zone name */
			'requirement' => sprintf( __( 'JNBC 2018 wind zone "%s" basic wind speed.', 'nvoos-content-graph-pro' ), $wind_zone ),
			'status'      => 'pass',
			/* translators: 1: wind speed in mph, 2: wind speed in m/s, 3: design standard reference */
			'details'     => sprintf( __( 'Basic wind speed: %1$.0f mph (%2$.1f m/s) — %3$s.', 'nvoos-content-graph-pro' ), (float) $wind['basic_wind_mph'], (float) $wind['basic_wind_ms'], $wind['standard'] ),
		);

		// Opening protection.
		$req_open = ! empty( $structural['opening_protection_required'] );
		if ( $req_open ) {
			$checks[] = array(
				'category'    => 'structural',
				'requirement' => __( 'Impact-rated glazing or hurricane shutters on all openings (JNBC 2018 Part 7).', 'nvoos-content-graph-pro' ),
				'status'      => $opening_prot ? 'pass' : 'fail',
				'details'     => $opening_prot ? __( 'Plan declares impact-rated openings.', 'nvoos-content-graph-pro' ) : __( 'Provide impact-rated glazing or hurricane shutters on all openings.', 'nvoos-content-graph-pro' ),
			);
		}

		// Continuous load path / tie-downs.
		$req_tiedown = ! empty( $structural['tie_down_continuous'] );
		if ( $req_tiedown ) {
			$checks[] = array(
				'category'    => 'structural',
				'requirement' => __( 'Continuous load path (foundation to roof) with hurricane tie-downs.', 'nvoos-content-graph-pro' ),
				'status'      => $continuous ? 'pass' : 'fail',
				'details'     => $continuous ? __( 'Continuous tie-down path declared.', 'nvoos-content-graph-pro' ) : __( 'Provide continuous tie-down straps and anchors connecting roof to foundation.', 'nvoos-content-graph-pro' ),
			);
		}

		// Roof-to-wall attachment.
		if ( $attachment ) {
			$adequate = in_array( strtolower( $attachment ), array( 'h2.5_clip', 'h2.5', 'strap', 'h-strap', 'h10', 'h10a', 'engineered_strap' ), true );
			$checks[] = array(
				'category'    => 'structural',
				'requirement' => __( 'Roof-to-wall attachment must resist hurricane uplift (engineered strap or H-clip preferred).', 'nvoos-content-graph-pro' ),
				'status'      => $adequate ? 'pass' : 'fail',
				/* translators: %s: declared roof-to-wall attachment type */
				'details'     => sprintf( __( 'Declared: %s. Toe-nail-only attachment is not adequate for Jamaica wind zones.', 'nvoos-content-graph-pro' ), $attachment ),
			);
		}

		// Essential-facility uplift criterion.
		if ( 'essential' === $occupancy ) {
			$req_uplift = isset( $structural['essential_facility_v_uplift_kpa'] ) ? floatval( $structural['essential_facility_v_uplift_kpa'] ) : 0.0;
			if ( $req_uplift > 0 ) {
				$checks[] = array(
					'category'    => 'structural',
					/* translators: %.2f: uplift design pressure in kPa */
					'requirement' => sprintf( __( 'Essential facility — design for uplift ≥ %.2f kPa.', 'nvoos-content-graph-pro' ), $req_uplift ),
					'status'      => 'warning',
					'details'     => __( 'Essential facilities (hospitals, fire stations, shelters) must use Risk Category IV with Iw ≥ 1.15.', 'nvoos-content-graph-pro' ),
				);
			}
		}

		// Roof pitch heuristic — flat / shallow roofs experience higher uplift.
		if ( $roof_pitch > 0 && $roof_pitch < 14 ) {
			$checks[] = array(
				'category'    => 'structural',
				'requirement' => __( 'Low-slope roof.', 'nvoos-content-graph-pro' ),
				'status'      => 'warning',
				/* translators: %.1f: roof pitch angle in degrees */
				'details'     => sprintf( __( 'Roof pitch %.1f° experiences elevated wind uplift in hurricanes — verify membrane attachment and roof edge securement.', 'nvoos-content-graph-pro' ), $roof_pitch ),
			);
		}

		// Building height heuristic — > 23 m triggers JNBC sprinkler requirement.
		if ( $height > 23.0 ) {
			$checks[] = array(
				'category'    => 'fire_safety',
				'requirement' => __( 'Sprinklers required per JNBC for buildings > 23 m.', 'nvoos-content-graph-pro' ),
				'status'      => 'warning',
				/* translators: %.1f: building height in meters */
				'details'     => sprintf( __( 'Building height %.1f m exceeds 23 m — confirm sprinkler design.', 'nvoos-content-graph-pro' ), $height ),
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
			'disclaimer'         => __( 'Analytical / advisory output only. Engage a chartered structural engineer; parish councils and the BSJ may impose additional requirements.', 'nvoos-content-graph-pro' ),
		);
	}
}
