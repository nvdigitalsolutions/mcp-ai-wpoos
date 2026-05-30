<?php
/**
 * Send Lead Email — outbound email with consent gate and audit trail.
 *
 * Uses nodemailer (PHP fallback via wp_mail) to send a templated email.
 * Enforces consent check before sending.
 *
 * @package WP_MCP_AI_Pro @since 2.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class WP_MCP_AI_Tool_Send_Lead_Email implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ); }
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' ); }
	public function get_slug() {
		return 'send_lead_email'; }
	public function get_name() {
		return __( 'Send Lead Email', 'mcp-ai-wpoos-pro' ); }
	public function get_description() {
		return __( 'Send an outbound email to a lead. Requires active email consent.', 'mcp-ai-wpoos-pro' ); }
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Lead or contact post ID.', 'mcp-ai-wpoos-pro' ),
				),
				'subject'     => array(
					'type'        => 'string',
					'description' => __( 'Email subject line.', 'mcp-ai-wpoos-pro' ),
				),
				'body'        => array(
					'type'        => 'string',
					'description' => __( 'Email body (HTML or plain text).', 'mcp-ai-wpoos-pro' ),
				),
				'template_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional MJML template identifier.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'lead_id', 'subject', 'body' ),
		);
	}
	public function get_required_capability() {
		return 'edit_posts'; }
	public function requires_base_pro() {
		return true; }
	public function get_capability_flags() {
		return array( 'pro', 'outbound-network', 'database-write', 'requires-capability', 'requires-consent' ); }

	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() ); }
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ); }

		$lead_id = absint( $arguments['lead_id'] );
		$email   = get_post_meta( $lead_id, 'email', true );
		if ( ! $email ) {
			return new WP_Error( 'no_email', __( 'Lead has no email address.', 'mcp-ai-wpoos-pro' ) ); }

		// Consent gate.
		if ( class_exists( 'WP_MCP_AI_CRM_Consent' ) && ! WP_MCP_AI_CRM_Consent::is_permitted( $lead_id, 'email' ) ) {
			return new WP_Error( 'consent_required', __( 'Lead has not consented to email communication.', 'mcp-ai-wpoos-pro' ) );
		}
		// DNC gate.
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) && WP_MCP_AI_CRM_Engine::check_dnc( $email, 'email' ) ) {
			return new WP_Error( 'dnc_blocked', __( 'Lead email is on the Do Not Contact list.', 'mcp-ai-wpoos-pro' ) );
		}

		// Before-outbound-send hook (allows external code to veto).
		$veto = apply_filters( 'wp_mcp_ai_crm_before_outbound_send', null, $lead_id, 'email', $context );
		if ( is_wp_error( $veto ) ) {
			return $veto;
		}

		// Suppression check (allows external DNC/compliance integrations to block).
		$block = apply_filters( 'wp_mcp_ai_crm_suppression_check', null, $lead_id, 'email' );
		if ( is_wp_error( $block ) ) {
			return $block;
		}

		// Sequence step hooks — fire when this send is part of an outreach sequence.
		$sequence_id = isset( $arguments['sequence_id'] ) ? absint( $arguments['sequence_id'] ) : 0;
		$step_index  = isset( $arguments['sequence_step'] ) ? absint( $arguments['sequence_step'] ) : 0;
		if ( $sequence_id ) {
			do_action( 'wp_mcp_ai_crm_sequence_step_before_send', $lead_id, $sequence_id, $step_index, 'email', $arguments, $context );
		}

		$subject = sanitize_text_field( $arguments['subject'] );
		$body    = wp_kses_post( $arguments['body'] );

		// Append CAN-SPAM footer if configured.
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$s = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			if ( ! empty( $s['consent']['physical_address'] ) ) {
				$body .= '<br><br><small>' . esc_html( $s['consent']['physical_address'] ) . '</small>';
			}
		}

		$sent = wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

		if ( ! $sent ) {
			return new WP_Error( 'send_failed', __( 'Email failed to send.', 'mcp-ai-wpoos-pro' ) ); }

		// Log as activity.
		$activity_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_crm_activity',
				'post_title'  => sprintf( __( 'Sent email: %s', 'mcp-ai-wpoos-pro' ), $subject ),
				'post_status' => 'publish',
			),
			true
		);
		if ( ! is_wp_error( $activity_id ) ) {
			update_post_meta( $activity_id, 'activity_type', 'email' );
			update_post_meta( $activity_id, 'related_type', 'lead' );
			update_post_meta( $activity_id, 'related_id', $lead_id );
			update_post_meta( $activity_id, 'disposition', 'sent' );
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'outbound_email_sent', 'lead', $lead_id, array( 'email' => $email ) ); }

		do_action( 'wp_mcp_ai_crm_after_outbound_send', $lead_id, 'email', array( 'activity_id' => $activity_id ), $context );

		if ( $sequence_id ) {
			do_action( 'wp_mcp_ai_crm_sequence_step_after_send', $lead_id, $sequence_id, $step_index, 'email', $activity_id, $context );
		}

		return array(
			'success'     => true,
			'message'     => __( 'Email sent successfully.', 'mcp-ai-wpoos-pro' ),
			'lead_id'     => $lead_id,
			'to'          => $email,
			'activity_id' => $activity_id,
		);
	}
}
