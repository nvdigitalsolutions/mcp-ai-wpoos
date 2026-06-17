<?php
/**
 * Tool: qms_sign_document
 *
 * Apply a 21 CFR Part 11-friendly electronic signature (intent + identity +
 * binding hash) to a controlled document. Re-prompts for the signer's
 * password and binds the signature to the document content hash.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_QMS_Sign_Document tool.
 */
class WP_MCP_AI_Tool_QMS_Sign_Document implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'qms_sign_document';
	}
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'QMS: Sign Document', 'mcp-ai-wpoos-pro' );
	}
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Apply an electronic signature to a controlled document. The signer\'s password is required (re-authentication) and the signature is cryptographically bound to the current document content hash. Intent must be one of: reviewed, approved, witnessed.', 'mcp-ai-wpoos-pro' );
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
				'post_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'intent'   => array(
					'type' => 'string',
					'enum' => array( 'reviewed', 'approved', 'witnessed' ),
				),
				'password' => array(
					'type'      => 'string',
					'minLength' => 1,
				),
			),
			'required'             => array( 'post_id', 'intent', 'password' ),
			'additionalProperties' => false,
		);
	}
		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing', 'pii-data' );
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
		$post_id  = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$intent   = isset( $arguments['intent'] ) ? sanitize_key( $arguments['intent'] ) : '';
		$password = isset( $arguments['password'] ) ? (string) $arguments['password'] : '';
		if ( ! $post_id || '' === $intent || '' === $password ) {
			return new WP_Error( 'wp_mcp_ai_invalid', __( 'post_id, intent, and password are required.', 'mcp-ai-wpoos-pro' ) );
		}

		// For approval signatures, the signer must be an assigned approver.
		if ( 'approved' === $intent ) {
			$approvers = (array) ( get_post_meta( $post_id, '_qms_approver_ids', true ) ? get_post_meta( $post_id, '_qms_approver_ids', true ) : array() );
			if ( ! user_can( $user_id, 'manage_options' ) && ! in_array( $user_id, array_map( 'intval', $approvers ), true ) ) {
				return new WP_Error( 'wp_mcp_ai_qms_not_approver', __( 'Only assigned approvers may apply an approval signature.', 'mcp-ai-wpoos-pro' ) );
			}
		}
		if ( 'reviewed' === $intent ) {
			$reviewers = (array) ( get_post_meta( $post_id, '_qms_reviewer_ids', true ) ? get_post_meta( $post_id, '_qms_reviewer_ids', true ) : array() );
			if ( ! user_can( $user_id, 'manage_options' ) && ! in_array( $user_id, array_map( 'intval', $reviewers ), true ) ) {
				return new WP_Error( 'wp_mcp_ai_qms_not_reviewer', __( 'Only assigned reviewers may apply a reviewed signature.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		$result = WP_MCP_AI_QMS_Workflow::sign( $post_id, $intent, $user_id, $password );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'success'    => true,
			'post_id'    => $post_id,
			'intent'     => $intent,
			'message'    => __( 'Signature recorded.', 'mcp-ai-wpoos-pro' ),
			'signatures' => (array) ( get_post_meta( $post_id, '_qms_signatures', true ) ? get_post_meta( $post_id, '_qms_signatures', true ) : array() ),
		);
	}
}
