<?php
/**
 * Tool: Query Graph
 *
 * Answers a natural-language question by traversing the knowledge graph.
 *
 * @package NVoOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query the knowledge graph with a natural-language question.
 *
 * Uses BFS or DFS traversal to collect relevant context from the graph
 * within a configurable depth and token budget.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Tool_Query_Graph implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_slug() {
		return 'graphify_query_graph';
	}

	/**
	 * Get the human-readable tool name.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_name() {
		return __( 'Query Knowledge Graph', 'nvoos-graphify' );
	}

	/**
	 * Get the LLM-facing description.
	 *
	 * @since  0.1.0
	 * @return string
	 */
	public function get_description() {
		return __( 'Answer a natural-language question by traversing the site knowledge graph. Supports BFS or DFS traversal with configurable depth and token budget to control context size.', 'nvoos-graphify' );
	}

	/**
	 * Get capability flags for the tool registry.
	 *
	 * @since  0.1.0
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'cacheable', 'local-only' );
	}

	/**
	 * Get the JSON Schema for accepted parameters.
	 *
	 * @since  0.1.0
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'question'     => array(
					'type'        => 'string',
					'minLength'   => 1,
					'maxLength'   => 500,
					'description' => __( 'The natural-language question to answer using the knowledge graph.', 'nvoos-graphify' ),
				),
				'mode'         => array(
					'type'        => 'string',
					'enum'        => array( 'bfs', 'dfs' ),
					'default'     => 'bfs',
					'description' => __( 'Graph traversal strategy: breadth-first (bfs) or depth-first (dfs).', 'nvoos-graphify' ),
				),
				'depth'        => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 6,
					'default'     => 3,
					'description' => __( 'Maximum traversal depth from the seed nodes.', 'nvoos-graphify' ),
				),
				'token_budget' => array(
					'type'        => 'integer',
					'minimum'     => 100,
					'maximum'     => 16000,
					'default'     => 4000,
					'description' => __( 'Approximate token budget for the returned context text.', 'nvoos-graphify' ),
				),
			),
			'required'   => array( 'question' ),
		);
	}

	/**
	 * Execute the graph query.
	 *
	 * @since  0.1.0
	 * @param  array $arguments Tool arguments.
	 * @param  array $context   Execution context.
	 * @return array|WP_Error Context text on success, WP_Error on failure.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$is_guest = ! empty( $context['guest_request'] ) && ! empty( $context['assistant_id'] );

		if ( ! $is_guest && ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'nvoos-graphify' ) );
		}

		if ( empty( $arguments['question'] ) ) {
			return new WP_Error( 'missing_question', __( 'A question is required.', 'nvoos-graphify' ) );
		}

		$question     = sanitize_text_field( $arguments['question'] );
		$mode         = isset( $arguments['mode'] ) ? sanitize_text_field( $arguments['mode'] ) : 'bfs';
		$depth        = isset( $arguments['depth'] ) ? absint( $arguments['depth'] ) : 3;
		$token_budget = isset( $arguments['token_budget'] ) ? absint( $arguments['token_budget'] ) : 4000;

		if ( ! in_array( $mode, array( 'bfs', 'dfs' ), true ) ) {
			return new WP_Error( 'invalid_mode', __( 'Mode must be "bfs" or "dfs".', 'nvoos-graphify' ) );
		}

		$depth        = max( 1, min( 6, $depth ) );
		$token_budget = max( 100, min( 16000, $token_budget ) );

		$analyzer = new NV_oOS_Graphify_Analyzer();
		$result   = $analyzer->query_graph( $question, $mode, $depth, $token_budget );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
			'message' => __( 'Graph query completed.', 'nvoos-graphify' ),
			'data'    => array(
				'context' => $result,
			),
		);
	}
}
