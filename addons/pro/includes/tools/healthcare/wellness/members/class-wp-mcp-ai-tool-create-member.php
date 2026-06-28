<?php
/**
 * Tool for creating members (people & pets).
 *
 * Allows AI assistants to create new members in the health wellness system.
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
 * Creates a new member (person or pet).
 */
class WP_MCP_AI_Tool_Create_Member implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_member';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Member', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new member (person or pet) or updates an existing one if member_id is provided. Members can have profiles with demographic info, contact details, and emergency contacts.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Optional member ID. If provided, updates the existing member instead of creating a new one.', 'mcp-ai-wpoos-pro' ),
				),
				'name'               => array(
					'type'        => 'string',
					'description' => __( 'Member name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'type'               => array(
					'type'        => 'string',
					'description' => __( 'Member type: person or pet (optional, defaults to person)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'person', 'pet' ),
					'default'     => 'person',
				),
				'description'        => array(
					'type'        => 'string',
					'description' => __( 'Additional notes or description about the member (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'date_of_birth'      => array(
					'type'        => 'string',
					'description' => __( 'Date of birth in ISO 8601 format (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'gender'             => array(
					'type'        => 'string',
					'description' => __( 'Gender (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'blood_type'         => array(
					'type'        => 'string',
					'description' => __( 'Blood type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', '' ),
				),
				'email'              => array(
					'type'        => 'string',
					'description' => __( 'Email address (optional)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'email',
				),
				'phone'              => array(
					'type'        => 'string',
					'description' => __( 'Phone number (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'address'            => array(
					'type'        => 'string',
					'description' => __( 'Physical address (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'emergency_contact'  => array(
					'type'        => 'string',
					'description' => __( 'Emergency contact information (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'species'            => array(
					'type'        => 'string',
					'description' => __( 'Species (for pets only) (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'breed'              => array(
					'type'        => 'string',
					'description' => __( 'Breed (for pets only) (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'mrn'                => array(
					'type'        => 'string',
					'description' => __( 'Medical Record Number (MRN) — internal or provider-assigned identifier (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'preferred_pharmacy' => array(
					'type'        => 'string',
					'description' => __( 'Preferred pharmacy name and/or address (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 300,
				),
			),
			'required'             => array( 'name' ),
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
			'post_type'             => 'mcp_ai_member',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'caregiver', 'patient' ),
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create members.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $current_user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check if this is an update operation.
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$is_update = false;

		if ( $member_id ) {
			// Verify member exists and user has permission to update it.
			$existing_member = get_post( $member_id );

			if ( ! $existing_member || 'mcp_ai_member' !== $existing_member->post_type ) {
				return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
			}

			// Check permissions: must be author or have edit_others_posts capability.
			$is_author       = absint( $existing_member->post_author ) === $current_user_id;
			$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

			if ( ! $is_author && ! $can_edit_others ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this member.', 'mcp-ai-wpoos-pro' ) );
			}

			$is_update = true;
		}

		// Validate and sanitize inputs.
		$name               = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		$type               = isset( $arguments['type'] ) ? sanitize_key( $arguments['type'] ) : 'person';
		$description        = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$date_of_birth      = isset( $arguments['date_of_birth'] ) ? sanitize_text_field( $arguments['date_of_birth'] ) : '';
		$gender             = isset( $arguments['gender'] ) ? sanitize_text_field( $arguments['gender'] ) : '';
		$blood_type         = isset( $arguments['blood_type'] ) ? sanitize_text_field( $arguments['blood_type'] ) : '';
		$email              = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';
		$phone              = isset( $arguments['phone'] ) ? sanitize_text_field( $arguments['phone'] ) : '';
		$address            = isset( $arguments['address'] ) ? sanitize_textarea_field( $arguments['address'] ) : '';
		$emergency_contact  = isset( $arguments['emergency_contact'] ) ? sanitize_textarea_field( $arguments['emergency_contact'] ) : '';
		$species            = isset( $arguments['species'] ) ? sanitize_text_field( $arguments['species'] ) : '';
		$breed              = isset( $arguments['breed'] ) ? sanitize_text_field( $arguments['breed'] ) : '';
		$mrn                = isset( $arguments['mrn'] ) ? sanitize_text_field( $arguments['mrn'] ) : '';
		$preferred_pharmacy = isset( $arguments['preferred_pharmacy'] ) ? sanitize_text_field( $arguments['preferred_pharmacy'] ) : '';

		if ( '' === $name ) {
			return new WP_Error( 'wp_mcp_ai_missing_name', __( 'Member name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate type.
		if ( ! in_array( $type, array( 'person', 'pet' ), true ) ) {
			$type = 'person';
		}

		// Validate date of birth.
		if ( $date_of_birth && ! $this->validate_date( $date_of_birth ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid date of birth format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate email.
		if ( $email && ! is_email( $email ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_email', __( 'Invalid email address.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $is_update ) {
			// Update existing member.
			$post_data = array(
				'ID'           => $member_id,
				'post_title'   => $name,
				'post_content' => $description,
			);

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			// Set member type taxonomy.
			wp_set_object_terms( $member_id, $type, 'mcp_ai_member_type' );

			// Update member metadata.
			if ( $date_of_birth ) {
				update_post_meta( $member_id, '_member_date_of_birth', $date_of_birth );
			}

			if ( $gender ) {
				update_post_meta( $member_id, '_member_gender', $gender );
			}

			if ( $blood_type ) {
				update_post_meta( $member_id, '_member_blood_type', $blood_type );
			}

			if ( $email ) {
				update_post_meta( $member_id, '_member_email', $email );
			}

			if ( $phone ) {
				update_post_meta( $member_id, '_member_phone', $phone );
			}

			if ( $address ) {
				update_post_meta( $member_id, '_member_address', $address );
			}

			if ( $emergency_contact ) {
				update_post_meta( $member_id, '_member_emergency_contact', $emergency_contact );
			}

			// Pet-specific fields.
			if ( 'pet' === $type ) {
				if ( $species ) {
					update_post_meta( $member_id, '_pet_species', $species );
				}

				if ( $breed ) {
					update_post_meta( $member_id, '_pet_breed', $breed );
				}
			}

			if ( $mrn ) {
				update_post_meta( $member_id, '_member_mrn', $mrn );
			}

			if ( $preferred_pharmacy ) {
				update_post_meta( $member_id, '_member_preferred_pharmacy', $preferred_pharmacy );
			}

			$member = get_post( $member_id );

			return array(
				'success'   => true,
				'message'   => __( 'Member updated successfully.', 'mcp-ai-wpoos-pro' ),
				'member_id' => $member_id,
				'member'    => array(
					'id'                 => $member_id,
					'name'               => $name,
					'type'               => $type,
					'description'        => $description,
					'date_of_birth'      => $date_of_birth,
					'gender'             => $gender,
					'blood_type'         => $blood_type,
					'email'              => $email,
					'phone'              => $phone,
					'address'            => $address,
					'emergency_contact'  => $emergency_contact,
					'species'            => $species,
					'breed'              => $breed,
					'mrn'                => $mrn,
					'preferred_pharmacy' => $preferred_pharmacy,
					'updated_at'         => $member->post_modified,
				),
				'updated'   => true,
			);
		} else {
			// Create member post.
			$post_data = array(
				'post_type'    => 'mcp_ai_member',
				'post_title'   => $name,
				'post_content' => $description,
				'post_status'  => 'publish',
				'post_author'  => $current_user_id,
			);

			$member_id = wp_insert_post( $post_data, true );

			if ( is_wp_error( $member_id ) ) {
				return $member_id;
			}

			// Set member type taxonomy.
			wp_set_object_terms( $member_id, $type, 'mcp_ai_member_type' );

			// Save member metadata.
			if ( $date_of_birth ) {
				update_post_meta( $member_id, '_member_date_of_birth', $date_of_birth );
			}

			if ( $gender ) {
				update_post_meta( $member_id, '_member_gender', $gender );
			}

			if ( $blood_type ) {
				update_post_meta( $member_id, '_member_blood_type', $blood_type );
			}

			if ( $email ) {
				update_post_meta( $member_id, '_member_email', $email );
			}

			if ( $phone ) {
				update_post_meta( $member_id, '_member_phone', $phone );
			}

			if ( $address ) {
				update_post_meta( $member_id, '_member_address', $address );
			}

			if ( $emergency_contact ) {
				update_post_meta( $member_id, '_member_emergency_contact', $emergency_contact );
			}

			// Pet-specific fields.
			if ( 'pet' === $type ) {
				if ( $species ) {
					update_post_meta( $member_id, '_pet_species', $species );
				}

				if ( $breed ) {
					update_post_meta( $member_id, '_pet_breed', $breed );
				}
			}

			if ( $mrn ) {
				update_post_meta( $member_id, '_member_mrn', $mrn );
			}

			if ( $preferred_pharmacy ) {
				update_post_meta( $member_id, '_member_preferred_pharmacy', $preferred_pharmacy );
			}

			$member = get_post( $member_id );

			return array(
				'success'   => true,
				'message'   => __( 'Member created successfully.', 'mcp-ai-wpoos-pro' ),
				'member_id' => $member_id,
				'member'    => array(
					'id'                 => $member_id,
					'name'               => $name,
					'type'               => $type,
					'description'        => $description,
					'date_of_birth'      => $date_of_birth,
					'gender'             => $gender,
					'blood_type'         => $blood_type,
					'email'              => $email,
					'phone'              => $phone,
					'address'            => $address,
					'emergency_contact'  => $emergency_contact,
					'species'            => $species,
					'breed'              => $breed,
					'mrn'                => $mrn,
					'preferred_pharmacy' => $preferred_pharmacy,
					'created_at'         => $member->post_date,
				),
				'updated'   => false,
			);
		}
	}

	/**
	 * Validate date format (YYYY-MM-DD).
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function validate_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
