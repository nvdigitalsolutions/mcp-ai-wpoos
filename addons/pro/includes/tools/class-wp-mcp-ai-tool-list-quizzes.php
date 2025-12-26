<?php
/**
 * Tool for listing quizzes.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists available quizzes.
 */
class WP_MCP_AI_Tool_List_Quizzes implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_quizzes';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Quizzes', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists available quizzes with optional filtering.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'author_id' => array(
					'type'        => 'integer',
					'description' => __( 'Filter by quiz author ID.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'per_page'  => array(
					'type'        => 'integer',
					'description' => __( 'Number of quizzes to retrieve per page.', 'wp-mcp-ai' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'page'      => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination.', 'wp-mcp-ai' ),
					'default'     => 1,
					'minimum'     => 1,
				),
			),
			'required'             => array(),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view quizzes.', 'wp-mcp-ai' ) );
		}

		$author_id = isset( $arguments['author_id'] ) ? absint( $arguments['author_id'] ) : 0;
		$per_page  = isset( $arguments['per_page'] ) ? absint( $arguments['per_page'] ) : 10;
		$page      = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$query_args = array(
			'post_type'      => 'mcp_ai_quiz',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( $author_id > 0 ) {
			$query_args['author'] = $author_id;
		}

		$query = new WP_Query( $query_args );

		$quizzes = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$quiz_id = get_the_ID();

				$quizzes[] = array(
					'quiz_id'        => $quiz_id,
					'title'          => get_the_title(),
					'description'    => get_post_meta( $quiz_id, '_mcp_ai_quiz_description', true ),
					'time_limit'     => absint( get_post_meta( $quiz_id, '_mcp_ai_quiz_time_limit', true ) ),
					'question_count' => count( get_post_meta( $quiz_id, '_mcp_ai_quiz_questions', true ) ),
					'total_points'   => absint( get_post_meta( $quiz_id, '_mcp_ai_quiz_total_points', true ) ),
					'passing_score'  => absint( get_post_meta( $quiz_id, '_mcp_ai_quiz_passing_score', true ) ),
					'author_id'      => absint( get_the_author_meta( 'ID' ) ),
					'created_at'     => get_the_date( 'c' ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'summary'     => sprintf(
				/* translators: %d: number of quizzes */
				_n( 'Found %d quiz', 'Found %d quizzes', count( $quizzes ), 'wp-mcp-ai' ),
				count( $quizzes )
			),
			'quizzes'     => $quizzes,
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
