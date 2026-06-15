<?php
/**
 * Enroll Lead in Sequence — idempotent enrollment with suppression check.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enroll Lead in Sequence tool.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Enroll_Lead_In_Sequence implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'enroll_lead_in_sequence';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Enroll Lead in Sequence', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Enroll a lead into an outreach sequence. Idempotent — skips if already enrolled. Respects suppression list.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id'     => array( 'type' => 'integer' ),
				'sequence_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'lead_id', 'sequence_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$lead_id = absint( $arguments['lead_id'] );
		$seq_id  = absint( $arguments['sequence_id'] );
		$lp      = get_post( $lead_id );
		$sp      = get_post( $seq_id );
		if ( ! $lp || ! in_array( $lp->post_type, array( 'mcp_ai_lead', 'mcp_crm_contacts' ), true ) ) {
			return new WP_Error( 'not_found', __( 'Lead not found.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! $sp || 'mcp_ai_sequence' !== $sp->post_type ) {
			return new WP_Error( 'not_found', __( 'Sequence not found.', 'mcp-ai-wpoos-pro' ) );
		}
		// DNC check.
		$email = get_post_meta( $lead_id, 'email', true );
		$phone = get_post_meta( $lead_id, 'phone', true );
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			if ( ( $email && WP_MCP_AI_CRM_Engine::check_dnc( $email ) ) || ( $phone && WP_MCP_AI_CRM_Engine::check_dnc( $phone ) ) ) {
				return new WP_Error( 'dnc_blocked', __( 'Lead is on the suppression list.', 'mcp-ai-wpoos-pro' ) );
			}
		}
		// Idempotent: check if already enrolled.
		$existing = get_post_meta( $lead_id, '_active_sequence_id', true );
		if ( $existing ) {
			return array(
				'success'          => true,
				'message'          => __( 'Lead is already enrolled in a sequence.', 'mcp-ai-wpoos-pro' ),
				'lead_id'          => $lead_id,
				'sequence_id'      => (int) $existing,
				'already_enrolled' => true,
			);
		}
		update_post_meta( $lead_id, '_active_sequence_id', $seq_id );
		update_post_meta( $lead_id, '_sequence_step', 0 );
		update_post_meta( $lead_id, '_sequence_started', gmdate( 'c' ) );
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'sequence_enrolled', 'sequence_enrollment', $lead_id, array( 'sequence_id' => $seq_id ) );
		}
		return array(
			'success'     => true,
			'message'     => __( 'Lead enrolled in sequence.', 'mcp-ai-wpoos-pro' ),
			'lead_id'     => $lead_id,
			'sequence_id' => $seq_id,
		);
	}
}
