<?php
/**
 * Tool for deleting medical records.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes a medical record.
 */
class WP_MCP_AI_Tool_Delete_Medical_Record implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_medical_record';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Medical Record', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Permanently deletes a medical record from the system.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'record_id' => array(
					'type'        => 'integer',
					'description' => __( 'Record ID to delete (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'record_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'destructive' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete medical records.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate record ID.
		$record_id = isset( $arguments['record_id'] ) ? absint( $arguments['record_id'] ) : 0;

		if ( ! $record_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Record ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get record.
		$record = get_post( $record_id );

		if ( ! $record || 'mcp_ai_med_record' !== $record->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Medical record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Delete the record.
		$result = wp_delete_post( $record_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete medical record.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success' => true,
			'message' => __( 'Medical record deleted successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
