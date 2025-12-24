<?php
/**
 * Tool for submitting quiz answers.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submits answers for a quiz.
 */
class WP_MCP_AI_Tool_Submit_Quiz_Answer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'submit_quiz_answer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Submit Quiz Answer', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Submits answers for a quiz. Creates a submission record for grading.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'quiz_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the quiz being answered.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'answers' => array(
					'type'        => 'array',
					'description' => __( 'Array of answers to quiz questions.', 'wp-mcp-ai' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'question_index' => array(
								'type'        => 'integer',
								'description' => __( 'Zero-based index of the question.', 'wp-mcp-ai' ),
								'minimum'     => 0,
							),
							'answer'         => array(
								'type'        => 'string',
								'description' => __( 'The submitted answer.', 'wp-mcp-ai' ),
							),
						),
						'required'   => array( 'question_index', 'answer' ),
					),
				),
				'user_id' => array(
					'type'        => 'integer',
					'description' => __( 'User ID submitting the answers. Defaults to current user.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'quiz_id', 'answers' ),
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to submit quiz answers.', 'wp-mcp-ai' ) );
		}

		$quiz_id = isset( $arguments['quiz_id'] ) ? absint( $arguments['quiz_id'] ) : 0;
		$answers = isset( $arguments['answers'] ) && is_array( $arguments['answers'] ) ? $arguments['answers'] : array();
		$user_id = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : $current_user_id;

		if ( ! $quiz_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_quiz_id', __( 'Quiz ID is required.', 'wp-mcp-ai' ) );
		}

		if ( empty( $answers ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_answers', __( 'At least one answer is required.', 'wp-mcp-ai' ) );
		}

		$quiz = get_post( $quiz_id );

		if ( ! $quiz || 'mcp_ai_quiz' !== $quiz->post_type ) {
			return new WP_Error( 'wp_mcp_ai_quiz_not_found', __( 'Quiz not found.', 'wp-mcp-ai' ) );
		}

		// Check if submitting for another user.
		if ( $user_id !== $current_user_id && ! user_can( $current_user_id, 'edit_users' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to submit on behalf of other users.', 'wp-mcp-ai' ) );
		}

		// Validate submitted user exists.
		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_user', __( 'The specified user does not exist.', 'wp-mcp-ai' ) );
		}

		// Check if submission already exists.
		$existing = get_posts(
			array(
				'post_type'   => 'mcp_ai_submission',
				'author'      => $user_id,
				'meta_key'    => '_mcp_ai_submission_quiz_id',
				'meta_value'  => $quiz_id,
				'post_status' => array( 'publish', 'pending' ),
				'numberposts' => 1,
			)
		);

		if ( ! empty( $existing ) ) {
			return new WP_Error( 'wp_mcp_ai_duplicate_submission', __( 'A submission for this quiz already exists.', 'wp-mcp-ai' ) );
		}

		// Sanitize answers.
		$sanitized_answers = array();
		foreach ( $answers as $answer_data ) {
			if ( ! isset( $answer_data['question_index'] ) || ! isset( $answer_data['answer'] ) ) {
				continue;
			}

			$sanitized_answers[] = array(
				'question_index' => absint( $answer_data['question_index'] ),
				'answer'         => sanitize_text_field( $answer_data['answer'] ),
			);
		}

		// Create submission post.
		$submission_data = array(
			'post_type'   => 'mcp_ai_submission',
			'post_title'  => sprintf(
				/* translators: 1: quiz title, 2: user display name */
				__( '%1$s - %2$s', 'wp-mcp-ai' ),
				get_the_title( $quiz ),
				get_userdata( $user_id )->display_name
			),
			'post_status' => 'pending',
			'post_author' => $user_id,
		);

		$submission_id = wp_insert_post( $submission_data, true );

		if ( is_wp_error( $submission_id ) ) {
			return $submission_id;
		}

		// Store submission metadata.
		update_post_meta( $submission_id, '_mcp_ai_submission_quiz_id', $quiz_id );
		update_post_meta( $submission_id, '_mcp_ai_submission_answers', $sanitized_answers );
		update_post_meta( $submission_id, '_mcp_ai_submission_status', 'pending' );
		update_post_meta( $submission_id, '_mcp_ai_submission_submitted_at', current_time( 'mysql' ) );

		$submission = get_post( $submission_id );

		return array(
			'summary'       => sprintf(
				/* translators: %s: quiz title */
				__( 'Quiz submission created for: %s', 'wp-mcp-ai' ),
				get_the_title( $quiz )
			),
			'submission_id' => $submission_id,
			'quiz_id'       => $quiz_id,
			'user_id'       => $user_id,
			'answer_count'  => count( $sanitized_answers ),
			'status'        => 'pending',
			'submitted_at'  => $submission->post_date,
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
