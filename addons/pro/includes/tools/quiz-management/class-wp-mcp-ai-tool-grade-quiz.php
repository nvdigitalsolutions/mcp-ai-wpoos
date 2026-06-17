<?php
/**
 * Tool for grading quiz submissions.
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
 * Grades a quiz submission.
 */
class WP_MCP_AI_Tool_Grade_Quiz implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'grade_quiz';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Grade Quiz', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Grades a quiz submission. Provides scores for each question and calculates total score and pass/fail status.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'submission_id'    => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the submission to grade.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'grades'           => array(
					'type'        => 'array',
					'description' => __( 'Array of grades for each question.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'question_index' => array(
								'type'        => 'integer',
								'description' => __( 'Zero-based index of the question.', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0,
							),
							'points_earned'  => array(
								'type'        => 'number',
								'description' => __( 'Points earned for this question.', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0,
							),
							'feedback'       => array(
								'type'        => 'string',
								'description' => __( 'Optional feedback for this question.', 'mcp-ai-wpoos-pro' ),
							),
						),
						'required'   => array( 'question_index', 'points_earned' ),
					),
				),
				'overall_feedback' => array(
					'type'        => 'string',
					'description' => __( 'Optional overall feedback for the submission.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'submission_id', 'grades' ),
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
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to grade quizzes.', 'mcp-ai-wpoos-pro' ) );
		}

		$submission_id    = isset( $arguments['submission_id'] ) ? absint( $arguments['submission_id'] ) : 0;
		$grades           = isset( $arguments['grades'] ) && is_array( $arguments['grades'] ) ? $arguments['grades'] : array();
		$overall_feedback = isset( $arguments['overall_feedback'] ) ? wp_kses_post( $arguments['overall_feedback'] ) : '';

		if ( ! $submission_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_submission_id', __( 'Submission ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $grades ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_grades', __( 'At least one grade is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$submission = get_post( $submission_id );

		if ( ! $submission || 'mcp_ai_submission' !== $submission->post_type ) {
			return new WP_Error( 'wp_mcp_ai_submission_not_found', __( 'Submission not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get quiz details.
		$quiz_id = get_post_meta( $submission_id, '_mcp_ai_submission_quiz_id', true );
		$quiz    = get_post( $quiz_id );

		if ( ! $quiz || 'mcp_ai_quiz' !== $quiz->post_type ) {
			return new WP_Error( 'wp_mcp_ai_quiz_not_found', __( 'Associated quiz not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check if user can grade this quiz (must be author or have edit_others_posts).
		$quiz_author = absint( $quiz->post_author );
		if ( $quiz_author !== $current_user_id && ! user_can( $current_user_id, 'edit_others_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to grade this quiz.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get quiz metadata.
		$total_points  = get_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', true );
		$passing_score = get_post_meta( $quiz_id, '_mcp_ai_quiz_passing_score', true );
		$questions     = get_post_meta( $quiz_id, '_mcp_ai_quiz_questions', true );

		if ( ! is_array( $questions ) ) {
			$questions = array();
		}

		// Sanitize and validate grades.
		$sanitized_grades = array();
		$earned_points    = 0;

		foreach ( $grades as $grade_data ) {
			if ( ! isset( $grade_data['question_index'] ) || ! isset( $grade_data['points_earned'] ) ) {
				continue;
			}

			$question_index = absint( $grade_data['question_index'] );
			$points         = floatval( $grade_data['points_earned'] );

			// Validate question index exists.
			if ( ! isset( $questions[ $question_index ] ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_question_index',
					sprintf(
						/* translators: %d: question index */
						__( 'Invalid question index: %d', 'mcp-ai-wpoos-pro' ),
						$question_index
					)
				);
			}

			$question   = $questions[ $question_index ];
			$max_points = isset( $question['points'] ) ? absint( $question['points'] ) : 1;

			// Validate points earned don't exceed max points for question.
			if ( $points > $max_points ) {
				return new WP_Error(
					'wp_mcp_ai_points_exceed_max',
					sprintf(
						/* translators: 1: question index, 2: points earned, 3: max points */
						__( 'Points earned (%2$.1f) for question %1$d exceed maximum points (%3$d).', 'mcp-ai-wpoos-pro' ),
						$question_index + 1,
						$points,
						$max_points
					)
				);
			}

			// Ensure non-negative points.
			if ( $points < 0 ) {
				$points = 0;
			}

			$grade = array(
				'question_index' => $question_index,
				'points_earned'  => $points,
			);

			if ( isset( $grade_data['feedback'] ) && '' !== $grade_data['feedback'] ) {
				$grade['feedback'] = wp_kses_post( $grade_data['feedback'] );
			}

			$earned_points     += $points;
			$sanitized_grades[] = $grade;
		}

		// Calculate percentage and pass/fail.
		$percentage = $total_points > 0 ? ( $earned_points / $total_points ) * 100 : 0;
		$passed     = $percentage >= $passing_score;

		// Update submission.
		wp_update_post(
			array(
				'ID'          => $submission_id,
				'post_status' => 'publish',
			)
		);

		// Store grading metadata.
		update_post_meta( $submission_id, '_mcp_ai_submission_grades', $sanitized_grades );
		update_post_meta( $submission_id, '_mcp_ai_submission_earned_points', $earned_points );
		update_post_meta( $submission_id, '_mcp_ai_submission_percentage', $percentage );
		update_post_meta( $submission_id, '_mcp_ai_submission_passed', $passed );
		update_post_meta( $submission_id, '_mcp_ai_submission_status', 'graded' );
		update_post_meta( $submission_id, '_mcp_ai_submission_graded_by', $current_user_id );
		update_post_meta( $submission_id, '_mcp_ai_submission_graded_at', current_time( 'mysql' ) );

		if ( '' !== $overall_feedback ) {
			update_post_meta( $submission_id, '_mcp_ai_submission_overall_feedback', $overall_feedback );
		}

		// Audit logging for grade changes.
		$grader_data  = get_userdata( $current_user_id );
		$student_data = get_userdata( $submission->post_author );

		$audit_log = array(
			'timestamp'     => current_time( 'mysql' ),
			'grader_id'     => $current_user_id,
			'grader_name'   => $grader_data ? $grader_data->display_name : 'Unknown',
			'student_id'    => absint( $submission->post_author ),
			'student_name'  => $student_data ? $student_data->display_name : 'Unknown',
			'quiz_id'       => $quiz_id,
			'quiz_title'    => get_the_title( $quiz_id ),
			'earned_points' => $earned_points,
			'total_points'  => absint( $total_points ),
			'percentage'    => round( $percentage, 2 ),
			'passed'        => $passed,
			'ip_address'    => $this->get_client_ip(),
		);

		// Store audit log in post meta (append to existing logs).
		$existing_logs = get_post_meta( $submission_id, '_mcp_ai_submission_audit_log', true );
		if ( ! is_array( $existing_logs ) ) {
			$existing_logs = array();
		}
		$existing_logs[] = $audit_log;
		update_post_meta( $submission_id, '_mcp_ai_submission_audit_log', $existing_logs );

		return array(
			'summary'          => sprintf(
				/* translators: 1: earned points, 2: total points, 3: percentage */
				__( 'Quiz graded: %1$.1f / %2$.1f points (%3$.1f%%)', 'mcp-ai-wpoos-pro' ),
				$earned_points,
				$total_points,
				$percentage
			),
			'submission_id'    => $submission_id,
			'quiz_id'          => $quiz_id,
			'student_id'       => absint( $submission->post_author ),
			'earned_points'    => $earned_points,
			'total_points'     => absint( $total_points ),
			'percentage'       => round( $percentage, 2 ),
			'passed'           => $passed,
			'passing_score'    => absint( $passing_score ),
			'graded_by'        => $current_user_id,
			'graded_at'        => current_time( 'mysql' ),
			'overall_feedback' => $overall_feedback,
		);
	}

	/**
	 * Get client IP address for audit logging.
	 *
	 * @return string Client IP address.
	 */
	private function get_client_ip() {
		$ip_address = '';

		// Check for various proxy headers.
		$headers = array(
			'HTTP_CLIENT_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_FORWARDED',
			'HTTP_X_CLUSTER_CLIENT_IP',
			'HTTP_FORWARDED_FOR',
			'HTTP_FORWARDED',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( isset( $_SERVER[ $header ] ) && ! empty( $_SERVER[ $header ] ) ) {
				$ip_address = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// If multiple IPs, take the first one.
				if ( strpos( $ip_address, ',' ) !== false ) {
					$ip_parts   = explode( ',', $ip_address );
					$ip_address = trim( $ip_parts[0] );
				}
				break;
			}
		}

		return $ip_address;
	}

	/**
	 * {@inheritdoc}
	 */

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
			'post_type'             => 'mcp_ai_quiz',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'educator', 'trainer' ),
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'local-only',
			'requires-capability',
			'state-changing',
		);
	}
}
