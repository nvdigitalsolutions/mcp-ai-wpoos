<?php
/**
 * Tool for deleting prescriptions.
 *
 * Allows AI assistants to delete prescriptions from the health wellness system.
 *
 * @package WP_MCP_AI
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
		return __( 'Deletes a prescription from the health and wellness system. Only the prescription creator or users with delete_others_posts capability can delete prescriptions.', 'mcp-ai-wpoos-pro' );
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
				'force'           => array(
					'type'        => 'boolean',
					'description' => __( 'Force permanent deletion (bypass trash). Default: false', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'prescription_id' ),
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

		if ( ! $current_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to delete prescriptions.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$prescription_id = isset( $arguments['prescription_id'] ) ? absint( $arguments['prescription_id'] ) : 0;
		$force           = isset( $arguments['force'] ) ? (bool) $arguments['force'] : false;

		if ( ! $prescription_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_prescription_id', __( 'Prescription ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify prescription exists.
		$prescription = get_post( $prescription_id );

		if ( ! $prescription || 'mcp_ai_prescription' !== $prescription->post_type ) {
			return new WP_Error( 'wp_mcp_ai_prescription_not_found', __( 'Prescription not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$is_author         = absint( $prescription->post_author ) === $current_user_id;
		$can_delete_others = user_can( $current_user_id, 'delete_others_posts' );

		if ( ! $is_author && ! $can_delete_others ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to delete this prescription.', 'mcp-ai-wpoos-pro' ) );
		}

		// Delete the prescription.
		$result = wp_delete_post( $prescription_id, $force );

		if ( ! $result ) {
			return new WP_Error( 'wp_mcp_ai_delete_failed', __( 'Failed to delete prescription.', 'mcp-ai-wpoos-pro' ) );
		}

		return array(
			'success'         => true,
			'prescription_id' => $prescription_id,
			'message'         => sprintf(
				/* translators: 1: medication name, 2: action (deleted/trashed) */
				__( 'Prescription for "%1$s" has been %2$s.', 'mcp-ai-wpoos-pro' ),
				$prescription->post_title,
				$force ? __( 'permanently deleted', 'mcp-ai-wpoos-pro' ) : __( 'moved to trash', 'mcp-ai-wpoos-pro' )
			),
		);
	}
}
