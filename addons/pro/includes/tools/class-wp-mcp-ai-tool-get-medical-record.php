<?php
/**
 * Tool for getting single medical record details.
 *
 * Allows AI assistants to retrieve detailed information about a specific medical record.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets detailed information for a single medical record.
 */
class WP_MCP_AI_Tool_Get_Medical_Record implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_medical_record';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Medical Record', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific medical record, including all notes and attachments.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Medical record ID (required)', 'mcp-ai-wpoos-pro' ),
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
		return array( 'pro', 'database-read' );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view medical records.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$record_id = isset( $arguments['record_id'] ) ? absint( $arguments['record_id'] ) : 0;

		if ( ! $record_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_record_id', __( 'Medical record ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify record exists.
		$record = get_post( $record_id );
		if ( ! $record || 'mcp_ai_medical_record' !== $record->post_type ) {
			return new WP_Error( 'wp_mcp_ai_record_not_found', __( 'Medical record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get record type.
		$types       = wp_get_object_terms( $record_id, 'mcp_ai_record_type', array( 'fields' => 'names' ) );
		$record_type = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : '';

		// Get member info.
		$member_id   = get_post_meta( $record_id, '_record_member_id', true );
		$member_name = '';
		if ( $member_id ) {
			$member      = get_post( $member_id );
			$member_name = $member ? $member->post_title : '';
		}

		// Build record data.
		$record_data = array(
			'id'          => $record_id,
			'title'       => $record->post_title,
			'type'        => $record_type,
			'member_id'   => $member_id,
			'member_name' => $member_name,
			'date'        => get_post_meta( $record_id, '_record_date', true ),
			'provider'    => get_post_meta( $record_id, '_record_provider', true ),
			'facility'    => get_post_meta( $record_id, '_record_facility', true ),
			'diagnosis'   => get_post_meta( $record_id, '_record_diagnosis', true ),
			'description' => $record->post_content,
			'attachments' => get_post_meta( $record_id, '_record_attachments', true ),
			'created_at'  => $record->post_date,
			'modified_at' => $record->post_modified,
			'author_id'   => absint( $record->post_author ),
		);

		return array(
			'success' => true,
			'record'  => $record_data,
		);
	}
}
