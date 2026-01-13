<?php
/**
 * Tool for updating prescription information.
 *
 * Allows AI assistants to update existing prescriptions.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing prescription.
 */
class WP_MCP_AI_Tool_Update_Prescription implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_prescription';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Prescription', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing prescription. Only the prescription creator or users with edit_others_posts capability can update prescriptions.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prescription_id' => array(
					'type'        => 'integer',
					'description' => __( 'Prescription ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'medication'      => array(
					'type'        => 'string',
					'description' => __( 'Medication name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'dosage'          => array(
					'type'        => 'string',
					'description' => __( 'Dosage (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'frequency'       => array(
					'type'        => 'string',
					'description' => __( 'Frequency (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'prescriber'      => array(
					'type'        => 'string',
					'description' => __( 'Prescriber/doctor name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'start_date'      => array(
					'type'        => 'string',
					'description' => __( 'Start date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'        => array(
					'type'        => 'string',
					'description' => __( 'End date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'instructions'    => array(
					'type'        => 'string',
					'description' => __( 'Detailed instructions or notes (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
				'pharmacy'        => array(
					'type'        => 'string',
					'description' => __( 'Pharmacy name or location (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'refills'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of refills available (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
			),
			'required'             => array( 'prescription_id' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to update prescriptions.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get prescription ID.
		$prescription_id = isset( $arguments['prescription_id'] ) ? absint( $arguments['prescription_id'] ) : 0;

		if ( ! $prescription_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_prescription_id', __( 'Prescription ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify prescription exists.
		$prescription = get_post( $prescription_id );

		if ( ! $prescription || 'mcp_ai_prescription' !== $prescription->post_type ) {
			return new WP_Error( 'wp_mcp_ai_prescription_not_found', __( 'Prescription not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$is_author       = absint( $prescription->post_author ) === $current_user_id;
		$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

		if ( ! $is_author && ! $can_edit_others ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this prescription.', 'mcp-ai-wpoos-pro' ) );
		}

		// Track updated fields.
		$updated_fields = array();

		// Update medication if provided.
		if ( isset( $arguments['medication'] ) ) {
			$medication = sanitize_text_field( $arguments['medication'] );
			if ( '' === $medication ) {
				return new WP_Error( 'wp_mcp_ai_invalid_medication', __( 'Medication name cannot be empty.', 'mcp-ai-wpoos-pro' ) );
			}

			$result = wp_update_post(
				array(
					'ID'         => $prescription_id,
					'post_title' => $medication,
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$updated_fields[] = 'medication';
		}

		// Update instructions if provided.
		if ( isset( $arguments['instructions'] ) ) {
			$instructions = sanitize_textarea_field( $arguments['instructions'] );
			$result       = wp_update_post(
				array(
					'ID'           => $prescription_id,
					'post_content' => $instructions,
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$updated_fields[] = 'instructions';
		}

		// Update dosage if provided.
		if ( isset( $arguments['dosage'] ) ) {
			$dosage = sanitize_text_field( $arguments['dosage'] );
			update_post_meta( $prescription_id, '_prescription_dosage', $dosage );
			$updated_fields[] = 'dosage';
		}

		// Update frequency if provided.
		if ( isset( $arguments['frequency'] ) ) {
			$frequency = sanitize_text_field( $arguments['frequency'] );
			update_post_meta( $prescription_id, '_prescription_frequency', $frequency );
			$updated_fields[] = 'frequency';
		}

		// Update prescriber if provided.
		if ( isset( $arguments['prescriber'] ) ) {
			$prescriber = sanitize_text_field( $arguments['prescriber'] );
			update_post_meta( $prescription_id, '_prescription_prescriber', $prescriber );
			$updated_fields[] = 'prescriber';
		}

		// Update start date if provided.
		if ( isset( $arguments['start_date'] ) ) {
			$start_date = sanitize_text_field( $arguments['start_date'] );
			if ( ! empty( $start_date ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_start_date', __( 'Start date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
			}
			update_post_meta( $prescription_id, '_prescription_start_date', $start_date );
			$updated_fields[] = 'start_date';
		}

		// Update end date if provided.
		if ( isset( $arguments['end_date'] ) ) {
			$end_date = sanitize_text_field( $arguments['end_date'] );
			if ( ! empty( $end_date ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_end_date', __( 'End date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
			}
			update_post_meta( $prescription_id, '_prescription_end_date', $end_date );
			$updated_fields[] = 'end_date';
		}

		// Update pharmacy if provided.
		if ( isset( $arguments['pharmacy'] ) ) {
			$pharmacy = sanitize_text_field( $arguments['pharmacy'] );
			update_post_meta( $prescription_id, '_prescription_pharmacy', $pharmacy );
			$updated_fields[] = 'pharmacy';
		}

		// Update refills if provided.
		if ( isset( $arguments['refills'] ) ) {
			$refills = absint( $arguments['refills'] );
			update_post_meta( $prescription_id, '_prescription_refills', $refills );
			$updated_fields[] = 'refills';
		}

		if ( empty( $updated_fields ) ) {
			return new WP_Error( 'wp_mcp_ai_no_updates', __( 'No fields were provided to update.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get updated prescription data.
		$updated_prescription = get_post( $prescription_id );

		// Check if currently active.
		$start     = get_post_meta( $prescription_id, '_prescription_start_date', true );
		$end       = get_post_meta( $prescription_id, '_prescription_end_date', true );
		$today     = current_time( 'Y-m-d' );
		$is_active = ( ! $start || $start <= $today ) && ( ! $end || $end >= $today );

		$prescription_data = array(
			'id'           => $prescription_id,
			'medication'   => $updated_prescription->post_title,
			'dosage'       => get_post_meta( $prescription_id, '_prescription_dosage', true ),
			'frequency'    => get_post_meta( $prescription_id, '_prescription_frequency', true ),
			'prescriber'   => get_post_meta( $prescription_id, '_prescription_prescriber', true ),
			'start_date'   => $start,
			'end_date'     => $end,
			'instructions' => $updated_prescription->post_content,
			'pharmacy'     => get_post_meta( $prescription_id, '_prescription_pharmacy', true ),
			'refills'      => get_post_meta( $prescription_id, '_prescription_refills', true ),
			'is_active'    => $is_active,
			'modified_at'  => $updated_prescription->post_modified,
		);

		return array(
			'success'        => true,
			'prescription'   => $prescription_data,
			'updated_fields' => $updated_fields,
		);
	}
}
