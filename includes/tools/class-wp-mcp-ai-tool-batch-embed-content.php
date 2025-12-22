<?php
/**
 * Tool that generates embeddings for multiple posts/pages in batch.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates embeddings for multiple WordPress posts in batch.
 */
class WP_MCP_AI_Tool_Batch_Embed_Content implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Maximum text length in characters for embedding.
	 * Approximate limit to stay within token constraints.
	 *
	 * @var int
	 */
	const MAX_TEXT_LENGTH = 32000;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'batch_embed_content';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Batch Embed Content', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates embeddings for multiple posts/pages in batch. Use this to prepare semantic search, index content library, build recommendation systems, or initialize vector databases.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_ids'        => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'integer',
					),
					'description' => __( 'Specific post IDs to process. If not provided, uses post_types filter.', 'wp-mcp-ai' ),
				),
				'post_types'      => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'description' => __( 'Post types to process. Defaults to ["post", "page"].', 'wp-mcp-ai' ),
					'default'     => array( 'post', 'page' ),
				),
				'limit'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum posts to process per batch.', 'wp-mcp-ai' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 100,
				),
				'provider'        => array(
					'type'        => 'string',
					'enum'        => array( 'openai', 'gemini' ),
					'description' => __( 'AI provider to use for embeddings. OpenAI uses individual API calls, Gemini uses efficient batch processing.', 'wp-mcp-ai' ),
					'default'     => 'openai',
				),
				'model'           => array(
					'type'        => 'string',
					'enum'        => array( 'text-embedding-3-small', 'text-embedding-3-large', 'text-embedding-ada-002', 'text-embedding-004', 'text-embedding-005' ),
					'description' => __( 'Embedding model to use. OpenAI: text-embedding-3-small/large/ada-002. Gemini: text-embedding-004/005.', 'wp-mcp-ai' ),
					'default'     => 'text-embedding-3-small',
				),
				'store_in_meta'   => array(
					'type'        => 'boolean',
					'description' => __( 'Store embeddings in post metadata.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'update_existing' => array(
					'type'        => 'boolean',
					'description' => __( 'Re-embed posts that already have embeddings.', 'wp-mcp-ai' ),
					'default'     => false,
				),
			),
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
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to batch embed content.', 'wp-mcp-ai' )
			);
		}

		// Get parameters.
		$post_ids        = isset( $arguments['post_ids'] ) && is_array( $arguments['post_ids'] ) ? array_map( 'absint', $arguments['post_ids'] ) : array();
		$post_types      = isset( $arguments['post_types'] ) && is_array( $arguments['post_types'] ) ? array_map( 'sanitize_text_field', $arguments['post_types'] ) : array( 'post', 'page' );
		$limit           = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;
		$provider        = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : 'openai';
		$model           = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : 'text-embedding-3-small';
		$store_in_meta   = isset( $arguments['store_in_meta'] ) ? (bool) $arguments['store_in_meta'] : true;
		$update_existing = isset( $arguments['update_existing'] ) ? (bool) $arguments['update_existing'] : false;

		// Ensure limit is within bounds.
		$limit = max( 1, min( 100, $limit ) );

		// Validate provider.
		if ( ! in_array( $provider, array( 'openai', 'gemini' ), true ) ) {
			$provider = 'openai';
		}

		// Build query args.
		$query_args = array(
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		if ( ! empty( $post_ids ) ) {
			$query_args['post__in'] = $post_ids;
			$query_args['post_type'] = 'any';
		} else {
			$query_args['post_type'] = $post_types;
		}

		// Exclude posts with existing embeddings if not updating.
		if ( ! $update_existing ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => '_wp_mcp_ai_embeddings',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_wp_mcp_ai_embeddings',
					'value'   => '',
					'compare' => '=',
				),
			);
		}

		$posts_query = new WP_Query( $query_args );

		if ( ! $posts_query->have_posts() ) {
			return array(
				'success'        => true,
				'processed'      => 0,
				'embedded'       => 0,
				'skipped'        => 0,
				'errors'         => 0,
				'total_tokens'   => 0,
				'estimated_cost' => 0,
				'model'          => $model,
				'provider'       => $provider,
				'summary'        => __( 'No posts found to process.', 'wp-mcp-ai' ),
			);
		}

		// Use Gemini batch processing for better performance.
		if ( 'gemini' === $provider ) {
			return $this->process_with_gemini( $posts_query, $user_id, $model, $store_in_meta );
		}

		// Use OpenAI individual processing (legacy).
		return $this->process_with_openai( $posts_query, $user_id, $model, $store_in_meta );
	}

	/**
	 * Process posts with Gemini batch embedding API.
	 *
	 * @param WP_Query $posts_query Query object with posts to process.
	 * @param int      $user_id     User ID for permission checks.
	 * @param string   $model       Embedding model to use.
	 * @param bool     $store_in_meta Whether to store embeddings in post meta.
	 * @return array Processing results.
	 */
	protected function process_with_gemini( $posts_query, $user_id, $model, $store_in_meta ) {
		$gemini_client = new WP_MCP_AI_Gemini_Client();

		$processed    = 0;
		$embedded     = 0;
		$skipped      = 0;
		$errors       = 0;
		$total_tokens = 0;

		// Prepare texts and post mappings for batch processing.
		$texts        = array();
		$post_mapping = array();

		while ( $posts_query->have_posts() ) {
			$posts_query->the_post();
			$post_id = get_the_ID();
			$processed++;

			// Check if user can edit this post.
			if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
				$skipped++;
				continue;
			}

			// Prepare content for embedding.
			$title   = get_the_title();
			$content = get_the_content();

			// Combine title and content for embedding.
			$text_to_embed = $title . "\n\n" . wp_strip_all_tags( $content );

			// Limit text length (approximate token limit).
			$text_to_embed = mb_substr( $text_to_embed, 0, self::MAX_TEXT_LENGTH );

			if ( empty( $text_to_embed ) ) {
				$skipped++;
				continue;
			}

			$texts[]                             = $text_to_embed;
			$post_mapping[ count( $texts ) - 1 ] = array(
				'post_id' => $post_id,
				'text'    => $text_to_embed,
			);
		}

		wp_reset_postdata();

		// Process batch if we have texts.
		if ( ! empty( $texts ) ) {
			$batch_result = $gemini_client->batch_embed_content(
				$texts,
				array(
					'model'     => $model,
					'task_type' => 'RETRIEVAL_DOCUMENT',
				)
			);

			if ( is_wp_error( $batch_result ) ) {
				$errors = count( $texts );
			} else {
				// Process results and store embeddings.
				if ( isset( $batch_result['embeddings'] ) && is_array( $batch_result['embeddings'] ) ) {
					foreach ( $batch_result['embeddings'] as $index => $embedding ) {
						if ( ! isset( $post_mapping[ $index ] ) ) {
							continue;
						}

						$post_id       = $post_mapping[ $index ]['post_id'];
						$text_to_embed = $post_mapping[ $index ]['text'];

						if ( $store_in_meta && isset( $embedding['values'] ) ) {
							$embeddings_data = array(
								'embeddings' => array(
									array(
										'embedding' => $embedding['values'],
										'index'     => 0,
									),
								),
								'model'      => $model,
								'provider'   => 'gemini',
								'created_at' => gmdate( 'Y-m-d H:i:s' ),
								'text_hash'  => md5( $text_to_embed ),
							);

							update_post_meta( $post_id, '_wp_mcp_ai_embeddings', $embeddings_data );
							$embedded++;
						}
					}
				}
			}
		}

		// Gemini doesn't return token counts in the same format, estimate based on text length.
		// Rough estimate: 1 token ≈ 4 characters.
		foreach ( $texts as $text ) {
			$total_tokens += absint( mb_strlen( $text ) / 4 );
		}

		// Model pricing (per 1M tokens) - Gemini embedding pricing.
		$pricing = array(
			'text-embedding-004' => 0.00001,  // $0.00001 per 1K tokens = $0.01 per 1M tokens.
			'text-embedding-005' => 0.00001,
		);

		$model_cost_per_1m = isset( $pricing[ $model ] ) ? $pricing[ $model ] : 0.00001;
		$estimated_cost    = ( $total_tokens / 1000000 ) * $model_cost_per_1m;

		return array(
			'success'        => true,
			'processed'      => $processed,
			'embedded'       => $embedded,
			'skipped'        => $skipped,
			'errors'         => $errors,
			'total_tokens'   => $total_tokens,
			'estimated_cost' => round( $estimated_cost, 5 ),
			'model'          => $model,
			'provider'       => 'gemini',
			'batch_mode'     => true,
			'summary'        => sprintf(
				/* translators: 1: embedded count, 2: processed count */
				__( 'Successfully embedded %1$d of %2$d processed posts using Gemini batch API.', 'wp-mcp-ai' ),
				$embedded,
				$processed
			),
		);
	}

	/**
	 * Process posts with OpenAI individual embedding API (legacy).
	 *
	 * @param WP_Query $posts_query Query object with posts to process.
	 * @param int      $user_id     User ID for permission checks.
	 * @param string   $model       Embedding model to use.
	 * @param bool     $store_in_meta Whether to store embeddings in post meta.
	 * @return array Processing results.
	 */
	protected function process_with_openai( $posts_query, $user_id, $model, $store_in_meta ) {
		$client = new WP_MCP_AI_OpenAI_Client();

		$processed      = 0;
		$embedded       = 0;
		$skipped        = 0;
		$errors         = 0;
		$total_tokens   = 0;
		$estimated_cost = 0.0;

		// Model pricing (per 1M tokens).
		$pricing = array(
			'text-embedding-3-small' => 0.02,
			'text-embedding-3-large' => 0.13,
			'text-embedding-ada-002' => 0.10,
		);

		$model_cost_per_1m = isset( $pricing[ $model ] ) ? $pricing[ $model ] : 0.02;

		while ( $posts_query->have_posts() ) {
			$posts_query->the_post();
			$post_id = get_the_ID();
			$processed++;

			// Check if user can edit this post.
			if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
				$skipped++;
				continue;
			}

			// Prepare content for embedding.
			$title   = get_the_title();
			$content = get_the_content();

			// Combine title and content for embedding.
			$text_to_embed = $title . "\n\n" . wp_strip_all_tags( $content );

			// Limit text length (approximate token limit).
			$text_to_embed = mb_substr( $text_to_embed, 0, self::MAX_TEXT_LENGTH );

			if ( empty( $text_to_embed ) ) {
				$skipped++;
				continue;
			}

			// Generate embedding.
			$embedding_result = $client->create_embeddings(
				$text_to_embed,
				array(
					'model' => $model,
				)
			);

			if ( is_wp_error( $embedding_result ) ) {
				$errors++;
				continue;
			}

			// Store embedding in post meta.
			if ( $store_in_meta ) {
				$embeddings_data = array(
					'embeddings' => isset( $embedding_result['data'] ) ? $embedding_result['data'] : array(),
					'model'      => isset( $embedding_result['model'] ) ? $embedding_result['model'] : $model,
					'provider'   => 'openai',
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
					'text_hash'  => md5( $text_to_embed ),
				);

				update_post_meta( $post_id, '_wp_mcp_ai_embeddings', $embeddings_data );
			}

			// Track token usage.
			if ( isset( $embedding_result['usage']['total_tokens'] ) ) {
				$total_tokens += $embedding_result['usage']['total_tokens'];
			}

			$embedded++;
		}

		wp_reset_postdata();

		// Calculate estimated cost.
		$estimated_cost = ( $total_tokens / 1000000 ) * $model_cost_per_1m;

		return array(
			'success'        => true,
			'processed'      => $processed,
			'embedded'       => $embedded,
			'skipped'        => $skipped,
			'errors'         => $errors,
			'total_tokens'   => $total_tokens,
			'estimated_cost' => round( $estimated_cost, 5 ),
			'model'          => $model,
			'provider'       => 'openai',
			'batch_mode'     => false,
			'summary'        => sprintf(
				/* translators: 1: embedded count, 2: processed count */
				__( 'Successfully embedded %1$d of %2$d processed posts using OpenAI.', 'wp-mcp-ai' ),
				$embedded,
				$processed
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api',         // Makes external API calls to OpenAI or Gemini.
			'requires-capability',  // Requires 'edit_posts' capability.
			'modifies-state',       // Modifies post metadata.
			'batch-operation',      // Processes multiple items.
		);
	}
}
