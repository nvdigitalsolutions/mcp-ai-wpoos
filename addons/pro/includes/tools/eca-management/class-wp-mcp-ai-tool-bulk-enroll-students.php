<?php
/**
 * Tool for bulk enrolling students in ECAs.
 *
 * Allows AI assistants to batch enroll multiple students in an Extra-Curricular Activity.
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
 * Batch enrollment of multiple students in an ECA.
 */
class WP_MCP_AI_Tool_Bulk_Enroll_Students implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'bulk_enroll_students';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Bulk Enroll Students', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Batch enrollment of multiple students in an ECA.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'              => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the ECA (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'students'            => array(
					'type'        => 'array',
					'description' => __( 'Array of student enrollment objects (required)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'student_id'      => array(
								'type'        => 'integer',
								'description' => __( 'WordPress post ID of the student', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 1,
							),
							'enrollment_type' => array(
								'type'        => 'string',
								'description' => __( 'Type of enrollment', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'confirmed', 'waitlist', 'preference', 'trial' ),
								'default'     => 'confirmed',
							),
							'payment_status'  => array(
								'type'        => 'string',
								'description' => __( 'Payment status', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'pending', 'paid', 'partial', 'waived' ),
								'default'     => 'pending',
							),
						),
						'required'             => array( 'student_id' ),
						'additionalProperties' => false,
					),
				),
				'skip_capacity_check' => array(
					'type'        => 'boolean',
					'description' => __( 'Skip capacity check (admin override)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'eca_id', 'students' ),
			'additionalProperties' => false,
		);
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
			'profession_tags'       => array( 'educator', 'school_admin' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to enroll students.', 'mcp-ai-wpoos-pro' )
			);
		}

		$eca_id              = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;
		$students_input      = isset( $arguments['students'] ) && is_array( $arguments['students'] ) ? $arguments['students'] : array();
		$skip_capacity_check = isset( $arguments['skip_capacity_check'] ) ? (bool) $arguments['skip_capacity_check'] : false;

		if ( ! $eca_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_eca',
				__( 'eca_id is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $students_input ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_students',
				__( 'students array is required and must not be empty.', 'mcp-ai-wpoos-pro' )
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

		// Load ECA enrollment data.
		$eca_enrollments = get_post_meta( $eca_id, '_eca_student_enrollments', true );
		if ( ! is_array( $eca_enrollments ) ) {
			$eca_enrollments = array();
		}

		$max_students       = absint( get_post_meta( $eca_id, '_eca_max_students', true ) );
		$current_enrollment = absint( get_post_meta( $eca_id, '_eca_current_enrollment', true ) );

		$is_paid = get_post_meta( $eca_id, '_eca_is_paid', true ) === 'yes';
		$cost    = $is_paid ? floatval( get_post_meta( $eca_id, '_eca_cost', true ) ) : 0;

		$results          = array();
		$enrolled_count   = 0;
		$waitlisted_count = 0;
		$failed_count     = 0;

		foreach ( $students_input as $student_entry ) {
			$student_id      = isset( $student_entry['student_id'] ) ? absint( $student_entry['student_id'] ) : 0;
			$enrollment_type = isset( $student_entry['enrollment_type'] ) ? sanitize_key( $student_entry['enrollment_type'] ) : 'confirmed';
			$payment_status  = isset( $student_entry['payment_status'] ) ? sanitize_key( $student_entry['payment_status'] ) : 'pending';

			// Validate enrollment type.
			$valid_types = array( 'confirmed', 'waitlist', 'preference', 'trial' );
			if ( ! in_array( $enrollment_type, $valid_types, true ) ) {
				$enrollment_type = 'confirmed';
			}

			// Validate payment status.
			$valid_statuses = array( 'pending', 'paid', 'partial', 'waived' );
			if ( ! in_array( $payment_status, $valid_statuses, true ) ) {
				$payment_status = 'pending';
			}

			if ( ! $student_id ) {
				$results[] = array(
					'student_id' => 0,
					'status'     => 'failed',
					'reason'     => __( 'Missing student_id.', 'mcp-ai-wpoos-pro' ),
				);
				++$failed_count;
				continue;
			}

			// Verify student exists.
			$student = get_post( $student_id );
			if ( ! $student || 'mcp_ai_student' !== $student->post_type ) {
				$results[] = array(
					'student_id' => $student_id,
					'status'     => 'failed',
					'reason'     => __( 'Invalid student ID.', 'mcp-ai-wpoos-pro' ),
				);
				++$failed_count;
				continue;
			}

			// Check if already enrolled.
			if ( isset( $eca_enrollments[ $student_id ] ) ) {
				$results[] = array(
					'student_id'   => $student_id,
					'student_name' => $student->post_title,
					'status'       => 'failed',
					'reason'       => __( 'Already enrolled.', 'mcp-ai-wpoos-pro' ),
				);
				++$failed_count;
				continue;
			}

			// Check capacity for confirmed enrollments.
			if ( 'confirmed' === $enrollment_type && ! $skip_capacity_check ) {
				if ( $max_students > 0 && $current_enrollment >= $max_students ) {
					$enrollment_type = 'waitlist';
				}
			}

			// Create enrollment record.
			$enrollment_data = array(
				'student_id'      => $student_id,
				'eca_id'          => $eca_id,
				'enrollment_type' => $enrollment_type,
				'enrollment_date' => current_time( 'mysql' ),
				'payment_status'  => $is_paid ? $payment_status : 'n/a',
				'amount_due'      => $cost,
				'notes'           => '',
				'enrolled_by'     => $current_user_id,
			);

			// Store in ECA meta.
			$eca_enrollments[ $student_id ] = $enrollment_data;

			// Store in student meta.
			$student_enrollments = get_post_meta( $student_id, '_student_eca_enrollments', true );
			if ( ! is_array( $student_enrollments ) ) {
				$student_enrollments = array();
			}
			$student_enrollments[ $eca_id ] = $enrollment_data;
			update_post_meta( $student_id, '_student_eca_enrollments', $student_enrollments );

			if ( 'confirmed' === $enrollment_type ) {
				++$current_enrollment;
				++$enrolled_count;
				$results[] = array(
					'student_id'   => $student_id,
					'student_name' => $student->post_title,
					'status'       => 'enrolled',
				);
			} else {
				++$waitlisted_count;
				$results[] = array(
					'student_id'   => $student_id,
					'student_name' => $student->post_title,
					'status'       => 'waitlisted',
				);
			}
		}

		// Persist ECA enrollments and updated count.
		update_post_meta( $eca_id, '_eca_student_enrollments', $eca_enrollments );
		update_post_meta( $eca_id, '_eca_current_enrollment', $current_enrollment );

		// Update ECA status if now full.
		if ( $max_students > 0 && $current_enrollment >= $max_students ) {
			update_post_meta( $eca_id, '_eca_status', 'full' );
		}

		return array(
			'success'          => true,
			'eca_id'           => $eca_id,
			'eca_name'         => $eca->post_title,
			'total_processed'  => count( $results ),
			'enrolled_count'   => $enrolled_count,
			'waitlisted_count' => $waitlisted_count,
			'failed_count'     => $failed_count,
			'results'          => $results,
		);
	}
}
