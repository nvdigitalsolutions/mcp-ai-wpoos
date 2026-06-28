<?php
/**
 * Tool: qms_submit_for_review
 *
 * Transition a controlled document from `draft` to `in_review`.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_QMS_Submit_For_Review tool.
 */
class WP_MCP_AI_Tool_QMS_Submit_For_Review implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'qms_submit_for_review';
	}
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'QMS: Submit for Review', 'mcp-ai-wpoos-pro' );
	}
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Transition a controlled document from draft to in_review state. At least one reviewer must be assigned.', 'mcp-ai-wpoos-pro' );
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
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'reason'  => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
			),
			'required'             => array( 'post_id' ),
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, WP_MCP_AI_QMS_Capabilities::CAP ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) );
		}
		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		if ( ! $post_id ) {
			return new WP_Error( 'wp_mcp_ai_invalid', __( 'post_id required.', 'mcp-ai-wpoos-pro' ) );
		}
		$result = WP_MCP_AI_QMS_Workflow::transition(
			$post_id,
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW,
			array(
				'actor_id' => $user_id,
				'reason'   => isset( $arguments['reason'] ) ? sanitize_textarea_field( $arguments['reason'] ) : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'success' => true,
			'post_id' => $post_id,
			'status'  => WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_IN_REVIEW,
		);
	}
}
