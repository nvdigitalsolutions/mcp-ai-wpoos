<?php
/**
 * Tool: qms_get_audit_trail
 *
 * Read-only audit query. Retrieves audit log entries for a specific record,
 * document_id, or subsystem.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_QMS_Get_Audit_Trail tool.
 */
class WP_MCP_AI_Tool_QMS_Get_Audit_Trail implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'qms_get_audit_trail';
	}
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'QMS: Get Audit Trail', 'mcp-ai-wpoos-pro' );
	}
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Read-only query of the QMS/PARA immutable audit log. Filter by post_id, document_id, subsystem (qms or para), and event type.', 'mcp-ai-wpoos-pro' );
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
				'post_id'   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'doc_id'    => array(
					'type'      => 'string',
					'maxLength' => 64,
				),
				'subsystem' => array(
					'type' => 'string',
					'enum' => array( 'qms', 'para' ),
				),
				'event'     => array(
					'type'      => 'string',
					'maxLength' => 64,
				),
				'limit'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 500,
					'default' => 50,
				),
			),
			'additionalProperties' => false,
		);
	}
		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'paginated' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to read the audit trail.', 'mcp-ai-wpoos-pro' ) );
		}
		$rows = WP_MCP_AI_QMS_Audit_Log::query(
			array(
				'post_id'   => isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0,
				'doc_id'    => isset( $arguments['doc_id'] ) ? sanitize_text_field( $arguments['doc_id'] ) : '',
				'subsystem' => isset( $arguments['subsystem'] ) ? sanitize_key( $arguments['subsystem'] ) : '',
				'event'     => isset( $arguments['event'] ) ? sanitize_key( $arguments['event'] ) : '',
				'limit'     => isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50,
			)
		);
		return array(
			'success' => true,
			'count'   => count( $rows ),
			'entries' => $rows,
		);
	}
}
