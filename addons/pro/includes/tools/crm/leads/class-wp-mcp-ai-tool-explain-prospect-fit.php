<?php
/**
 * Explain Prospect Fit — evidence card for one lead.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Explain why a lead is on a list.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_Tool_Explain_Prospect_Fit implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Availability.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * Unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'explain_prospect_fit';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Explain Prospect Fit', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Return the stored signals, evidence, source, confidence and observation date for one lead, with a one-line summary.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Usage guidance.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Before writing a personalised email, or when a reviewer asks why a prospect is on the list.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Building a list; use query_signal_cohort. Collecting signals; use collect_prospect_signals.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'collect_prospect_signals', 'query_signal_cohort' ),
			'notes'           => __( 'Read-only. Evidence is returned exactly as stored, including the source URL and the matched line.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Parameters.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id' => array(
					'type'    => 'integer',
					'maximum' => WP_MCP_AI_Tool_Schema_Helper::MAX_SAFE_INTEGER,
				),
			),
			'required'   => array( 'lead_id' ),
		);
	}

	/**
	 * Capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Requires Base Pro.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'pii-access' );
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Arguments.
	 * @param array $context   Context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() );
		}
		if ( ! class_exists( 'WP_MCP_AI_CRM_Signals' ) ) {
			return new WP_Error( 'engine_missing', __( 'Signals engine is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$lead_id = absint( $arguments['lead_id'] ?? 0 );
		if ( ! $lead_id || ! get_post( $lead_id ) ) {
			return new WP_Error( 'not_found', __( 'Lead not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$card = WP_MCP_AI_CRM_Signals::evidence_card( $lead_id );

		return array(
			'success'          => true,
			'message'          => $card['summary'] ? $card['summary'] : __( 'No confirmed signals recorded.', 'mcp-ai-wpoos-pro' ),
			'lead_id'          => $lead_id,
			'title'            => get_the_title( $lead_id ),
			'email'            => get_post_meta( $lead_id, 'email', true ),
			'email_status'     => class_exists( 'WP_MCP_AI_CRM_Deliverability' ) ? WP_MCP_AI_CRM_Deliverability::get_status( $lead_id ) : array(),
			'signals'          => $card['signals'],
			'evidence_summary' => $card['summary'],
		);
	}
}
