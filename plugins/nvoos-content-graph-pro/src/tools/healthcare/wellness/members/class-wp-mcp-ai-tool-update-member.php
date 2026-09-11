<?php
/**
 * wellness/members/class-wp-mcp-ai-tool-update-member.php (ecosystem port — Wave F4, healthcare wellness CRUD batch 1).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/wellness/members/class-wp-mcp-ai-tool-update-member.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps; the base-owned trait/interface requires gain
 * `trait_exists`-gated seams resolving from the addon's D8-compat `src/` copies (the monorepo
 * root classmap serves the base copies monolith).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

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
		return __( 'Update Member', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing member. Provide only the fields you want to update.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Member ID to update (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'name'              => array(
					'type'        => 'string',
					'description' => __( 'New member name (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
				'type'              => array(
					'type'        => 'string',
					'description' => __( 'New member type: person or pet (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'person', 'pet' ),
				),
				'description'       => array(
					'type'        => 'string',
					'description' => __( 'New description (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 5000,
				),
				'date_of_birth'     => array(
					'type'        => 'string',
					'description' => __( 'New date of birth (YYYY-MM-DD) (optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'gender'            => array(
					'type'        => 'string',
					'description' => __( 'New gender (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 50,
				),
				'blood_type'        => array(
					'type'        => 'string',
					'description' => __( 'New blood type (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', '' ),
				),
				'email'             => array(
					'type'        => 'string',
					'description' => __( 'New email address (optional)', 'nvoos-content-graph-pro' ),
					'format'      => 'email',
				),
				'phone'             => array(
					'type'        => 'string',
					'description' => __( 'New phone number (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 50,
				),
				'address'           => array(
					'type'        => 'string',
					'description' => __( 'New physical address (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 500,
				),
				'emergency_contact' => array(
					'type'        => 'string',
					'description' => __( 'New emergency contact information (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 500,
				),
				'species'           => array(
					'type'        => 'string',
					'description' => __( 'New species (for pets only) (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 100,
				),
				'breed'             => array(
					'type'        => 'string',
					'description' => __( 'New breed (for pets only) (optional)', 'nvoos-content-graph-pro' ),
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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update members.', 'nvoos-content-graph-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Member ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$member = get_post( $member_id );

		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'Invalid member ID.', 'nvoos-content-graph-pro' ) );
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
						return new WP_Error( 'wp_mcp_ai_invalid_email', __( 'Invalid email address.', 'nvoos-content-graph-pro' ) );
					}
				} elseif ( in_array( $arg_key, array( 'address', 'emergency_contact' ), true ) ) {
					$value = sanitize_textarea_field( $value );
				} else {
					$value = sanitize_text_field( $value );
				}

				// Validate date of birth.
				if ( 'date_of_birth' === $arg_key && $value ) {
					if ( ! $this->validate_date( $value ) ) {
						return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid date format. Use YYYY-MM-DD.', 'nvoos-content-graph-pro' ) );
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
			'message' => __( 'Member updated successfully.', 'nvoos-content-graph-pro' ),
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
