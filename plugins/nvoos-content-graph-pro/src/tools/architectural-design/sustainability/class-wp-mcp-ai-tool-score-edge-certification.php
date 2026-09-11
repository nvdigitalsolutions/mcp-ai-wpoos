<?php
/**
 * Tool_Score_Edge_Certification (ecosystem port - Wave F2, architectural-design tool batch).
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
 * Score EDGE certification (energy / water / embodied carbon).
 */
class WP_MCP_AI_Tool_Score_Edge_Certification implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */
	/**
	 * Whether this tool is available for registration.
	 *
	 * @since 1.4.0
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
	 * @since 1.4.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'score_edge_certification';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Score EDGE Certification', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Compute IFC EDGE certification scoring (Certified / Advanced / Zero Carbon) from energy, water, and embodied-carbon savings versus a regional baseline. Pass either absolute proposed values or percentages directly. Indicative — final certification requires an EDGE Auditor.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'ISO 3166-1 alpha-2 country code.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'LK', 'JM', 'US' ),
				),
				'building_use'             => array(
					'type'        => 'string',
					'description' => __( 'Building use category.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'residential', 'commercial' ),
					'default'     => 'residential',
				),
				'eui_kwh_m2_year'          => array(
					'type'        => 'number',
					'description' => __( 'Proposed annual energy use intensity (kWh / m² / year). Used if energy_savings_pct is not provided.', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'water_l_person_day'       => array(
					'type'        => 'number',
					'description' => __( 'Proposed water use (litres / person / day). Used if water_savings_pct is not provided.', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'embodied_co2_kgco2e_m2'   => array(
					'type'        => 'number',
					'description' => __( 'Proposed embodied carbon (kg CO₂e / m²). Used if embodied_co2_savings_pct is not provided.', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'energy_savings_pct'       => array(
					'type'        => 'number',
					'description' => __( 'Direct energy savings vs baseline (%). Takes precedence over eui_kwh_m2_year.', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'water_savings_pct'        => array(
					'type'        => 'number',
					'description' => __( 'Direct water savings vs baseline (%).', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
				'embodied_co2_savings_pct' => array(
					'type'        => 'number',
					'description' => __( 'Direct embodied-carbon savings vs baseline (%).', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
					'maximum'     => 100,
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
				__( 'You do not have permission to score EDGE certification.', 'nvoos-content-graph-pro' )
			);
		}

		$country = isset( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : '';
		$use     = isset( $arguments['building_use'] ) ? sanitize_text_field( $arguments['building_use'] ) : 'residential';
		if ( '' === $country ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'country_code is required.', 'nvoos-content-graph-pro' )
			);
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Sustainability' ) ) {
			return new WP_Error(
				'wp_mcp_ai_engine_missing',
				__( 'Architectural sustainability engine is unavailable.', 'nvoos-content-graph-pro' )
			);
		}

		// Build proposed payload from any of the supplied inputs.
		$proposed = array();
		foreach ( array(
			'eui_kwh_m2_year',
			'water_l_person_day',
			'embodied_co2_kgco2e_m2',
			'energy_savings_pct',
			'water_savings_pct',
			'embodied_co2_savings_pct',
		) as $key ) {
			if ( isset( $arguments[ $key ] ) && '' !== $arguments[ $key ] ) {
				$proposed[ $key ] = floatval( $arguments[ $key ] );
			}
		}

		$score = WP_MCP_AI_Architectural_Sustainability::score_edge( $country, $use, $proposed );
		if ( empty( $score['success'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_edge_score_failed',
				isset( $score['error'] ) ? sanitize_text_field( $score['error'] ) : __( 'Unable to score EDGE certification.', 'nvoos-content-graph-pro' )
			);
		}

		$score['method']     = __( 'IFC EDGE methodology — savings computed against the country / use baseline.', 'nvoos-content-graph-pro' );
		$score['disclaimer'] = __( 'Indicative scoring only. Final EDGE certification requires registration with an accredited EDGE Auditor and the EDGE certification provider.', 'nvoos-content-graph-pro' );

		/**
		 * Fires after an EDGE certification score completes.
		 *
		 * @since 1.4.0
		 *
		 * @param array $score   Score result.
		 * @param array $args    Tool arguments.
		 * @param array $context Tool context.
		 */
		do_action( 'wp_mcp_ai_arch_edge_scored', $score, $arguments, $context );

		return $score;
	}
}
