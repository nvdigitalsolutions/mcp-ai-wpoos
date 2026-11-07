<?php
/**
 * Collect Prospect Signals — free own-site signal collection.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collect signals from a lead's website.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_Tool_Collect_Prospect_Signals implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

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
		return 'collect_prospect_signals';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Collect Prospect Signals', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Collect confirmed signals from a lead\'s own website: booking gap, schema, ad pixels, tech stack, contact form, incumbent agency, hiring and blog staleness.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Usage guidance.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'After confirming signals in settings and before building a cohort or writing a personalised first line.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'When no signal has been confirmed; use the signal settings first.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'explain_prospect_fit', 'query_signal_cohort' ),
			'notes'           => __( 'Free: reads public pages only, no model call. Unconfirmed signals are never collected.', 'mcp-ai-wpoos-pro' ),
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
				'url'     => array(
					'type'        => 'string',
					'description' => __( 'Optional URL override. Defaults to the lead website meta.', 'mcp-ai-wpoos-pro' ),
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
		return array( 'pro', 'outbound-network', 'database-write', 'requires-capability' );
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
		if ( ! class_exists( 'WP_MCP_AI_CRM_Signal_Collector' ) ) {
			return new WP_Error( 'engine_missing', __( 'Signal collector is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$lead_id   = absint( $arguments['lead_id'] ?? 0 );
		$url       = isset( $arguments['url'] ) ? esc_url_raw( $arguments['url'] ) : '';
		$collected = WP_MCP_AI_CRM_Signal_Collector::collect(
			$lead_id,
			array( 'url' => $url )
		);

		return array(
			'success'   => true,
			'message'   => __( 'Signal collection complete.', 'mcp-ai-wpoos-pro' ),
			'lead_id'   => $lead_id,
			'collected' => $collected,
			'count'     => count( $collected ),
		);
	}
}
