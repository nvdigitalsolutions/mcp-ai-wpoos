<?php
/**
 * Send Lead DM — direct message via LinkedIn or chat-channels.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send a direct message via LinkedIn or existing chat-channels integration.
 * Stub — returns instructions.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Send_Lead_Dm implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'send_lead_dm';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Send Lead DM', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Send a direct message via LinkedIn or existing chat-channels integration. Stub — returns instructions.', 'mcp-ai-wpoos-pro' );
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
				'lead_id'  => array( 'type' => 'integer' ),
				'message'  => array( 'type' => 'string' ),
				'platform' => array(
					'type'    => 'string',
					'enum'    => array( 'linkedin', 'telegram', 'webchat' ),
					'default' => 'linkedin',
				),
			),
			'required'   => array( 'lead_id', 'message' ),
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
		return array( 'pro', 'outbound-network', 'database-write', 'requires-capability', 'requires-consent' );
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

		$platform    = sanitize_key( $arguments['platform'] ?? 'linkedin' );
		$lead_id     = absint( $arguments['lead_id'] );

		// Consent gate.
		if ( class_exists( 'WP_MCP_AI_CRM_Consent' ) && ! WP_MCP_AI_CRM_Consent::is_permitted( $lead_id, 'dm' ) ) {
			return new WP_Error( 'consent_required', __( 'Lead has not consented to direct message communication.', 'mcp-ai-wpoos-pro' ) );
		}

		// DNC gate.
		$email = get_post_meta( $lead_id, 'email', true );
		if ( $email && class_exists( 'WP_MCP_AI_CRM_Engine' ) && WP_MCP_AI_CRM_Engine::check_dnc( $email, 'dm' ) ) {
			return new WP_Error( 'dnc_blocked', __( 'Lead is on the Do Not Contact list.', 'mcp-ai-wpoos-pro' ) );
		}

		// Before-outbound-send hook.
		$veto = apply_filters( 'wp_mcp_ai_crm_before_outbound_send', null, $lead_id, 'dm', $context );
		if ( is_wp_error( $veto ) ) {
			return $veto;
		}

		// Suppression check.
		$block = apply_filters( 'wp_mcp_ai_crm_suppression_check', null, $lead_id, 'dm' );
		if ( is_wp_error( $block ) ) {
			return $block;
		}

		$activity_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_crm_activity',
				'post_title'  => sprintf(
					/* translators: %s: platform name */
					__( 'Sent %s DM', 'mcp-ai-wpoos-pro' ),
					ucfirst( $platform )
				),
				'post_status' => 'publish',
			),
			true
		);
		if ( ! is_wp_error( $activity_id ) ) {
			update_post_meta( $activity_id, 'activity_type', 'email' );
			update_post_meta( $activity_id, 'related_type', 'lead' );
			update_post_meta( $activity_id, 'related_id', $lead_id );
			update_post_meta( $activity_id, 'disposition', 'dm_sent' );
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'outbound_dm_sent', 'lead', $lead_id, array( 'platform' => $platform ) );
		}

		do_action( 'wp_mcp_ai_crm_after_outbound_send', $lead_id, 'dm', array( 'activity_id' => $activity_id ), $context );

		return array(
			'success'     => true,
			'message'     => sprintf(
				/* translators: %s: platform name */
				__( 'DM logged as activity (stub). Use the Chat Channels toolkit for %s delivery.', 'mcp-ai-wpoos-pro' ),
				$platform
			),
			'lead_id'     => $lead_id,
			'activity_id' => $activity_id,
		);
	}
}
