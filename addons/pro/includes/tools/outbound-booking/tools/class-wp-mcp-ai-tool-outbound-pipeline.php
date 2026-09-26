<?php
/**
 * Outbound Pipeline Stats — funnel metrics for the outbound booking system.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Outbound_Booking_Toolkit
 * @since 2.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outbound pipeline stats tool.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_Tool_Outbound_Pipeline implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_outbound_booking_toolkit'] ) && ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'Outbound Booking Toolkit and CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'outbound_get_pipeline_stats';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Outbound Pipeline Stats', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Get outbound pipeline funnel stats: prospects, messaged, replied, positive replies, booked calls, and show-ups.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Checking how the outbound appointment-booking pipeline is performing or reporting on it.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Managing leads or sequences; use the CRM tools.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'outbound_import_leads', 'outbound_manage_angle', 'get_sequence_performance' ),
			'notes'           => __( 'Read-only; rates are computed from message/reply/booked states.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
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
		return array( 'pro', 'read-only', 'requires-capability' );
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
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		return array(
			'success' => true,
			'message' => __( 'Pipeline stats retrieved.', 'mcp-ai-wpoos-pro' ),
			'stats'   => WP_MCP_AI_OA_Engine::get_pipeline_stats(),
		);
	}
}
