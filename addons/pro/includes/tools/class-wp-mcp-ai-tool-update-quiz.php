<?php
/**
 * Tool for updating existing quizzes.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing quiz with new questions or settings.
 */
class WP_MCP_AI_Tool_Update_Quiz implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_quiz';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Quiz', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing quiz with new questions or settings. Only the quiz author or users with edit_others_posts capability can update quizzes.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'quiz_id'       => array(
					'type'        => 'integer',
					'description' => __( 'ID of the quiz to update.', 'wp-mcp-ai' ),
				),
				'title'         => array(
					'type'        => 'string',
					'description' => __( 'New title of the quiz (optional).', 'wp-mcp-ai' ),
				),
				'description'   => array(
					'type'        => 'string',
					'description' => __( 'New description or instructions for the quiz (optional).', 'wp-mcp-ai' ),
				),
				'time_limit'    => array(
					'type'        => 'integer',
					'description' => __( 'New time limit in minutes for completing the quiz. 0 for no limit (optional).', 'wp-mcp-ai' ),
					'minimum'     => 0,
				),
				'questions'     => array(
					'type'        => 'array',
					'description' => __( 'New array of questions for the quiz (optional). This will replace all existing questions.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'question'       => array(
								'type'        => 'string',
								'description' => __( 'The question text.', 'wp-mcp-ai' ),
							),
							'type'           => array(
								'type'        => 'string',
								'description' => __( 'Question type: multiple_choice, true_false, or short_answer.', 'wp-mcp-ai' ),
								'enum'        => array( 'multiple_choice', 'true_false', 'short_answer' ),
							),
							'options'        => array(
								'type'        => 'array',
								'description' => __( 'Answer options for multiple choice questions.', 'wp-mcp-ai' ),
								'items'       => array( 'type' => 'string' ),
							),
							'correct_answer' => array(
								'type'        => 'string',
								'description' => __( 'The correct answer (for grading reference).', 'wp-mcp-ai' ),
							),
							'points'         => array(
								'type'        => 'integer',
								'description' => __( 'Points awarded for correct answer.', 'wp-mcp-ai' ),
								'default'     => 1,
								'minimum'     => 1,
							),
						),
						'required'   => array( 'question', 'type' ),
					),
				),
				'passing_score' => array(
					'type'        => 'integer',
					'description' => __( 'New minimum percentage (0-100) required to pass the quiz (optional).', 'wp-mcp-ai' ),
					'minimum'     => 0,
					'maximum'     => 100,
				),
			),
			'required'             => array( 'quiz_id' ),
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

		if ( ! $current_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to update quizzes.', 'wp-mcp-ai' ) );
		}

		// Get quiz ID.
		$quiz_id = isset( $arguments['quiz_id'] ) ? absint( $arguments['quiz_id'] ) : 0;

		if ( ! $quiz_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_quiz_id', __( 'Quiz ID is required.', 'wp-mcp-ai' ) );
		}

		// Verify quiz exists.
		$quiz = get_post( $quiz_id );

		if ( ! $quiz || 'mcp_ai_quiz' !== $quiz->post_type ) {
			return new WP_Error( 'wp_mcp_ai_quiz_not_found', __( 'Quiz not found.', 'wp-mcp-ai' ) );
		}

		// Check permissions: must be author or have edit_others_posts capability.
		$is_author = absint( $quiz->post_author ) === $current_user_id;
		$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

		if ( ! $is_author && ! $can_edit_others ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this quiz.', 'wp-mcp-ai' ) );
		}

		// Track what's being updated.
		$updated_fields = array();

		// Update title if provided.
		if ( isset( $arguments['title'] ) ) {
			$title = sanitize_text_field( $arguments['title'] );
			if ( '' === $title ) {
				return new WP_Error( 'wp_mcp_ai_invalid_title', __( 'Quiz title cannot be empty.', 'wp-mcp-ai' ) );
			}
			wp_update_post(
				array(
					'ID'         => $quiz_id,
					'post_title' => $title,
				)
			);
			$updated_fields[] = 'title';
		}

		// Update description if provided.
		if ( isset( $arguments['description'] ) ) {
			$description = wp_kses_post( $arguments['description'] );
			update_post_meta( $quiz_id, '_mcp_ai_quiz_description', $description );
			$updated_fields[] = 'description';
		}

		// Update time limit if provided.
		if ( isset( $arguments['time_limit'] ) ) {
			$time_limit = absint( $arguments['time_limit'] );
			update_post_meta( $quiz_id, '_mcp_ai_quiz_time_limit', $time_limit );
			$updated_fields[] = 'time_limit';
		}

		// Update passing score if provided.
		if ( isset( $arguments['passing_score'] ) ) {
			$passing_score = absint( $arguments['passing_score'] );
			if ( $passing_score < 0 || $passing_score > 100 ) {
				return new WP_Error( 'wp_mcp_ai_invalid_passing_score', __( 'Passing score must be between 0 and 100.', 'wp-mcp-ai' ) );
			}
			update_post_meta( $quiz_id, '_mcp_ai_quiz_passing_score', $passing_score );
			$updated_fields[] = 'passing_score';
		}

		// Update questions if provided.
		if ( isset( $arguments['questions'] ) && is_array( $arguments['questions'] ) ) {
			$questions = $arguments['questions'];

			if ( empty( $questions ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_questions', __( 'At least one question is required.', 'wp-mcp-ai' ) );
			}

			// Validate questions.
			$validated_questions = array();
			$total_points        = 0;

			foreach ( $questions as $index => $question_data ) {
				if ( ! isset( $question_data['question'] ) || '' === trim( $question_data['question'] ) ) {
					return new WP_Error( 'wp_mcp_ai_invalid_question', sprintf( __( 'Question %d is missing question text.', 'wp-mcp-ai' ), $index + 1 ) );
				}

				if ( ! isset( $question_data['type'] ) || ! in_array( $question_data['type'], array( 'multiple_choice', 'true_false', 'short_answer' ), true ) ) {
					return new WP_Error( 'wp_mcp_ai_invalid_type', sprintf( __( 'Question %d has an invalid type.', 'wp-mcp-ai' ), $index + 1 ) );
				}

				$validated_question = array(
					'question' => sanitize_text_field( $question_data['question'] ),
					'type'     => sanitize_key( $question_data['type'] ),
					'points'   => isset( $question_data['points'] ) ? absint( $question_data['points'] ) : 1,
				);

				// Validate options for multiple choice.
				if ( 'multiple_choice' === $validated_question['type'] ) {
					if ( empty( $question_data['options'] ) || ! is_array( $question_data['options'] ) ) {
						return new WP_Error( 'wp_mcp_ai_missing_options', sprintf( __( 'Question %d is missing answer options.', 'wp-mcp-ai' ), $index + 1 ) );
					}
					$validated_question['options'] = array_map( 'sanitize_text_field', $question_data['options'] );
				}

				// Store correct answer if provided.
				if ( isset( $question_data['correct_answer'] ) ) {
					$validated_question['correct_answer'] = sanitize_text_field( $question_data['correct_answer'] );
				}

				$total_points         += $validated_question['points'];
				$validated_questions[] = $validated_question;
			}

			// Update questions and total points.
			update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $validated_questions );
			update_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', $total_points );
			$updated_fields[] = 'questions';
		}

		// If nothing was updated, return error.
		if ( empty( $updated_fields ) ) {
			return new WP_Error( 'wp_mcp_ai_no_updates', __( 'No fields provided to update.', 'wp-mcp-ai' ) );
		}

		// Trigger CCT sync by touching the post (this will trigger the save_post hook).
		wp_update_post(
			array(
				'ID'            => $quiz_id,
				'post_modified' => current_time( 'mysql' ),
			)
		);

		// Get updated quiz data.
		$quiz            = get_post( $quiz_id );
		$description     = get_post_meta( $quiz_id, '_mcp_ai_quiz_description', true );
		$time_limit      = get_post_meta( $quiz_id, '_mcp_ai_quiz_time_limit', true );
		$questions       = get_post_meta( $quiz_id, '_mcp_ai_quiz_questions', true );
		$total_points    = get_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', true );
		$passing_score   = get_post_meta( $quiz_id, '_mcp_ai_quiz_passing_score', true );

		return array(
			'summary'        => sprintf(
				/* translators: 1: quiz title, 2: quiz ID, 3: comma-separated list of updated fields */
				__( 'Quiz updated: %1$s (ID: %2$d). Updated fields: %3$s', 'wp-mcp-ai' ),
				$quiz->post_title,
				$quiz_id,
				implode( ', ', $updated_fields )
			),
			'quiz_id'        => $quiz_id,
			'title'          => $quiz->post_title,
			'description'    => $description,
			'time_limit'     => absint( $time_limit ),
			'question_count' => is_array( $questions ) ? count( $questions ) : 0,
			'total_points'   => absint( $total_points ),
			'passing_score'  => absint( $passing_score ),
			'author_id'      => absint( $quiz->post_author ),
			'updated_at'     => $quiz->post_modified,
			'updated_fields' => $updated_fields,
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
			'reversible',
		);
	}
}
