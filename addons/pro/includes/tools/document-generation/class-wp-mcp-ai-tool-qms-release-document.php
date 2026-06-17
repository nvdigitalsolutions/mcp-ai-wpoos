<?php
/**
 * Tool: qms_release_document
 *
 * Transition an approved controlled document to `released` (live, in force).
 * Sets effective_date to today if not already set.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_QMS_Release_Document tool.
 */
class WP_MCP_AI_Tool_QMS_Release_Document implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'qms_release_document';
	}
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'QMS: Release Document', 'mcp-ai-wpoos-pro' );
	}
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Release an approved controlled document so it is in force. Requires a signed approval e-signature on the record. Sets the effective_date to today if not already set.', 'mcp-ai-wpoos-pro' );
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
				'post_id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'effective_date' => array(
					'type'    => 'string',
					'pattern' => '^\d{4}-\d{2}-\d{2}$',
				),
				'reason'         => array(
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, WP_MCP_AI_QMS_Capabilities::CAP ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) );
		}
		$post_id = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		if ( ! $post_id ) {
			return new WP_Error( 'wp_mcp_ai_invalid', __( 'post_id required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Set effective date.
		$existing_eff = (string) get_post_meta( $post_id, '_qms_effective_date', true );
		if ( ! $existing_eff ) {
			$eff = ! empty( $arguments['effective_date'] )
				? sanitize_text_field( $arguments['effective_date'] )
				: current_time( 'Y-m-d' );
			update_post_meta( $post_id, '_qms_effective_date', $eff );
		} elseif ( ! empty( $arguments['effective_date'] ) ) {
			update_post_meta( $post_id, '_qms_effective_date', sanitize_text_field( $arguments['effective_date'] ) );
		}

		$result = WP_MCP_AI_QMS_Workflow::transition(
			$post_id,
			WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED,
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
			'status'  => WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED,
			'record'  => WP_MCP_AI_QMS_Doc_Record_CPT::get_record( $post_id ),
		);
	}
}
