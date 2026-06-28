<?php
/**
 * Tool for sending ECA parent reports.
 *
 * Generates and emails a participation report to a student's parents.
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
 * Generates and emails a participation report to a student's parents.
 */
class WP_MCP_AI_Tool_Send_ECA_Parent_Report implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_eca_parent_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send ECA Parent Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates and emails a participation report to a student\'s parents. Includes ECA enrollment details, attendance rates, and optional teacher notes.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'student_id'           => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the student (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'term'                 => array(
					'type'        => 'string',
					'description' => __( 'Academic term for the report', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'Term 1', 'Term 2', 'Term 3', 'Yearly' ),
				),
				'include_attendance'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include attendance rates in the report', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_achievements' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include achievements in the report', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'custom_notes'         => array(
					'type'        => 'string',
					'description' => __( 'Optional teacher notes to include in the report', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
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
		return array( 'pro', 'database-write', 'email' );
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
				__( 'You do not have permission to send parent reports.', 'mcp-ai-wpoos-pro' )
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

		$term        = isset( $arguments['term'] ) ? sanitize_text_field( $arguments['term'] ) : '';
		$valid_terms = array( 'Term 1', 'Term 2', 'Term 3', 'Yearly' );
		if ( $term && ! in_array( $term, $valid_terms, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_term',
				__( 'Invalid term. Must be Term 1, Term 2, Term 3, or Yearly.', 'mcp-ai-wpoos-pro' )
			);
		}

		$include_attendance   = isset( $arguments['include_attendance'] ) ? (bool) $arguments['include_attendance'] : true;
		$include_achievements = isset( $arguments['include_achievements'] ) ? (bool) $arguments['include_achievements'] : false;
		$custom_notes         = isset( $arguments['custom_notes'] ) ? sanitize_textarea_field( $arguments['custom_notes'] ) : '';

		// Get student's ECA enrollments.
		$enrollments = get_post_meta( $student_id, '_student_eca_enrollments', true );
		if ( ! is_array( $enrollments ) ) {
			$enrollments = array();
		}

		if ( empty( $enrollments ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_enrollments',
				__( 'This student has no ECA enrollments.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build ECA details for report.
		$eca_details = array();

		foreach ( $enrollments as $eca_id => $enrollment ) {
			$eca_id = absint( $eca_id );
			$eca    = get_post( $eca_id );
			if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
				continue;
			}

			$detail = array(
				'name'   => sanitize_text_field( $eca->post_title ),
				'type'   => sanitize_text_field( get_post_meta( $eca_id, '_eca_type', true ) ),
				'day'    => sanitize_text_field( get_post_meta( $eca_id, '_eca_day', true ) ),
				'time'   => sanitize_text_field( get_post_meta( $eca_id, '_eca_time', true ) ),
				'venue'  => sanitize_text_field( get_post_meta( $eca_id, '_eca_venue', true ) ),
				'status' => isset( $enrollment['enrollment_type'] ) ? sanitize_key( $enrollment['enrollment_type'] ) : 'unknown',
			);

			// Calculate attendance rate if requested.
			if ( $include_attendance ) {
				$detail['attendance_rate'] = $this->calculate_attendance_rate( $eca_id, $student_id );
			}

			$eca_details[] = $detail;
		}

		if ( empty( $eca_details ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_valid_ecas',
				__( 'No valid ECA enrollments found for this student.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build HTML report.
		$student_name = sanitize_text_field( $student->post_title );
		$report_html  = $this->build_report_html( $student_name, $term, $eca_details, $include_attendance, $include_achievements, $custom_notes );

		// Determine recipient email.
		$parent_email = sanitize_email( get_post_meta( $student_id, '_student_parent_email', true ) );
		if ( ! $parent_email || ! is_email( $parent_email ) ) {
			$parent_email = sanitize_email( get_post_meta( $student_id, '_student_email', true ) );
		}

		if ( ! $parent_email || ! is_email( $parent_email ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_email',
				__( 'No valid email address found for student or parent.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Send email.
		$subject = sprintf(
			/* translators: 1: student name, 2: term label */
			__( 'ECA Participation Report: %1$s%2$s', 'mcp-ai-wpoos-pro' ),
			$student_name,
			$term ? ' - ' . $term : ''
		);
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$sent    = wp_mail( $parent_email, $subject, $report_html, $headers );

		if ( ! $sent ) {
			return new WP_Error(
				'wp_mcp_ai_email_failed',
				__( 'Failed to send the report email.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Log report in student meta.
		$reports_sent = get_post_meta( $student_id, '_student_reports_sent', true );
		if ( ! is_array( $reports_sent ) ) {
			$reports_sent = array();
		}
		$reports_sent[] = array(
			'term'    => $term ? $term : 'N/A',
			'sent_at' => current_time( 'mysql' ),
			'sent_by' => $current_user_id,
			'sent_to' => $parent_email,
		);
		update_post_meta( $student_id, '_student_reports_sent', $reports_sent );

		return array(
			'success'         => true,
			'student_id'      => $student_id,
			'student_name'    => $student_name,
			'term'            => $term ? $term : 'N/A',
			'recipient_email' => $parent_email,
			'ecas_included'   => count( $eca_details ),
			'report_summary'  => $eca_details,
			'message'         => sprintf(
				/* translators: 1: student name, 2: email address */
				__( 'ECA parent report for %1$s sent to %2$s.', 'mcp-ai-wpoos-pro' ),
				$student_name,
				$parent_email
			),
		);
	}

	/**
	 * Calculate attendance rate for a student in a specific ECA.
	 *
	 * @param int $eca_id     ECA post ID.
	 * @param int $student_id Student post ID.
	 * @return float Attendance rate as a percentage.
	 */
	private function calculate_attendance_rate( $eca_id, $student_id ) {
		$attendance_log = get_post_meta( $eca_id, '_eca_attendance_log', true );
		if ( ! is_array( $attendance_log ) || empty( $attendance_log ) ) {
			return 0.0;
		}

		$total_sessions = 0;
		$attended       = 0;

		foreach ( $attendance_log as $session ) {
			if ( ! isset( $session['attendees'] ) || ! is_array( $session['attendees'] ) ) {
				continue;
			}

			foreach ( $session['attendees'] as $attendee ) {
				if ( isset( $attendee['student_id'] ) && absint( $attendee['student_id'] ) === $student_id ) {
					++$total_sessions;
					if ( isset( $attendee['status'] ) && in_array( $attendee['status'], array( 'present', 'late' ), true ) ) {
						++$attended;
					}
					break;
				}
			}
		}

		if ( 0 === $total_sessions ) {
			return 0.0;
		}

		return round( ( $attended / $total_sessions ) * 100, 1 );
	}

	/**
	 * Build the HTML report email body.
	 *
	 * @param string $student_name        Student display name.
	 * @param string $term                Academic term.
	 * @param array  $eca_details         Array of ECA detail arrays.
	 * @param bool   $include_attendance  Whether to include attendance rates.
	 * @param bool   $include_achievements Whether to include achievements.
	 * @param string $custom_notes        Optional teacher notes.
	 * @return string HTML report body.
	 */
	private function build_report_html( $student_name, $term, $eca_details, $include_attendance, $include_achievements, $custom_notes ) {
		$html  = '<html><body style="font-family:Arial,sans-serif;color:#333;">';
		$html .= '<h2>' . esc_html__( 'ECA Participation Report', 'mcp-ai-wpoos-pro' ) . '</h2>';
		$html .= '<p><strong>' . esc_html__( 'Student:', 'mcp-ai-wpoos-pro' ) . '</strong> ' . esc_html( $student_name ) . '</p>';

		if ( $term ) {
			$html .= '<p><strong>' . esc_html__( 'Term:', 'mcp-ai-wpoos-pro' ) . '</strong> ' . esc_html( $term ) . '</p>';
		}

		$html .= '<hr style="margin:16px 0;" />';
		$html .= '<h3>' . esc_html__( 'Enrolled Activities', 'mcp-ai-wpoos-pro' ) . '</h3>';

		foreach ( $eca_details as $eca ) {
			$html .= '<div style="margin-bottom:16px;padding:12px;border:1px solid #ddd;border-radius:4px;">';
			$html .= '<h4 style="margin:0 0 8px 0;">' . esc_html( $eca['name'] ) . '</h4>';
			$html .= '<table style="border-collapse:collapse;width:100%;">';

			if ( ! empty( $eca['type'] ) ) {
				$html .= '<tr><td style="padding:2px 8px;font-weight:bold;">' . esc_html__( 'Type', 'mcp-ai-wpoos-pro' ) . '</td><td style="padding:2px 8px;">' . esc_html( $eca['type'] ) . '</td></tr>';
			}
			if ( ! empty( $eca['day'] ) ) {
				$html .= '<tr><td style="padding:2px 8px;font-weight:bold;">' . esc_html__( 'Day', 'mcp-ai-wpoos-pro' ) . '</td><td style="padding:2px 8px;">' . esc_html( $eca['day'] ) . '</td></tr>';
			}
			if ( ! empty( $eca['time'] ) ) {
				$html .= '<tr><td style="padding:2px 8px;font-weight:bold;">' . esc_html__( 'Time', 'mcp-ai-wpoos-pro' ) . '</td><td style="padding:2px 8px;">' . esc_html( $eca['time'] ) . '</td></tr>';
			}
			if ( ! empty( $eca['venue'] ) ) {
				$html .= '<tr><td style="padding:2px 8px;font-weight:bold;">' . esc_html__( 'Venue', 'mcp-ai-wpoos-pro' ) . '</td><td style="padding:2px 8px;">' . esc_html( $eca['venue'] ) . '</td></tr>';
			}

			$html .= '<tr><td style="padding:2px 8px;font-weight:bold;">' . esc_html__( 'Status', 'mcp-ai-wpoos-pro' ) . '</td><td style="padding:2px 8px;">' . esc_html( ucfirst( $eca['status'] ) ) . '</td></tr>';

			if ( $include_attendance && isset( $eca['attendance_rate'] ) ) {
				$html .= '<tr><td style="padding:2px 8px;font-weight:bold;">' . esc_html__( 'Attendance Rate', 'mcp-ai-wpoos-pro' ) . '</td><td style="padding:2px 8px;">' . esc_html( $eca['attendance_rate'] . '%' ) . '</td></tr>';
			}

			$html .= '</table>';
			$html .= '</div>';
		}

		if ( $include_achievements ) {
			$html .= '<hr style="margin:16px 0;" />';
			$html .= '<h3>' . esc_html__( 'Achievements', 'mcp-ai-wpoos-pro' ) . '</h3>';
			$html .= '<p>' . esc_html__( 'No achievements data available at this time.', 'mcp-ai-wpoos-pro' ) . '</p>';
		}

		if ( $custom_notes ) {
			$html .= '<hr style="margin:16px 0;" />';
			$html .= '<h3>' . esc_html__( 'Teacher Notes', 'mcp-ai-wpoos-pro' ) . '</h3>';
			$html .= '<p>' . nl2br( esc_html( $custom_notes ) ) . '</p>';
		}

		$html .= '</body></html>';

		return $html;
	}
}
