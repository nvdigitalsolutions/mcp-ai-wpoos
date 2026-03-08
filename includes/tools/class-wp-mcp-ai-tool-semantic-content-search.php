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
	use WP_MCP_AI_Tool_Chat_Response;

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
		return __( 'Semantic Content Search', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Performs semantic search across WordPress content using vector embeddings. Supports OpenAI and Gemini embedding providers — automatically uses the embedding service that matches the assistant\'s configured AI provider. Use this to find similar posts/pages, provide content recommendations, answer questions from a knowledge base, or detect duplicate content.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'           => array(
					'type'        => 'string',
					'description' => __( 'Search query text to find semantically similar content.', 'mcp-ai-wpoos' ),
				),
				'vector_store_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional OpenAI vector store ID to search. When provided (or configured on the assistant), the tool searches the vector store in addition to local WordPress content.', 'mcp-ai-wpoos' ),
				),
				'post_types'      => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'description' => __( 'Array of post types to search. Defaults to ["post", "page"].', 'mcp-ai-wpoos' ),
					'default'     => array( 'post', 'page' ),
				),
				'limit'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return.', 'mcp-ai-wpoos' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'threshold'       => array(
					'type'        => 'number',
					'description' => __( 'Minimum similarity score threshold (0-1). Higher values return more similar results.', 'mcp-ai-wpoos' ),
					'default'     => 0.7,
					'minimum'     => 0,
					'maximum'     => 1,
				),
				'include_meta'    => array(
					'type'        => 'boolean',
					'description' => __( 'Include post metadata in results.', 'mcp-ai-wpoos' ),
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
	 * @param array $context   Execution context including user_id and assistant_config.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check permissions.
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to perform semantic search.', 'mcp-ai-wpoos' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}
		// Validate query.
		if ( ! isset( $arguments['query'] ) || empty( $arguments['query'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_query',
				__( 'Search query is required.', 'mcp-ai-wpoos' )
			);
		}

		$query        = sanitize_text_field( $arguments['query'] );
		$post_types   = isset( $arguments['post_types'] ) && is_array( $arguments['post_types'] ) ? array_map( 'sanitize_text_field', $arguments['post_types'] ) : array( 'post', 'page' );
		$limit        = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$threshold    = isset( $arguments['threshold'] ) ? floatval( $arguments['threshold'] ) : 0.7;
		$include_meta = isset( $arguments['include_meta'] ) ? (bool) $arguments['include_meta'] : false;

		// Resolve vector store ID: explicit argument > assistant context configuration.
		$vector_store_id = '';
		if ( ! empty( $arguments['vector_store_id'] ) ) {
			$vector_store_id = sanitize_text_field( $arguments['vector_store_id'] );
		} elseif ( ! empty( $context['assistant_config']['vector_store_id'] ) ) {
			$vector_store_id = sanitize_text_field( $context['assistant_config']['vector_store_id'] );
		}

		// Ensure limit is within bounds.
		$limit = max( 1, min( 100, $limit ) );

		// Ensure threshold is within bounds.
		$threshold = max( 0.0, min( 1.0, $threshold ) );

		// Determine which AI provider the assistant uses so embeddings are generated
		// by a configured provider rather than always requiring OpenAI.
		$provider       = isset( $context['assistant_config']['provider'] ) ? strtolower( $context['assistant_config']['provider'] ) : 'openai';
		$results        = array();
		$vector_results = array();

		// Search the OpenAI vector store when one is configured.
		// Vector store search is always OpenAI-specific; a configured OpenAI client is used regardless of the chat provider.
		if ( ! empty( $vector_store_id ) ) {
			$openai_client = new WP_MCP_AI_OpenAI_Client();
			$vs_response   = $openai_client->search_vector_store(
				$vector_store_id,
				$query,
				array( 'max_num_results' => $limit )
			);

			if ( ! is_wp_error( $vs_response ) && ! empty( $vs_response['data'] ) ) {
				foreach ( $vs_response['data'] as $item ) {
					$content_text = '';
					if ( ! empty( $item['content'] ) && is_array( $item['content'] ) ) {
						foreach ( $item['content'] as $chunk ) {
							if ( isset( $chunk['text'] ) ) {
								$content_text .= $chunk['text'] . ' ';
							}
						}
						$content_text = trim( $content_text );
					}

					$vector_results[] = array(
						'source'           => 'vector_store',
						'vector_store_id'  => $vector_store_id,
						'file_id'          => isset( $item['file_id'] ) ? $item['file_id'] : '',
						'filename'         => isset( $item['filename'] ) ? $item['filename'] : '',
						'excerpt'          => $content_text,
						'similarity_score' => isset( $item['score'] ) ? round( floatval( $item['score'] ), 4 ) : 0.0,
					);
				}
			}
		}

		// Generate embedding for the search query using the configured provider.
		// This supports both OpenAI and Gemini so that assistants using either
		// provider can perform local WordPress content semantic search.
		$embedding_data = $this->generate_query_embedding( $query, $provider );

		if ( is_wp_error( $embedding_data ) ) {
			// If embedding fails but we have vector store results, return those.
			if ( ! empty( $vector_results ) ) {
				$summary_text = sprintf(
					/* translators: 1: number of results, 2: search query */
					__( 'Found %1$d result(s) in the vector store for "%2$s". Local WordPress content search was unavailable.', 'mcp-ai-wpoos' ),
					count( $vector_results ),
					$query
				);

				return array(
					'success'               => true,
					'results'               => array_slice( $vector_results, 0, $limit ),
					'total_found'           => count( $vector_results ),
					'query'                 => $query,
					'query_embedding_model' => '',
					'threshold'             => $threshold,
					'vector_store_id'       => $vector_store_id,
					'message'               => $summary_text,
					'summary'               => $summary_text,
				);
			}

			return new WP_Error(
				$embedding_data->get_error_code(),
				$embedding_data->get_error_message(),
				$embedding_data->get_error_data()
			);
		}

		$query_embedding = $embedding_data['embedding'];
		$embedding_model = $embedding_data['model'];

		if ( empty( $query_embedding ) ) {
			return new WP_Error(
				'wp_mcp_ai_embedding_failed',
				__( 'Failed to generate query embedding.', 'mcp-ai-wpoos' )
			);
		}

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
					'source'           => 'wordpress',
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

		// Merge vector store and local WordPress results.
		$all_results = array_merge( $vector_results, $results );

		// Sort combined results by similarity score (descending).
		usort(
			$all_results,
			function ( $a, $b ) {
				return $b['similarity_score'] <=> $a['similarity_score'];
			}
		);

		// Limit results.
		$all_results = array_slice( $all_results, 0, $limit );

		$summary_text = sprintf(
			/* translators: 1: number of results, 2: search query */
			__( 'Found %1$d semantically similar results for "%2$s".', 'mcp-ai-wpoos' ),
			count( $all_results ),
			$query
		);

		return array(
			'success'               => true,
			'results'               => $all_results,
			'total_found'           => count( $all_results ),
			'query'                 => $query,
			'query_embedding_model' => $embedding_model,
			'threshold'             => $threshold,
			'vector_store_id'       => $vector_store_id,
			'message'               => $summary_text,
			'summary'               => $summary_text,
		);
	}

	/**
	 * Generate a query embedding using the provider that matches the assistant context.
	 *
	 * When the assistant uses Gemini as its chat provider, embeddings are generated via
	 * the Gemini Embeddings API so that no OpenAI API key is required. For all other
	 * providers (OpenAI, Ollama, Cloudflare, etc.) OpenAI embeddings are used, falling
	 * back gracefully when neither service is available.
	 *
	 * @param string $query    Search query text.
	 * @param string $provider Lowercase AI provider name from assistant context (e.g. 'openai', 'gemini').
	 * @return array|WP_Error Array with 'embedding' (float[]) and 'model' (string), or WP_Error on failure.
	 */
	private function generate_query_embedding( $query, $provider ) {
		// Use Gemini embeddings when the assistant is backed by Google Gemini.
		if ( in_array( $provider, array( 'gemini', 'google' ), true ) && class_exists( 'WP_MCP_AI_Gemini_Client' ) ) {
			$gemini_client   = new WP_MCP_AI_Gemini_Client();
			$gemini_response = $gemini_client->create_embedding( $query, array( 'task_type' => 'RETRIEVAL_QUERY' ) );

			if ( ! is_wp_error( $gemini_response ) && isset( $gemini_response['embedding']['values'] ) ) {
				return array(
					'embedding' => $gemini_response['embedding']['values'],
					'model'     => 'text-embedding-004',
				);
			}

			// If Gemini embedding fails, fall through to OpenAI as a last resort.
		}

		// Default: use OpenAI embeddings (also acts as fallback for providers without
		// native embedding APIs such as Ollama, Cloudflare, and Hugging Face).
		$openai_client   = new WP_MCP_AI_OpenAI_Client();
		$openai_response = $openai_client->create_embeddings( $query );

		if ( is_wp_error( $openai_response ) ) {
			return $openai_response;
		}

		if ( ! isset( $openai_response['data'][0]['embedding'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_embedding_failed',
				__( 'Failed to generate query embedding.', 'mcp-ai-wpoos' )
			);
		}

		return array(
			'embedding' => $openai_response['data'][0]['embedding'],
			'model'     => isset( $openai_response['model'] ) ? $openai_response['model'] : 'text-embedding-3-small',
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
		$vector_size = count( $vector_a );

		for ( $i = 0; $i < $vector_size; $i++ ) {
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

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'content_publishing',

			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),

			'profession_tags'       => array( 'content_strategist', 'researcher' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Makes external API calls (OpenAI or Gemini) for query embedding.
			'requires-capability',  // Requires 'read' capability.
		);
	}
}
