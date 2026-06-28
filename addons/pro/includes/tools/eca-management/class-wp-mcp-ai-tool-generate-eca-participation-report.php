<?php
/**
 * Tool for generating detailed ECA participation reports.
 *
 * Generates participation reports scoped to an ECA, student, year group,
 * or entire school with structured data for dashboards or documents.
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
 * Generates detailed ECA participation reports scoped by ECA, student, year group, or school.
 */
class WP_MCP_AI_Tool_Generate_ECA_Participation_Report implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_eca_participation_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate ECA Participation Report', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates a detailed participation report scoped to an ECA, student, year group, or entire school. Returns structured data for embedding in documents or dashboards.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'scope'    => array(
					'type'        => 'string',
					'description' => __( 'Report scope: eca, student, year_group, or school', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'eca', 'student', 'year_group', 'school' ),
				),
				'scope_id' => array(
					'type'        => 'string',
					'description' => __( 'ID for the scope: ECA post ID for eca scope, student post ID for student scope, year group name for year_group scope. Not required for school scope.', 'mcp-ai-wpoos-pro' ),
				),
				'term'     => array(
					'type'        => 'string',
					'description' => __( 'Academic term filter', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'Term 1', 'Term 2', 'Term 3', 'Yearly' ),
				),
				'format'   => array(
					'type'        => 'string',
					'description' => __( 'Output format: json or markdown', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'json', 'markdown' ),
					'default'     => 'json',
				),
			),
			'required'             => array( 'scope' ),
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
				__( 'You do not have permission to generate participation reports.', 'mcp-ai-wpoos-pro' )
			);
		}

		$scope    = isset( $arguments['scope'] ) ? sanitize_key( $arguments['scope'] ) : '';
		$scope_id = isset( $arguments['scope_id'] ) ? sanitize_text_field( $arguments['scope_id'] ) : '';
		$term     = isset( $arguments['term'] ) ? sanitize_text_field( $arguments['term'] ) : '';
		$format   = isset( $arguments['format'] ) ? sanitize_key( $arguments['format'] ) : 'json';

		$valid_scopes = array( 'eca', 'student', 'year_group', 'school' );
		if ( ! in_array( $scope, $valid_scopes, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_scope',
				__( 'Invalid scope. Must be one of: eca, student, year_group, school.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! in_array( $format, array( 'json', 'markdown' ), true ) ) {
			$format = 'json';
		}

		// Validate scope_id for non-school scopes.
		if ( 'school' !== $scope && empty( $scope_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_scope_id',
				__( 'scope_id is required for eca, student, and year_group scopes.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Generate report based on scope.
		switch ( $scope ) {
			case 'eca':
				$report_data = $this->build_eca_report( absint( $scope_id ), $term );
				break;

			case 'student':
				$report_data = $this->build_student_report( absint( $scope_id ), $term );
				break;

			case 'year_group':
				$report_data = $this->build_year_group_report( $scope_id, $term );
				break;

			case 'school':
				$report_data = $this->build_school_report( $term );
				break;

			default:
				$report_data = array();
				break;
		}

		if ( is_wp_error( $report_data ) ) {
			return $report_data;
		}

		$result = array(
			'success'      => true,
			'scope'        => $scope,
			'report_data'  => $report_data,
			'format'       => $format,
			'generated_at' => current_time( 'c' ),
		);

		if ( 'markdown' === $format ) {
			$result['markdown'] = $this->convert_to_markdown( $scope, $report_data );
		}

		return $result;
	}

	/**
	 * Build report data scoped to a single ECA.
	 *
	 * @param int    $eca_id ECA post ID.
	 * @param string $term   Optional term filter.
	 * @return array|WP_Error Report data or error.
	 */
	private function build_eca_report( $eca_id, $term ) {
		$eca = get_post( $eca_id );
		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_eca',
				__( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		$enrollment        = absint( get_post_meta( $eca_id, '_eca_current_enrollment', true ) );
		$max_students      = absint( get_post_meta( $eca_id, '_eca_max_students', true ) );
		$enrolled_students = get_post_meta( $eca_id, '_eca_enrolled_students', true );
		$attendance_log    = get_post_meta( $eca_id, '_eca_attendance_log', true );

		$students_data = array();
		if ( is_array( $enrolled_students ) ) {
			foreach ( $enrolled_students as $sid ) {
				$student = get_post( absint( $sid ) );
				if ( $student ) {
					$student_entry = array(
						'student_id'   => absint( $sid ),
						'student_name' => sanitize_text_field( $student->post_title ),
					);

					$student_entry['attendance_rate'] = $this->get_student_attendance_rate(
						$attendance_log,
						absint( $sid )
					);

					$students_data[] = $student_entry;
				}
			}
		}

		return array(
			'eca_id'          => $eca_id,
			'eca_name'        => $eca->post_title,
			'eca_type'        => get_post_meta( $eca_id, '_eca_type', true ),
			'total_students'  => $enrollment,
			'max_students'    => $max_students,
			'total_ecas'      => 1,
			'students'        => $students_data,
			'attendance_rate' => $this->get_overall_attendance_rate( $attendance_log ),
		);
	}

	/**
	 * Build report data scoped to a single student.
	 *
	 * @param int    $student_id Student post ID.
	 * @param string $term       Optional term filter.
	 * @return array|WP_Error Report data or error.
	 */
	private function build_student_report( $student_id, $term ) {
		$student = get_post( $student_id );
		if ( ! $student || 'mcp_ai_student' !== $student->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_student',
				__( 'Invalid student ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Find all ECAs this student is enrolled in.
		$eca_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_eca',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'generate_eca_participation_report', 0, 1000 ) : 1000,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_eca_enrolled_students',
						'value'   => (string) $student_id,
						'compare' => 'LIKE',
					),
				),
			)
		);

		$enrollments    = array();
		$total_rate_sum = 0;
		$rate_count     = 0;

		foreach ( $eca_query->posts as $eca ) {
			$attendance_log = get_post_meta( $eca->ID, '_eca_attendance_log', true );
			$rate           = $this->get_student_attendance_rate( $attendance_log, $student_id );

			$enrollments[] = array(
				'eca_id'          => $eca->ID,
				'eca_name'        => $eca->post_title,
				'eca_type'        => get_post_meta( $eca->ID, '_eca_type', true ),
				'attendance_rate' => $rate,
			);

			if ( null !== $rate ) {
				$total_rate_sum += $rate;
				++$rate_count;
			}
		}

		$avg_attendance = $rate_count > 0 ? round( $total_rate_sum / $rate_count, 1 ) : null;

		return array(
			'student_id'           => $student_id,
			'student_name'         => sanitize_text_field( $student->post_title ),
			'total_students'       => 1,
			'total_ecas'           => count( $enrollments ),
			'avg_ecas_per_student' => count( $enrollments ),
			'enrollments'          => $enrollments,
			'avg_attendance_rate'  => $avg_attendance,
		);
	}

	/**
	 * Build report data scoped to a year group.
	 *
	 * @param string $year_group Year group name.
	 * @param string $term       Optional term filter.
	 * @return array Report data.
	 */
	private function build_year_group_report( $year_group, $term ) {
		// Get all students in the year group.
		$students_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_student',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'generate_eca_participation_report', 0, 1000 ) : 1000,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_student_year_group',
						'value' => sanitize_text_field( $year_group ),
					),
				),
			)
		);

		$student_ids       = wp_list_pluck( $students_query->posts, 'ID' );
		$total_students    = count( $student_ids );
		$eca_set           = array();
		$total_enrollments = 0;

		foreach ( $student_ids as $sid ) {
			$eca_query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_eca',
					'post_status'    => 'publish',
					'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'generate_eca_participation_report', 0, 1000 ) : 1000,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array(
							'key'     => '_eca_enrolled_students',
							'value'   => (string) $sid,
							'compare' => 'LIKE',
						),
					),
				)
			);

			$total_enrollments += $eca_query->found_posts;
			foreach ( $eca_query->posts as $eca_id ) {
				$eca_set[ $eca_id ] = true;
			}
		}

		$total_ecas           = count( $eca_set );
		$avg_ecas_per_student = $total_students > 0 ? round( $total_enrollments / $total_students, 1 ) : 0;

		// Calculate attendance rates across all relevant ECAs.
		$attendance_rates = $this->calculate_group_attendance( array_keys( $eca_set ), $student_ids );

		return array(
			'year_group'           => $year_group,
			'total_students'       => $total_students,
			'total_ecas'           => $total_ecas,
			'total_enrollments'    => $total_enrollments,
			'avg_ecas_per_student' => $avg_ecas_per_student,
			'attendance_rates'     => $attendance_rates,
		);
	}

	/**
	 * Build report data for the entire school.
	 *
	 * @param string $term Optional term filter.
	 * @return array Report data.
	 */
	private function build_school_report( $term ) {
		// Query all ECAs.
		$eca_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_eca',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'generate_eca_participation_report', 0, 1000 ) : 1000,
			)
		);

		// Query all students.
		$student_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_student',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'generate_eca_participation_report', 0, 1000 ) : 1000,
				'fields'         => 'ids',
			)
		);

		$total_students   = $student_query->found_posts;
		$total_ecas       = $eca_query->found_posts;
		$total_enrollment = 0;
		$all_student_ids  = array();

		foreach ( $eca_query->posts as $eca ) {
			$enrollment        = absint( get_post_meta( $eca->ID, '_eca_current_enrollment', true ) );
			$total_enrollment += $enrollment;

			$enrolled = get_post_meta( $eca->ID, '_eca_enrolled_students', true );
			if ( is_array( $enrolled ) ) {
				foreach ( $enrolled as $sid ) {
					$all_student_ids[ absint( $sid ) ] = true;
				}
			}
		}

		$unique_participants  = count( $all_student_ids );
		$avg_ecas_per_student = $unique_participants > 0 ? round( $total_enrollment / $unique_participants, 1 ) : 0;

		$eca_ids          = wp_list_pluck( $eca_query->posts, 'ID' );
		$attendance_rates = $this->calculate_group_attendance( $eca_ids, array_keys( $all_student_ids ) );

		return array(
			'total_students'       => $total_students,
			'unique_participants'  => $unique_participants,
			'total_ecas'           => $total_ecas,
			'total_enrollments'    => $total_enrollment,
			'avg_ecas_per_student' => $avg_ecas_per_student,
			'attendance_rates'     => $attendance_rates,
		);
	}

	/**
	 * Get a student's attendance rate for a specific ECA.
	 *
	 * @param mixed $attendance_log Attendance log from post meta.
	 * @param int   $student_id     Student post ID.
	 * @return float|null Attendance rate percentage or null if no data.
	 */
	private function get_student_attendance_rate( $attendance_log, $student_id ) {
		if ( ! is_array( $attendance_log ) || empty( $attendance_log ) ) {
			return null;
		}

		$total   = 0;
		$present = 0;

		foreach ( $attendance_log as $session ) {
			$attendees = isset( $session['attendees'] ) && is_array( $session['attendees'] ) ? $session['attendees'] : array();

			foreach ( $attendees as $attendee ) {
				if ( absint( $attendee['student_id'] ) !== $student_id ) {
					continue;
				}

				++$total;
				$status = isset( $attendee['status'] ) ? $attendee['status'] : '';
				if ( 'present' === $status || 'late' === $status ) {
					++$present;
				}
			}
		}

		return $total > 0 ? round( ( $present / $total ) * 100, 1 ) : null;
	}

	/**
	 * Get overall attendance rate across all students for an ECA.
	 *
	 * @param mixed $attendance_log Attendance log from post meta.
	 * @return float|null Attendance rate percentage or null if no data.
	 */
	private function get_overall_attendance_rate( $attendance_log ) {
		if ( ! is_array( $attendance_log ) || empty( $attendance_log ) ) {
			return null;
		}

		$total   = 0;
		$present = 0;

		foreach ( $attendance_log as $session ) {
			$attendees = isset( $session['attendees'] ) && is_array( $session['attendees'] ) ? $session['attendees'] : array();

			foreach ( $attendees as $attendee ) {
				++$total;
				$status = isset( $attendee['status'] ) ? $attendee['status'] : '';
				if ( 'present' === $status || 'late' === $status ) {
					++$present;
				}
			}
		}

		return $total > 0 ? round( ( $present / $total ) * 100, 1 ) : null;
	}

	/**
	 * Calculate attendance rates for a group of ECAs and students.
	 *
	 * @param array $eca_ids     Array of ECA post IDs.
	 * @param array $student_ids Array of student post IDs.
	 * @return array Attendance rate data.
	 */
	private function calculate_group_attendance( $eca_ids, $student_ids ) {
		$total   = 0;
		$present = 0;

		foreach ( $eca_ids as $eca_id ) {
			$attendance_log = get_post_meta( $eca_id, '_eca_attendance_log', true );
			if ( ! is_array( $attendance_log ) ) {
				continue;
			}

			foreach ( $attendance_log as $session ) {
				$attendees = isset( $session['attendees'] ) && is_array( $session['attendees'] ) ? $session['attendees'] : array();

				foreach ( $attendees as $attendee ) {
					$sid = absint( $attendee['student_id'] );
					if ( ! empty( $student_ids ) && ! in_array( $sid, $student_ids, true ) ) {
						continue;
					}

					++$total;
					$status = isset( $attendee['status'] ) ? $attendee['status'] : '';
					if ( 'present' === $status || 'late' === $status ) {
						++$present;
					}
				}
			}
		}

		$overall_rate = $total > 0 ? round( ( $present / $total ) * 100, 1 ) : 0;

		return array(
			'total_records'           => $total,
			'total_present_or_late'   => $present,
			'overall_attendance_rate' => $overall_rate,
		);
	}

	/**
	 * Convert report data to markdown table format.
	 *
	 * @param string $scope       Report scope.
	 * @param array  $report_data Report data array.
	 * @return string Markdown-formatted report.
	 */
	private function convert_to_markdown( $scope, $report_data ) {
		$lines   = array();
		$lines[] = '# ECA Participation Report';
		$lines[] = '';
		$lines[] = '**Scope:** ' . esc_html( $scope );
		$lines[] = '**Generated:** ' . current_time( 'Y-m-d H:i:s' );
		$lines[] = '';

		// Summary table.
		$lines[] = '## Summary';
		$lines[] = '';
		$lines[] = '| Metric | Value |';
		$lines[] = '|--------|-------|';

		if ( isset( $report_data['total_students'] ) ) {
			$lines[] = '| Total Students | ' . intval( $report_data['total_students'] ) . ' |';
		}
		if ( isset( $report_data['total_ecas'] ) ) {
			$lines[] = '| Total ECAs | ' . intval( $report_data['total_ecas'] ) . ' |';
		}
		if ( isset( $report_data['avg_ecas_per_student'] ) ) {
			$lines[] = '| Avg ECAs per Student | ' . floatval( $report_data['avg_ecas_per_student'] ) . ' |';
		}
		if ( isset( $report_data['attendance_rates']['overall_attendance_rate'] ) ) {
			$lines[] = '| Attendance Rate | ' . floatval( $report_data['attendance_rates']['overall_attendance_rate'] ) . '% |';
		}
		if ( isset( $report_data['attendance_rate'] ) && null !== $report_data['attendance_rate'] ) {
			$lines[] = '| Attendance Rate | ' . floatval( $report_data['attendance_rate'] ) . '% |';
		}

		$lines[] = '';

		// Students table if available.
		if ( ! empty( $report_data['students'] ) ) {
			$lines[] = '## Students';
			$lines[] = '';
			$lines[] = '| Student | Attendance Rate |';
			$lines[] = '|---------|----------------|';

			foreach ( $report_data['students'] as $student ) {
				$rate    = null !== $student['attendance_rate'] ? $student['attendance_rate'] . '%' : 'N/A';
				$lines[] = '| ' . esc_html( $student['student_name'] ) . ' | ' . esc_html( $rate ) . ' |';
			}
			$lines[] = '';
		}

		// Enrollments table if available.
		if ( ! empty( $report_data['enrollments'] ) ) {
			$lines[] = '## Enrollments';
			$lines[] = '';
			$lines[] = '| ECA | Type | Attendance Rate |';
			$lines[] = '|-----|------|----------------|';

			foreach ( $report_data['enrollments'] as $enrollment ) {
				$rate    = null !== $enrollment['attendance_rate'] ? $enrollment['attendance_rate'] . '%' : 'N/A';
				$lines[] = '| ' . esc_html( $enrollment['eca_name'] ) . ' | ' . esc_html( $enrollment['eca_type'] ) . ' | ' . esc_html( $rate ) . ' |';
			}
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}
}
