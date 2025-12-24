<?php
/**
 * Tool for grading quiz submissions.
 *
 * @package WP_MCP_AI
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
		return __( 'Grade Quiz', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Grades a quiz submission. Provides scores for each question and calculates total score and pass/fail status.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'submission_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the submission to grade.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'grades'        => array(
					'type'        => 'array',
					'description' => __( 'Array of grades for each question.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'question_index' => array(
								'type'        => 'integer',
								'description' => __( 'Zero-based index of the question.', 'wp-mcp-ai' ),
								'minimum'     => 0,
							),
							'points_earned'  => array(
								'type'        => 'number',
								'description' => __( 'Points earned for this question.', 'wp-mcp-ai' ),
								'minimum'     => 0,
							),
							'feedback'       => array(
								'type'        => 'string',
								'description' => __( 'Optional feedback for this question.', 'wp-mcp-ai' ),
							),
						),
						'required'   => array( 'question_index', 'points_earned' ),
					),
				),
				'overall_feedback' => array(
					'type'        => 'string',
					'description' => __( 'Optional overall feedback for the submission.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'submission_id', 'grades' ),
			'additionalProperties' => false,
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to grade quizzes.', 'wp-mcp-ai' ) );
		}

		$submission_id     = isset( $arguments['submission_id'] ) ? absint( $arguments['submission_id'] ) : 0;
		$grades            = isset( $arguments['grades'] ) && is_array( $arguments['grades'] ) ? $arguments['grades'] : array();
		$overall_feedback  = isset( $arguments['overall_feedback'] ) ? wp_kses_post( $arguments['overall_feedback'] ) : '';

		if ( ! $submission_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_submission_id', __( 'Submission ID is required.', 'wp-mcp-ai' ) );
		}

		if ( empty( $grades ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_grades', __( 'At least one grade is required.', 'wp-mcp-ai' ) );
		}

		$submission = get_post( $submission_id );

		if ( ! $submission || 'mcp_ai_submission' !== $submission->post_type ) {
			return new WP_Error( 'wp_mcp_ai_submission_not_found', __( 'Submission not found.', 'wp-mcp-ai' ) );
		}

		// Get quiz details.
		$quiz_id = get_post_meta( $submission_id, '_mcp_ai_submission_quiz_id', true );
		$quiz    = get_post( $quiz_id );

		if ( ! $quiz || 'mcp_ai_quiz' !== $quiz->post_type ) {
			return new WP_Error( 'wp_mcp_ai_quiz_not_found', __( 'Associated quiz not found.', 'wp-mcp-ai' ) );
		}

		// Check if user can grade this quiz (must be author or have edit_others_posts).
		$quiz_author = absint( $quiz->post_author );
		if ( $quiz_author !== $current_user_id && ! user_can( $current_user_id, 'edit_others_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to grade this quiz.', 'wp-mcp-ai' ) );
		}

		// Get quiz metadata.
		$total_points  = get_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', true );
		$passing_score = get_post_meta( $quiz_id, '_mcp_ai_quiz_passing_score', true );

		// Sanitize grades.
		$sanitized_grades = array();
		$earned_points    = 0;

		foreach ( $grades as $grade_data ) {
			if ( ! isset( $grade_data['question_index'] ) || ! isset( $grade_data['points_earned'] ) ) {
				continue;
			}

			$points = floatval( $grade_data['points_earned'] );
			if ( $points < 0 ) {
				$points = 0;
			}

			$grade = array(
				'question_index' => absint( $grade_data['question_index'] ),
				'points_earned'  => $points,
			);

			if ( isset( $grade_data['feedback'] ) && '' !== $grade_data['feedback'] ) {
				$grade['feedback'] = wp_kses_post( $grade_data['feedback'] );
			}

			$earned_points      += $points;
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

		return array(
			'summary'          => sprintf(
				/* translators: 1: earned points, 2: total points, 3: percentage */
				__( 'Quiz graded: %1$.1f / %2$.1f points (%3$.1f%%)', 'wp-mcp-ai' ),
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
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'write',
			'local-only',
			'requires-capability',
			'state-changing',
		);
	}
}
