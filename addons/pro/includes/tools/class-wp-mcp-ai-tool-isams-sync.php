<?php
/**
 * Tool for iSAMS/SOCS integration.
 *
 * Allows AI assistants to synchronize ECA data with iSAMS and SOCS booking system.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronizes ECA data with iSAMS and SOCS.
 */
class WP_MCP_AI_Tool_ISAMS_Sync implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'isams_sync';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'iSAMS/SOCS Sync', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Synchronizes ECA and booking data with iSAMS school management system and SOCS online booking platform. Supports importing student data, syncing ECA details, and managing booking allocations.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'            => array(
					'type'        => 'string',
					'description' => __( 'Sync action to perform (required)', 'wp-mcp-ai' ),
					'enum'        => array( 'import_students', 'export_ecas', 'import_bookings', 'sync_allocations', 'test_connection' ),
				),
				'isams_api_url'     => array(
					'type'        => 'string',
					'description' => __( 'iSAMS API endpoint URL (optional, uses saved settings if not provided)', 'wp-mcp-ai' ),
					'format'      => 'uri',
				),
				'isams_api_key'     => array(
					'type'        => 'string',
					'description' => __( 'iSAMS API key (optional, uses saved settings if not provided)', 'wp-mcp-ai' ),
					'maxLength'   => 200,
				),
				'socs_school_id'    => array(
					'type'        => 'string',
					'description' => __( 'SOCS school identifier (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 100,
				),
				'term'              => array(
					'type'        => 'string',
					'description' => __( 'Academic term (e.g., "Lent 2026") (optional)', 'wp-mcp-ai' ),
					'maxLength'   => 100,
				),
				'year_groups'       => array(
					'type'        => 'array',
					'description' => __( 'Filter by year groups for import (optional)', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'dry_run'           => array(
					'type'        => 'boolean',
					'description' => __( 'Perform validation only without making changes (default: false)', 'wp-mcp-ai' ),
					'default'     => false,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'external-api', 'database-write' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// ECA management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_eca_management'] );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to perform iSAMS sync operations.', 'wp-mcp-ai' ) );
		}

		$action  = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';
		$dry_run = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : false;

		if ( ! $action ) {
			return new WP_Error( 'wp_mcp_ai_missing_action', __( 'Action is required.', 'wp-mcp-ai' ) );
		}

		// Get API credentials from arguments or settings.
		$isams_api_url = isset( $arguments['isams_api_url'] ) ? esc_url_raw( $arguments['isams_api_url'] ) : '';
		$isams_api_key = isset( $arguments['isams_api_key'] ) ? sanitize_text_field( $arguments['isams_api_key'] ) : '';

		if ( ! $isams_api_url || ! $isams_api_key ) {
			$settings      = get_option( 'wp_mcp_ai_eca_settings', array() );
			$isams_api_url = $isams_api_url ?: ( $settings['isams_api_url'] ?? '' );
			$isams_api_key = $isams_api_key ?: ( $settings['isams_api_key'] ?? '' );
		}

		switch ( $action ) {
			case 'test_connection':
				return $this->test_connection( $isams_api_url, $isams_api_key );
			case 'import_students':
				return $this->import_students( $arguments, $isams_api_url, $isams_api_key, $dry_run );
			case 'export_ecas':
				return $this->export_ecas( $arguments, $isams_api_url, $isams_api_key, $dry_run );
			case 'import_bookings':
				return $this->import_bookings( $arguments, $isams_api_url, $isams_api_key, $dry_run );
			case 'sync_allocations':
				return $this->sync_allocations( $arguments, $isams_api_url, $isams_api_key, $dry_run );
			default:
				return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid sync action.', 'wp-mcp-ai' ) );
		}
	}

	/**
	 * Test connection to iSAMS API.
	 *
	 * @param string $api_url API URL.
	 * @param string $api_key API key.
	 * @return array|WP_Error
	 */
	private function test_connection( $api_url, $api_key ) {
		if ( ! $api_url || ! $api_key ) {
			return new WP_Error( 'wp_mcp_ai_missing_credentials', __( 'iSAMS API URL and API key are required.', 'wp-mcp-ai' ) );
		}

		// This is a placeholder - actual implementation would make real API call.
		return array(
			'success' => true,
			'message' => __( 'iSAMS connection test successful (placeholder implementation).', 'wp-mcp-ai' ),
			'details' => array(
				'api_url'   => $api_url,
				'connected' => true,
				'note'      => 'This is a mock response. In production, this would make a real API call to iSAMS.',
			),
		);
	}

	/**
	 * Import students from iSAMS.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_url   API URL.
	 * @param string $api_key   API key.
	 * @param bool   $dry_run   Dry run flag.
	 * @return array|WP_Error
	 */
	private function import_students( $arguments, $api_url, $api_key, $dry_run ) {
		if ( ! $api_url || ! $api_key ) {
			return new WP_Error( 'wp_mcp_ai_missing_credentials', __( 'iSAMS API credentials are required.', 'wp-mcp-ai' ) );
		}

		$year_groups = isset( $arguments['year_groups'] ) && is_array( $arguments['year_groups'] ) ? $arguments['year_groups'] : array();

		// Placeholder implementation.
		$mock_students = array(
			array(
				'id'         => '12345',
				'name'       => 'John Smith',
				'email'      => 'john.smith@example.com',
				'year_group' => 'Year 7',
			),
			array(
				'id'         => '12346',
				'name'       => 'Jane Doe',
				'email'      => 'jane.doe@example.com',
				'year_group' => 'Year 8',
			),
		);

		return array(
			'success'  => true,
			'message'  => sprintf( __( 'Imported %d students from iSAMS (placeholder).', 'wp-mcp-ai' ), count( $mock_students ) ),
			'dry_run'  => $dry_run,
			'imported' => $dry_run ? 0 : count( $mock_students ),
			'students' => $mock_students,
		);
	}

	/**
	 * Export ECAs to iSAMS/SOCS.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_url   API URL.
	 * @param string $api_key   API key.
	 * @param bool   $dry_run   Dry run flag.
	 * @return array|WP_Error
	 */
	private function export_ecas( $arguments, $api_url, $api_key, $dry_run ) {
		if ( ! $api_url || ! $api_key ) {
			return new WP_Error( 'wp_mcp_ai_missing_credentials', __( 'iSAMS API credentials are required.', 'wp-mcp-ai' ) );
		}

		// Query ECAs.
		$query_args = array(
			'post_type'      => 'mcp_ai_eca',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		);

		$query = new WP_Query( $query_args );
		$count = $query->found_posts;

		if ( ! $dry_run ) {
			// Placeholder - would make real API calls here.
		}

		return array(
			'success'  => true,
			'message'  => sprintf( __( 'Exported %d ECAs to iSAMS/SOCS (placeholder).', 'wp-mcp-ai' ), $count ),
			'dry_run'  => $dry_run,
			'exported' => $dry_run ? 0 : $count,
			'total'    => $count,
		);
	}

	/**
	 * Import bookings from SOCS.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_url   API URL.
	 * @param string $api_key   API key.
	 * @param bool   $dry_run   Dry run flag.
	 * @return array|WP_Error
	 */
	private function import_bookings( $arguments, $api_url, $api_key, $dry_run ) {
		if ( ! $api_url || ! $api_key ) {
			return new WP_Error( 'wp_mcp_ai_missing_credentials', __( 'iSAMS API credentials are required.', 'wp-mcp-ai' ) );
		}

		// Placeholder implementation.
		$mock_count = 25;

		return array(
			'success'  => true,
			'message'  => sprintf( __( 'Imported %d bookings from SOCS (placeholder).', 'wp-mcp-ai' ), $mock_count ),
			'dry_run'  => $dry_run,
			'imported' => $dry_run ? 0 : $mock_count,
		);
	}

	/**
	 * Sync booking allocations with SOCS.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_url   API URL.
	 * @param string $api_key   API key.
	 * @param bool   $dry_run   Dry run flag.
	 * @return array|WP_Error
	 */
	private function sync_allocations( $arguments, $api_url, $api_key, $dry_run ) {
		if ( ! $api_url || ! $api_key ) {
			return new WP_Error( 'wp_mcp_ai_missing_credentials', __( 'iSAMS API credentials are required.', 'wp-mcp-ai' ) );
		}

		// Placeholder implementation.
		$mock_count = 18;

		return array(
			'success' => true,
			'message' => sprintf( __( 'Synced %d booking allocations with SOCS (placeholder).', 'wp-mcp-ai' ), $mock_count ),
			'dry_run' => $dry_run,
			'synced'  => $dry_run ? 0 : $mock_count,
		);
	}
}
