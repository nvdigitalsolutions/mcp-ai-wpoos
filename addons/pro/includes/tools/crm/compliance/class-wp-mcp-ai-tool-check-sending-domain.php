<?php
/**
 * Check Sending Domain — SPF, DKIM and DMARC readiness.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check a sending domain's authentication records.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_Tool_Check_Sending_Domain implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

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
		return 'check_sending_domain';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Check Sending Domain', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Check SPF, DKIM and DMARC records for the sending domain and report READY/NOT READY with reasons.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Usage guidance.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Before enabling auto-send, after changing DNS, or when mail starts landing in spam.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Per-lead address checks; use verify_lead_email. Full readiness; use get_sending_readiness.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'verify_lead_email', 'get_sending_readiness', 'manage_sending_warmup' ),
			'notes'           => __( 'Reads public DNS only; caches for 6 hours. A resolver failure is reported as unknown, never as a hard failure.', 'mcp-ai-wpoos-pro' ),
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
				'domain'   => array(
					'type'        => 'string',
					'description' => __( 'Domain to check. Defaults to the configured sending domain.', 'mcp-ai-wpoos-pro' ),
				),
				'selector' => array(
					'type'        => 'string',
					'description' => __( 'DKIM selector. Defaults to the configured selector.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array(),
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
		return array( 'pro', 'outbound-network', 'requires-capability' );
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

		$settings = WP_MCP_AI_CRM_Deliverability::get_settings();
		$domain   = isset( $arguments['domain'] ) ? sanitize_text_field( wp_unslash( $arguments['domain'] ) ) : '';
		if ( ! $domain ) {
			$domain = (string) $settings['sending_domain'];
		}
		if ( ! $domain ) {
			$from   = (string) get_option( 'admin_email', '' );
			$domain = $from && is_email( $from ) ? substr( strrchr( $from, '@' ), 1 ) : '';
		}

		$selector = isset( $arguments['selector'] ) ? sanitize_key( $arguments['selector'] ) : '';
		$result   = WP_MCP_AI_CRM_Deliverability::check_domain( $domain, $selector );

		return array(
			'success'  => true,
			'message'  => ! empty( $result['ready'] )
				? __( 'Sending domain is ready.', 'mcp-ai-wpoos-pro' )
				: __( 'Sending domain is not ready.', 'mcp-ai-wpoos-pro' ),
			'domain'   => $result['domain'],
			'ready'    => ! empty( $result['ready'] ),
			'checks'   => $result['checks'],
			'blockers' => $result['blockers'],
			'warnings' => $result['warnings'],
		);
	}
}
