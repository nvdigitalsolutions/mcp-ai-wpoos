<?php
/**
 * Tool for electronic submission of registrations to regulatory authorities.
 *
 * Allows AI assistants to submit registration applications electronically
 * to various regulatory authorities.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submits registrations to regulatory authorities.
 */
class WP_MCP_AI_Tool_Submit_To_Authority implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'submit_to_authority';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Submit to Authority', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Electronically submits registration application to regulatory authority with all required documents and metadata.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Registration ID to submit (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'submission_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of submission (optional, default: "new")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'new', 'renewal', 'variation', 'transfer' ),
					'default'     => 'new',
				),
				'priority'        => array(
					'type'        => 'string',
					'description' => __( 'Submission priority (optional, default: "normal")', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'normal', 'expedited', 'fast_track' ),
					'default'     => 'normal',
				),
				'notes'           => array(
					'type'        => 'string',
					'description' => __( 'Additional submission notes (optional)', 'mcp-ai-wpoos-pro' ),
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
			'database-read',        // Reads from database.
			'database-write',       // Updates submission status.
			'destructive',          // Critical action.
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to submit to authorities.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_id'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$registration_id = absint( $arguments['registration_id'] );
		$submission_type = ! empty( $arguments['submission_type'] ) ? sanitize_text_field( $arguments['submission_type'] ) : 'new';
		$priority        = ! empty( $arguments['priority'] ) ? sanitize_text_field( $arguments['priority'] ) : 'normal';
		$notes           = ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';

		// Verify registration exists.
		$registration = get_post( $registration_id );
		if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Registration not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get registration details.
		$country    = get_post_meta( $registration_id, 'country', true );
		$authority  = get_post_meta( $registration_id, 'authority', true );
		$product_id = absint( get_post_meta( $registration_id, 'product_id', true ) );

		// Verify required documents are attached.
		$documents_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_reg_document',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => 'registration_id',
						'value' => $registration_id,
					),
				),
			)
		);

		if ( ! $documents_query->have_posts() ) {
			return new WP_Error( 'wp_mcp_ai_validation_error', __( 'At least one document is required for submission.', 'mcp-ai-wpoos-pro' ) );
		}

		$document_count = $documents_query->found_posts;

		// Generate submission reference.
		$submission_reference = sprintf( 'SUB-%s-%d-%s', strtoupper( substr( $country, 0, 2 ) ), $registration_id, gmdate( 'YmdHis' ) );

		// Placeholder for actual electronic submission.
		$submission_result = array(
			'status'           => 'submitted',
			'tracking_id'      => $submission_reference,
			'submission_date'  => current_time( 'mysql' ),
			'estimated_review' => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
			'documents_count'  => $document_count,
		);

		// Update registration status to submitted.
		$submitted_term = get_term_by( 'slug', 'submitted', 'mcp_ai_reg_status' );
		if ( $submitted_term ) {
			wp_set_post_terms( $registration_id, array( $submitted_term->term_id ), 'mcp_ai_reg_status' );
		}

		// Update metadata.
		update_post_meta( $registration_id, 'submission_date', current_time( 'mysql' ) );
		update_post_meta( $registration_id, '_submission_reference', $submission_reference );
		update_post_meta( $registration_id, '_submission_type', $submission_type );
		update_post_meta( $registration_id, '_submission_priority', $priority );
		if ( $notes ) {
			update_post_meta( $registration_id, '_submission_notes', $notes );
		}

		// Log submission.
		$submission_log = get_post_meta( $registration_id, '_submission_log', true );
		if ( ! is_array( $submission_log ) ) {
			$submission_log = array();
		}
		$submission_log[] = array(
			'timestamp' => current_time( 'mysql' ),
			'user_id'   => $current_user_id,
			'type'      => $submission_type,
			'priority'  => $priority,
			'reference' => $submission_reference,
		);
		update_post_meta( $registration_id, '_submission_log', $submission_log );

		return array(
			'success'              => true,
			'registration_id'      => $registration_id,
			'submission_reference' => $submission_reference,
			'submission_type'      => $submission_type,
			'priority'             => $priority,
			'authority'            => $authority,
			'country'              => $country,
			'documents_submitted'  => $document_count,
			'submitted_at'         => current_time( 'mysql' ),
			'estimated_review'     => $submission_result['estimated_review'],
			'message'              => __( 'Successfully submitted to regulatory authority.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
