<?php
/**
 * Tool — Query Knowledge Graph
 *
 * Natural language → keyword extraction → graph traversal → text context.
 * Enables AI assistants to navigate the site's content structure to answer
 * questions about content relationships, topics, and architecture.
 *
 * @package WP_MCP_AI
 * @since   1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query Knowledge Graph tool implementation.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Tool_Graphify_Query implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'graphify_query_graph';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Query Knowledge Graph', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Query the site knowledge graph with a natural language question. Extracts keywords, finds matching content nodes, traverses their relationships via BFS or DFS, and returns a structured subgraph as context. Use this to understand how content topics are connected, find related posts, discover content clusters, or navigate the site architecture.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'question'     => array(
					'type'        => 'string',
					'description' => __( 'Natural language question about site content, e.g. "What topics are related to WordPress security?" or "How is the about page connected to other content?"', 'mcp-ai-wpoos' ),
					'minLength'   => 3,
					'maxLength'   => 500,
				),
				'mode'         => array(
					'type'        => 'string',
					'description' => __( 'Graph traversal mode. "bfs" (breadth-first) explores neighboring content layer by layer — best for finding closely related content. "dfs" (depth-first) follows chains deeply — best for tracing content paths.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'bfs', 'dfs' ),
					'default'     => 'bfs',
				),
				'depth'        => array(
					'type'        => 'integer',
					'description' => __( 'How many relationship hops to traverse from matching nodes. Higher = more context but potentially more noise.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 6,
					'default'     => 2,
				),
				'token_budget' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum tokens for the text context output. Controls how much graph data is included.', 'mcp-ai-wpoos' ),
					'minimum'     => 500,
					'maximum'     => 16000,
					'default'     => 4000,
				),
			),
			'required'             => array( 'question' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Query results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to query the knowledge graph.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$question     = isset( $arguments['question'] ) ? sanitize_text_field( $arguments['question'] ) : '';
		$mode         = isset( $arguments['mode'] ) ? sanitize_key( $arguments['mode'] ) : 'bfs';
		$depth        = isset( $arguments['depth'] ) ? absint( $arguments['depth'] ) : 2;
		$token_budget = isset( $arguments['token_budget'] ) ? absint( $arguments['token_budget'] ) : 4000;

		if ( empty( $question ) ) {
			return new WP_Error( 'wp_mcp_ai_graphify_missing_question', __( 'Please provide a question to query the knowledge graph.', 'mcp-ai-wpoos' ) );
		}

		// Validate mode.
		if ( ! in_array( $mode, array( 'bfs', 'dfs' ), true ) ) {
			$mode = 'bfs';
		}

		// Clamp depth.
		$depth        = max( 1, min( $depth, 6 ) );
		$token_budget = max( 500, min( $token_budget, 16000 ) );

		$graphify = WP_MCP_AI_Graphify::get_instance();
		$result   = $graphify->query_graph( $question, $mode, $depth, $token_budget );

		if ( empty( $result['context'] ) ) {
			$msg = isset( $result['message'] ) ? $result['message'] : __( 'No relevant content found in the knowledge graph. The graph may need to be built first using graphify_build_graph.', 'mcp-ai-wpoos' );
			return $this->format_chat_response( $result, $msg );
		}

		$message = sprintf(
			/* translators: 1: node count, 2: edge count, 3: anchor nodes */
			__( 'Found %1$d nodes and %2$d relationships. Starting from: %3$s', 'mcp-ai-wpoos' ),
			$result['nodes_found'],
			$result['edges_found'],
			implode( ', ', $result['anchor_nodes'] )
		);

		return $this->format_chat_response( $result, $message );
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.6.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'knowledge_graph',
			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),
			'profession_tags'       => array( 'researcher', 'content_strategist', 'seo_specialist', 'writer' ),
			'risk_level'            => 'info',
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
			'cacheable',
		);
	}
}
