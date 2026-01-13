<?php
/**
 * Tool for getting single member details.
 *
 * Allows AI assistants to retrieve detailed information about a specific member.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets detailed information for a single member.
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
		return __( 'Retrieves detailed information about a specific member (person or pet), including demographics, contact details, and emergency information.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Member ID (required)', 'mcp-ai-wpoos-pro' ),
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
		// Health and Wellness management is a Pro feature.
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

		// Validate inputs.
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get member type.
		$types       = wp_get_object_terms( $member_id, 'mcp_ai_member_type', array( 'fields' => 'slugs' ) );
		$member_type = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : 'person';

		// Build member data.
		$member_data = array(
			'id'                => $member_id,
			'name'              => $member->post_title,
			'type'              => $member_type,
			'date_of_birth'     => get_post_meta( $member_id, '_member_date_of_birth', true ),
			'gender'            => get_post_meta( $member_id, '_member_gender', true ),
			'blood_type'        => get_post_meta( $member_id, '_member_blood_type', true ),
			'email'             => get_post_meta( $member_id, '_member_email', true ),
			'phone'             => get_post_meta( $member_id, '_member_phone', true ),
			'address'           => get_post_meta( $member_id, '_member_address', true ),
			'emergency_contact' => get_post_meta( $member_id, '_member_emergency_contact', true ),
			'created_at'        => $member->post_date,
			'modified_at'       => $member->post_modified,
			'author_id'         => absint( $member->post_author ),
		);

		// Add pet-specific fields.
		if ( 'pet' === $member_type ) {
			$member_data['species'] = get_post_meta( $member_id, '_pet_species', true );
			$member_data['breed']   = get_post_meta( $member_id, '_pet_breed', true );
		}

		// Get related counts.
		$allergies_count       = $this->get_related_count( $member_id, 'mcp_ai_allergy', '_allergy_member_id' );
		$prescriptions_count   = $this->get_related_count( $member_id, 'mcp_ai_prescription', '_prescription_member_id' );
		$checkups_count        = $this->get_related_count( $member_id, 'mcp_ai_checkup', '_checkup_member_id' );
		$medical_records_count = $this->get_related_count( $member_id, 'mcp_ai_medical_record', '_record_member_id' );
		$policies_count        = $this->get_related_count( $member_id, 'mcp_ai_policy', '_policy_member_id' );

		$member_data['related_records'] = array(
			'allergies'       => $allergies_count,
			'prescriptions'   => $prescriptions_count,
			'checkups'        => $checkups_count,
			'medical_records' => $medical_records_count,
			'policies'        => $policies_count,
		);

		return array(
			'success' => true,
			'member'  => $member_data,
		);
	}

	/**
	 * Get count of related records for a member.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $post_type Post type to count.
	 * @param string $meta_key  Meta key that stores member ID.
	 * @return int Count of related records.
	 */
	private function get_related_count( $member_id, $post_type, $meta_key ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'meta_key'       => $meta_key,
				'meta_value'     => $member_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		return (int) $query->found_posts;
	}
}
