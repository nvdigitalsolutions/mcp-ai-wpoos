<?php
/**
 * Tool that performs semantic search across WordPress content using embeddings.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Performs semantic search across WordPress content using vector embeddings.
 */
class WP_MCP_AI_Tool_Semantic_Content_Search implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Maximum posts to query for semantic search.
	 *
	 * @var int
	 */
	const MAX_POSTS_TO_QUERY = 1000;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'semantic_content_search';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Semantic Content Search', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Performs semantic search across WordPress content using vector embeddings. Use this to find similar posts/pages, provide content recommendations, answer questions from a knowledge base, or detect duplicate content.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'        => array(
					'type'        => 'string',
					'description' => __( 'Search query text to find semantically similar content.', 'wp-mcp-ai' ),
				),
				'post_types'   => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'description' => __( 'Array of post types to search. Defaults to ["post", "page"].', 'wp-mcp-ai' ),
					'default'     => array( 'post', 'page' ),
				),
				'limit'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return.', 'wp-mcp-ai' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'threshold'    => array(
					'type'        => 'number',
					'description' => __( 'Minimum similarity score threshold (0-1). Higher values return more similar results.', 'wp-mcp-ai' ),
					'default'     => 0.7,
					'minimum'     => 0,
					'maximum'     => 1,
				),
				'include_meta' => array(
					'type'        => 'boolean',
					'description' => __( 'Include post metadata in results.', 'wp-mcp-ai' ),
					'default'     => false,
				),
			),
			'required'             => array( 'query' ),
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check permissions.
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to perform semantic search.', 'wp-mcp-ai' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}
		// Validate query.
		if ( ! isset( $arguments['query'] ) || empty( $arguments['query'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_query',
				__( 'Search query is required.', 'wp-mcp-ai' )
			);
		}

		$query        = sanitize_text_field( $arguments['query'] );
		$post_types   = isset( $arguments['post_types'] ) && is_array( $arguments['post_types'] ) ? array_map( 'sanitize_text_field', $arguments['post_types'] ) : array( 'post', 'page' );
		$limit        = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$threshold    = isset( $arguments['threshold'] ) ? floatval( $arguments['threshold'] ) : 0.7;
		$include_meta = isset( $arguments['include_meta'] ) ? (bool) $arguments['include_meta'] : false;

		// Ensure limit is within bounds.
		$limit = max( 1, min( 100, $limit ) );

		// Ensure threshold is within bounds.
		$threshold = max( 0.0, min( 1.0, $threshold ) );

		// Generate embedding for the search query.
		$client           = new WP_MCP_AI_OpenAI_Client();
		$embedding_result = $client->create_embeddings( $query );

		if ( is_wp_error( $embedding_result ) ) {
			return new WP_Error(
				$embedding_result->get_error_code(),
				$embedding_result->get_error_message(),
				$embedding_result->get_error_data()
			);
		}

		// Extract the query embedding vector.
		$query_embedding = isset( $embedding_result['data'][0]['embedding'] ) ? $embedding_result['data'][0]['embedding'] : array();

		if ( empty( $query_embedding ) ) {
			return new WP_Error(
				'wp_mcp_ai_embedding_failed',
				__( 'Failed to generate query embedding.', 'wp-mcp-ai' )
			);
		}

		$embedding_model = isset( $embedding_result['model'] ) ? $embedding_result['model'] : 'text-embedding-3-small';

		// Query posts with stored embeddings.
		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => self::MAX_POSTS_TO_QUERY,
			'meta_key'       => '_wp_mcp_ai_embeddings', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_wp_mcp_ai_embeddings',
					'compare' => 'EXISTS',
				),
			),
		);

		$posts_query = new WP_Query( $args );
		$results     = array();

		if ( $posts_query->have_posts() ) {
			while ( $posts_query->have_posts() ) {
				$posts_query->the_post();
				$post_id = get_the_ID();

				// Get stored embeddings.
				$stored_embeddings = get_post_meta( $post_id, '_wp_mcp_ai_embeddings', true );

				if ( empty( $stored_embeddings ) || ! isset( $stored_embeddings['embeddings'][0]['embedding'] ) ) {
					continue;
				}

				$post_embedding = $stored_embeddings['embeddings'][0]['embedding'];

				// Calculate cosine similarity.
				$similarity = $this->calculate_cosine_similarity( $query_embedding, $post_embedding );

				// Filter by threshold.
				if ( $similarity < $threshold ) {
					continue;
				}

				$result = array(
					'post_id'          => $post_id,
					'title'            => get_the_title(),
					'excerpt'          => get_the_excerpt(),
					'similarity_score' => round( $similarity, 4 ),
					'permalink'        => get_permalink(),
					'post_type'        => get_post_type(),
				);

				if ( $include_meta ) {
					$result['meta'] = array(
						'author'          => get_the_author(),
						'date'            => get_the_date( 'Y-m-d H:i:s' ),
						'modified'        => get_the_modified_date( 'Y-m-d H:i:s' ),
						'embedding_model' => isset( $stored_embeddings['model'] ) ? $stored_embeddings['model'] : '',
					);
				}

				$results[] = $result;
			}

			wp_reset_postdata();
		}

		// Sort results by similarity score (descending).
		usort(
			$results,
			function ( $a, $b ) {
				return $b['similarity_score'] <=> $a['similarity_score'];
			}
		);

		// Limit results.
		$results = array_slice( $results, 0, $limit );

		return array(
			'success'               => true,
			'results'               => $results,
			'total_found'           => count( $results ),
			'query'                 => $query,
			'query_embedding_model' => $embedding_model,
			'threshold'             => $threshold,
			'summary'               => sprintf(
				/* translators: 1: number of results, 2: search query */
				__( 'Found %1$d semantically similar results for "%2$s".', 'wp-mcp-ai' ),
				count( $results ),
				$query
			),
		);
	}

	/**
	 * Calculate cosine similarity between two vectors.
	 *
	 * @param array $vector_a First vector.
	 * @param array $vector_b Second vector.
	 * @return float Cosine similarity score (0-1).
	 */
	private function calculate_cosine_similarity( $vector_a, $vector_b ) {
		if ( count( $vector_a ) !== count( $vector_b ) ) {
			return 0.0;
		}

		$dot_product = 0.0;
		$magnitude_a = 0.0;
		$magnitude_b = 0.0;

		for ( $i = 0; $i < count( $vector_a ); $i++ ) {
			$dot_product += $vector_a[ $i ] * $vector_b[ $i ];
			$magnitude_a += $vector_a[ $i ] * $vector_a[ $i ];
			$magnitude_b += $vector_b[ $i ] * $vector_b[ $i ];
		}

		$magnitude_a = sqrt( $magnitude_a );
		$magnitude_b = sqrt( $magnitude_b );

		if ( 0.0 === $magnitude_a || 0.0 === $magnitude_b ) {
			return 0.0;
		}

		return $dot_product / ( $magnitude_a * $magnitude_b );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Makes external API calls to OpenAI for query embedding.
			'requires-capability',  // Requires 'read' capability.
		);
	}
}
