<?php
/**
 * Tool: qms_supersede_document
 *
 * Mark a released controlled document as superseded by a new revision/record.
 * Links both records via _qms_supersedes / _qms_superseded_by meta.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_QMS_Supersede_Document tool.
 */
class WP_MCP_AI_Tool_QMS_Supersede_Document implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'qms_supersede_document';
	}
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'QMS: Supersede Document', 'mcp-ai-wpoos-pro' );
	}
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Mark a released controlled document as superseded by a new revision. The new record (successor_post_id) must already exist; the previous record is left in `superseded` state and linked.', 'mcp-ai-wpoos-pro' );
	}
		/**
		 * Get the parameters schema.
		 *
		 * @return array
		 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'           => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'successor_post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'reason'            => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
			),
			'required'             => array( 'post_id', 'successor_post_id' ),
			'additionalProperties' => false,
		);
	}
		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing' );
	}
	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WP_MCP_AI_QMS_Capabilities' ) && WP_MCP_AI_QMS_Capabilities::is_enabled();
	}
	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, WP_MCP_AI_QMS_Capabilities::CAP ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) );
		}
		$post_id   = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$successor = isset( $arguments['successor_post_id'] ) ? absint( $arguments['successor_post_id'] ) : 0;
		if ( ! $post_id || ! $successor || $post_id === $successor ) {
			return new WP_Error( 'wp_mcp_ai_invalid', __( 'Valid post_id and successor_post_id are required and must differ.', 'mcp-ai-wpoos-pro' ) );
		}
		$succ = get_post( $successor );
		if ( ! $succ || WP_MCP_AI_QMS_Doc_Record_CPT::POST_TYPE !== $succ->post_type ) {
			return new WP_Error( 'wp_mcp_ai_qms_invalid_successor', __( 'Successor must be a controlled document record.', 'mcp-ai-wpoos-pro' ) );
		}

		update_post_meta( $post_id, '_qms_superseded_by', $successor );
		update_post_meta( $successor, '_qms_supersedes', $post_id );

		$result = WP_MCP_AI_QMS_Workflow::transition(
			$post_id,
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_SUPERSEDED,
			array(
				'actor_id' => $user_id,
				'reason'   => isset( $arguments['reason'] ) ? sanitize_textarea_field( $arguments['reason'] ) : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'success'           => true,
			'post_id'           => $post_id,
			'successor_post_id' => $successor,
			'status'            => WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_SUPERSEDED,
		);
	}
}
