<?php
/**
 * Tool_Score_Leed_V4_Certification (ecosystem port - Wave F2, architectural-design tool batch).
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
 * Score LEED v4 BD+C certification.
 */
class WP_MCP_AI_Tool_Score_Leed_V4_Certification implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */
	/**
	 * Whether this tool is available for registration.
	 *
	 * @since 1.4.0
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
		return 'score_leed_v4_certification';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Score LEED v4 BD+C Certification', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Score a LEED v4/v4.1 BD+C: New Construction submission. Pass `awarded_credits` (credit-id => points) and `met_prerequisites` (prereq-id => bool). Returns the awarded certification level (Certified / Silver / Gold / Platinum), category totals, missing prerequisites, and any over-max or unknown credit IDs. Indicative — final certification requires GBCI review.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'awarded_credits'   => array(
					'type'                 => 'object',
					'description'          => __( 'Map of credit-id (e.g. EA_c2) to awarded points.', 'nvoos-content-graph-pro' ),
					'additionalProperties' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
				'met_prerequisites' => array(
					'type'                 => 'object',
					'description'          => __( 'Map of prerequisite-id (e.g. EA_p2) to boolean.', 'nvoos-content-graph-pro' ),
					'additionalProperties' => array( 'type' => 'boolean' ),
				),
				'include_catalog'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include the full LEED v4 BD+C catalog in the response.', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'awarded_credits' ),
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
				__( 'You do not have permission to score LEED certification.', 'nvoos-content-graph-pro' )
			);
		}

		if ( empty( $arguments['awarded_credits'] ) || ! is_array( $arguments['awarded_credits'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'awarded_credits must be a map of credit-id to integer points.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Architectural_Sustainability' ) ) {
			return new WP_Error(
				'wp_mcp_ai_engine_missing',
				__( 'Architectural sustainability engine is unavailable.', 'nvoos-content-graph-pro' )
			);
		}

		// Sanitise inputs.
		$awarded = array();
		foreach ( (array) $arguments['awarded_credits'] as $credit_id => $points ) {
			$credit_id = sanitize_text_field( (string) $credit_id );
			if ( '' === $credit_id ) {
				continue;
			}
			$awarded[ $credit_id ] = max( 0, intval( $points ) );
		}

		$prereqs = array();
		if ( isset( $arguments['met_prerequisites'] ) && is_array( $arguments['met_prerequisites'] ) ) {
			foreach ( $arguments['met_prerequisites'] as $prereq_id => $is_met ) {
				$prereq_id = sanitize_text_field( (string) $prereq_id );
				if ( '' === $prereq_id ) {
					continue;
				}
				$prereqs[ $prereq_id ] = (bool) $is_met;
			}
		}

		$score = WP_MCP_AI_Architectural_Sustainability::score_leed_v4_bdc( $awarded, $prereqs );
		if ( empty( $score['success'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_leed_score_failed',
				__( 'Unable to score LEED submission.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! empty( $arguments['include_catalog'] ) ) {
			$score['catalog'] = WP_MCP_AI_Architectural_Sustainability::get_leed_v4_bdc_catalog();
		}

		$score['method']     = __( 'LEED v4 / v4.1 BD+C: New Construction (USGBC).', 'nvoos-content-graph-pro' );
		$score['disclaimer'] = __( 'Indicative scoring only. Final certification requires GBCI review of documentation and credit interpretations.', 'nvoos-content-graph-pro' );

		/**
		 * Fires after a LEED scoring completes.
		 *
		 * @since 1.4.0
		 *
		 * @param array $score   Score result.
		 * @param array $args    Tool arguments.
		 * @param array $context Tool context.
		 */
		do_action( 'wp_mcp_ai_arch_leed_scored', $score, $arguments, $context );

		return $score;
	}
}
