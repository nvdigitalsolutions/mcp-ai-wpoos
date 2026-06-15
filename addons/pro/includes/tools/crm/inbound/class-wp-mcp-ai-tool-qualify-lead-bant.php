<?php
/**
 * Qualify Lead BANT — BANT qualification assessment.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assess a lead using the BANT framework (Budget, Authority, Need, Timeline).
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Qualify_Lead_Bant implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'qualify_lead_bant';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Qualify Lead (BANT)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Assess a lead using the BANT framework (Budget, Authority, Need, Timeline).', 'mcp-ai-wpoos-pro' );
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
				'lead_id'          => array( 'type' => 'integer' ),
				'message_or_notes' => array(
					'type'        => 'string',
					'description' => __( 'Conversation text, notes, or message body to analyse.', 'mcp-ai-wpoos-pro' ),
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

		$text = sanitize_textarea_field( $arguments['message_or_notes'] ?? $p->post_content ?? '' );
		if ( ! class_exists( 'WP_MCP_AI_CRM_Classifier' ) ) {
			return new WP_Error( 'classifier_missing', __( 'CRM Classifier not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$bant = WP_MCP_AI_CRM_Classifier::extract_bant( $text );
		update_post_meta( $lead_id, 'bant_assessment', $bant );
		$overall = ( $bant['budget']['score'] + $bant['authority']['score'] + $bant['need']['score'] + $bant['timeline']['score'] ) / 4;
		$missing = array();
		foreach ( $bant as $k => $v ) {
			if ( 0 === $v['score'] ) {
				$missing[] = $k;
			}
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'lead_qualified_bant', 'lead', $lead_id );
		}

		return array(
			'success'       => true,
			'lead_id'       => $lead_id,
			'bant'          => $bant,
			'overall_score' => round( $overall, 1 ),
			'missing_info'  => $missing,
			'is_qualified'  => $overall >= 50,
			'message'       => $overall >= 50
				? __( 'Lead meets BANT qualification threshold.', 'mcp-ai-wpoos-pro' )
				: __( 'Lead needs more information before BANT qualification.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
