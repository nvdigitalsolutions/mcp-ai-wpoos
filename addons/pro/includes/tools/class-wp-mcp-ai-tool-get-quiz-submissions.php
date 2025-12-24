<?php
/**
 * Tool for retrieving quiz submissions.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves quiz submissions.
 */
class WP_MCP_AI_Tool_Get_Quiz_Submissions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_quiz_submissions';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Quiz Submissions', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves submissions for a specific quiz.', 'wp-mcp-ai' );
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
					'description' => __( 'The ID of the quiz.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'status'  => array(
					'type'        => 'string',
					'description' => __( 'Filter by submission status: pending, graded, or all.', 'wp-mcp-ai' ),
					'enum'        => array( 'pending', 'graded', 'all' ),
					'default'     => 'all',
				),
				'per_page' => array(
					'type'        => 'integer',
					'description' => __( 'Number of submissions to retrieve per page.', 'wp-mcp-ai' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'     => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination.', 'wp-mcp-ai' ),
					'default'     => 1,
					'minimum'     => 1,
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view submissions.', 'wp-mcp-ai' ) );
		}

		$quiz_id  = isset( $arguments['quiz_id'] ) ? absint( $arguments['quiz_id'] ) : 0;
		$status   = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : 'all';
		$per_page = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		if ( ! $quiz_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_quiz_id', __( 'Quiz ID is required.', 'wp-mcp-ai' ) );
		}

		$quiz = get_post( $quiz_id );

		if ( ! $quiz || 'mcp_ai_quiz' !== $quiz->post_type ) {
			return new WP_Error( 'wp_mcp_ai_quiz_not_found', __( 'Quiz not found.', 'wp-mcp-ai' ) );
		}

		// Check if user can view submissions (must be author or have edit_others_posts).
		$quiz_author = absint( $quiz->post_author );
		if ( $quiz_author !== $current_user_id && ! user_can( $current_user_id, 'edit_others_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view submissions for this quiz.', 'wp-mcp-ai' ) );
		}

		$query_args = array(
			'post_type'      => 'mcp_ai_submission',
			'post_status'    => array( 'publish', 'pending' ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'meta_query'     => array(
				array(
					'key'   => '_mcp_ai_submission_quiz_id',
					'value' => $quiz_id,
				),
			),
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		// Filter by status if specified.
		if ( 'pending' === $status ) {
			$query_args['meta_query'][] = array(
				'key'   => '_mcp_ai_submission_status',
				'value' => 'pending',
			);
		} elseif ( 'graded' === $status ) {
			$query_args['meta_query'][] = array(
				'key'   => '_mcp_ai_submission_status',
				'value' => 'graded',
			);
		}

		$query = new WP_Query( $query_args );

		$submissions = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$submission_id = get_the_ID();

				$submission_status = get_post_meta( $submission_id, '_mcp_ai_submission_status', true );
				$submission_data   = array(
					'submission_id' => $submission_id,
					'student_id'    => absint( get_the_author_meta( 'ID' ) ),
					'student_name'  => get_the_author_meta( 'display_name' ),
					'status'        => $submission_status,
					'submitted_at'  => get_the_date( 'c' ),
				);

				// Add time tracking information.
				$completion_time = get_post_meta( $submission_id, '_mcp_ai_submission_completion_time', true );
				if ( $completion_time ) {
					$submission_data['completion_time_minutes'] = floatval( $completion_time );
				}

				// Add grading info if graded.
				if ( 'graded' === $submission_status ) {
					$submission_data['earned_points'] = floatval( get_post_meta( $submission_id, '_mcp_ai_submission_earned_points', true ) );
					$submission_data['percentage']    = floatval( get_post_meta( $submission_id, '_mcp_ai_submission_percentage', true ) );
					$submission_data['passed']        = (bool) get_post_meta( $submission_id, '_mcp_ai_submission_passed', true );
					$submission_data['graded_by']     = absint( get_post_meta( $submission_id, '_mcp_ai_submission_graded_by', true ) );
					$submission_data['graded_at']     = get_post_meta( $submission_id, '_mcp_ai_submission_graded_at', true );
				}

				$submissions[] = $submission_data;
			}
			wp_reset_postdata();
		}

		return array(
			'summary'     => sprintf(
				/* translators: %d: number of submissions */
				_n( 'Found %d submission', 'Found %d submissions', count( $submissions ), 'wp-mcp-ai' ),
				count( $submissions )
			),
			'quiz_id'     => $quiz_id,
			'quiz_title'  => get_the_title( $quiz ),
			'submissions' => $submissions,
			'total'       => $query->found_posts,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $query->max_num_pages,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'local-only',
			'requires-capability',
			'paginated',
		);
	}
}
