<?php
/**
 * Get Sending Readiness — composite go/no-go for outbound.
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
 * Readiness snapshot.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_Tool_Get_Sending_Readiness implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

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
		return 'get_sending_readiness';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Get Sending Readiness', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Return the composite sending-readiness score: domain authentication, warmup progress, bounce/complaint rates, verified coverage and gate settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Usage guidance.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Before enabling auto-send, during onboarding, or when deciding whether a campaign may launch.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Checking one record; use check_sending_domain or verify_lead_email.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'check_sending_domain', 'manage_sending_warmup', 'preview_pre_send_gate' ),
			'notes'           => __( 'A non-empty blockers list means auto-send must not start. Warnings are advisory.', 'mcp-ai-wpoos-pro' ),
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
			'properties' => array(),
			'required'   => array(),
		);
	}

	/**
	 * Capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'manage_options';
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
		return array( 'pro', 'requires-capability' );
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
		if ( ! class_exists( 'WP_MCP_AI_CRM_Deliverability' ) ) {
			return new WP_Error( 'engine_missing', __( 'Deliverability engine is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$readiness = WP_MCP_AI_CRM_Deliverability::readiness();

		return array(
			'success'  => true,
			'message'  => ! empty( $readiness['ready'] )
				? __( 'Ready to send.', 'mcp-ai-wpoos-pro' )
				: __( 'Not ready to send.', 'mcp-ai-wpoos-pro' ),
			'score'    => $readiness['score'],
			'ready'    => $readiness['ready'],
			'blockers' => $readiness['blockers'],
			'warnings' => $readiness['warnings'],
			'checks'   => $readiness['checks'],
		);
	}
}
