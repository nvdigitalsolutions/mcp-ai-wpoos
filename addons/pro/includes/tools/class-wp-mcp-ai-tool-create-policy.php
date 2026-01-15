<?php
/**
 * Tool for creating insurance policies.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/trait-wp-mcp-ai-tool-content-media.php';

/**
 * Creates a new insurance policy.
 */
class WP_MCP_AI_Tool_Create_Policy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Content_Media;
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
				'policy_number'    => array(
					'type'        => 'string',
					'description' => __( 'Policy number (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 100,
				),
				'name'             => array(
					'type'        => 'string',
					'description' => __( 'Policy name or title (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'policy_type'      => array(
					'type'        => 'string',
					'description' => __( 'Type of insurance policy (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'health-insurance', 'dental-insurance', 'vision-insurance', 'pet-insurance', 'life-insurance' ),
				),
				'provider'         => array(
					'type'        => 'string',
					'description' => __( 'Insurance provider name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'status'           => array(
					'type'        => 'string',
					'description' => __( 'Policy status (optional, defaults to active)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'expired', 'pending', 'cancelled' ),
					'default'     => 'active',
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
				'premium'          => array(
					'type'        => 'string',
					'description' => __( 'Premium amount (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'coverage_details' => array(
					'type'        => 'string',
					'description' => __( 'Coverage details and benefits (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
			),
			'required'             => array( 'member_id', 'policy_number', 'policy_type' ),
			'additionalProperties' => false,
		);

		// Merge content media parameters.
		$schema['properties'] = array_merge( $schema['properties'], $this->get_content_media_parameters() );

		return $schema;
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
		$member_id     = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$policy_number = isset( $arguments['policy_number'] ) ? sanitize_text_field( $arguments['policy_number'] ) : '';
		$policy_type   = isset( $arguments['policy_type'] ) ? sanitize_key( $arguments['policy_type'] ) : '';

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $policy_number ) {
			return new WP_Error( 'wp_mcp_ai_missing_policy_number', __( 'Policy number is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! $policy_type ) {
			return new WP_Error( 'wp_mcp_ai_missing_policy_type', __( 'Policy type is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'Invalid member ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize optional fields.
		$name             = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : $policy_number;
		$provider         = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : '';
		$status           = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'active';
		$effective_date   = isset( $arguments['effective_date'] ) ? sanitize_text_field( $arguments['effective_date'] ) : '';
		$expiration_date  = isset( $arguments['expiration_date'] ) ? sanitize_text_field( $arguments['expiration_date'] ) : '';
		$premium          = isset( $arguments['premium'] ) ? sanitize_text_field( $arguments['premium'] ) : '';
		$coverage_details = isset( $arguments['coverage_details'] ) ? wp_kses_post( $arguments['coverage_details'] ) : '';

		// Validate dates.
		if ( $effective_date && ! $this->validate_date( $effective_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid effective date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $expiration_date && ! $this->validate_date( $expiration_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid expiration date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		// Create policy post.
		$post_data = array(
			'post_type'    => 'mcp_ai_policy',
			'post_title'   => $name,
			'post_content' => $this->embed_content_media( $coverage_details, $arguments ),
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		);

		$policy_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $policy_id ) ) {
			return $policy_id;
		}

		// Set policy type taxonomy.
		wp_set_object_terms( $policy_id, $policy_type, 'mcp_ai_policy_type' );

		// Save policy metadata.
		update_post_meta( $policy_id, '_policy_member_id', $member_id );
		update_post_meta( $policy_id, '_policy_number', $policy_number );

		if ( $provider ) {
			update_post_meta( $policy_id, '_policy_provider', $provider );
		}

		if ( $status ) {
			update_post_meta( $policy_id, '_policy_status', $status );
		}

		if ( $effective_date ) {
			update_post_meta( $policy_id, '_policy_effective_date', $effective_date );
		}

		if ( $expiration_date ) {
			update_post_meta( $policy_id, '_policy_expiration_date', $expiration_date );
		}

		if ( $premium ) {
			update_post_meta( $policy_id, '_policy_premium', $premium );
		}

		return array(
			'success'   => true,
			'message'   => __( 'Policy created successfully.', 'mcp-ai-wpoos-pro' ),
			'policy_id' => $policy_id,
			'policy'    => array(
				'id'               => $policy_id,
				'member_id'        => $member_id,
				'policy_number'    => $policy_number,
				'name'             => $name,
				'type'             => $policy_type,
				'provider'         => $provider,
				'status'           => $status,
				'effective_date'   => $effective_date,
				'expiration_date'  => $expiration_date,
				'premium'          => $premium,
				'coverage_details' => $coverage_details,
				'created_at'       => current_time( 'mysql' ),
			),
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
