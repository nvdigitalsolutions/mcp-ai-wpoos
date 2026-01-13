<?php
/**
 * Tool for updating policy information.
 *
 * Allows AI assistants to update existing insurance policies.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing insurance policy.
 */
class WP_MCP_AI_Tool_Update_Policy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_policy';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Policy', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing insurance policy. Only the policy creator or users with edit_others_posts capability can update policies.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'policy_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Policy ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'name'             => array(
					'type'        => 'string',
					'description' => __( 'Policy name or description (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'policy_number'    => array(
					'type'        => 'string',
					'description' => __( 'Policy number (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'type'             => array(
					'type'        => 'string',
					'description' => __( 'Type of insurance policy (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'health-insurance', 'dental-insurance', 'vision-insurance', 'pet-insurance', 'life-insurance' ),
				),
				'provider'         => array(
					'type'        => 'string',
					'description' => __( 'Insurance provider/company name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'effective_date'   => array(
					'type'        => 'string',
					'description' => __( 'Policy effective date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'expiration_date'  => array(
					'type'        => 'string',
					'description' => __( 'Policy expiration date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'status'           => array(
					'type'        => 'string',
					'description' => __( 'Policy status (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'expired', 'pending', 'cancelled' ),
				),
				'premium'          => array(
					'type'        => 'string',
					'description' => __( 'Premium amount (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'deductible'       => array(
					'type'        => 'string',
					'description' => __( 'Deductible amount (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'coverage_details' => array(
					'type'        => 'string',
					'description' => __( 'Detailed coverage information (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'group_number'     => array(
					'type'        => 'string',
					'description' => __( 'Group number if employer-sponsored (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'phone'            => array(
					'type'        => 'string',
					'description' => __( 'Provider customer service phone (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
			),
			'required'             => array( 'policy_id' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to update policies.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get policy ID.
		$policy_id = isset( $arguments['policy_id'] ) ? absint( $arguments['policy_id'] ) : 0;

		if ( ! $policy_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_policy_id', __( 'Policy ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify policy exists.
		$policy = get_post( $policy_id );

		if ( ! $policy || 'mcp_ai_policy' !== $policy->post_type ) {
			return new WP_Error( 'wp_mcp_ai_policy_not_found', __( 'Policy not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$is_author = absint( $policy->post_author ) === $current_user_id;
		$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

		if ( ! $is_author && ! $can_edit_others ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this policy.', 'mcp-ai-wpoos-pro' ) );
		}

		// Track updated fields.
		$updated_fields = array();

		// Update name if provided.
		if ( isset( $arguments['name'] ) ) {
			$name = sanitize_text_field( $arguments['name'] );
			if ( '' === $name ) {
				return new WP_Error( 'wp_mcp_ai_invalid_name', __( 'Policy name cannot be empty.', 'mcp-ai-wpoos-pro' ) );
			}

			$result = wp_update_post(
		array(
				'ID'         => $policy_id,
				'post_title' => $name,
			), true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$updated_fields[] = 'name';
		}

		// Update coverage details if provided.
		if ( isset( $arguments['coverage_details'] ) ) {
			$coverage = sanitize_textarea_field( $arguments['coverage_details'] );
			$result = wp_update_post(
		array(
				'ID'           => $policy_id,
				'post_content' => $coverage,
			), true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$updated_fields[] = 'coverage_details';
		}

		// Update policy type if provided.
		if ( isset( $arguments['type'] ) ) {
			$type = sanitize_key( $arguments['type'] );
			if ( ! in_array( $type, array( 'health-insurance', 'dental-insurance', 'vision-insurance', 'pet-insurance', 'life-insurance' ), true ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_type', __( 'Invalid policy type.', 'mcp-ai-wpoos-pro' ) );
			}

			wp_set_object_terms( $policy_id, $type, 'mcp_ai_policy_type' );
			$updated_fields[] = 'type';
		}

		// Update policy number if provided.
		if ( isset( $arguments['policy_number'] ) ) {
			$policy_number = sanitize_text_field( $arguments['policy_number'] );
			update_post_meta( $policy_id, '_policy_number', $policy_number );
			$updated_fields[] = 'policy_number';
		}

		// Update provider if provided.
		if ( isset( $arguments['provider'] ) ) {
			$provider = sanitize_text_field( $arguments['provider'] );
			update_post_meta( $policy_id, '_policy_provider', $provider );
			$updated_fields[] = 'provider';
		}

		// Update effective date if provided.
		if ( isset( $arguments['effective_date'] ) ) {
			$effective_date = sanitize_text_field( $arguments['effective_date'] );
			if ( ! empty( $effective_date ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $effective_date ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_effective_date', __( 'Effective date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
			}
			update_post_meta( $policy_id, '_policy_effective_date', $effective_date );
			$updated_fields[] = 'effective_date';
		}

		// Update expiration date if provided.
		if ( isset( $arguments['expiration_date'] ) ) {
			$expiration_date = sanitize_text_field( $arguments['expiration_date'] );
			if ( ! empty( $expiration_date ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expiration_date ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_expiration_date', __( 'Expiration date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
			}
			update_post_meta( $policy_id, '_policy_expiration_date', $expiration_date );
			$updated_fields[] = 'expiration_date';
		}

		// Update status if provided.
		if ( isset( $arguments['status'] ) ) {
			$status = sanitize_key( $arguments['status'] );
			update_post_meta( $policy_id, '_policy_status', $status );
			$updated_fields[] = 'status';
		}

		// Update premium if provided.
		if ( isset( $arguments['premium'] ) ) {
			$premium = sanitize_text_field( $arguments['premium'] );
			update_post_meta( $policy_id, '_policy_premium', $premium );
			$updated_fields[] = 'premium';
		}

		// Update deductible if provided.
		if ( isset( $arguments['deductible'] ) ) {
			$deductible = sanitize_text_field( $arguments['deductible'] );
			update_post_meta( $policy_id, '_policy_deductible', $deductible );
			$updated_fields[] = 'deductible';
		}

		// Update group number if provided.
		if ( isset( $arguments['group_number'] ) ) {
			$group_number = sanitize_text_field( $arguments['group_number'] );
			update_post_meta( $policy_id, '_policy_group_number', $group_number );
			$updated_fields[] = 'group_number';
		}

		// Update phone if provided.
		if ( isset( $arguments['phone'] ) ) {
			$phone = sanitize_text_field( $arguments['phone'] );
			update_post_meta( $policy_id, '_policy_phone', $phone );
			$updated_fields[] = 'phone';
		}

		if ( empty( $updated_fields ) ) {
			return new WP_Error( 'wp_mcp_ai_no_updates', __( 'No fields were provided to update.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get updated policy data.
		$updated_policy = get_post( $policy_id );
		$types          = wp_get_object_terms( $policy_id, 'mcp_ai_policy_type', array( 'fields' => 'slugs' ) );
		$policy_type    = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : '';

		$policy_data = array(
			'id'               => $policy_id,
			'policy_number'    => get_post_meta( $policy_id, '_policy_number', true ),
			'name'             => $updated_policy->post_title,
			'type'             => $policy_type,
			'provider'         => get_post_meta( $policy_id, '_policy_provider', true ),
			'status'           => get_post_meta( $policy_id, '_policy_status', true ),
			'effective_date'   => get_post_meta( $policy_id, '_policy_effective_date', true ),
			'expiration_date'  => get_post_meta( $policy_id, '_policy_expiration_date', true ),
			'premium'          => get_post_meta( $policy_id, '_policy_premium', true ),
			'deductible'       => get_post_meta( $policy_id, '_policy_deductible', true ),
			'group_number'     => get_post_meta( $policy_id, '_policy_group_number', true ),
			'phone'            => get_post_meta( $policy_id, '_policy_phone', true ),
			'coverage_details' => $updated_policy->post_content,
			'modified_at'      => $updated_policy->post_modified,
		);

		return array(
			'success'        => true,
			'policy'         => $policy_data,
			'updated_fields' => $updated_fields,
		);
	}
}
