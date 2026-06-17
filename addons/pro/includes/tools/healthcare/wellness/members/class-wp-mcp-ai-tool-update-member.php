<?php
/**
 * Tool for updating members (people & pets).
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
 * Updates an existing member.
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
		return __( 'Updates an existing member. Provide only the fields you want to update.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Member ID to update (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'name'              => array(
					'type'        => 'string',
					'description' => __( 'New member name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'type'              => array(
					'type'        => 'string',
					'description' => __( 'New member type: person or pet (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'person', 'pet' ),
				),
				'description'       => array(
					'type'        => 'string',
					'description' => __( 'New description (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'date_of_birth'     => array(
					'type'        => 'string',
					'description' => __( 'New date of birth (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'gender'            => array(
					'type'        => 'string',
					'description' => __( 'New gender (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'blood_type'        => array(
					'type'        => 'string',
					'description' => __( 'New blood type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', '' ),
				),
				'email'             => array(
					'type'        => 'string',
					'description' => __( 'New email address (optional)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'email',
				),
				'phone'             => array(
					'type'        => 'string',
					'description' => __( 'New phone number (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'address'           => array(
					'type'        => 'string',
					'description' => __( 'New physical address (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'emergency_contact' => array(
					'type'        => 'string',
					'description' => __( 'New emergency contact information (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'species'           => array(
					'type'        => 'string',
					'description' => __( 'New species (for pets only) (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'breed'             => array(
					'type'        => 'string',
					'description' => __( 'New breed (for pets only) (optional)', 'mcp-ai-wpoos-pro' ),
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
			'profession_tags'       => array( 'healthcare_provider', 'caregiver' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update members.', 'mcp-ai-wpoos-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$member = get_post( $member_id );

		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'Invalid member ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Prepare post data update.
		$post_data = array( 'ID' => $member_id );

		if ( isset( $arguments['name'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $arguments['name'] );
		}

		if ( isset( $arguments['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $arguments['description'] );
		}

		// Update post if we have changes.
		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Update member type if provided.
		if ( isset( $arguments['type'] ) ) {
			$type = sanitize_key( $arguments['type'] );
			if ( in_array( $type, array( 'person', 'pet' ), true ) ) {
				wp_set_object_terms( $member_id, $type, 'mcp_ai_member_type' );
			}
		}

		// Update metadata fields.
		$meta_fields = array(
			'date_of_birth'     => '_member_date_of_birth',
			'gender'            => '_member_gender',
			'blood_type'        => '_member_blood_type',
			'email'             => '_member_email',
			'phone'             => '_member_phone',
			'address'           => '_member_address',
			'emergency_contact' => '_member_emergency_contact',
			'species'           => '_pet_species',
			'breed'             => '_pet_breed',
		);

		foreach ( $meta_fields as $arg_key => $meta_key ) {
			if ( isset( $arguments[ $arg_key ] ) ) {
				$value = $arguments[ $arg_key ];

				// Sanitize based on field type.
				if ( 'email' === $arg_key ) {
					$value = sanitize_email( $value );
					if ( $value && ! is_email( $value ) ) {
						return new WP_Error( 'wp_mcp_ai_invalid_email', __( 'Invalid email address.', 'mcp-ai-wpoos-pro' ) );
					}
				} elseif ( in_array( $arg_key, array( 'address', 'emergency_contact' ), true ) ) {
					$value = sanitize_textarea_field( $value );
				} else {
					$value = sanitize_text_field( $value );
				}

				// Validate date of birth.
				if ( 'date_of_birth' === $arg_key && $value ) {
					if ( ! $this->validate_date( $value ) ) {
						return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
					}
				}

				update_post_meta( $member_id, $meta_key, $value );
			}
		}

		// Get updated member data.
		$member = get_post( $member_id );
		$types  = wp_get_object_terms( $member_id, 'mcp_ai_member_type', array( 'fields' => 'slugs' ) );
		$type   = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : 'person';
		$is_pet = 'pet' === $type;

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
			'modified_at'       => $member->post_modified,
		);

		if ( $is_pet ) {
			$member_data['species'] = get_post_meta( $member_id, '_pet_species', true );
			$member_data['breed']   = get_post_meta( $member_id, '_pet_breed', true );
		}

		return array(
			'success' => true,
			'message' => __( 'Member updated successfully.', 'mcp-ai-wpoos-pro' ),
			'member'  => $member_data,
		);
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
