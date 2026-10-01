<?php
/**
 * Outbound Manage Angle — create/update angle bank and objection matrix entries.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Outbound_Booking_Toolkit
 * @since 2.12.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outbound angle management tool.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_Tool_Outbound_Manage_Angle implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

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
		return 'outbound_manage_angle';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Outbound Angle', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create or update outbound angle variants and objection→response entries for sequences.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Adding copy angles to the angle bank or building the objection matrix for auto-replies.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Sending messages; use the CRM send tools or let the engine run sequences.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'create_outreach_sequence', 'update_outreach_sequence', 'outbound_get_pipeline_stats' ),
			'notes'           => __( 'Group copy variants with variant_of for weekly A/B tests; the winner is promoted to champion automatically.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'angle_id'    => array( 'type' => 'integer', 'default' => 0, 'description' => __( 'Angle post ID. Omit to create; provide to update.', 'mcp-ai-wpoos-pro' ) ),
				'name'        => array( 'type' => 'string' ),
				'kind'        => array( 'type' => 'string', 'enum' => WP_MCP_AI_OA_Angle_CPT::KINDS, 'default' => 'angle' ),
				'channel'     => array( 'type' => 'string', 'enum' => WP_MCP_AI_OA_Angle_CPT::CHANNELS, 'default' => 'any' ),
				'subject'     => array( 'type' => 'string' ),
				'first_line'  => array( 'type' => 'string' ),
				'body'        => array( 'type' => 'string' ),
				'objection'   => array( 'type' => 'string' ),
				'response'    => array( 'type' => 'string' ),
				'variant_of'  => array( 'type' => 'integer', 'default' => 0 ),
				'test_status' => array( 'type' => 'string', 'enum' => WP_MCP_AI_OA_Angle_CPT::TEST_STATUSES, 'default' => '' ),
			),
			'required'   => array( 'name' ),
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
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() );
		}
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$angle_id = isset( $arguments['angle_id'] ) ? absint( $arguments['angle_id'] ) : 0;
		$name     = sanitize_text_field( $arguments['name'] );
		$kind     = sanitize_key( $arguments['kind'] ?? 'angle' );
		if ( ! in_array( $kind, WP_MCP_AI_OA_Angle_CPT::KINDS, true ) ) {
			$kind = 'angle';
		}
		$channel = sanitize_key( $arguments['channel'] ?? 'any' );
		if ( ! in_array( $channel, WP_MCP_AI_OA_Angle_CPT::CHANNELS, true ) ) {
			$channel = 'any';
		}
		$test_status = sanitize_key( $arguments['test_status'] ?? '' );
		if ( ! in_array( $test_status, WP_MCP_AI_OA_Angle_CPT::TEST_STATUSES, true ) ) {
			$test_status = '';
		}
		$variant_of = isset( $arguments['variant_of'] ) ? absint( $arguments['variant_of'] ) : 0;

		if ( $angle_id ) {
			$existing = get_post( $angle_id );
			if ( ! $existing || WP_MCP_AI_OA_Angle_CPT::POST_TYPE !== $existing->post_type ) {
				return new WP_Error( 'not_found', __( 'Angle not found.', 'mcp-ai-wpoos-pro' ) );
			}
			wp_update_post(
				array(
					'ID'         => $angle_id,
					'post_title' => $name,
				)
			);
		} else {
			$angle_id = wp_insert_post(
				array(
					'post_type'   => WP_MCP_AI_OA_Angle_CPT::POST_TYPE,
					'post_title'  => $name,
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $angle_id ) ) {
				return $angle_id;
			}
		}

		update_post_meta( $angle_id, '_oa_angle_kind', $kind );
		update_post_meta( $angle_id, '_oa_angle_channel', $channel );
		update_post_meta( $angle_id, '_oa_angle_test_status', $test_status );
		update_post_meta( $angle_id, '_oa_angle_variant_of', $variant_of === $angle_id ? 0 : $variant_of );
		if ( isset( $arguments['subject'] ) ) {
			update_post_meta( $angle_id, '_oa_angle_subject', sanitize_text_field( $arguments['subject'] ) );
		}
		if ( isset( $arguments['first_line'] ) ) {
			update_post_meta( $angle_id, '_oa_angle_first_line', sanitize_text_field( $arguments['first_line'] ) );
		}
		if ( isset( $arguments['body'] ) ) {
			update_post_meta( $angle_id, '_oa_angle_body', sanitize_textarea_field( $arguments['body'] ) );
		}
		if ( isset( $arguments['objection'] ) ) {
			update_post_meta( $angle_id, '_oa_angle_objection', sanitize_text_field( $arguments['objection'] ) );
		}
		if ( isset( $arguments['response'] ) ) {
			update_post_meta( $angle_id, '_oa_angle_response', sanitize_textarea_field( $arguments['response'] ) );
		}

		return array(
			'success'  => true,
			'message'  => __( 'Angle saved.', 'mcp-ai-wpoos-pro' ),
			'angle_id' => $angle_id,
			'kind'     => $kind,
			'channel'  => $channel,
		);
	}
}
