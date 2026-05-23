<?php
/**
 * Tool for updating prescriptions.
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
		return __( 'Updates an existing prescription with new medication details, dosage, or schedule information.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'prescription_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Prescription ID to update (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'medication_name'    => array(
					'type'        => 'string',
					'description' => __( 'Medication name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'dosage'             => array(
					'type'        => 'string',
					'description' => __( 'Dosage amount and unit (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'frequency'          => array(
					'type'        => 'string',
					'description' => __( 'Frequency of dosage (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'prescribing_doctor' => array(
					'type'        => 'string',
					'description' => __( 'Name of prescribing doctor (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'start_date'         => array(
					'type'        => 'string',
					'description' => __( 'Prescription start date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'           => array(
					'type'        => 'string',
					'description' => __( 'Prescription end date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'status'             => array(
					'type'        => 'string',
					'description' => __( 'Prescription status (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'completed', 'discontinued', 'expired' ),
				),
				'notes'              => array(
					'type'        => 'string',
					'description' => __( 'Additional notes or instructions (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'refills_remaining'  => array(
					'type'        => 'integer',
					'description' => __( 'Number of refills remaining (optional)', 'mcp-ai-wpoos-pro' ),
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
			'post_type'             => 'mcp_ai_prescription',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'pharmacist' ),
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update prescriptions.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate prescription ID.
		$prescription_id = isset( $arguments['prescription_id'] ) ? absint( $arguments['prescription_id'] ) : 0;

		if ( ! $prescription_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Prescription ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get prescription.
		$prescription = get_post( $prescription_id );

		if ( ! $prescription || 'mcp_ai_prescription' !== $prescription->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Prescription not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize optional fields.
		$medication_name    = isset( $arguments['medication_name'] ) ? sanitize_text_field( $arguments['medication_name'] ) : '';
		$dosage             = isset( $arguments['dosage'] ) ? sanitize_text_field( $arguments['dosage'] ) : '';
		$frequency          = isset( $arguments['frequency'] ) ? sanitize_text_field( $arguments['frequency'] ) : '';
		$prescribing_doctor = isset( $arguments['prescribing_doctor'] ) ? sanitize_text_field( $arguments['prescribing_doctor'] ) : '';
		$start_date         = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date           = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$status             = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';
		$notes              = isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '';
		$refills_remaining  = isset( $arguments['refills_remaining'] ) ? absint( $arguments['refills_remaining'] ) : -1;

		// Validate dates if provided.
		if ( $start_date && ! $this->validate_date( $start_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid start date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $end_date && ! $this->validate_date( $end_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid end date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		// Update post if medication name or notes changed.
		if ( $medication_name || $notes ) {
			$post_data = array(
				'ID' => $prescription_id,
			);

			if ( $medication_name ) {
				$post_data['post_title'] = $medication_name;
			}

			if ( $notes ) {
				$post_data['post_content'] = $notes;
			}

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Update metadata.
		if ( $medication_name ) {
			update_post_meta( $prescription_id, '_prescription_medication_name', $medication_name );
		}

		if ( $dosage ) {
			update_post_meta( $prescription_id, '_prescription_dosage', $dosage );
		}

		if ( $frequency ) {
			update_post_meta( $prescription_id, '_prescription_frequency', $frequency );
		}

		if ( $prescribing_doctor ) {
			update_post_meta( $prescription_id, '_prescription_doctor', $prescribing_doctor );
		}

		if ( $start_date ) {
			update_post_meta( $prescription_id, '_prescription_start_date', $start_date );
		}

		if ( $end_date ) {
			update_post_meta( $prescription_id, '_prescription_end_date', $end_date );
		}

		if ( $status ) {
			update_post_meta( $prescription_id, '_prescription_status', $status );
		}

		if ( $refills_remaining >= 0 ) {
			update_post_meta( $prescription_id, '_prescription_refills_remaining', $refills_remaining );
		}

		// Get updated prescription data.
		$member_id = get_post_meta( $prescription_id, '_prescription_member_id', true );

		return array(
			'success'      => true,
			'message'      => __( 'Prescription updated successfully.', 'mcp-ai-wpoos-pro' ),
			'prescription' => array(
				'id'                 => $prescription_id,
				'member_id'          => $member_id,
				'medication_name'    => $medication_name ? $medication_name : get_post_field( 'post_title', $prescription_id ),
				'dosage'             => $dosage ? $dosage : get_post_meta( $prescription_id, '_prescription_dosage', true ),
				'frequency'          => $frequency ? $frequency : get_post_meta( $prescription_id, '_prescription_frequency', true ),
				'prescribing_doctor' => $prescribing_doctor ? $prescribing_doctor : get_post_meta( $prescription_id, '_prescription_doctor', true ),
				'start_date'         => $start_date ? $start_date : get_post_meta( $prescription_id, '_prescription_start_date', true ),
				'end_date'           => $end_date ? $end_date : get_post_meta( $prescription_id, '_prescription_end_date', true ),
				'status'             => $status ? $status : get_post_meta( $prescription_id, '_prescription_status', true ),
				'refills_remaining'  => $refills_remaining >= 0 ? $refills_remaining : get_post_meta( $prescription_id, '_prescription_refills_remaining', true ),
				'updated_at'         => current_time( 'mysql' ),
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
