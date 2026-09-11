<?php
/**
 * Revoke Consent Tool (ecosystem port — Wave F2, CRM consent/DNC batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/crm/compliance/class-wp-mcp-ai-tool-revoke-consent.php`
 * for the standalone `nvoos-content-graph-pro` addon. Kept
 * byte-identical. The base Pro addon owns the class in monolith
 * installs — the addon's autoloader skips its copy when
 * `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`.
 *
 * @package NvoosContentGraphPro
 * @since     2.3.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Revokes consent for one or all channels and propagates to DNC list.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Revoke_Consent implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'CRM Toolkit required.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'revoke_consent';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Revoke Consent', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Revoke consent for one or all channels. Automatically propagates to DNC list and pauses active sequences. TCPA Apr 2025 FCC compliant.', 'nvoos-content-graph-pro' );
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
				'contact_id' => array( 'type' => 'integer' ),
				'channel'    => array(
					'type'        => 'string',
					'default'     => 'all',
					'description' => __( "'all' revokes every channel.", 'nvoos-content-graph-pro' ),
				),
			),
			'required'   => array( 'contact_id' ),
		);
	}

	/**
	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'manage_options';
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
		return array( 'pro', 'destructive', 'requires-capability', 'pii-access' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_CRM_Consent' ) ) {
			return new WP_Error( 'engine_missing', __( 'CRM Consent engine not available.', 'nvoos-content-graph-pro' ) );
		}
		$contact_id = absint( $arguments['contact_id'] );
		$channel    = sanitize_key( $arguments['channel'] ?? 'all' );
		$result     = WP_MCP_AI_CRM_Consent::revoke( $contact_id, $channel );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Also pause any active sequence for this contact.
		$active_seq = get_post_meta( $contact_id, '_active_sequence_id', true );
		if ( $active_seq ) {
			update_post_meta( $contact_id, '_sequence_paused', '1' );
			update_post_meta( $contact_id, '_sequence_paused_reason', 'consent_revoked' );
		}
		return array(
			'success'         => true,
			'message'         => __( 'Consent revoked. Contact removed from active sequences and added to DNC list.', 'nvoos-content-graph-pro' ),
			'contact_id'      => $contact_id,
			'channel'         => $channel,
			'sequence_paused' => (bool) $active_seq,
		);
	}
}
