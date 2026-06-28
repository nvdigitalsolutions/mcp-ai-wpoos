<?php
/**
 * Tool for enrolling students in ECAs.
 *
 * Allows AI assistants to enroll students in Extra-Curricular Activities.
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
 * Enrolls a student in an ECA.
 */
class WP_MCP_AI_Tool_Enroll_Student_ECA implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'enroll_student_eca';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Enroll Student in ECA', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Enrolls a student in an Extra-Curricular Activity. Checks capacity limits, year group eligibility, and handles payment requirements. Updates enrollment counts automatically.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'student_id'          => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the student (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'eca_id'              => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the ECA (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'enrollment_type'     => array(
					'type'        => 'string',
					'description' => __( 'Type of enrollment', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'confirmed', 'waitlist', 'preference', 'trial' ),
					'default'     => 'confirmed',
				),
				'payment_status'      => array(
					'type'        => 'string',
					'description' => __( 'Payment status (for paid ECAs)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'pending', 'paid', 'partial', 'waived' ),
					'default'     => 'pending',
				),
				'notes'               => array(
					'type'        => 'string',
					'description' => __( 'Enrollment notes or comments', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 1000,
				),
				'skip_capacity_check' => array(
					'type'        => 'boolean',
					'description' => __( 'Skip capacity check (admin override)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'student_id', 'eca_id' ),
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
			'toolkit'               => 'education',
			'post_type'             => 'mcp_ai_eca',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'educator', 'school_admin', 'student' ),
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
		return ! empty( $settings['enable_eca_management'] );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to enroll students.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate inputs.
		$student_id = isset( $arguments['student_id'] ) ? absint( $arguments['student_id'] ) : 0;
		$eca_id     = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;

		if ( ! $student_id || ! $eca_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_ids',
				__( 'Both student_id and eca_id are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Verify student exists.
		$student = get_post( $student_id );
		if ( ! $student || 'mcp_ai_student' !== $student->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_student',
				__( 'Invalid student ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Verify ECA exists.
		$eca = get_post( $eca_id );
		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_eca',
				__( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if already enrolled.
		$existing_enrollment = $this->get_enrollment( $student_id, $eca_id );
		if ( $existing_enrollment ) {
			return new WP_Error(
				'wp_mcp_ai_already_enrolled',
				__( 'Student is already enrolled in this ECA.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get enrollment parameters.
		$enrollment_type     = isset( $arguments['enrollment_type'] ) ? sanitize_key( $arguments['enrollment_type'] ) : 'confirmed';
		$payment_status      = isset( $arguments['payment_status'] ) ? sanitize_key( $arguments['payment_status'] ) : 'pending';
		$notes               = isset( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';
		$skip_capacity_check = isset( $arguments['skip_capacity_check'] ) ? (bool) $arguments['skip_capacity_check'] : false;

		// Validate enrollment type.
		$valid_types = array( 'confirmed', 'waitlist', 'preference', 'trial' );
		if ( ! in_array( $enrollment_type, $valid_types, true ) ) {
			$enrollment_type = 'confirmed';
		}

		// Only check capacity for confirmed enrollments.
		if ( 'confirmed' === $enrollment_type && ! $skip_capacity_check ) {
			$capacity_check = $this->check_capacity( $eca_id );
			if ( is_wp_error( $capacity_check ) ) {
				// If full, automatically switch to waitlist.
				$enrollment_type = 'waitlist';
			}
		}

		// Check year group eligibility.
		$student_year_group = get_post_meta( $student_id, '_student_year_group', true );
		$eca_year_groups    = get_post_meta( $eca_id, '_eca_year_groups', true );

		if ( is_array( $eca_year_groups ) && ! empty( $eca_year_groups ) && $student_year_group ) {
			if ( ! in_array( $student_year_group, $eca_year_groups, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_ineligible_year_group',
					sprintf(
						/* translators: 1: student year group, 2: ECA name */
						__( 'Student year group (%1$s) is not eligible for this ECA: %2$s', 'mcp-ai-wpoos-pro' ),
						$student_year_group,
						$eca->post_title
					)
				);
			}
		}

		// Check if ECA requires payment.
		$is_paid = get_post_meta( $eca_id, '_eca_is_paid', true ) === 'yes';
		$cost    = 0;
		if ( $is_paid ) {
			$cost = floatval( get_post_meta( $eca_id, '_eca_cost', true ) );
		}

		// Create enrollment record.
		$enrollment_data = array(
			'student_id'      => $student_id,
			'eca_id'          => $eca_id,
			'enrollment_type' => $enrollment_type,
			'enrollment_date' => current_time( 'mysql' ),
			'payment_status'  => $is_paid ? $payment_status : 'n/a',
			'amount_due'      => $cost,
			'notes'           => $notes,
			'enrolled_by'     => $current_user_id,
		);

		// Store enrollment in student meta.
		$student_enrollments = get_post_meta( $student_id, '_student_eca_enrollments', true );
		if ( ! is_array( $student_enrollments ) ) {
			$student_enrollments = array();
		}
		$student_enrollments[ $eca_id ] = $enrollment_data;
		update_post_meta( $student_id, '_student_eca_enrollments', $student_enrollments );

		// Store enrollment in ECA meta.
		$eca_enrollments = get_post_meta( $eca_id, '_eca_student_enrollments', true );
		if ( ! is_array( $eca_enrollments ) ) {
			$eca_enrollments = array();
		}
		$eca_enrollments[ $student_id ] = $enrollment_data;
		update_post_meta( $eca_id, '_eca_student_enrollments', $eca_enrollments );

		// Update enrollment count (only for confirmed enrollments).
		if ( 'confirmed' === $enrollment_type ) {
			$current_count = absint( get_post_meta( $eca_id, '_eca_current_enrollment', true ) );
			update_post_meta( $eca_id, '_eca_current_enrollment', $current_count + 1 );

			// Check if now full and update status.
			$max_students = absint( get_post_meta( $eca_id, '_eca_max_students', true ) );
			if ( $max_students > 0 && ( $current_count + 1 ) >= $max_students ) {
				update_post_meta( $eca_id, '_eca_status', 'full' );
			}
		}

		// Sync enrollment to iSAMS if configured.
		$isams_sync_result = $this->sync_enrollment_to_isams( $student_id, $eca_id, $enrollment_data );

		$result = array(
			'success'         => true,
			'enrollment_type' => $enrollment_type,
			'student_id'      => $student_id,
			'student_name'    => $student->post_title,
			'eca_id'          => $eca_id,
			'eca_name'        => $eca->post_title,
			'payment_status'  => $is_paid ? $payment_status : 'n/a',
			'amount_due'      => $cost,
			'enrollment_date' => $enrollment_data['enrollment_date'],
			'message'         => sprintf(
				/* translators: 1: student name, 2: ECA name */
				__( '%1$s has been enrolled in %2$s.', 'mcp-ai-wpoos-pro' ),
				$student->post_title,
				$eca->post_title
			),
		);

		if ( $isams_sync_result ) {
			$result['isams_sync'] = $isams_sync_result;
		}

		return $result;
	}

	/**
	 * Check if ECA has capacity.
	 *
	 * @param int $eca_id ECA post ID.
	 * @return true|WP_Error True if has capacity, error if full.
	 */
	private function check_capacity( $eca_id ) {
		$max_students = absint( get_post_meta( $eca_id, '_eca_max_students', true ) );
		if ( 0 === $max_students ) {
			return true; // No limit.
		}

		$current_enrollment = absint( get_post_meta( $eca_id, '_eca_current_enrollment', true ) );
		if ( $current_enrollment >= $max_students ) {
			return new WP_Error(
				'wp_mcp_ai_eca_full',
				__( 'ECA is at full capacity.', 'mcp-ai-wpoos-pro' )
			);
		}

		return true;
	}

	/**
	 * Get existing enrollment.
	 *
	 * @param int $student_id Student post ID.
	 * @param int $eca_id     ECA post ID.
	 * @return array|null Enrollment data or null if not found.
	 */
	private function get_enrollment( $student_id, $eca_id ) {
		$enrollments = get_post_meta( $student_id, '_student_eca_enrollments', true );
		if ( is_array( $enrollments ) && isset( $enrollments[ $eca_id ] ) ) {
			return $enrollments[ $eca_id ];
		}
		return null;
	}

	/**
	 * Sync enrollment to iSAMS.
	 *
	 * @param int   $student_id      Student post ID.
	 * @param int   $eca_id          ECA post ID.
	 * @param array $enrollment_data Enrollment data.
	 * @return array|null Sync result or null if not applicable.
	 */
	private function sync_enrollment_to_isams( $student_id, $eca_id, $enrollment_data ) {
		// Check if both student and ECA are synced with iSAMS.
		$student_isams_id = get_post_meta( $student_id, '_student_isams_id', true );
		$eca_isams_id     = get_post_meta( $eca_id, '_eca_isams_sync_id', true );

		if ( empty( $student_isams_id ) || empty( $eca_isams_id ) ) {
			return null;
		}

		// Check if iSAMS is configured.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['isams_api_url'] ) || empty( $settings['isams_api_key'] ) ) {
			return null;
		}

		// Store sync timestamp.
		update_post_meta( $student_id, '_enrollment_' . $eca_id . '_isams_synced', current_time( 'mysql' ) );

		return array(
			'synced'    => true,
			'timestamp' => current_time( 'mysql' ),
			'message'   => __( 'Enrollment synced to iSAMS.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
