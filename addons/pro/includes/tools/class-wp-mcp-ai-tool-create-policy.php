<?php
/**
 * Tool for creating insurance policies.
 *
 * Allows AI assistants to create new insurance policies for members.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new insurance policy.
 */
class WP_MCP_AI_Tool_Create_Policy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_policy';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Policy', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new insurance policy (health, dental, vision, pet, or life insurance) for a member.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Member ID this policy belongs to (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'name'             => array(
					'type'        => 'string',
					'description' => __( 'Policy name or description (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'policy_number'    => array(
					'type'        => 'string',
					'description' => __( 'Policy number (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 100,
				),
				'type'             => array(
					'type'        => 'string',
					'description' => __( 'Type of insurance policy (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'health-insurance', 'dental-insurance', 'vision-insurance', 'pet-insurance', 'life-insurance' ),
				),
				'provider'         => array(
					'type'        => 'string',
					'description' => __( 'Insurance provider/company name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'effective_date'   => array(
					'type'        => 'string',
					'description' => __( 'Policy effective date (YYYY-MM-DD) (required)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'expiration_date'  => array(
					'type'        => 'string',
					'description' => __( 'Policy expiration date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'status'           => array(
					'type'        => 'string',
					'description' => __( 'Policy status (optional, default: active)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'expired', 'pending', 'cancelled' ),
					'default'     => 'active',
				),
				'premium'          => array(
					'type'        => 'string',
					'description' => __( 'Premium amount (e.g., "$500/month") (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'deductible'       => array(
					'type'        => 'string',
					'description' => __( 'Deductible amount (e.g., "$1000") (optional)', 'mcp-ai-wpoos-pro' ),
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
			'required'             => array( 'member_id', 'name', 'policy_number', 'type', 'provider', 'effective_date' ),
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create policies.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		$member_id      = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$name           = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
		$policy_number  = isset( $arguments['policy_number'] ) ? sanitize_text_field( $arguments['policy_number'] ) : '';
		$type           = isset( $arguments['type'] ) ? sanitize_key( $arguments['type'] ) : '';
		$provider       = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : '';
		$effective_date = isset( $arguments['effective_date'] ) ? sanitize_text_field( $arguments['effective_date'] ) : '';

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $name ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_name', __( 'Policy name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $policy_number ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_policy_number', __( 'Policy number is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $type ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_type', __( 'Policy type is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! in_array( $type, array( 'health-insurance', 'dental-insurance', 'vision-insurance', 'pet-insurance', 'life-insurance' ), true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_type', __( 'Invalid policy type.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $provider ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_provider', __( 'Provider name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $effective_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $effective_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_effective_date', __( 'Valid effective date (YYYY-MM-DD) is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Optional fields.
		$expiration_date  = isset( $arguments['expiration_date'] ) ? sanitize_text_field( $arguments['expiration_date'] ) : '';
		$status           = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'active';
		$premium          = isset( $arguments['premium'] ) ? sanitize_text_field( $arguments['premium'] ) : '';
		$deductible       = isset( $arguments['deductible'] ) ? sanitize_text_field( $arguments['deductible'] ) : '';
		$coverage_details = isset( $arguments['coverage_details'] ) ? sanitize_textarea_field( $arguments['coverage_details'] ) : '';
		$group_number     = isset( $arguments['group_number'] ) ? sanitize_text_field( $arguments['group_number'] ) : '';
		$phone            = isset( $arguments['phone'] ) ? sanitize_text_field( $arguments['phone'] ) : '';

		// Validate expiration date if provided.
		if ( ! empty( $expiration_date ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expiration_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_expiration_date', __( 'Expiration date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
		}

		// Create the policy post.
		$policy_id = wp_insert_post( array(
			'post_type'    => 'mcp_ai_policy',
			'post_title'   => $name,
			'post_content' => $coverage_details,
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		), true );

		if ( is_wp_error( $policy_id ) ) {
			return $policy_id;
		}

		// Set policy metadata.
		update_post_meta( $policy_id, '_policy_member_id', $member_id );
		update_post_meta( $policy_id, '_policy_number', $policy_number );
		update_post_meta( $policy_id, '_policy_provider', $provider );
		update_post_meta( $policy_id, '_policy_effective_date', $effective_date );
		update_post_meta( $policy_id, '_policy_status', $status );

		if ( $expiration_date ) {
			update_post_meta( $policy_id, '_policy_expiration_date', $expiration_date );
		}
		if ( $premium ) {
			update_post_meta( $policy_id, '_policy_premium', $premium );
		}
		if ( $deductible ) {
			update_post_meta( $policy_id, '_policy_deductible', $deductible );
		}
		if ( $group_number ) {
			update_post_meta( $policy_id, '_policy_group_number', $group_number );
		}
		if ( $phone ) {
			update_post_meta( $policy_id, '_policy_phone', $phone );
		}

		// Set policy type taxonomy.
		wp_set_object_terms( $policy_id, $type, 'mcp_ai_policy_type' );

		// Build response.
		$policy_data = array(
			'id'               => $policy_id,
			'policy_number'    => $policy_number,
			'name'             => $name,
			'type'             => $type,
			'member_id'        => $member_id,
			'member_name'      => $member->post_title,
			'provider'         => $provider,
			'status'           => $status,
			'effective_date'   => $effective_date,
			'expiration_date'  => $expiration_date,
			'premium'          => $premium,
			'deductible'       => $deductible,
			'group_number'     => $group_number,
			'phone'            => $phone,
			'coverage_details' => $coverage_details,
		);

		return array(
			'success' => true,
			'policy'  => $policy_data,
			'message' => sprintf(
				/* translators: 1: policy name, 2: member name */
				__( 'Policy "%1$s" created successfully for %2$s.', 'mcp-ai-wpoos-pro' ),
				$name,
				$member->post_title
			),
		);
	}
}
