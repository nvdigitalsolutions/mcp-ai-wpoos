<?php
/**
 * Tool_Validate_Setbacks_And_Far (ecosystem port - Wave F2, architectural-design tool batch).
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
 * Validate proposed lot geometry against zoning rules.
 */
class WP_MCP_AI_Tool_Validate_Setbacks_And_Far implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */
	/**
	 * Whether this tool is available for registration.
	 *
	 * @since 1.3.0
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
		return 'validate_setbacks_and_far';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Validate Setbacks & FAR', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validate proposed lot geometry against the zoning rules of the supplied country / code pack(s): minimum lot size (perches or m²), maximum Floor Area Ratio, maximum site coverage and per-side setback minima. Returns pass/fail per rule.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country_code'  => array(
					'type'        => 'string',
					'description' => __( 'ISO country code (LK, JM, US).', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'LK', 'JM', 'US' ),
				),
				'code_packs'    => array(
					'type'        => 'array',
					'description' => __( 'Optional list of code-pack identifiers; defaults to the country pack.', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'string' ),
					'default'     => array(),
				),
				'building_type' => array(
					'type'        => 'string',
					'description' => __( 'Building type: residential, commercial, industrial, mixed-use.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'residential', 'commercial', 'industrial', 'mixed-use' ),
					'default'     => 'residential',
				),
				'lot'           => array(
					'type'        => 'object',
					'description' => __( 'Lot description.', 'nvoos-content-graph-pro' ),
					'properties'  => array(
						'lot_area_m2' => array( 'type' => 'number' ),
						'lot_perches' => array( 'type' => 'number' ),
					),
				),
				'building'      => array(
					'type'        => 'object',
					'description' => __( 'Building geometry description.', 'nvoos-content-graph-pro' ),
					'properties'  => array(
						'built_up_area_m2'  => array( 'type' => 'number' ),
						'footprint_area_m2' => array( 'type' => 'number' ),
						'setbacks_m'        => array(
							'type'       => 'object',
							'properties' => array(
								'front' => array( 'type' => 'number' ),
								'rear'  => array( 'type' => 'number' ),
								'left'  => array( 'type' => 'number' ),
								'right' => array( 'type' => 'number' ),
							),
						),
					),
				),
			),
			'required'             => array( 'country_code', 'lot', 'building' ),
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
				__( 'You do not have permission to validate setbacks.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Architectural_Engine' ) || ! class_exists( 'WP_MCP_AI_Architectural_Codes' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural engine is unavailable.', 'nvoos-content-graph-pro' ) );
		}

		$country_code  = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : '';
		$code_packs    = isset( $arguments['code_packs'] ) ? array_map( 'sanitize_text_field', (array) $arguments['code_packs'] ) : array();
		$building_type = isset( $arguments['building_type'] ) ? sanitize_text_field( $arguments['building_type'] ) : 'residential';
		$lot           = isset( $arguments['lot'] ) ? (array) $arguments['lot'] : array();
		$building      = isset( $arguments['building'] ) ? (array) $arguments['building'] : array();

		if ( empty( $country_code ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'country_code is required.', 'nvoos-content-graph-pro' ) );
		}
		if ( empty( $code_packs ) ) {
			$pack = WP_MCP_AI_Architectural_Codes::get_default_pack_for_country( $country_code );
			if ( $pack ) {
				$code_packs = array( $pack );
			}
		}

		$rules  = WP_MCP_AI_Architectural_Codes::merge_rules( $code_packs );
		$zoning = isset( $rules['zoning'] ) && is_array( $rules['zoning'] ) ? $rules['zoning'] : array();

		// Resolve lot area.
		$lot_area_m2 = isset( $lot['lot_area_m2'] ) ? floatval( $lot['lot_area_m2'] ) : 0.0;
		if ( $lot_area_m2 <= 0 && isset( $lot['lot_perches'] ) ) {
			$lot_area_m2 = WP_MCP_AI_Architectural_Engine::perches_to_sqm( floatval( $lot['lot_perches'] ) );
		}

		$built_up_m2  = isset( $building['built_up_area_m2'] ) ? floatval( $building['built_up_area_m2'] ) : 0.0;
		$footprint_m2 = isset( $building['footprint_area_m2'] ) ? floatval( $building['footprint_area_m2'] ) : 0.0;
		$setbacks     = isset( $building['setbacks_m'] ) ? (array) $building['setbacks_m'] : array();

		$checks = array();

		// Min lot size in perches (LK).
		$min_perch = isset( $zoning['min_lot_perches_residential'] ) ? floatval( $zoning['min_lot_perches_residential'] ) : 0.0;
		if ( $min_perch > 0 && $lot_area_m2 > 0 ) {
			$lot_perches = WP_MCP_AI_Architectural_Engine::sqm_to_perches( $lot_area_m2 );
			$pass        = ( $lot_perches + 1e-6 >= $min_perch );
			$checks[]    = array(
				'rule'        => 'min_lot_perches_residential',
				/* translators: %.1f: minimum lot size in perches */
				'requirement' => sprintf( __( 'Minimum lot %.1f perches.', 'nvoos-content-graph-pro' ), $min_perch ),
				'actual'      => sprintf( '%.2f perches', $lot_perches ),
				'status'      => $pass ? 'pass' : 'fail',
			);
		}

		// FAR.
		if ( $lot_area_m2 > 0 && $built_up_m2 > 0 ) {
			$far_max = isset( $zoning['far_max_residential'] ) ? floatval( $zoning['far_max_residential'] ) : 0.0;
			if ( 'commercial' === $building_type && isset( $zoning['far_max_commercial'] ) ) {
				$far_max = floatval( $zoning['far_max_commercial'] );
			} elseif ( 'mixed-use' === $building_type && isset( $zoning['far_max_mixed_use'] ) ) {
				$far_max = floatval( $zoning['far_max_mixed_use'] );
			}
			$far_actual = WP_MCP_AI_Architectural_Engine::calculate_far( $built_up_m2, $lot_area_m2 );
			if ( $far_max > 0 ) {
				$pass     = ( $far_actual <= $far_max + 1e-6 );
				$checks[] = array(
					'rule'        => 'far_max',
					/* translators: %.2f: maximum FAR value */
					'requirement' => sprintf( __( 'Maximum FAR %.2f.', 'nvoos-content-graph-pro' ), $far_max ),
					'actual'      => sprintf( '%.2f', $far_actual ),
					'status'      => $pass ? 'pass' : 'fail',
				);
			}
		}

		// Site coverage.
		if ( $lot_area_m2 > 0 && $footprint_m2 > 0 ) {
			$cov_max = isset( $zoning['site_coverage_max'] ) ? floatval( $zoning['site_coverage_max'] ) : 0.0;
			if ( $cov_max > 0 ) {
				$cov_actual = WP_MCP_AI_Architectural_Engine::calculate_site_coverage( $footprint_m2, $lot_area_m2 );
				$pass       = ( $cov_actual <= $cov_max + 1e-6 );
				$checks[]   = array(
					'rule'        => 'site_coverage_max',
					/* translators: %.0f: maximum site coverage percentage */
					'requirement' => sprintf( __( 'Maximum site coverage %.0f%%.', 'nvoos-content-graph-pro' ), $cov_max ),
					'actual'      => sprintf( '%.1f%%', $cov_actual ),
					'status'      => $pass ? 'pass' : 'fail',
				);
			}
		}

		// Setbacks.
		if ( ! empty( $setbacks ) ) {
			$req    = array(
				'front' => isset( $zoning['min_setback_front_m'] ) ? floatval( $zoning['min_setback_front_m'] ) : 0.0,
				'rear'  => isset( $zoning['min_setback_rear_m'] ) ? floatval( $zoning['min_setback_rear_m'] ) : 0.0,
				'left'  => isset( $zoning['min_setback_side_m'] ) ? floatval( $zoning['min_setback_side_m'] ) : 0.0,
				'right' => isset( $zoning['min_setback_side_m'] ) ? floatval( $zoning['min_setback_side_m'] ) : 0.0,
			);
			$result = WP_MCP_AI_Architectural_Engine::validate_setbacks( array_map( 'floatval', $setbacks ), $req );
			foreach ( array( 'front', 'rear', 'left', 'right' ) as $side ) {
				if ( $req[ $side ] <= 0 ) {
					continue;
				}
				$prov     = isset( $setbacks[ $side ] ) ? floatval( $setbacks[ $side ] ) : 0.0;
				$pass     = ( $prov + 1e-6 >= $req[ $side ] );
				$checks[] = array(
					'rule'        => 'min_setback_' . $side . '_m',
					/* translators: %1$s: setback side, %2$.2f: required setback in meters */
					'requirement' => sprintf( __( 'Minimum %1$s setback %2$.2f m.', 'nvoos-content-graph-pro' ), $side, $req[ $side ] ),
					'actual'      => sprintf( '%.2f m', $prov ),
					'status'      => $pass ? 'pass' : 'fail',
				);
			}
		}

		$failures = array_filter(
			$checks,
			static function ( $c ) {
				return 'fail' === $c['status'];
			}
		);

		return array(
			'success'        => true,
			'country_code'   => $country_code,
			'code_packs'     => $code_packs,
			'building_type'  => $building_type,
			'lot_area_m2'    => round( $lot_area_m2, 2 ),
			'checks'         => $checks,
			'overall_status' => empty( $failures ) ? 'pass' : 'fail',
			'disclaimer'     => __( 'Analytical / advisory output only. Confirm with the local planning authority.', 'nvoos-content-graph-pro' ),
		);
	}
}
