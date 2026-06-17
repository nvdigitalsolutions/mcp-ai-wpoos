<?php
/**
 * Tool for retrieving quiz details.
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
 * Retrieves quiz details.
 */
class WP_MCP_AI_Tool_Get_Quiz implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_quiz';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Quiz', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves details of a specific quiz, including questions and settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'quiz_id'         => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the quiz to retrieve.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'include_answers' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include correct answers (requires edit capability).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'             => array( 'quiz_id' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view quizzes.', 'mcp-ai-wpoos-pro' ) );
		}

		$quiz_id         = isset( $arguments['quiz_id'] ) ? absint( $arguments['quiz_id'] ) : 0;
		$include_answers = isset( $arguments['include_answers'] ) ? (bool) $arguments['include_answers'] : false;

		if ( ! $quiz_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_quiz_id', __( 'Quiz ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$quiz = get_post( $quiz_id );

		if ( ! $quiz || 'mcp_ai_quiz' !== $quiz->post_type ) {
			return new WP_Error( 'wp_mcp_ai_quiz_not_found', __( 'Quiz not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get quiz metadata.
		$description   = get_post_meta( $quiz_id, '_mcp_ai_quiz_description', true );
		$time_limit    = get_post_meta( $quiz_id, '_mcp_ai_quiz_time_limit', true );
		$questions     = get_post_meta( $quiz_id, '_mcp_ai_quiz_questions', true );
		$total_points  = get_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', true );
		$passing_score = get_post_meta( $quiz_id, '_mcp_ai_quiz_passing_score', true );

		if ( ! is_array( $questions ) ) {
			$questions = array();
		}

		// Check if user can see correct answers.
		$can_edit = user_can( $current_user_id, 'edit_post', $quiz_id );

		// Filter questions based on permissions.
		$filtered_questions = array();
		foreach ( $questions as $index => $question ) {
			$filtered_question = array(
				'question' => $question['question'],
				'type'     => $question['type'],
				'points'   => isset( $question['points'] ) ? $question['points'] : 1,
			);

			if ( 'multiple_choice' === $question['type'] && isset( $question['options'] ) ) {
				$filtered_question['options'] = $question['options'];
			}

			// Include correct answer only if user has permission and requested.
			if ( $include_answers && $can_edit && isset( $question['correct_answer'] ) ) {
				$filtered_question['correct_answer'] = $question['correct_answer'];
			}

			$filtered_questions[] = $filtered_question;
		}

		return array(
			'summary'        => sprintf(
				/* translators: %s: quiz title */
				__( 'Quiz: %s', 'mcp-ai-wpoos-pro' ),
				get_the_title( $quiz )
			),
			'quiz_id'        => $quiz_id,
			'title'          => get_the_title( $quiz ),
			'description'    => $description,
			'time_limit'     => absint( $time_limit ),
			'questions'      => $filtered_questions,
			'question_count' => count( $filtered_questions ),
			'total_points'   => absint( $total_points ),
			'passing_score'  => absint( $passing_score ),
			'author_id'      => absint( $quiz->post_author ),
			'created_at'     => $quiz->post_date,
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
