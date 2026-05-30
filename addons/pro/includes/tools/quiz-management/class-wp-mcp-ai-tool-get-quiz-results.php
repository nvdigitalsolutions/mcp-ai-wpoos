<?php
/**
 * Tool for retrieving quiz results.
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
 * Retrieves detailed results for a quiz submission.
 */
class WP_MCP_AI_Tool_Get_Quiz_Results implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_quiz_results';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Quiz Results', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed results for a graded quiz submission, including answers, grades, and feedback.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'The ID of the submission to retrieve results for.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'submission_id' ),
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view results.', 'mcp-ai-wpoos-pro' ) );
		}

		$submission_id = isset( $arguments['submission_id'] ) ? absint( $arguments['submission_id'] ) : 0;

		if ( ! $submission_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_submission_id', __( 'Submission ID is required.', 'mcp-ai-wpoos-pro' ) );
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

		// Check permissions: student can view own results, quiz author can view all.
		$student_id  = absint( $submission->post_author );
		$quiz_author = absint( $quiz->post_author );

		if ( $student_id !== $current_user_id && $quiz_author !== $current_user_id && ! user_can( $current_user_id, 'edit_others_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view these results.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get submission data.
		$status           = get_post_meta( $submission_id, '_mcp_ai_submission_status', true );
		$answers          = get_post_meta( $submission_id, '_mcp_ai_submission_answers', true );
		$grades           = get_post_meta( $submission_id, '_mcp_ai_submission_grades', true );
		$earned_points    = get_post_meta( $submission_id, '_mcp_ai_submission_earned_points', true );
		$percentage       = get_post_meta( $submission_id, '_mcp_ai_submission_percentage', true );
		$passed           = get_post_meta( $submission_id, '_mcp_ai_submission_passed', true );
		$overall_feedback = get_post_meta( $submission_id, '_mcp_ai_submission_overall_feedback', true );

		// Get quiz data.
		$questions     = get_post_meta( $quiz_id, '_mcp_ai_quiz_questions', true );
		$total_points  = get_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', true );
		$passing_score = get_post_meta( $quiz_id, '_mcp_ai_quiz_passing_score', true );

		if ( ! is_array( $answers ) ) {
			$answers = array();
		}
		if ( ! is_array( $grades ) ) {
			$grades = array();
		}
		if ( ! is_array( $questions ) ) {
			$questions = array();
		}

		// Build detailed results.
		$detailed_results = array();

		foreach ( $answers as $answer_data ) {
			$question_index = isset( $answer_data['question_index'] ) ? absint( $answer_data['question_index'] ) : -1;

			if ( $question_index < 0 || ! isset( $questions[ $question_index ] ) ) {
				continue;
			}

			$question = $questions[ $question_index ];
			$result   = array(
				'question_index' => $question_index,
				'question'       => $question['question'],
				'type'           => $question['type'],
				'answer'         => isset( $answer_data['answer'] ) ? $answer_data['answer'] : '',
				'points'         => isset( $question['points'] ) ? $question['points'] : 1,
			);

			// Add grading info if available.
			$grade_found = false;
			foreach ( $grades as $grade_data ) {
				if ( isset( $grade_data['question_index'] ) && absint( $grade_data['question_index'] ) === $question_index ) {
					$result['points_earned'] = isset( $grade_data['points_earned'] ) ? floatval( $grade_data['points_earned'] ) : 0;
					if ( isset( $grade_data['feedback'] ) ) {
						$result['feedback'] = $grade_data['feedback'];
					}
					$grade_found = true;
					break;
				}
			}

			if ( ! $grade_found && 'graded' === $status ) {
				$result['points_earned'] = 0;
			}

			$detailed_results[] = $result;
		}

		$response = array(
			'summary'          => sprintf(
				/* translators: 1: quiz title, 2: student name */
				__( 'Results for %1$s - %2$s', 'mcp-ai-wpoos-pro' ),
				get_the_title( $quiz ),
				get_userdata( $student_id )->display_name
			),
			'submission_id'    => $submission_id,
			'quiz_id'          => $quiz_id,
			'quiz_title'       => get_the_title( $quiz ),
			'student_id'       => $student_id,
			'student_name'     => get_userdata( $student_id )->display_name,
			'status'           => $status,
			'submitted_at'     => $submission->post_date,
			'detailed_results' => $detailed_results,
		);

		// Add time tracking information.
		$started_at      = get_post_meta( $submission_id, '_mcp_ai_submission_started_at', true );
		$completion_time = get_post_meta( $submission_id, '_mcp_ai_submission_completion_time', true );
		$quiz_time_limit = get_post_meta( $quiz_id, '_mcp_ai_quiz_time_limit', true );

		if ( $started_at ) {
			$response['started_at'] = $started_at;
		}
		if ( $completion_time ) {
			$response['completion_time_minutes'] = floatval( $completion_time );
		}
		if ( $quiz_time_limit ) {
			$response['time_limit'] = absint( $quiz_time_limit );
		}

		// Add grading info if graded.
		if ( 'graded' === $status ) {
			$response['earned_points'] = floatval( $earned_points );
			$response['total_points']  = absint( $total_points );
			$response['percentage']    = floatval( $percentage );
			$response['passed']        = (bool) $passed;
			$response['passing_score'] = absint( $passing_score );
			$response['graded_by']     = absint( get_post_meta( $submission_id, '_mcp_ai_submission_graded_by', true ) );
			$response['graded_at']     = get_post_meta( $submission_id, '_mcp_ai_submission_graded_at', true );

			if ( '' !== $overall_feedback ) {
				$response['overall_feedback'] = $overall_feedback;
			}

			// Add Chart.js visualization for question performance.
			$response['chart'] = $this->generate_results_chart( $detailed_results, $questions );
		}

		return $response;
	}

	/**
	 * Generate Chart.js configuration for individual results.
	 *
	 * @param array $detailed_results Detailed results array.
	 * @param array $questions        Quiz questions.
	 * @return array Chart.js configuration.
	 */
	private function generate_results_chart( $detailed_results, $questions ) {
		$labels        = array();
		$points_earned = array();
		$points_max    = array();

		foreach ( $detailed_results as $result ) {
			$q_num    = $result['question_number'];
			$labels[] = sprintf( __( 'Q%d', 'mcp-ai-wpoos-pro' ), $q_num );

			$earned = isset( $result['points_earned'] ) ? floatval( $result['points_earned'] ) : 0;
			$max    = isset( $result['points_possible'] ) ? floatval( $result['points_possible'] ) : 1;

			$points_earned[] = $earned;
			$points_max[]    = $max;
		}

		return array(
			'type'    => 'bar',
			'data'    => array(
				'labels'   => $labels,
				'datasets' => array(
					array(
						'label'           => __( 'Points Earned', 'mcp-ai-wpoos-pro' ),
						'data'            => $points_earned,
						'backgroundColor' => 'rgba(75, 192, 192, 0.6)',
						'borderColor'     => 'rgba(75, 192, 192, 1)',
						'borderWidth'     => 1,
					),
					array(
						'label'           => __( 'Points Possible', 'mcp-ai-wpoos-pro' ),
						'data'            => $points_max,
						'backgroundColor' => 'rgba(201, 203, 207, 0.6)',
						'borderColor'     => 'rgba(201, 203, 207, 1)',
						'borderWidth'     => 1,
					),
				),
			),
			'options' => array(
				'responsive' => true,
				'plugins'    => array(
					'title'  => array(
						'display' => true,
						'text'    => __( 'Your Performance by Question', 'mcp-ai-wpoos-pro' ),
					),
					'legend' => array(
						'display' => true,
					),
				),
				'scales'     => array(
					'y' => array(
						'beginAtZero' => true,
						'title'       => array(
							'display' => true,
							'text'    => __( 'Points', 'mcp-ai-wpoos-pro' ),
						),
					),
					'x' => array(
						'title' => array(
							'display' => true,
							'text'    => __( 'Question', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
			),
		);
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
			'profession_tags'       => array( 'educator', 'student' ),
			'risk_level'            => 'info',
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
			'read-only',
			'local-only',
			'requires-capability',
		);
	}
}
