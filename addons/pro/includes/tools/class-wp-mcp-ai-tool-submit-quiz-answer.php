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
				'started_at' => array(
					'type'        => 'string',
					'description' => __( 'ISO 8601 timestamp when the quiz was started. Used to validate time limits.', 'wp-mcp-ai' ),
					'format'      => 'date-time',
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

		$quiz_id    = isset( $arguments['quiz_id'] ) ? absint( $arguments['quiz_id'] ) : 0;
		$answers    = isset( $arguments['answers'] ) && is_array( $arguments['answers'] ) ? $arguments['answers'] : array();
		$user_id    = isset( $arguments['user_id'] ) ? absint( $arguments['user_id'] ) : $current_user_id;
		$started_at = isset( $arguments['started_at'] ) ? sanitize_text_field( $arguments['started_at'] ) : '';

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

		// Get quiz time limit.
		$time_limit = get_post_meta( $quiz_id, '_mcp_ai_quiz_time_limit', true );
		$time_limit = absint( $time_limit );

		// Validate time limit if quiz has one and started_at is provided.
		if ( $time_limit > 0 && $started_at ) {
			$started_timestamp = strtotime( $started_at );
			$current_timestamp = current_time( 'timestamp' );

			if ( false === $started_timestamp ) {
				return new WP_Error( 'wp_mcp_ai_invalid_timestamp', __( 'Invalid started_at timestamp format.', 'wp-mcp-ai' ) );
			}

			// Calculate elapsed time in minutes.
			$elapsed_minutes = ( $current_timestamp - $started_timestamp ) / 60;

			// Allow 1 minute grace period for submission processing.
			if ( $elapsed_minutes > ( $time_limit + 1 ) ) {
				return new WP_Error(
					'wp_mcp_ai_time_limit_exceeded',
					sprintf(
						/* translators: 1: time limit, 2: elapsed time */
						__( 'Time limit exceeded. Quiz time limit: %1$d minutes. Time taken: %2$.1f minutes.', 'wp-mcp-ai' ),
						$time_limit,
						$elapsed_minutes
					)
				);
			}
		} elseif ( $time_limit > 0 && ! $started_at ) {
			// Warn if time limit exists but no start time provided.
			return new WP_Error(
				'wp_mcp_ai_missing_start_time',
				sprintf(
					/* translators: %d: time limit in minutes */
					__( 'This quiz has a %d minute time limit. Please provide started_at timestamp.', 'wp-mcp-ai' ),
					$time_limit
				)
			);
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

		// Get quiz total points for grading context.
		$total_points = get_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', true );

		// Calculate completion time if started_at was provided.
		$completion_time_minutes = null;
		if ( $started_at ) {
			$started_timestamp = strtotime( $started_at );
			$current_timestamp = current_time( 'timestamp' );
			$completion_time_minutes = round( ( $current_timestamp - $started_timestamp ) / 60, 2 );
		}

		// Store submission metadata.
		update_post_meta( $submission_id, '_mcp_ai_submission_quiz_id', $quiz_id );
		update_post_meta( $submission_id, '_mcp_ai_submission_answers', $sanitized_answers );
		update_post_meta( $submission_id, '_mcp_ai_submission_status', 'pending' );
		update_post_meta( $submission_id, '_mcp_ai_submission_total_points', absint( $total_points ) );
		update_post_meta( $submission_id, '_mcp_ai_submission_submitted_at', current_time( 'mysql' ) );

		// Store time tracking data.
		if ( $started_at ) {
			update_post_meta( $submission_id, '_mcp_ai_submission_started_at', $started_at );
		}
		if ( null !== $completion_time_minutes ) {
			update_post_meta( $submission_id, '_mcp_ai_submission_completion_time', $completion_time_minutes );
		}

		$submission = get_post( $submission_id );

		$result = array(
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

		// Add time tracking information if available.
		if ( $time_limit > 0 ) {
			$result['time_limit'] = $time_limit;
		}
		if ( $started_at ) {
			$result['started_at'] = $started_at;
		}
		if ( null !== $completion_time_minutes ) {
			$result['completion_time_minutes'] = $completion_time_minutes;
		}

		return $result;
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
