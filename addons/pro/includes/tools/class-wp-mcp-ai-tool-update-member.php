<?php
/**
 * Tool for updating member information.
 *
 * Allows AI assistants to update existing members in the health wellness system.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing member (person or pet).
 */
class WP_MCP_AI_Tool_Update_Member implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_member';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Member', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing member (person or pet) in the health and wellness system. Only the member creator or users with edit_others_posts capability can update members.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'         => array(
					'type'        => 'integer',
					'description' => __( 'Member ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'name'              => array(
					'type'        => 'string',
					'description' => __( 'New member name (optional)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'type'              => array(
					'type'        => 'string',
					'description' => __( 'Member type: person or pet (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'person', 'pet' ),
				),
				'date_of_birth'     => array(
					'type'        => 'string',
					'description' => __( 'Date of birth in ISO 8601 format (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'gender'            => array(
					'type'        => 'string',
					'description' => __( 'Gender (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'blood_type'        => array(
					'type'        => 'string',
					'description' => __( 'Blood type (e.g., A+, O-, AB+) (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 10,
				),
				'email'             => array(
					'type'        => 'string',
					'description' => __( 'Email address (optional)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'email',
					'maxLength'   => 100,
				),
				'phone'             => array(
					'type'        => 'string',
					'description' => __( 'Phone number (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'address'           => array(
					'type'        => 'string',
					'description' => __( 'Physical address (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'emergency_contact' => array(
					'type'        => 'string',
					'description' => __( 'Emergency contact information (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'species'           => array(
					'type'        => 'string',
					'description' => __( 'Pet species (e.g., dog, cat, bird) - only for pet members (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'breed'             => array(
					'type'        => 'string',
					'description' => __( 'Pet breed - only for pet members (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
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
		return array( 'pro', 'database-write' );
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

		if ( ! $current_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to update members.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get member ID.
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );

		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions: must be author or have edit_others_posts capability.
		$is_author = absint( $member->post_author ) === $current_user_id;
		$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

		if ( ! $is_author && ! $can_edit_others ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this member.', 'mcp-ai-wpoos-pro' ) );
		}

		// Track what's being updated.
		$updated_fields = array();

		// Update name if provided.
		if ( isset( $arguments['name'] ) ) {
			$name = sanitize_text_field( $arguments['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'wp_mcp_ai_invalid_name', __( 'Member name cannot be empty.', 'mcp-ai-wpoos-pro' ) );
			}

			$result = wp_update_post(
		array(
				'ID'         => $member_id,
				'post_title' => $name,
			), true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$updated_fields[] = 'name';
		}

		// Update member type if provided.
		if ( isset( $arguments['type'] ) ) {
			$type = sanitize_key( $arguments['type'] );
			if ( ! in_array( $type, array( 'person', 'pet' ), true ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_type', __( 'Member type must be either "person" or "pet".', 'mcp-ai-wpoos-pro' ) );
			}

			wp_set_object_terms( $member_id, $type, 'mcp_ai_member_type' );
			$updated_fields[] = 'type';
		}

		// Update date of birth if provided.
		if ( isset( $arguments['date_of_birth'] ) ) {
			$dob = sanitize_text_field( $arguments['date_of_birth'] );
			// Validate date format.
			if ( ! empty( $dob ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dob ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Date of birth must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
			}
			update_post_meta( $member_id, '_member_date_of_birth', $dob );
			$updated_fields[] = 'date_of_birth';
		}

		// Update gender if provided.
		if ( isset( $arguments['gender'] ) ) {
			$gender = sanitize_text_field( $arguments['gender'] );
			update_post_meta( $member_id, '_member_gender', $gender );
			$updated_fields[] = 'gender';
		}

		// Update blood type if provided.
		if ( isset( $arguments['blood_type'] ) ) {
			$blood_type = sanitize_text_field( $arguments['blood_type'] );
			update_post_meta( $member_id, '_member_blood_type', $blood_type );
			$updated_fields[] = 'blood_type';
		}

		// Update email if provided.
		if ( isset( $arguments['email'] ) ) {
			$email = sanitize_email( $arguments['email'] );
			if ( ! empty( $email ) && ! is_email( $email ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_email', __( 'Invalid email address.', 'mcp-ai-wpoos-pro' ) );
			}
			update_post_meta( $member_id, '_member_email', $email );
			$updated_fields[] = 'email';
		}

		// Update phone if provided.
		if ( isset( $arguments['phone'] ) ) {
			$phone = sanitize_text_field( $arguments['phone'] );
			update_post_meta( $member_id, '_member_phone', $phone );
			$updated_fields[] = 'phone';
		}

		// Update address if provided.
		if ( isset( $arguments['address'] ) ) {
			$address = sanitize_textarea_field( $arguments['address'] );
			update_post_meta( $member_id, '_member_address', $address );
			$updated_fields[] = 'address';
		}

		// Update emergency contact if provided.
		if ( isset( $arguments['emergency_contact'] ) ) {
			$emergency_contact = sanitize_textarea_field( $arguments['emergency_contact'] );
			update_post_meta( $member_id, '_member_emergency_contact', $emergency_contact );
			$updated_fields[] = 'emergency_contact';
		}

		// Update pet-specific fields if provided.
		if ( isset( $arguments['species'] ) ) {
			$species = sanitize_text_field( $arguments['species'] );
			update_post_meta( $member_id, '_pet_species', $species );
			$updated_fields[] = 'species';
		}

		if ( isset( $arguments['breed'] ) ) {
			$breed = sanitize_text_field( $arguments['breed'] );
			update_post_meta( $member_id, '_pet_breed', $breed );
			$updated_fields[] = 'breed';
		}

		if ( empty( $updated_fields ) ) {
			return new WP_Error( 'wp_mcp_ai_no_updates', __( 'No fields were provided to update.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get updated member data.
		$updated_member = get_post( $member_id );
		$types          = wp_get_object_terms( $member_id, 'mcp_ai_member_type', array( 'fields' => 'slugs' ) );
		$member_type    = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : 'person';

		$member_data = array(
			'id'                => $member_id,
			'name'              => $updated_member->post_title,
			'type'              => $member_type,
			'date_of_birth'     => get_post_meta( $member_id, '_member_date_of_birth', true ),
			'gender'            => get_post_meta( $member_id, '_member_gender', true ),
			'blood_type'        => get_post_meta( $member_id, '_member_blood_type', true ),
			'email'             => get_post_meta( $member_id, '_member_email', true ),
			'phone'             => get_post_meta( $member_id, '_member_phone', true ),
			'address'           => get_post_meta( $member_id, '_member_address', true ),
			'emergency_contact' => get_post_meta( $member_id, '_member_emergency_contact', true ),
			'modified_at'       => $updated_member->post_modified,
		);

		// Add pet-specific fields if pet.
		if ( 'pet' === $member_type ) {
			$member_data['species'] = get_post_meta( $member_id, '_pet_species', true );
			$member_data['breed']   = get_post_meta( $member_id, '_pet_breed', true );
		}

		return array(
			'success'        => true,
			'member'         => $member_data,
			'updated_fields' => $updated_fields,
		);
	}
}
