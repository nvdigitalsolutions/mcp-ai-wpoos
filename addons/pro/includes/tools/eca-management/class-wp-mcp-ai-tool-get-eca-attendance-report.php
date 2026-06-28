<?php
/**
 * Tool for retrieving ECA attendance reports.
 *
 * Retrieves attendance data with analytics for an ECA.
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
 * Retrieves attendance data with analytics for an ECA.
 */
class WP_MCP_AI_Tool_Get_ECA_Attendance_Report implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_eca_attendance_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get ECA Attendance Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves attendance data with analytics for an ECA. Supports filtering by date range and student, and returns summary or detailed breakdowns with Chart.js-compatible datasets.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'     => array(
					'type'        => 'integer',
					'description' => __( 'WordPress post ID of the ECA (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'student_id' => array(
					'type'        => 'integer',
					'description' => __( 'Filter attendance for a specific student', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'date_from'  => array(
					'type'        => 'string',
					'description' => __( 'Start date for the report range in YYYY-MM-DD format', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 10,
					'maxLength'   => 10,
				),
				'date_to'    => array(
					'type'        => 'string',
					'description' => __( 'End date for the report range in YYYY-MM-DD format', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 10,
					'maxLength'   => 10,
				),
				'format'     => array(
					'type'        => 'string',
					'description' => __( 'Report format: summary returns totals and rates, detailed includes per-session breakdown', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'summary', 'detailed' ),
					'default'     => 'summary',
				),
			),
			'required'             => array( 'eca_id' ),
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to view attendance reports.', 'mcp-ai-wpoos-pro' )
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

		// Get parameters.
		$student_id = isset( $arguments['student_id'] ) ? absint( $arguments['student_id'] ) : 0;
		$date_from  = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : '';
		$date_to    = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : '';
		$format     = isset( $arguments['format'] ) ? sanitize_key( $arguments['format'] ) : 'summary';

		if ( ! in_array( $format, array( 'summary', 'detailed' ), true ) ) {
			$format = 'summary';
		}

		// Validate student if provided.
		if ( $student_id ) {
			$student = get_post( $student_id );
			if ( ! $student || 'mcp_ai_student' !== $student->post_type ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_student',
					__( 'Invalid student ID.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Retrieve attendance log.
		$attendance_log = get_post_meta( $eca_id, '_eca_attendance_log', true );
		if ( ! is_array( $attendance_log ) || empty( $attendance_log ) ) {
			return array(
				'success'        => true,
				'eca_id'         => $eca_id,
				'eca_name'       => $eca->post_title,
				'total_sessions' => 0,
				'message'        => __( 'No attendance records found for this ECA.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Filter by date range.
		$filtered_log = $this->filter_by_date_range( $attendance_log, $date_from, $date_to );

		if ( empty( $filtered_log ) ) {
			return array(
				'success'        => true,
				'eca_id'         => $eca_id,
				'eca_name'       => $eca->post_title,
				'total_sessions' => 0,
				'message'        => __( 'No attendance records found for the specified date range.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Calculate analytics.
		$analytics     = $this->calculate_analytics( $filtered_log, $student_id );
		$chart_data    = $this->build_chart_data( $filtered_log );
		$student_rates = $this->calculate_student_rates( $filtered_log );

		$result = array(
			'success'         => true,
			'eca_id'          => $eca_id,
			'eca_name'        => $eca->post_title,
			'total_sessions'  => $analytics['total_sessions'],
			'attendance_rate' => $analytics['attendance_rate'],
			'summary'         => $analytics['summary'],
			'student_rates'   => $student_rates,
			'chart_data'      => $chart_data,
		);

		if ( $student_id ) {
			$result['student_filter'] = array(
				'student_id'   => $student_id,
				'student_name' => isset( $student ) ? sanitize_text_field( $student->post_title ) : '',
			);
		}

		// Include per-session breakdown for detailed format.
		if ( 'detailed' === $format ) {
			$result['sessions'] = $this->build_session_breakdown( $filtered_log, $student_id );
		}

		return $result;
	}

	/**
	 * Filter attendance log by date range.
	 *
	 * @param array  $attendance_log Full attendance log.
	 * @param string $date_from      Start date (YYYY-MM-DD) or empty.
	 * @param string $date_to        End date (YYYY-MM-DD) or empty.
	 * @return array Filtered attendance log.
	 */
	private function filter_by_date_range( $attendance_log, $date_from, $date_to ) {
		if ( '' === $date_from && '' === $date_to ) {
			return $attendance_log;
		}

		$filtered = array();
		foreach ( $attendance_log as $date => $session ) {
			if ( '' !== $date_from && $date < $date_from ) {
				continue;
			}
			if ( '' !== $date_to && $date > $date_to ) {
				continue;
			}
			$filtered[ $date ] = $session;
		}

		return $filtered;
	}

	/**
	 * Calculate attendance analytics.
	 *
	 * @param array $filtered_log Filtered attendance log.
	 * @param int   $student_id   Optional student ID filter.
	 * @return array Analytics data.
	 */
	private function calculate_analytics( $filtered_log, $student_id ) {
		$total_sessions = count( $filtered_log );
		$total_present  = 0;
		$total_absent   = 0;
		$total_late     = 0;
		$total_excused  = 0;
		$total_records  = 0;

		foreach ( $filtered_log as $session ) {
			$attendees = isset( $session['attendees'] ) && is_array( $session['attendees'] ) ? $session['attendees'] : array();

			foreach ( $attendees as $attendee ) {
				// Filter by student if requested.
				if ( $student_id && absint( $attendee['student_id'] ) !== $student_id ) {
					continue;
				}

				++$total_records;
				$status = isset( $attendee['status'] ) ? $attendee['status'] : '';

				switch ( $status ) {
					case 'present':
						++$total_present;
						break;
					case 'absent':
						++$total_absent;
						break;
					case 'late':
						++$total_late;
						break;
					case 'excused':
						++$total_excused;
						break;
				}
			}
		}

		$attendance_rate = $total_records > 0
			? round( ( ( $total_present + $total_late ) / $total_records ) * 100, 1 )
			: 0;

		return array(
			'total_sessions'  => $total_sessions,
			'attendance_rate' => $attendance_rate,
			'summary'         => array(
				'total_records' => $total_records,
				'present'       => $total_present,
				'absent'        => $total_absent,
				'late'          => $total_late,
				'excused'       => $total_excused,
			),
		);
	}

	/**
	 * Calculate per-student attendance rates.
	 *
	 * @param array $filtered_log Filtered attendance log.
	 * @return array Per-student attendance rates.
	 */
	private function calculate_student_rates( $filtered_log ) {
		$student_data = array();

		foreach ( $filtered_log as $session ) {
			$attendees = isset( $session['attendees'] ) && is_array( $session['attendees'] ) ? $session['attendees'] : array();

			foreach ( $attendees as $attendee ) {
				$sid    = absint( $attendee['student_id'] );
				$status = isset( $attendee['status'] ) ? $attendee['status'] : '';

				if ( ! isset( $student_data[ $sid ] ) ) {
					$student_data[ $sid ] = array(
						'student_id'   => $sid,
						'student_name' => isset( $attendee['student_name'] ) ? $attendee['student_name'] : '',
						'total'        => 0,
						'present'      => 0,
						'absent'       => 0,
						'late'         => 0,
						'excused'      => 0,
					);
				}

				++$student_data[ $sid ]['total'];
				if ( isset( $student_data[ $sid ][ $status ] ) ) {
					++$student_data[ $sid ][ $status ];
				}
			}
		}

		// Calculate rates.
		$rates = array();
		foreach ( $student_data as $sid => $data ) {
			$total        = $data['total'];
			$data['rate'] = $total > 0
				? round( ( ( $data['present'] + $data['late'] ) / $total ) * 100, 1 )
				: 0;
			$rates[]      = $data;
		}

		return $rates;
	}

	/**
	 * Build Chart.js-compatible data.
	 *
	 * @param array $filtered_log Filtered attendance log.
	 * @return array Chart.js-compatible chart data.
	 */
	private function build_chart_data( $filtered_log ) {
		ksort( $filtered_log );

		$labels  = array();
		$present = array();
		$absent  = array();
		$late    = array();
		$excused = array();

		foreach ( $filtered_log as $date => $session ) {
			$labels[]  = $date;
			$present[] = isset( $session['present_count'] ) ? absint( $session['present_count'] ) : 0;
			$absent[]  = isset( $session['absent_count'] ) ? absint( $session['absent_count'] ) : 0;
			$late[]    = isset( $session['late_count'] ) ? absint( $session['late_count'] ) : 0;
			$excused[] = isset( $session['excused_count'] ) ? absint( $session['excused_count'] ) : 0;
		}

		return array(
			'labels'   => $labels,
			'datasets' => array(
				array(
					'label' => __( 'Present', 'mcp-ai-wpoos-pro' ),
					'data'  => $present,
				),
				array(
					'label' => __( 'Absent', 'mcp-ai-wpoos-pro' ),
					'data'  => $absent,
				),
				array(
					'label' => __( 'Late', 'mcp-ai-wpoos-pro' ),
					'data'  => $late,
				),
				array(
					'label' => __( 'Excused', 'mcp-ai-wpoos-pro' ),
					'data'  => $excused,
				),
			),
		);
	}

	/**
	 * Build per-session breakdown for detailed reports.
	 *
	 * @param array $filtered_log Filtered attendance log.
	 * @param int   $student_id   Optional student ID filter.
	 * @return array Per-session breakdown.
	 */
	private function build_session_breakdown( $filtered_log, $student_id ) {
		ksort( $filtered_log );

		$sessions = array();
		foreach ( $filtered_log as $date => $session ) {
			$attendees = isset( $session['attendees'] ) && is_array( $session['attendees'] ) ? $session['attendees'] : array();

			// Filter attendees by student if needed.
			if ( $student_id ) {
				$attendees = array_values(
					array_filter(
						$attendees,
						function ( $a ) use ( $student_id ) {
							return absint( $a['student_id'] ) === $student_id;
						}
					)
				);
			}

			$sessions[] = array(
				'date'          => $date,
				'session_notes' => isset( $session['session_notes'] ) ? $session['session_notes'] : '',
				'recorded_by'   => isset( $session['recorded_by'] ) ? absint( $session['recorded_by'] ) : 0,
				'recorded_at'   => isset( $session['recorded_at'] ) ? $session['recorded_at'] : '',
				'attendees'     => $attendees,
			);
		}

		return $sessions;
	}
}
