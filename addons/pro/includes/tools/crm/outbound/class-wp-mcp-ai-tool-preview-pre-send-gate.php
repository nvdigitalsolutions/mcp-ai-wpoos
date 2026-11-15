<?php
/**
 * Preview Pre-Send Gate — dry-run the gate.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 *
 * @license Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gate preview tool.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_Tool_Preview_Pre_Send_Gate implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

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
		return 'preview_pre_send_gate';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Preview Pre-Send Gate', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Dry-run the deterministic pre-send gate against a lead and draft. Returns pass/fail, every reason, warnings and the normalised payload. Writes nothing.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Usage guidance.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Before approving a draft, debugging a block, or testing a new template against the gate.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Sending; use send_lead_email or the outbox. Verifying an address; use verify_lead_email.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'send_lead_email', 'verify_lead_email', 'review_held_send' ),
			'notes'           => __( 'Read-only. The gate never calls a model.', 'mcp-ai-wpoos-pro' ),
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
				'channel' => array(
					'type'    => 'string',
					'default' => 'email',
				),
				'kind'    => array(
					'type'    => 'string',
					'default' => 'sequence',
					'enum'    => array( 'sequence', 'one_off', 'reply', 'transactional', 'marketing' ),
				),
				'subject' => array( 'type' => 'string' ),
				'body'    => array( 'type' => 'string' ),
				'step'    => array(
					'type'    => 'integer',
					'default' => 0,
				),
			),
			'required'   => array( 'lead_id', 'body' ),
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
		if ( ! class_exists( 'WP_MCP_AI_CRM_Pre_Send_Gate' ) ) {
			return new WP_Error( 'engine_missing', __( 'Pre-send gate is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$lead_id = absint( $arguments['lead_id'] ?? 0 );
		$email   = $lead_id ? (string) get_post_meta( $lead_id, 'email', true ) : '';

		$preview = WP_MCP_AI_CRM_Pre_Send_Gate::preview(
			array(
				'lead_id' => $lead_id,
				'channel' => sanitize_key( $arguments['channel'] ?? 'email' ),
				'kind'    => sanitize_key( $arguments['kind'] ?? 'sequence' ),
				'to'      => $email,
				'subject' => sanitize_text_field( $arguments['subject'] ?? '' ),
				'body'    => wp_kses_post( $arguments['body'] ?? '' ),
				'step'    => absint( $arguments['step'] ?? 0 ),
			)
		);

		return array(
			'success'    => true,
			'message'    => $preview['ok'] ? __( 'Draft passes the gate.', 'mcp-ai-wpoos-pro' ) : __( 'Draft is blocked.', 'mcp-ai-wpoos-pro' ),
			'ok'         => $preview['ok'],
			'reasons'    => $preview['reasons'],
			'warnings'   => $preview['warnings'],
			'normalized' => $preview['normalized'],
		);
	}
}
