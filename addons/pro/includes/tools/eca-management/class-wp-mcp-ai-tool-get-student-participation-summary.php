<?php
/**
 * Tool for retrieving a student's cross-ECA participation summary.
 *
 * Provides a comprehensive participation report for a student across all enrolled ECAs.
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
 * Cross-ECA participation report for a student.
 */
class WP_MCP_AI_Tool_Get_Student_Participation_Summary implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_student_participation_summary';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Student Participation Summary', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves a cross-ECA participation report for a student, including enrollment details and attendance rates for each ECA, with an overall engagement score.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'student_id'         => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the student (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'term'               => array(
					'type'        => 'string',
					'description' => __( 'Filter by academic term', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'include_attendance' => array(
					'type'        => 'boolean',
					'description' => __( 'Include attendance rate calculations for each ECA', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'student_id' ),
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
			'post_type'             => 'mcp_ai_student',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'educator', 'school_admin', 'activities_coordinator' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view student participation data.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate student.
		$student_id = isset( $arguments['student_id'] ) ? absint( $arguments['student_id'] ) : 0;
		if ( ! $student_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_id',
				__( 'Student ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$student = get_post( $student_id );
		if ( ! $student || 'mcp_ai_student' !== $student->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_student',
				__( 'Invalid student ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		$term               = isset( $arguments['term'] ) ? sanitize_text_field( $arguments['term'] ) : '';
		$include_attendance = isset( $arguments['include_attendance'] ) ? (bool) $arguments['include_attendance'] : true;

		// Get student enrollments.
		$enrollments = get_post_meta( $student_id, '_student_eca_enrollments', true );
		if ( ! is_array( $enrollments ) ) {
			$enrollments = array();
		}

		// Build ECA participation data.
		$ecas_enrolled   = array();
		$total_rate_sum  = 0;
		$rated_eca_count = 0;
		$active_count    = 0;
		$total_sessions  = 0;
		$total_present   = 0;

		foreach ( $enrollments as $eca_id => $enrollment ) {
			$eca_id   = absint( $eca_id );
			$eca_post = get_post( $eca_id );
			if ( ! $eca_post || 'mcp_ai_eca' !== $eca_post->post_type ) {
				continue;
			}

			// Filter by term if provided.
			if ( '' !== $term ) {
				$eca_term = get_post_meta( $eca_id, '_eca_term', true );
				if ( $eca_term !== $term ) {
					continue;
				}
			}

			$eca_status = get_post_meta( $eca_id, '_eca_status', true );
			if ( 'active' === $eca_status || 'full' === $eca_status ) {
				++$active_count;
			}

			$eca_entry = array(
				'eca_id'          => $eca_id,
				'eca_name'        => $eca_post->post_title,
				'eca_type'        => get_post_meta( $eca_id, '_eca_type', true ),
				'eca_status'      => $eca_status,
				'enrollment_type' => isset( $enrollment['enrollment_type'] ) ? $enrollment['enrollment_type'] : 'confirmed',
				'enrollment_date' => isset( $enrollment['enrollment_date'] ) ? $enrollment['enrollment_date'] : '',
				'day'             => get_post_meta( $eca_id, '_eca_day', true ),
				'venue'           => get_post_meta( $eca_id, '_eca_venue', true ),
			);

			// Calculate attendance for this ECA.
			if ( $include_attendance ) {
				$attendance_stats        = $this->calculate_student_attendance( $eca_id, $student_id );
				$eca_entry['attendance'] = $attendance_stats;

				if ( $attendance_stats['sessions_recorded'] > 0 ) {
					$total_rate_sum += $attendance_stats['attendance_rate'];
					++$rated_eca_count;
					$total_sessions += $attendance_stats['sessions_recorded'];
					$total_present  += $attendance_stats['present'] + $attendance_stats['late'];
				}
			}

			$ecas_enrolled[] = $eca_entry;
		}

		// Calculate overall engagement score.
		$engagement_score = $rated_eca_count > 0
			? round( $total_rate_sum / $rated_eca_count, 1 )
			: 0;

		$overall_attendance_rate = $total_sessions > 0
			? round( ( $total_present / $total_sessions ) * 100, 1 )
			: 0;

		// Build student info.
		$student_info = array(
			'student_id'   => $student_id,
			'student_name' => sanitize_text_field( $student->post_title ),
			'year_group'   => get_post_meta( $student_id, '_student_year_group', true ),
		);

		return array(
			'success'       => true,
			'student'       => $student_info,
			'ecas_enrolled' => $ecas_enrolled,
			'overall_stats' => array(
				'total_ecas'              => count( $ecas_enrolled ),
				'active_ecas'             => $active_count,
				'total_sessions_attended' => $total_sessions,
				'overall_attendance_rate' => $overall_attendance_rate,
				'engagement_score'        => $engagement_score,
			),
			'filters'       => array(
				'term'               => $term,
				'include_attendance' => $include_attendance,
			),
		);
	}

	/**
	 * Calculate attendance statistics for a student in a specific ECA.
	 *
	 * @param int $eca_id     ECA post ID.
	 * @param int $student_id Student post ID.
	 * @return array Attendance statistics.
	 */
	private function calculate_student_attendance( $eca_id, $student_id ) {
		$attendance_log = get_post_meta( $eca_id, '_eca_attendance_log', true );

		$stats = array(
			'sessions_recorded' => 0,
			'present'           => 0,
			'absent'            => 0,
			'late'              => 0,
			'excused'           => 0,
			'attendance_rate'   => 0,
		);

		if ( ! is_array( $attendance_log ) || empty( $attendance_log ) ) {
			return $stats;
		}

		foreach ( $attendance_log as $session ) {
			$attendees = isset( $session['attendees'] ) && is_array( $session['attendees'] ) ? $session['attendees'] : array();

			foreach ( $attendees as $attendee ) {
				if ( absint( $attendee['student_id'] ) !== $student_id ) {
					continue;
				}

				++$stats['sessions_recorded'];
				$status = isset( $attendee['status'] ) ? $attendee['status'] : '';

				if ( isset( $stats[ $status ] ) ) {
					++$stats[ $status ];
				}
			}
		}

		if ( $stats['sessions_recorded'] > 0 ) {
			$stats['attendance_rate'] = round(
				( ( $stats['present'] + $stats['late'] ) / $stats['sessions_recorded'] ) * 100,
				1
			);
		}

		return $stats;
	}
}
