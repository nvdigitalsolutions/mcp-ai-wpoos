<?php
/**
 * Process Opt-Out — channel-specific or global opt-out.
 *
 * @package   WP_MCP_AI_Pro
 * @since     2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Processes an opt-out request: adds to DNC list, revokes consent, and logs.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Process_Opt_Out implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * Reason the tool is unavailable.
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
		return 'process_opt_out';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Process Opt-Out', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Process an opt-out request: add to DNC list, revoke consent, and log for compliance.', 'mcp-ai-wpoos-pro' );
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
				'identifier' => array(
					'type'        => 'string',
					'description' => __( 'Email address or phone number.', 'mcp-ai-wpoos-pro' ),
				),
				'channel'    => array(
					'type'    => 'string',
					'default' => 'all',
				),
				'reason'     => array(
					'type'    => 'string',
					'default' => 'user_request',
				),
			),
			'required'   => array( 'identifier' ),
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
	 * Whether the tool requires Base Pro.
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
		$identifier = strtolower( trim( sanitize_text_field( $arguments['identifier'] ) ) );
		$channel    = sanitize_key( $arguments['channel'] ?? 'all' );
		$reason     = sanitize_key( $arguments['reason'] ?? 'user_request' );
		if ( ! class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			return new WP_Error( 'engine_missing', __( 'CRM Engine not available.', 'mcp-ai-wpoos-pro' ) );
		}
		WP_MCP_AI_CRM_Engine::add_to_dnc( $identifier, $channel );
		// If email, find matching contact and revoke consent.
		if ( strpos( $identifier, '@' ) !== false ) {
			$q = new WP_Query(
				array(
					'post_type'      => array( 'mcp_ai_lead', 'mcp_crm_contacts' ),
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'   => 'email',
							'value' => $identifier,
						),
					),
					'no_found_rows'  => true,
				)
			);
			if ( $q->have_posts() && class_exists( 'WP_MCP_AI_CRM_Consent' ) ) {
				WP_MCP_AI_CRM_Consent::revoke( $q->posts[0], $channel );
			}
		}
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'opt_out_processed',
				'dnc',
				0,
				array(
					'identifier' => $identifier,
					'channel'    => $channel,
					'reason'     => $reason,
				)
			);
		}
		return array(
			'success'    => true,
			'message'    => __( 'Opt-out processed.', 'mcp-ai-wpoos-pro' ),
			'identifier' => $identifier,
			'channel'    => $channel,
		);
	}
}
