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
				'model'           => array(
					'type'        => 'string',
					'enum'        => array( 'text-embedding-3-small', 'text-embedding-3-large', 'text-embedding-ada-002' ),
					'description' => __( 'Embedding model to use.', 'wp-mcp-ai' ),
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
		$model           = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : 'text-embedding-3-small';
		$store_in_meta   = isset( $arguments['store_in_meta'] ) ? (bool) $arguments['store_in_meta'] : true;
		$update_existing = isset( $arguments['update_existing'] ) ? (bool) $arguments['update_existing'] : false;

		// Ensure limit is within bounds.
		$limit = max( 1, min( 100, $limit ) );

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

		$processed       = 0;
		$embedded        = 0;
		$skipped         = 0;
		$errors          = 0;
		$total_tokens    = 0;
		$estimated_cost  = 0.0;
		$client          = new WP_MCP_AI_OpenAI_Client();

		// Model pricing (per 1M tokens).
		$pricing = array(
			'text-embedding-3-small'  => 0.02,
			'text-embedding-3-large'  => 0.13,
			'text-embedding-ada-002'  => 0.10,
		);

		$model_cost_per_1m = isset( $pricing[ $model ] ) ? $pricing[ $model ] : 0.02;

		if ( $posts_query->have_posts() ) {
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
				$excerpt = get_the_excerpt();

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
		}

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
			'summary'        => sprintf(
				/* translators: 1: embedded count, 2: processed count */
				__( 'Successfully embedded %1$d of %2$d processed posts.', 'wp-mcp-ai' ),
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
			'external-api',         // Makes external API calls to OpenAI.
			'requires-capability',  // Requires 'edit_posts' capability.
			'modifies-state',       // Modifies post metadata.
			'batch-operation',      // Processes multiple items.
		);
	}
}
