<?php
/**
 * Tool for renewing registrations in the Regulatory Registration system.
 *
 * Allows AI assistants to create renewal registrations from expiring ones.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renews a regulatory registration.
 */
class WP_MCP_AI_Tool_Renew_Registration implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'renew_registration';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Renew Registration', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a renewal registration from an existing registration. Carries forward product and authority information, resets dates and status for renewal workflow.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Original registration ID to renew (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'expected_expiry_date' => array(
					'type'        => 'string',
					'description' => __( 'Expected new expiry date after renewal (YYYY-MM-DD, optional)', 'mcp-ai-wpoos-pro' ),
				),
				'notes'                => array(
					'type'        => 'string',
					'description' => __( 'Notes about the renewal (optional)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'registration_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-write',       // Writes to database.
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['registration_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Registration ID is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$original_registration_id = absint( $arguments['registration_id'] );

		// Verify original registration exists.
		$original_registration = get_post( $original_registration_id );
		if ( ! $original_registration || 'mcp_ai_registration' !== $original_registration->post_type ) {
			return array(
				'success' => false,
				'error'   => __( 'Original registration not found.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get original registration data.
		$product_id      = get_post_meta( $original_registration_id, 'product_id', true );
		$country         = get_post_meta( $original_registration_id, 'country', true );
		$authority       = get_post_meta( $original_registration_id, 'authority', true );
		$old_cos_number  = get_post_meta( $original_registration_id, 'cos_number', true );
		$old_expiry_date = get_post_meta( $original_registration_id, 'expiry_date', true );

		// Create renewal registration.
		$renewal_title = sprintf(
			'%s - %s Renewal',
			$original_registration->post_title,
			$country
		);

		$notes = ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';
		if ( ! empty( $old_expiry_date ) ) {
			$notes .= sprintf(
				"\n\nRenewal of COS: %s (Expired: %s)",
				$old_cos_number,
				$old_expiry_date
			);
		}

		$renewal_data = array(
			'post_title'   => $renewal_title,
			'post_type'    => 'mcp_ai_registration',
			'post_status'  => 'publish',
			'post_content' => $notes,
		);

		$renewal_id = wp_insert_post( $renewal_data );

		if ( is_wp_error( $renewal_id ) ) {
			return array(
				'success' => false,
				'error'   => $renewal_id->get_error_message(),
			);
		}

		// Save renewal metadata.
		update_post_meta( $renewal_id, 'product_id', $product_id );
		update_post_meta( $renewal_id, 'country', $country );
		update_post_meta( $renewal_id, 'authority', $authority );
		update_post_meta( $renewal_id, 'registration_type', 'renewal' );
		update_post_meta( $renewal_id, 'original_registration_id', $original_registration_id );

		if ( ! empty( $arguments['expected_expiry_date'] ) ) {
			update_post_meta( $renewal_id, 'expected_expiry_date', sanitize_text_field( $arguments['expected_expiry_date'] ) );
		}

		// Set initial status to Draft.
		$draft_status = get_term_by( 'slug', 'draft', 'mcp_ai_reg_status' );
		if ( $draft_status ) {
			wp_set_object_terms( $renewal_id, $draft_status->term_id, 'mcp_ai_reg_status' );
		}

		// Update original registration to mark it as renewed.
		update_post_meta( $original_registration_id, 'renewed_by', $renewal_id );
		update_post_meta( $original_registration_id, 'renewal_date', current_time( 'mysql' ) );

		return array(
			'success'                  => true,
			'renewal_id'               => $renewal_id,
			'renewal_title'            => $renewal_title,
			'original_registration_id' => $original_registration_id,
			'product_id'               => $product_id,
			'country'                  => $country,
			'authority'                => $authority,
			'registration_type'        => 'renewal',
			'message'                  => __( 'Renewal registration created successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
