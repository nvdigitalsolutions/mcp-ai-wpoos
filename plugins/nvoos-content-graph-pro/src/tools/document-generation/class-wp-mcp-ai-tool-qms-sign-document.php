<?php
/**
 * WP_MCP_AI_Tool_QMS_Sign_Document (ecosystem port - Wave F2, document-generation tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/document-generation/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger/trait requires gain exists-check seams resolving from the addon's D8-compat `src/` copies; the monolith-gated openai/gemini/ollama client requires stay monolith-gated (architectural-design precedent).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
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
		return __( 'QMS: Sign Document', 'nvoos-content-graph-pro' );
	}
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Apply an electronic signature to a controlled document. The signer\'s password is required (re-authentication) and the signature is cryptographically bound to the current document content hash. Intent must be one of: reviewed, approved, witnessed.', 'nvoos-content-graph-pro' );
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, WP_MCP_AI_QMS_Capabilities::CAP ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'nvoos-content-graph-pro' ) );
		}
		$post_id  = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$intent   = isset( $arguments['intent'] ) ? sanitize_key( $arguments['intent'] ) : '';
		$password = isset( $arguments['password'] ) ? (string) $arguments['password'] : '';
		if ( ! $post_id || '' === $intent || '' === $password ) {
			return new WP_Error( 'wp_mcp_ai_invalid', __( 'post_id, intent, and password are required.', 'nvoos-content-graph-pro' ) );
		}

		// For approval signatures, the signer must be an assigned approver.
		if ( 'approved' === $intent ) {
			$approvers = (array) ( get_post_meta( $post_id, '_qms_approver_ids', true ) ? get_post_meta( $post_id, '_qms_approver_ids', true ) : array() );
			if ( ! user_can( $user_id, 'manage_options' ) && ! in_array( $user_id, array_map( 'intval', $approvers ), true ) ) {
				return new WP_Error( 'wp_mcp_ai_qms_not_approver', __( 'Only assigned approvers may apply an approval signature.', 'nvoos-content-graph-pro' ) );
			}
		}
		if ( 'reviewed' === $intent ) {
			$reviewers = (array) ( get_post_meta( $post_id, '_qms_reviewer_ids', true ) ? get_post_meta( $post_id, '_qms_reviewer_ids', true ) : array() );
			if ( ! user_can( $user_id, 'manage_options' ) && ! in_array( $user_id, array_map( 'intval', $reviewers ), true ) ) {
				return new WP_Error( 'wp_mcp_ai_qms_not_reviewer', __( 'Only assigned reviewers may apply a reviewed signature.', 'nvoos-content-graph-pro' ) );
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
			'message'    => __( 'Signature recorded.', 'nvoos-content-graph-pro' ),
			'signatures' => (array) ( get_post_meta( $post_id, '_qms_signatures', true ) ? get_post_meta( $post_id, '_qms_signatures', true ) : array() ),
		);
	}
}
