<?php
/**
 * Tool: qms_mark_obsolete
 *
 * Mark a controlled document as obsolete. Cross-bridge moves it to PARA archives.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_MCP_AI_Tool_QMS_Mark_Obsolete implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	public function get_slug() {
		return 'qms_mark_obsolete';
	}
	public function get_name() {
		return __( 'QMS: Mark Obsolete', 'mcp-ai-wpoos-pro' );
	}
	public function get_description() {
		return __( 'Mark a controlled document as obsolete. The record is preserved (never destroyed) and moved to PARA archives if PARA is enabled.', 'mcp-ai-wpoos-pro' );
	}
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'reason'  => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ),
			),
			'required'             => array( 'post_id', 'reason' ),
			'additionalProperties' => false,
		);
	}
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing' );
	}
	public static function is_available() {
		return class_exists( 'WP_MCP_AI_QMS_Capabilities' ) && WP_MCP_AI_QMS_Capabilities::is_enabled();
	}
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, WP_MCP_AI_QMS_Capabilities::CAP ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) );
		}
		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$reason  = isset( $arguments['reason'] ) ? sanitize_textarea_field( $arguments['reason'] ) : '';
		if ( ! $post_id || '' === $reason ) {
			return new WP_Error( 'wp_mcp_ai_invalid', __( 'post_id and reason are required.', 'mcp-ai-wpoos-pro' ) );
		}
		$result = WP_MCP_AI_QMS_Workflow::transition(
			$post_id,
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_OBSOLETE,
			array( 'actor_id' => $user_id, 'reason' => $reason )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'success' => true,
			'post_id' => $post_id,
			'status'  => WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_OBSOLETE,
		);
	}
}
