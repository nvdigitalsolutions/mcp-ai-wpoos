<?php
/**
 * Tool for creating quizzes.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new quiz with questions.
 */
class WP_MCP_AI_Tool_Create_Quiz implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_quiz';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Quiz', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new quiz with questions. Supports multiple choice, true/false, and short answer formats. Optionally includes a time limit.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'         => array(
					'type'        => 'string',
					'description' => __( 'Title of the quiz.', 'wp-mcp-ai' ),
				),
				'description'   => array(
					'type'        => 'string',
					'description' => __( 'Optional description or instructions for the quiz.', 'wp-mcp-ai' ),
				),
				'time_limit'    => array(
					'type'        => 'integer',
					'description' => __( 'Time limit in minutes for completing the quiz. 0 for no limit.', 'wp-mcp-ai' ),
					'default'     => 0,
					'minimum'     => 0,
				),
				'questions'     => array(
					'type'        => 'array',
					'description' => __( 'Array of questions for the quiz.', 'wp-mcp-ai' ),
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
					'description' => __( 'Minimum percentage (0-100) required to pass the quiz.', 'wp-mcp-ai' ),
					'default'     => 70,
					'minimum'     => 0,
					'maximum'     => 100,
				),
			),
			'required'             => array( 'title', 'questions' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create quizzes.', 'wp-mcp-ai' ) );
		}

		// Validate and sanitize inputs.
		$title         = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$description   = isset( $arguments['description'] ) ? wp_kses_post( $arguments['description'] ) : '';
		$time_limit    = isset( $arguments['time_limit'] ) ? absint( $arguments['time_limit'] ) : 0;
		$questions     = isset( $arguments['questions'] ) && is_array( $arguments['questions'] ) ? $arguments['questions'] : array();
		$passing_score = isset( $arguments['passing_score'] ) ? absint( $arguments['passing_score'] ) : 70;

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Quiz title is required.', 'wp-mcp-ai' ) );
		}

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

		// Create quiz post.
		$quiz_data = array(
			'post_type'   => 'mcp_ai_quiz',
			'post_title'  => $title,
			'post_status' => 'publish',
			'post_author' => $current_user_id,
		);

		$quiz_id = wp_insert_post( $quiz_data, true );

		if ( is_wp_error( $quiz_id ) ) {
			return $quiz_id;
		}

		// Store quiz metadata.
		update_post_meta( $quiz_id, '_mcp_ai_quiz_description', $description );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_time_limit', $time_limit );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_questions', $validated_questions );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', $total_points );
		update_post_meta( $quiz_id, '_mcp_ai_quiz_passing_score', $passing_score );

		$quiz = get_post( $quiz_id );

		return array(
			'summary'        => sprintf(
				/* translators: 1: quiz title, 2: quiz ID */
				__( 'Quiz created: %1$s (ID: %2$d)', 'wp-mcp-ai' ),
				$title,
				$quiz_id
			),
			'quiz_id'        => $quiz_id,
			'title'          => $title,
			'description'    => $description,
			'time_limit'     => $time_limit,
			'question_count' => count( $validated_questions ),
			'total_points'   => $total_points,
			'passing_score'  => $passing_score,
			'author_id'      => $current_user_id,
			'created_at'     => $quiz->post_date,
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
