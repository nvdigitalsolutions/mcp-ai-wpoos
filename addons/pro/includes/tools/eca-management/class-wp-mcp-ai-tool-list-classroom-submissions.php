<?php
/**
 * Tool for listing Google Classroom student submissions.
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
 * Lists student submissions for Classroom coursework, optionally
 * cross-referencing ECA attendance.
 */
class WP_MCP_AI_Tool_List_Classroom_Submissions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_classroom_submissions';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Classroom Submissions', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists Google Classroom student submissions for a coursework item with turn-in state filtering, and can cross-reference each student against ECA attendance records.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Checking who has turned in a permission slip or rehearsal check-in, or spotting students who missed both the session and the form.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Grading or returning work; not supported by this toolkit. Aggregate numbers; use classroom_course_analytics.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'create_classroom_coursework', 'classroom_course_analytics', 'get-eca-attendance-report' ),
			'notes'           => __( 'Read-only. Requires the classroom.student-submissions.students.readonly scope (or the coursework write scope).', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id'               => array(
					'type'        => 'string',
					'description' => __( 'Remote Sites connection ID for Google Classroom.', 'mcp-ai-wpoos-pro' ),
				),
				'course_id'                   => array(
					'type'        => 'string',
					'description' => __( 'Google Classroom course ID. Defaults to the connection\'s default course.', 'mcp-ai-wpoos-pro' ),
				),
				'course_work_id'              => array(
					'type'        => 'string',
					'description' => __( 'Coursework item ID. When omitted, the latest coursework items are scanned.', 'mcp-ai-wpoos-pro' ),
				),
				'states'                      => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'NEW', 'CREATED', 'TURNED_IN', 'RETURNED', 'RECLAIMED_BY_STUDENT' ),
					),
					'description' => __( 'Submission states to include. Defaults to all.', 'mcp-ai-wpoos-pro' ),
				),
				'include_attendance_crossref' => array(
					'type'        => 'boolean',
					'description' => __( 'Add each student\'s ECA attendance summary when the course is linked to an ECA (default: false).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'connection_id' ),
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
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'external-api' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return WP_MCP_AI_ECA_Classroom_Helper::feature_enabled();
	}

	/**
	 * Get unavailable reason message.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return WP_MCP_AI_ECA_Classroom_Helper::unavailable_reason();
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
			'profession_tags'       => array( 'school_admin', 'it_admin' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : '';
		$resolved      = WP_MCP_AI_ECA_Classroom_Helper::get_client( $connection_id, 'edit_posts', $context );

		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$scope_check = WP_MCP_AI_Google_Classroom_Credentials::require_scope(
			$resolved['credentials'],
			WP_MCP_AI_Google_Classroom_Scopes::SCOPE_SUBMISSIONS_STUDENTS_READONLY
		);

		if ( is_wp_error( $scope_check ) ) {
			return $scope_check;
		}

		$course_id = WP_MCP_AI_Google_Classroom_Credentials::resolve_default_course_id(
			$resolved['credentials'],
			isset( $arguments['course_id'] ) ? sanitize_text_field( $arguments['course_id'] ) : ''
		);

		if ( is_wp_error( $course_id ) ) {
			return $course_id;
		}

		$coursework_id = isset( $arguments['course_work_id'] ) ? sanitize_text_field( $arguments['course_work_id'] ) : '';
		$crossref      = ! empty( $arguments['include_attendance_crossref'] );

		$states = isset( $arguments['states'] ) && is_array( $arguments['states'] )
			? array_map( 'sanitize_key', $arguments['states'] )
			: array();

		$states = array_values(
			array_filter(
				$states,
				static function ( $state ) {
					return in_array( $state, array( 'NEW', 'CREATED', 'TURNED_IN', 'RETURNED', 'RECLAIMED_BY_STUDENT' ), true );
				}
			)
		);

		$params = array( 'pageSize' => 100 );

		if ( ! empty( $states ) ) {
			$params['states'] = $states;
		}

		$work_items = array();

		if ( '' !== $coursework_id ) {
			$work_items[] = array( 'id' => $coursework_id );
		} else {
			$listed = $resolved['client']->list_coursework( $course_id, array( 'pageSize' => 20 ) );

			if ( is_wp_error( $listed ) ) {
				return $listed;
			}

			$work_items = isset( $listed['courseWork'] ) && is_array( $listed['courseWork'] ) ? $listed['courseWork'] : array();
		}

		$rows = array();

		foreach ( $work_items as $work ) {
			if ( ! is_array( $work ) || empty( $work['id'] ) ) {
				continue;
			}

			$submissions = $resolved['client']->list_student_submissions( $course_id, (string) $work['id'], $params );

			if ( is_wp_error( $submissions ) ) {
				$rows[] = array(
					'coursework_id' => sanitize_text_field( (string) $work['id'] ),
					'error'         => $submissions->get_error_message(),
				);
				continue;
			}

			foreach ( isset( $submissions['studentSubmissions'] ) && is_array( $submissions['studentSubmissions'] ) ? $submissions['studentSubmissions'] : array() as $submission ) {
				if ( ! is_array( $submission ) ) {
					continue;
				}

				$row = array(
					'coursework_id'  => sanitize_text_field( (string) $work['id'] ),
					'submission_id'  => isset( $submission['id'] ) ? sanitize_text_field( $submission['id'] ) : '',
					'user_id'        => isset( $submission['userId'] ) ? sanitize_text_field( $submission['userId'] ) : '',
					'state'          => isset( $submission['state'] ) ? sanitize_key( $submission['state'] ) : '',
					'assigned_grade' => isset( $submission['assignedGrade'] ) ? (float) $submission['assignedGrade'] : null,
				);

				if ( $crossref ) {
					$row['attendance'] = $this->attendance_crossref( $course_id, $row['user_id'] );
				}

				$rows[] = $row;
			}
		}

		return array(
			'success'     => true,
			'course_id'   => $course_id,
			'submissions' => $rows,
			'count'       => count( $rows ),
			'message'     => sprintf(
				/* translators: %d: number of submissions */
				__( '%d Classroom submissions returned.', 'mcp-ai-wpoos-pro' ),
				count( $rows )
			),
		);
	}

	/**
	 * Cross-reference a Classroom user with ECA attendance when the course is
	 * linked to an ECA.
	 *
	 * @param string $course_id Google Classroom course ID.
	 * @param string $user_id   Google Classroom user ID.
	 * @return array<string,mixed> Attendance summary, or an empty array.
	 */
	private function attendance_crossref( $course_id, $user_id ) {
		$eca_id = WP_MCP_AI_ECA_Classroom_Helper::find_eca_by_google_course( $course_id );

		if ( 0 === $eca_id ) {
			return array();
		}

		$student_id = WP_MCP_AI_ECA_Classroom_Helper::find_student_by_google_id( $user_id );

		if ( 0 === $student_id ) {
			return array();
		}

		if ( ! class_exists( 'WP_MCP_AI_ECA_Attendance_DB' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/eca/class-wp-mcp-ai-eca-attendance-db.php';
		}

		$records = WP_MCP_AI_ECA_Attendance_DB::get_student_attendance( $student_id, 'school', 0 );

		return array(
			'eca_id'        => $eca_id,
			'student_id'    => $student_id,
			'session_count' => count( $records ),
			'present_count' => count(
				array_filter(
					$records,
					static function ( $record ) {
						return 'present' === ( isset( $record['status'] ) ? $record['status'] : '' );
					}
				)
			),
		);
	}
}
