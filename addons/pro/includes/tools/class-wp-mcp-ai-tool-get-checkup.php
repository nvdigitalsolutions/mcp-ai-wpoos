<?php
/**
 * Tool for getting single checkup details.
 *
 * Allows AI assistants to retrieve detailed information about a specific checkup.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets detailed information for a single checkup.
 */
class WP_MCP_AI_Tool_Get_Checkup implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_checkup';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Checkup', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific checkup/appointment, including all scheduling details and notes.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'checkup_id' => array(
					'type'        => 'integer',
					'description' => __( 'Checkup ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'checkup_id' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view checkups.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$checkup_id = isset( $arguments['checkup_id'] ) ? absint( $arguments['checkup_id'] ) : 0;

		if ( ! $checkup_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_checkup_id', __( 'Checkup ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify checkup exists.
		$checkup = get_post( $checkup_id );
		if ( ! $checkup || 'mcp_ai_checkup' !== $checkup->post_type ) {
			return new WP_Error( 'wp_mcp_ai_checkup_not_found', __( 'Checkup not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get member info.
		$member_id   = get_post_meta( $checkup_id, '_checkup_member_id', true );
		$member_name = '';
		if ( $member_id ) {
			$member = get_post( $member_id );
			$member_name = $member ? $member->post_title : '';
		}

		// Build checkup data.
		$checkup_data = array(
			'id'          => $checkup_id,
			'title'       => $checkup->post_title,
			'member_id'   => $member_id,
			'member_name' => $member_name,
			'date'        => get_post_meta( $checkup_id, '_checkup_date', true ),
			'time'        => get_post_meta( $checkup_id, '_checkup_time', true ),
			'provider'    => get_post_meta( $checkup_id, '_checkup_provider', true ),
			'location'    => get_post_meta( $checkup_id, '_checkup_location', true ),
			'status'      => get_post_meta( $checkup_id, '_checkup_status', true ),
			'notes'       => $checkup->post_content,
			'created_at'  => $checkup->post_date,
			'modified_at' => $checkup->post_modified,
			'author_id'   => absint( $checkup->post_author ),
		);

		return array(
			'success' => true,
			'checkup' => $checkup_data,
		);
	}
}
