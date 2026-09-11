<?php
/**
 * Tool_Calculate_Seismic_Loads (ecosystem port - Wave F2, architectural-design tool batch).
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
 * Calculate seismic base shear and storey forces for the supplied country/zone.
 */
class WP_MCP_AI_Tool_Calculate_Seismic_Loads implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'calculate_seismic_loads';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Calculate Seismic Loads', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Calculate seismic base shear and per-storey forces using the simplified Equivalent Lateral Force method. Supports Sri Lanka (IS 1893 referenced), Jamaica (JNBC / ASCE 7 Caribbean) and the United States (ASCE 7-22). Analytical only — engage a chartered structural engineer for design.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'country_code'       => array(
					'type'        => 'string',
					'description' => __( 'ISO 3166-1 alpha-2 country code.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'LK', 'JM', 'US' ),
				),
				'seismic_zone'       => array(
					'type'        => 'string',
					'description' => __( 'Country-specific seismic zone (LK: zone2/zone3; JM: low/moderate/high; US: a-f).', 'nvoos-content-graph-pro' ),
				),
				'building_weight_kn' => array(
					'type'        => 'number',
					'description' => __( 'Total seismic weight of the building in kilonewtons.', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'storey_weights_kn'  => array(
					'type'        => 'array',
					'description' => __( 'Optional list of per-storey seismic weights (kN), bottom to top. If omitted the building weight is split equally across the supplied number of storeys.', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'number' ),
					'default'     => array(),
				),
				'storey_heights_m'   => array(
					'type'        => 'array',
					'description' => __( 'Optional list of cumulative storey heights (m), bottom to top. Defaults to 3.0 m per storey.', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'number' ),
					'default'     => array(),
				),
				'num_storeys'        => array(
					'type'        => 'integer',
					'description' => __( 'Number of storeys (used when storey arrays omitted).', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'default'     => 1,
				),
				'r_factor'           => array(
					'type'        => 'number',
					'description' => __( 'Response modification coefficient R. Defaults to 5.0 (ordinary moment frame).', 'nvoos-content-graph-pro' ),
					'minimum'     => 0.1,
					'default'     => 5.0,
				),
				'importance_factor'  => array(
					'type'        => 'number',
					'description' => __( 'Importance factor Ie. 1.0 standard, 1.5 essential.', 'nvoos-content-graph-pro' ),
					'minimum'     => 0.1,
					'default'     => 1.0,
				),
				'sds_override'       => array(
					'type'        => 'number',
					'description' => __( 'Optional override for SDS in g (e.g., from USGS Seismic Design Maps). Bypasses the registry zone lookup.', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
			),
			'required'             => array( 'country_code', 'building_weight_kn' ),
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
				__( 'You do not have permission to calculate seismic loads.', 'nvoos-content-graph-pro' )
			);
		}

		$country_code      = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : '';
		$seismic_zone      = isset( $arguments['seismic_zone'] ) ? sanitize_text_field( $arguments['seismic_zone'] ) : '';
		$weight_kn         = isset( $arguments['building_weight_kn'] ) ? floatval( $arguments['building_weight_kn'] ) : 0.0;
		$storey_weights    = isset( $arguments['storey_weights_kn'] ) ? array_map( 'floatval', (array) $arguments['storey_weights_kn'] ) : array();
		$storey_heights    = isset( $arguments['storey_heights_m'] ) ? array_map( 'floatval', (array) $arguments['storey_heights_m'] ) : array();
		$num_storeys       = isset( $arguments['num_storeys'] ) ? max( 1, absint( $arguments['num_storeys'] ) ) : 1;
		$r_factor          = isset( $arguments['r_factor'] ) ? floatval( $arguments['r_factor'] ) : 5.0;
		$importance_factor = isset( $arguments['importance_factor'] ) ? floatval( $arguments['importance_factor'] ) : 1.0;
		$sds_override      = isset( $arguments['sds_override'] ) ? floatval( $arguments['sds_override'] ) : 0.0;

		if ( empty( $country_code ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'country_code is required.', 'nvoos-content-graph-pro' ) );
		}
		if ( $weight_kn <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'building_weight_kn must be greater than zero.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Engine' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural engine is unavailable.', 'nvoos-content-graph-pro' ) );
		}

		// Resolve SDS.
		$standard = '';
		if ( $sds_override > 0 ) {
			$sds      = $sds_override;
			$standard = __( 'Site-specific override', 'nvoos-content-graph-pro' );
		} else {
			$params   = WP_MCP_AI_Architectural_Engine::get_seismic_design_parameters( $country_code, $seismic_zone );
			$sds      = (float) $params['sds'];
			$standard = (string) $params['standard'];
			if ( $sds <= 0 ) {
				return new WP_Error( 'wp_mcp_ai_unknown_zone', __( 'No seismic table is registered for the supplied country / zone.', 'nvoos-content-graph-pro' ) );
			}
		}

		$shear = WP_MCP_AI_Architectural_Engine::calculate_seismic_base_shear( $weight_kn, $sds, $r_factor, $importance_factor );

		// Distribute base shear across storeys (ASCE 7 §12.8.3, k=1 simplified for short-period structures).
		if ( empty( $storey_weights ) ) {
			$even           = $weight_kn / max( 1, $num_storeys );
			$storey_weights = array_fill( 0, $num_storeys, $even );
		}
		if ( empty( $storey_heights ) ) {
			$h              = 3.0;
			$storey_heights = array();
			// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found
			for ( $i = 1; $i <= count( $storey_weights ); $i++ ) {
				$storey_heights[] = $h * $i;
			}
		}

		$denom = 0.0;
		$count = min( count( $storey_weights ), count( $storey_heights ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$denom += $storey_weights[ $i ] * $storey_heights[ $i ];
		}
		$storey_forces = array();
		if ( $denom > 0 ) {
			for ( $i = 0; $i < $count; $i++ ) {
				$cv              = ( $storey_weights[ $i ] * $storey_heights[ $i ] ) / $denom;
				$fx              = $cv * $shear['base_shear_kn'];
				$storey_forces[] = array(
					'storey'    => $i + 1,
					'weight_kn' => round( $storey_weights[ $i ], 2 ),
					'height_m'  => round( $storey_heights[ $i ], 2 ),
					'cv'        => round( $cv, 4 ),
					'force_kn'  => round( $fx, 2 ),
				);
			}
		}

		$result = array(
			'success'           => true,
			'country_code'      => $country_code,
			'seismic_zone'      => $seismic_zone,
			'standard'          => $standard,
			'sds'               => round( $sds, 3 ),
			'r_factor'          => $r_factor,
			'importance_factor' => $importance_factor,
			'cs'                => round( (float) $shear['cs'], 4 ),
			'base_shear_kn'     => round( (float) $shear['base_shear_kn'], 2 ),
			'storey_forces'     => $storey_forces,
			'method'            => __( 'ASCE 7 Equivalent Lateral Force (simplified) — analytical only.', 'nvoos-content-graph-pro' ),
			'disclaimer'        => __( 'Analytical / advisory output only. Engage a chartered structural engineer for design.', 'nvoos-content-graph-pro' ),
		);

		/**
		 * Fires after a seismic-load calculation completes.
		 *
		 * @since 1.3.0
		 *
		 * @param array $result    Calculated result set.
		 * @param array $arguments Original arguments.
		 */
		do_action( 'wp_mcp_ai_arch_after_seismic_calculation', $result, $arguments );

		return $result;
	}
}
