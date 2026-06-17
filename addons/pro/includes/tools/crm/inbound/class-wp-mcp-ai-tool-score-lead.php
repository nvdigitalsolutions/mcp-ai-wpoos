<?php
/**
 * Score Lead — composite lead scoring using WP_MCP_AI_CRM_Engine.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculate a composite lead score (0-100) from fit, intent, engagement, and
 * recency factors.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Score_Lead implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'score_lead';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Score Lead', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Calculate a composite lead score (0-100) from fit, intent, engagement, and recency factors.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id'    => array( 'type' => 'integer' ),
				'fit'        => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100,
				),
				'intent'     => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100,
				),
				'engagement' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100,
				),
				'recency'    => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100,
				),
			),
			'required'   => array( 'lead_id' ),
		);
	}

	/**
	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Whether the tool requires base pro.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Get the capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() );
		}

		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$lead_id = absint( $arguments['lead_id'] );
		$p       = get_post( $lead_id );
		if ( ! $p || ! in_array( $p->post_type, array( 'mcp_ai_lead', 'mcp_crm_contacts' ), true ) ) {
			return new WP_Error( 'not_found', __( 'Lead not found.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			return new WP_Error( 'engine_missing', __( 'CRM Engine not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$factors = array(
			'fit'        => isset( $arguments['fit'] ) ? absint( $arguments['fit'] ) : 40,
			'intent'     => isset( $arguments['intent'] ) ? absint( $arguments['intent'] ) : 30,
			'engagement' => isset( $arguments['engagement'] ) ? absint( $arguments['engagement'] ) : 50,
			'recency'    => isset( $arguments['recency'] ) ? absint( $arguments['recency'] ) : 80,
		);
		$score   = WP_MCP_AI_CRM_Engine::calculate_lead_score( $factors );
		update_post_meta( $lead_id, 'lead_score', $score );
		update_post_meta( $lead_id, 'score_factors', $factors );

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'lead_scored', 'lead', $lead_id, array( 'score' => $score ) );
		}

		return array(
			'success'     => true,
			'lead_id'     => $lead_id,
			'score'       => $score,
			'score_label' => WP_MCP_AI_CRM_Engine::score_label( $score ),
			'factors'     => $factors,
		);
	}
}
