<?php
/**
 * Tool for marking ECA attendance.
 *
 * Records attendance for students in an ECA session.
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
 * Records attendance for students in an ECA session.
 */
class WP_MCP_AI_Tool_Mark_ECA_Attendance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'mark_eca_attendance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Mark ECA Attendance', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Records attendance for students in an ECA session. Tracks present, absent, late, and excused statuses with optional notes per student and per session.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'        => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the ECA (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'session_date'  => array(
					'type'        => 'string',
					'description' => __( 'Date of the session in YYYY-MM-DD format (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 10,
					'maxLength'   => 10,
				),
				'attendees'     => array(
					'type'        => 'array',
					'description' => __( 'Array of attendance records for each student (required)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'                 => 'object',
						'properties'           => array(
							'student_id' => array(
								'type'        => 'integer',
								'description' => __( 'WordPress post ID of the student', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 1,
							),
							'status'     => array(
								'type'        => 'string',
								'description' => __( 'Attendance status', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'present', 'absent', 'late', 'excused' ),
							),
							'notes'      => array(
								'type'        => 'string',
								'description' => __( 'Optional notes for this student', 'mcp-ai-wpoos-pro' ),
								'maxLength'   => 500,
							),
						),
						'required'             => array( 'student_id', 'status' ),
						'additionalProperties' => false,
					),
				),
				'session_notes' => array(
					'type'        => 'string',
					'description' => __( 'Optional notes for the overall session', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 1000,
				),
			),
			'required'             => array( 'eca_id', 'session_date', 'attendees' ),
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
			'profession_tags'       => array( 'educator', 'school_admin', 'activities_coordinator' ),
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
				__( 'You do not have permission to mark attendance.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate ECA.
		$eca_id = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;
		if ( ! $eca_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_id',
				__( 'ECA ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$eca = get_post( $eca_id );
		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_eca',
				__( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate session date.
		$session_date = isset( $arguments['session_date'] ) ? sanitize_text_field( $arguments['session_date'] ) : '';
		if ( ! $session_date || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $session_date ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_date',
				__( 'A valid session date in YYYY-MM-DD format is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate attendees.
		$attendees = isset( $arguments['attendees'] ) && is_array( $arguments['attendees'] ) ? $arguments['attendees'] : array();
		if ( empty( $attendees ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_attendees',
				__( 'At least one attendee record is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$session_notes  = isset( $arguments['session_notes'] ) ? sanitize_textarea_field( $arguments['session_notes'] ) : '';
		$valid_statuses = array( 'present', 'absent', 'late', 'excused' );

		// Validate each attendee.
		$sanitized_attendees = array();
		$present_count       = 0;
		$absent_count        = 0;
		$late_count          = 0;
		$excused_count       = 0;

		foreach ( $attendees as $attendee ) {
			$student_id = isset( $attendee['student_id'] ) ? absint( $attendee['student_id'] ) : 0;
			if ( ! $student_id ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_attendee',
					__( 'Each attendee must have a valid student_id.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Verify student exists.
			$student = get_post( $student_id );
			if ( ! $student || 'mcp_ai_student' !== $student->post_type ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_student',
					sprintf(
						/* translators: %d: student ID */
						__( 'Invalid student ID: %d', 'mcp-ai-wpoos-pro' ),
						$student_id
					)
				);
			}

			$status = isset( $attendee['status'] ) ? sanitize_key( $attendee['status'] ) : '';
			if ( ! in_array( $status, $valid_statuses, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_status',
					sprintf(
						/* translators: %d: student ID */
						__( 'Invalid attendance status for student ID: %d', 'mcp-ai-wpoos-pro' ),
						$student_id
					)
				);
			}

			$notes = isset( $attendee['notes'] ) ? sanitize_textarea_field( $attendee['notes'] ) : '';

			$sanitized_attendees[] = array(
				'student_id'   => $student_id,
				'student_name' => sanitize_text_field( $student->post_title ),
				'status'       => $status,
				'notes'        => $notes,
			);

			// Count statuses.
			switch ( $status ) {
				case 'present':
					++$present_count;
					break;
				case 'absent':
					++$absent_count;
					break;
				case 'late':
					++$late_count;
					break;
				case 'excused':
					++$excused_count;
					break;
			}
		}

		// Build session record.
		$session_record = array(
			'date'          => $session_date,
			'recorded_by'   => $current_user_id,
			'recorded_at'   => current_time( 'mysql' ),
			'session_notes' => $session_notes,
			'attendees'     => $sanitized_attendees,
			'present_count' => $present_count,
			'absent_count'  => $absent_count,
			'late_count'    => $late_count,
			'excused_count' => $excused_count,
		);

		// Store in ECA attendance log.
		$attendance_log = get_post_meta( $eca_id, '_eca_attendance_log', true );
		if ( ! is_array( $attendance_log ) ) {
			$attendance_log = array();
		}
		$attendance_log[ $session_date ] = $session_record;
		update_post_meta( $eca_id, '_eca_attendance_log', $attendance_log );

		return array(
			'success'          => true,
			'eca_id'           => $eca_id,
			'eca_name'         => $eca->post_title,
			'session_date'     => $session_date,
			'attendance_count' => count( $sanitized_attendees ),
			'present_count'    => $present_count,
			'absent_count'     => $absent_count,
			'late_count'       => $late_count,
			'excused_count'    => $excused_count,
			'message'          => sprintf(
				/* translators: 1: ECA name, 2: session date */
				__( 'Attendance recorded for %1$s on %2$s.', 'mcp-ai-wpoos-pro' ),
				$eca->post_title,
				$session_date
			),
		);
	}
}
