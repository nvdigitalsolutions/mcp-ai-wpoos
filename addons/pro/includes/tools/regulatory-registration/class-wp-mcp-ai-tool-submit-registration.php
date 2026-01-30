<?php
/**
 * Tool for marking registrations as submitted in the Regulatory Registration system.
 *
 * Allows AI assistants to update registration status to Submitted with submission date.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks registration as submitted.
 */
class WP_MCP_AI_Tool_Submit_Registration implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'submit_registration';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Submit Registration', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Marks a registration as submitted to the regulatory authority. Updates status to "Submitted" and records submission date.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_id' => array(
					'type'        => 'integer',
					'description' => __( 'Registration ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'submission_date' => array(
					'type'        => 'string',
					'description' => __( 'Submission date (YYYY-MM-DD format, optional, defaults to today)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'notes'           => array(
					'type'        => 'string',
					'description' => __( 'Submission notes (optional)', 'mcp-ai-wpoos-pro' ),
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
			'database-write',       // Modifies database.
		);
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
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to submit registrations.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$registration_id = absint( $arguments['registration_id'] );

		// Get the registration.
		$registration = get_post( $registration_id );

		if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Registration not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Set submission date (default to today).
		$submission_date = ! empty( $arguments['submission_date'] )
			? sanitize_text_field( $arguments['submission_date'] )
			: current_time( 'Y-m-d' );

		// Update status to Submitted.
		$term = term_exists( 'Submitted', 'mcp_ai_reg_status' );
		if ( ! $term ) {
			$term = wp_insert_term( 'Submitted', 'mcp_ai_reg_status' );
		}
		if ( ! is_wp_error( $term ) ) {
			wp_set_object_terms( $registration_id, absint( $term['term_id'] ), 'mcp_ai_reg_status' );
		}

		// Update submission date.
		update_post_meta( $registration_id, 'submission_date', $submission_date );

		// Update notes if provided.
		if ( ! empty( $arguments['notes'] ) ) {
			$post_content = get_post_field( 'post_content', $registration_id );
			$new_content  = $post_content . "\n\n" . sprintf(
				/* translators: 1: submission date, 2: notes */
				__( '[Submitted on %1$s] %2$s', 'mcp-ai-wpoos-pro' ),
				$submission_date,
				sanitize_textarea_field( $arguments['notes'] )
			);
			wp_update_post(
				array(
					'ID'           => $registration_id,
					'post_content' => $new_content,
				)
			);
		}

		return array(
			'success'         => true,
			'message'         => __( 'Registration marked as submitted successfully.', 'mcp-ai-wpoos-pro' ),
			'registration_id' => $registration_id,
			'status'          => 'Submitted',
			'submission_date' => $submission_date,
		);
	}
}
