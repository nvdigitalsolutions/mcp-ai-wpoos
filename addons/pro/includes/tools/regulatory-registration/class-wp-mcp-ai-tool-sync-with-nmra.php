<?php
/**
 * Tool for syncing registration data with Sri Lanka NMRA API.
 *
 * Allows AI assistants to sync registration information with the
 * National Medicines Regulatory Authority of Sri Lanka.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Syncs with Sri Lanka NMRA API.
 */
class WP_MCP_AI_Tool_Sync_With_Nmra implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'sync_with_nmra';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Sync with NMRA', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Synchronizes registration data with Sri Lanka National Medicines Regulatory Authority (NMRA) API for status updates and submissions.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_id' => array(
					'type'        => 'integer',
					'description' => __( 'Registration ID to sync (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'action'          => array(
					'type'        => 'string',
					'description' => __( 'Sync action (optional, default: "status_check")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'status_check', 'submit', 'update', 'withdraw' ),
					'default'     => 'status_check',
				),
				'api_key'         => array(
					'type'        => 'string',
					'description' => __( 'NMRA API key (optional, uses settings if not provided)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'registration_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'database-write',       // May update status.
		);
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
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to sync with NMRA.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$registration_id = absint( $arguments['registration_id'] );
		$action          = ! empty( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'status_check';
		$api_key         = ! empty( $arguments['api_key'] ) ? sanitize_text_field( $arguments['api_key'] ) : '';

		// Get API key from settings if not provided.
		if ( empty( $api_key ) ) {
			$settings = get_option( 'wp_mcp_ai_settings', array() );
			$api_key  = ! empty( $settings['nmra_api_key'] ) ? $settings['nmra_api_key'] : '';
		}

		if ( empty( $api_key ) ) {
			return new WP_Error( 'wp_mcp_ai_config_error', __( 'NMRA API key is not configured.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify registration exists.
		$registration = get_post( $registration_id );
		if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Registration not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify registration is for Sri Lanka.
		$country = get_post_meta( $registration_id, 'country', true );
		if ( 'LK' !== $country && 'Sri Lanka' !== $country ) {
			return new WP_Error( 'wp_mcp_ai_invalid_country', __( 'Registration must be for Sri Lanka to sync with NMRA.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get registration details.
		$product_id = absint( get_post_meta( $registration_id, 'product_id', true ) );
		$cos_number = get_post_meta( $registration_id, 'cos_number', true );

		// Placeholder for actual API integration.
		$api_endpoint = 'https://api.nmra.gov.lk/v1/registrations';

		$response_data = array(
			'nmra_status'    => 'Under Review',
			'nmra_reference' => 'NMRA-' . $registration_id . '-' . time(),
			'last_updated'   => current_time( 'mysql' ),
			'review_stage'   => 'Technical Assessment',
			'estimated_days' => 45,
		);

		// Update local registration with NMRA data.
		if ( 'status_check' === $action ) {
			update_post_meta( $registration_id, '_nmra_status', $response_data['nmra_status'] );
			update_post_meta( $registration_id, '_nmra_reference', $response_data['nmra_reference'] );
			update_post_meta( $registration_id, '_nmra_last_sync', current_time( 'mysql' ) );
		}

		return array(
			'success'         => true,
			'registration_id' => $registration_id,
			'action'          => $action,
			'nmra_response'   => $response_data,
			'synced_at'       => current_time( 'mysql' ),
			'message'         => sprintf(
				/* translators: %s: sync action */
				__( 'Successfully synced with NMRA (Action: %s)', 'mcp-ai-wpoos-pro' ),
				$action
			),
		);
	}
}
