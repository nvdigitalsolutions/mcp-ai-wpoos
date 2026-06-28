<?php
/**
 * Tool for deleting prescriptions.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes a prescription.
 */
class WP_MCP_AI_Tool_Delete_Prescription implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delete_prescription';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delete Prescription', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Permanently deletes a prescription from the system.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prescription_id' => array(
					'type'        => 'integer',
					'description' => __( 'Prescription ID to delete (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'prescription_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'health_wellness',
			'post_type'             => 'mcp_ai_prescription',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'admin' ),
			'risk_level'            => 'high',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'delete_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete prescriptions.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate prescription ID.
		$prescription_id = isset( $arguments['prescription_id'] ) ? absint( $arguments['prescription_id'] ) : 0;

		if ( ! $prescription_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Prescription ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get prescription.
		$prescription = get_post( $prescription_id );

		if ( ! $prescription || 'mcp_ai_prescription' !== $prescription->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Prescription not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Delete the prescription.
		$result = wp_delete_post( $prescription_id, true );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete prescription.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success' => true,
			'message' => __( 'Prescription deleted successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
