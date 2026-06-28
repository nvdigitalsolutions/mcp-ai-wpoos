<?php
/**
 * Tool for withdrawing students from ECAs.
 *
 * Allows AI assistants to formally withdraw students from Extra-Curricular Activities
 * with optional refund processing and automatic waitlist promotion.
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
 * Withdraws a student from an ECA.
 */
class WP_MCP_AI_Tool_Withdraw_Student_ECA implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'withdraw_student_eca';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Withdraw Student from ECA', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Formally withdraws a student from an ECA with optional refund processing and automatic waitlist promotion.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'student_id'     => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the student (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'eca_id'         => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the ECA (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'reason'         => array(
					'type'        => 'string',
					'description' => __( 'Reason for withdrawal', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'effective_date' => array(
					'type'        => 'string',
					'description' => __( 'Effective date of withdrawal in YYYY-MM-DD format', 'mcp-ai-wpoos-pro' ),
				),
				'process_refund' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to flag the enrollment for refund processing', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'student_id', 'eca_id' ),
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to withdraw students.', 'mcp-ai-wpoos-pro' )
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

		// Check that student is enrolled.
		$eca_enrollments = get_post_meta( $eca_id, '_eca_student_enrollments', true );
		if ( ! is_array( $eca_enrollments ) ) {
			$eca_enrollments = array();
		}

		if ( ! isset( $eca_enrollments[ $student_id ] ) ) {
			return new WP_Error(
				'wp_mcp_ai_not_enrolled',
				__( 'Student is not enrolled in this ECA.', 'mcp-ai-wpoos-pro' )
			);
		}

		$enrollment_data = $eca_enrollments[ $student_id ];
		$was_confirmed   = isset( $enrollment_data['enrollment_type'] ) && 'confirmed' === $enrollment_data['enrollment_type'];

		$reason         = isset( $arguments['reason'] ) ? sanitize_textarea_field( $arguments['reason'] ) : '';
		$effective_date = isset( $arguments['effective_date'] ) ? sanitize_text_field( $arguments['effective_date'] ) : current_time( 'Y-m-d' );
		$process_refund = isset( $arguments['process_refund'] ) ? (bool) $arguments['process_refund'] : false;

		// Remove from ECA enrollments.
		unset( $eca_enrollments[ $student_id ] );
		update_post_meta( $eca_id, '_eca_student_enrollments', $eca_enrollments );

		// Remove from student enrollments.
		$student_enrollments = get_post_meta( $student_id, '_student_eca_enrollments', true );
		if ( is_array( $student_enrollments ) && isset( $student_enrollments[ $eca_id ] ) ) {
			unset( $student_enrollments[ $eca_id ] );
			update_post_meta( $student_id, '_student_eca_enrollments', $student_enrollments );
		}

		// Decrement enrollment count if was confirmed.
		if ( $was_confirmed ) {
			$current_count = absint( get_post_meta( $eca_id, '_eca_current_enrollment', true ) );
			$new_count     = max( 0, $current_count - 1 );
			update_post_meta( $eca_id, '_eca_current_enrollment', $new_count );

			// Update status from full to active if now has capacity.
			$max_students = absint( get_post_meta( $eca_id, '_eca_max_students', true ) );
			$eca_status   = get_post_meta( $eca_id, '_eca_status', true );
			if ( 'full' === $eca_status && $max_students > 0 && $new_count < $max_students ) {
				update_post_meta( $eca_id, '_eca_status', 'active' );
			}
		}

		// Log withdrawal in change history.
		$change_history = get_post_meta( $eca_id, '_eca_change_history', true );
		if ( ! is_array( $change_history ) ) {
			$change_history = array();
		}
		$change_history[] = array(
			'action'         => 'withdrawal',
			'student_id'     => $student_id,
			'student_name'   => $student->post_title,
			'reason'         => $reason,
			'effective_date' => $effective_date,
			'process_refund' => $process_refund,
			'performed_by'   => $current_user_id,
			'timestamp'      => current_time( 'mysql' ),
		);
		update_post_meta( $eca_id, '_eca_change_history', $change_history );

		// Auto-promote next waitlisted student if a confirmed student was withdrawn.
		$waitlist_promoted = null;
		if ( $was_confirmed ) {
			$waitlist_promoted = $this->auto_promote_next( $eca_id, $eca_enrollments );
		}

		/**
		 * Fires when a student is withdrawn from an ECA.
		 *
		 * @param int    $student_id    Student post ID.
		 * @param int    $eca_id        ECA post ID.
		 * @param string $reason        Withdrawal reason.
		 * @param bool   $process_refund Whether refund was requested.
		 */
		do_action( 'wp_mcp_ai_eca_student_withdrawn', $student_id, $eca_id, $reason, $process_refund );

		$result = array(
			'success'        => true,
			'student_id'     => $student_id,
			'student_name'   => $student->post_title,
			'eca_id'         => $eca_id,
			'eca_name'       => $eca->post_title,
			'reason'         => $reason,
			'effective_date' => $effective_date,
			'process_refund' => $process_refund,
			'message'        => sprintf(
				/* translators: 1: student name, 2: ECA name */
				__( '%1$s has been withdrawn from %2$s.', 'mcp-ai-wpoos-pro' ),
				$student->post_title,
				$eca->post_title
			),
		);

		if ( $waitlist_promoted ) {
			$result['waitlist_promoted'] = $waitlist_promoted;
		}

		return $result;
	}

	/**
	 * Auto-promote the next waitlisted student.
	 *
	 * @param int   $eca_id          ECA post ID.
	 * @param array $eca_enrollments Current ECA enrollments (already updated).
	 * @return array|null Promoted student info or null if no waitlist.
	 */
	private function auto_promote_next( $eca_id, $eca_enrollments ) {
		// Re-read enrollments to get updated state.
		$eca_enrollments = get_post_meta( $eca_id, '_eca_student_enrollments', true );
		if ( ! is_array( $eca_enrollments ) ) {
			return null;
		}

		// Find waitlisted students sorted by enrollment date.
		$waitlist = array();
		foreach ( $eca_enrollments as $sid => $enrollment ) {
			if ( isset( $enrollment['enrollment_type'] ) && 'waitlist' === $enrollment['enrollment_type'] ) {
				$waitlist[ $sid ] = $enrollment;
			}
		}

		if ( empty( $waitlist ) ) {
			return null;
		}

		uasort(
			$waitlist,
			function ( $a, $b ) {
				$date_a = isset( $a['enrollment_date'] ) ? $a['enrollment_date'] : '';
				$date_b = isset( $b['enrollment_date'] ) ? $b['enrollment_date'] : '';
				return strcmp( $date_a, $date_b );
			}
		);

		$waitlist_ids = array_keys( $waitlist );
		$promote_id   = absint( reset( $waitlist_ids ) );

		// Update ECA-side enrollment.
		$eca_enrollments[ $promote_id ]['enrollment_type'] = 'confirmed';
		update_post_meta( $eca_id, '_eca_student_enrollments', $eca_enrollments );

		// Update student-side enrollment.
		$student_enrollments = get_post_meta( $promote_id, '_student_eca_enrollments', true );
		if ( is_array( $student_enrollments ) && isset( $student_enrollments[ $eca_id ] ) ) {
			$student_enrollments[ $eca_id ]['enrollment_type'] = 'confirmed';
			update_post_meta( $promote_id, '_student_eca_enrollments', $student_enrollments );
		}

		// Increment enrollment count for the promoted student.
		$current_count = absint( get_post_meta( $eca_id, '_eca_current_enrollment', true ) );
		update_post_meta( $eca_id, '_eca_current_enrollment', $current_count + 1 );

		$promoted_student = get_post( $promote_id );

		/**
		 * Fires when a student is promoted from the waitlist.
		 *
		 * @param int $student_id Student post ID.
		 * @param int $eca_id     ECA post ID.
		 */
		do_action( 'wp_mcp_ai_eca_waitlist_promoted', $promote_id, $eca_id );

		return array(
			'student_id'   => $promote_id,
			'student_name' => $promoted_student ? $promoted_student->post_title : __( 'Unknown', 'mcp-ai-wpoos-pro' ),
			'message'      => sprintf(
				/* translators: %s: student name */
				__( '%s has been auto-promoted from the waitlist.', 'mcp-ai-wpoos-pro' ),
				$promoted_student ? $promoted_student->post_title : __( 'Student', 'mcp-ai-wpoos-pro' )
			),
		);
	}
}
