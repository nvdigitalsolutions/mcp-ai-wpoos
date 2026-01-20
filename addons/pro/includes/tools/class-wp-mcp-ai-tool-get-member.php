<?php
/**
 * Tool for getting a single member's details.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get details of a single member.
 */
class WP_MCP_AI_Tool_Get_Member implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_member';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Member', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Gets detailed information about a specific member (person or pet), including demographics, contact info, and emergency contacts.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Member ID to retrieve (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'member_id' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view members.', 'mcp-ai-wpoos-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$member = get_post( $member_id );

		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'Invalid member ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get member type.
		$types = wp_get_object_terms( $member_id, 'mcp_ai_member_type', array( 'fields' => 'slugs' ) );
		$type  = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : 'person';

		// Build member data.
		$member_data = array(
			'id'                => $member_id,
			'name'              => $member->post_title,
			'type'              => $type,
			'description'       => $member->post_content,
			'date_of_birth'     => get_post_meta( $member_id, '_member_date_of_birth', true ),
			'gender'            => get_post_meta( $member_id, '_member_gender', true ),
			'blood_type'        => get_post_meta( $member_id, '_member_blood_type', true ),
			'email'             => get_post_meta( $member_id, '_member_email', true ),
			'phone'             => get_post_meta( $member_id, '_member_phone', true ),
			'address'           => get_post_meta( $member_id, '_member_address', true ),
			'emergency_contact' => get_post_meta( $member_id, '_member_emergency_contact', true ),
			'created_at'        => $member->post_date,
			'modified_at'       => $member->post_modified,
		);

		// Add pet-specific fields.
		if ( 'pet' === $type ) {
			$member_data['species'] = get_post_meta( $member_id, '_pet_species', true );
			$member_data['breed']   = get_post_meta( $member_id, '_pet_breed', true );
		}

		return array(
			'success' => true,
			'member'  => $member_data,
		);
	}
}
