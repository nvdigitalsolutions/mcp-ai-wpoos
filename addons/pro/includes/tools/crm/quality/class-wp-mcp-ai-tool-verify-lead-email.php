<?php
/**
 * Verify Lead Email — infer the domain pattern and verify one address.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verify one lead address.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_Tool_Verify_Lead_Email implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

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
	 * Slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'verify_lead_email';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Verify Lead Email', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Infer an address pattern from published addresses and verify one lead address, recording verified/risky/guess/invalid status. Never brute-forces name variations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Usage guidance.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Before enrolling a lead in a cold-email sequence, or when the address status is unknown or stale.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Checking the sending domain; use check_sending_domain. Previews of send eligibility; use preview_pre_send_gate.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'check_sending_domain', 'get_sending_readiness', 'preview_pre_send_gate' ),
			'notes'           => __( 'A verified status requires published evidence or a provider result. A guess is never treated as deliverable.', 'mcp-ai-wpoos-pro' ),
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
				'lead_id'   => array(
					'type'        => 'integer',
					'maximum'     => WP_MCP_AI_Tool_Schema_Helper::MAX_SAFE_INTEGER,
					'description' => __( 'Lead post ID.', 'mcp-ai-wpoos-pro' ),
				),
				'force'     => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Re-check even when a fresh status exists.', 'mcp-ai-wpoos-pro' ),
				),
				'published' => array(
					'type'        => 'array',
					'description' => __( 'Addresses the company publishes, used to infer the pattern.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'email' => array( 'type' => 'string' ),
							'first' => array( 'type' => 'string' ),
							'last'  => array( 'type' => 'string' ),
						),
					),
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
	 * Capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability', 'pii-access' );
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

		$lead_id   = absint( $arguments['lead_id'] ?? 0 );
		$force     = ! empty( $arguments['force'] );
		$published = array();
		if ( ! empty( $arguments['published'] ) && is_array( $arguments['published'] ) ) {
			foreach ( $arguments['published'] as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['email'] ) ) {
					continue;
				}
				$clean = array( 'email' => sanitize_email( $entry['email'] ) );
				if ( isset( $entry['first'] ) ) {
					$clean['first'] = sanitize_text_field( $entry['first'] );
				}
				if ( isset( $entry['last'] ) ) {
					$clean['last'] = sanitize_text_field( $entry['last'] );
				}
				$published[] = $clean;
			}
		}

		$result = WP_MCP_AI_CRM_Deliverability::verify(
			$lead_id,
			array(
				'force'     => $force,
				'published' => $published,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'    => true,
			'message'    => __( 'Address verification complete.', 'mcp-ai-wpoos-pro' ),
			'lead_id'    => $lead_id,
			'email'      => $result['email'],
			'status'     => $result['status'],
			'confidence' => $result['confidence'],
			'source'     => $result['source'],
			'pattern'    => $result['pattern'],
			'cached'     => ! empty( $result['cached'] ),
		);
	}
}
