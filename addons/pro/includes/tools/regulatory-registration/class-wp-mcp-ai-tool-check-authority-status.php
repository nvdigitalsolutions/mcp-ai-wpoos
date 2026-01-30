<?php
/**
 * Tool for checking registration status across multiple regulatory authorities.
 *
 * Allows AI assistants to check status of registrations across
 * multiple countries and authorities simultaneously.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks authority status across multiple countries.
 */
class WP_MCP_AI_Tool_Check_Authority_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_authority_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Authority Status', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Checks registration status across multiple regulatory authorities and countries, providing unified status updates and tracking information.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'registration_ids' => array(
					'type'        => 'array',
					'description' => __( 'Array of registration IDs to check (required)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'minItems'    => 1,
				),
				'countries'        => array(
					'type'        => 'array',
					'description' => __( 'Filter by specific countries (optional)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'include_history'  => array(
					'type'        => 'boolean',
					'description' => __( 'Include status history (optional, default: false)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'registration_ids' ),
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
			'read-only',            // Does not modify state.
			'idempotent',           // Can be called multiple times safely.
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to check authority status.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		if ( empty( $arguments['registration_ids'] ) || ! is_array( $arguments['registration_ids'] ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_param', __( 'Registration IDs array is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$registration_ids = array_map( 'absint', $arguments['registration_ids'] );
		$countries        = ! empty( $arguments['countries'] ) && is_array( $arguments['countries'] ) ? array_map( 'sanitize_text_field', $arguments['countries'] ) : array();
		$include_history  = ! empty( $arguments['include_history'] );

		$results = array();

		foreach ( $registration_ids as $registration_id ) {
			// Verify registration exists.
			$registration = get_post( $registration_id );
			if ( ! $registration || 'mcp_ai_registration' !== $registration->post_type ) {
				$results[] = array(
					'registration_id' => $registration_id,
					'error'           => __( 'Registration not found', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			// Get registration details.
			$country   = get_post_meta( $registration_id, 'country', true );
			$authority = get_post_meta( $registration_id, 'authority', true );

			// Filter by countries if specified.
			if ( ! empty( $countries ) && ! in_array( $country, $countries, true ) ) {
				continue;
			}

			// Get current status.
			$status_terms = wp_get_post_terms( $registration_id, 'mcp_ai_reg_status' );
			$status       = ! empty( $status_terms ) && ! is_wp_error( $status_terms ) ? $status_terms[0]->name : 'Unknown';

			$registration_data = array(
				'registration_id' => $registration_id,
				'title'           => $registration->post_title,
				'country'         => $country,
				'authority'       => $authority,
				'status'          => $status,
				'submission_date' => get_post_meta( $registration_id, 'submission_date', true ),
				'approval_date'   => get_post_meta( $registration_id, 'approval_date', true ),
				'expiry_date'     => get_post_meta( $registration_id, 'expiry_date', true ),
				'last_checked'    => current_time( 'mysql' ),
			);

			// Get authority-specific data.
			if ( 'Sri Lanka' === $country || 'LK' === $country ) {
				$registration_data['nmra_status']    = get_post_meta( $registration_id, '_nmra_status', true );
				$registration_data['nmra_reference'] = get_post_meta( $registration_id, '_nmra_reference', true );
			} elseif ( 'UAE' === $country || 'AE' === $country ) {
				$registration_data['mohap_status']      = get_post_meta( $registration_id, '_mohap_status', true );
				$registration_data['mohap_tracking_id'] = get_post_meta( $registration_id, '_mohap_tracking_id', true );
			}

			// Include history if requested.
			if ( $include_history ) {
				$registration_data['status_history'] = get_post_meta( $registration_id, '_status_history', true );
			}

			$results[] = $registration_data;
		}

		return array(
			'success'    => true,
			'total'      => count( $results ),
			'checked_at' => current_time( 'mysql' ),
			'results'    => $results,
		);
	}
}
