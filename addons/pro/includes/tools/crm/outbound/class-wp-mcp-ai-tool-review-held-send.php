<?php
/**
 * Review Held Send — list, approve or reject held outbox mail.
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
 * Held-mail review tool.
 *
 * @since 3.3.0
 */
class WP_MCP_AI_Tool_Review_Held_Send implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * Availability.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] ) && ! empty( $s['enable_outbound_booking_toolkit'] );
	}

	/**
	 * Unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM and Outbound Booking toolkits are required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'review_held_send';
	}

	/**
	 * Name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Review Held Send', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'List outbox messages held by the pre-send gate, or approve/reject one. Approving re-runs the gate and only sends when it passes.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Usage guidance.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'When the Outbox reports held mail, or after a gate/agent-guard block.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Previewing a draft; use preview_pre_send_gate. Sending directly; use send_lead_email.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'preview_pre_send_gate', 'outbound_pipeline' ),
			'notes'           => __( 'Held mail is never included in bulk approve. Approval re-checks the content.', 'mcp-ai-wpoos-pro' ),
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
				'action'    => array(
					'type'    => 'string',
					'enum'    => array( 'list', 'approve', 'reject' ),
					'default' => 'list',
				),
				'outbox_id' => array(
					'type'    => 'integer',
					'maximum' => WP_MCP_AI_Tool_Schema_Helper::MAX_SAFE_INTEGER,
				),
				'limit'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
					'default' => 20,
				),
			),
			'required'   => array( 'action' ),
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
		if ( ! class_exists( 'WP_MCP_AI_OA_Outbox' ) ) {
			return new WP_Error( 'engine_missing', __( 'Outbox is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$action = sanitize_key( $arguments['action'] ?? 'list' );

		if ( 'list' === $action ) {
			$limit = max( 1, min( 100, absint( $arguments['limit'] ?? 20 ) ) );
			$posts = get_posts(
				array(
					'post_type'      => WP_MCP_AI_OA_Outbox::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => $limit,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'   => '_oa_ob_status',
							'value' => 'held',
						),
					),
				)
			);

			$rows = array();
			foreach ( $posts as $outbox_id ) {
				$reasons = get_post_meta( $outbox_id, '_oa_ob_hold_reason', true );
				$rows[]  = array(
					'outbox_id' => (int) $outbox_id,
					'lead_id'   => (int) get_post_meta( $outbox_id, '_oa_ob_lead_id', true ),
					'lead'      => get_the_title( (int) get_post_meta( $outbox_id, '_oa_ob_lead_id', true ) ),
					'channel'   => get_post_meta( $outbox_id, '_oa_ob_channel', true ),
					'subject'   => get_post_meta( $outbox_id, '_oa_ob_subject', true ),
					'body'      => get_post_meta( $outbox_id, '_oa_ob_body', true ),
					'reasons'   => is_string( $reasons ) ? json_decode( $reasons, true ) : $reasons,
					'held_at'   => get_post_meta( $outbox_id, '_oa_ob_held_at', true ),
				);
			}

			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %d: held count */
					__( '%d held message(s).', 'mcp-ai-wpoos-pro' ),
					count( $rows )
				),
				'count'   => count( $rows ),
				'held'    => $rows,
			);
		}

		$outbox_id = absint( $arguments['outbox_id'] ?? 0 );
		if ( ! $outbox_id ) {
			return new WP_Error( 'missing_outbox', __( 'outbox_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( 'reject' === $action ) {
			WP_MCP_AI_OA_Outbox::set_status( $outbox_id, 'rejected' );
			return array(
				'success'   => true,
				'message'   => __( 'Held message rejected.', 'mcp-ai-wpoos-pro' ),
				'outbox_id' => $outbox_id,
			);
		}

		$result = WP_MCP_AI_OA_Outbox::approve_held( $outbox_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'   => true,
			'message'   => __( 'Held message approved and dispatched.', 'mcp-ai-wpoos-pro' ),
			'outbox_id' => $outbox_id,
		);
	}
}
