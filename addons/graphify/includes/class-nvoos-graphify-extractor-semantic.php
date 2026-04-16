<?php
/**
 * NV oOS Graphify Addon — Semantic Extractor
 *
 * AI-powered extraction engine that uses the NV oOS AI provider to
 * discover named entities, topics, and semantic similarity between
 * WordPress posts, producing INFERRED graph nodes and edges.
 *
 * @package NV_oOS_Graphify
 * @since   0.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Semantic extractor for the NV oOS Graphify Addon.
 *
 * Sends post content to an AI provider to extract named entities,
 * key topics, and semantic similarity relationships. All produced
 * nodes and edges are tagged with INFERRED confidence.
 *
 * AI provider resolution order:
 * 1. `wp_mcp_ai_generate_text` filter (NV oOS base plugin).
 * 2. Direct OpenAI API call using the configured API key.
 *
 * @since 0.3.0
 */
class NV_oOS_Graphify_Extractor_Semantic {

	/**
	 * WP Cron hook for background semantic extraction.
	 *
	 * @since 0.3.0
	 *
	 * @var string
	 */
	const CRON_HOOK = 'nvoos_graphify_semantic_extraction';

	/**
	 * Maximum number of words sent to the AI provider per request.
	 *
	 * @since 0.3.0
	 *
	 * @var int
	 */
	const MAX_CONTENT_WORDS = 2000;

	/**
	 * Default number of posts processed per AI batch.
	 *
	 * @since 0.3.0
	 *
	 * @var int
	 */
	const DEFAULT_BATCH_SIZE = 5;

	/**
	 * Maximum pairwise comparisons per similarity batch.
	 *
	 * @since 0.3.0
	 *
	 * @var int
	 */
	const MAX_SIMILARITY_PAIRS = 100;

	/**
	 * Post meta key used to cache extraction hashes.
	 *
	 * @since 0.3.0
	 *
	 * @var string
	 */
	const CACHE_META_KEY = '_nvoos_graphify_extraction_hash';

	/**
	 * Graph ID for all produced nodes and edges.
	 *
	 * @since 0.3.0
	 *
	 * @var int
	 */
	private $graph_id;

	/**
	 * Number of posts to process per AI batch.
	 *
	 * @since 0.3.0
	 *
	 * @var int
	 */
	private $batch_size;

	/**
	 * Constructor.
	 *
	 * @since 0.3.0
	 *
	 * @param int $graph_id   Graph ID to assign to extracted data.
	 * @param int $batch_size Optional. Posts per AI batch. Default 5.
	 */
	public function __construct( $graph_id, $batch_size = 0 ) {
		$this->graph_id   = (int) $graph_id;
		$this->batch_size = $batch_size > 0 ? (int) $batch_size : self::DEFAULT_BATCH_SIZE;
	}

	/**
	 * Run all semantic extraction on detected content.
	 *
	 * Processes posts in batches to respect AI token budgets. Each
	 * post is checked against a content hash cache before calling
	 * the AI provider.
	 *
	 * @since 0.3.0
	 *
	 * @param array $detected_content Output from NV_oOS_Graphify_Detector::detect().
	 * @return array {
	 *     Extracted graph data.
	 *
	 *     @type array $nodes Array of node arrays.
	 *     @type array $edges Array of edge arrays.
	 * }
	 */
	public function extract( $detected_content ) {
		$nodes = array();
		$edges = array();

		$posts = isset( $detected_content['posts'] ) ? $detected_content['posts'] : array();

		if ( empty( $posts ) ) {
			return array(
				'nodes' => $nodes,
				'edges' => $edges,
			);
		}

		// Process posts in batches to respect token budgets.
		$batches = array_chunk( $posts, $this->batch_size );

		foreach ( $batches as $batch ) {
			foreach ( $batch as $post ) {
				$content = isset( $post['post_content'] ) ? $post['post_content'] : '';
				$hash    = $this->get_content_hash( $post['post_title'] . $content );

				if ( $this->is_cached( (int) $post['ID'], $hash ) ) {
					continue;
				}

				// Extract entities.
				$entity_result = $this->extract_entities( $post );
				if ( ! empty( $entity_result['nodes'] ) ) {
					$nodes = array_merge( $nodes, $entity_result['nodes'] );
				}
				if ( ! empty( $entity_result['edges'] ) ) {
					$edges = array_merge( $edges, $entity_result['edges'] );
				}

				// Extract topics.
				$topic_result = $this->extract_topics( $post );
				if ( ! empty( $topic_result['nodes'] ) ) {
					$nodes = array_merge( $nodes, $topic_result['nodes'] );
				}
				if ( ! empty( $topic_result['edges'] ) ) {
					$edges = array_merge( $edges, $topic_result['edges'] );
				}

				// Cache the extraction result.
				$this->cache_result( (int) $post['ID'], $hash );
			}

			// Semantic similarity within each batch.
			$similarity_edges = $this->extract_semantic_similarity( $batch );
			if ( ! empty( $similarity_edges ) ) {
				$edges = array_merge( $edges, $similarity_edges );
			}
		}

		return array(
			'nodes' => $nodes,
			'edges' => $edges,
		);
	}

	/**
	 * Extract named entities from a single post via AI.
	 *
	 * Sends the post title and a truncated content excerpt to the
	 * AI provider, requesting structured entity data. Creates
	 * `entity` type nodes tagged INFERRED with `mentions` edges
	 * back to the source post.
	 *
	 * @since 0.3.0
	 *
	 * @param array $post Post data array from the detector.
	 * @return array {
	 *     @type array $nodes Entity nodes created.
	 *     @type array $edges Mention edges created.
	 * }
	 */
	public function extract_entities( $post ) {
		$nodes = array();
		$edges = array();

		$title   = isset( $post['post_title'] ) ? sanitize_text_field( $post['post_title'] ) : '';
		$content = isset( $post['post_content'] ) ? $post['post_content'] : '';
		$excerpt = $this->truncate_content( $content );

		if ( empty( $excerpt ) && empty( $title ) ) {
			return array(
				'nodes' => $nodes,
				'edges' => $edges,
			);
		}

		$prompt = sprintf(
			"Extract named entities from this WordPress post content. Return a JSON array of objects with 'name', 'type' (person, place, organization, product, concept, event), and 'confidence' (0.0-1.0).\n\nTitle: %s\nContent: %s",
			$title,
			$excerpt
		);

		$response = $this->call_ai( $prompt );

		if ( is_wp_error( $response ) ) {
			return array(
				'nodes' => $nodes,
				'edges' => $edges,
			);
		}

		$entities = $this->parse_json_response( $response );

		if ( empty( $entities ) || ! is_array( $entities ) ) {
			return array(
				'nodes' => $nodes,
				'edges' => $edges,
			);
		}

		$post_node_id = 'post_' . $post['ID'];

		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}

			$name = isset( $entity['name'] ) ? sanitize_text_field( $entity['name'] ) : '';
			$type = isset( $entity['type'] ) ? sanitize_text_field( $entity['type'] ) : 'concept';

			if ( empty( $name ) ) {
				continue;
			}

			$confidence = isset( $entity['confidence'] ) ? (float) $entity['confidence'] : 0.5;
			$confidence = max( 0.0, min( 1.0, $confidence ) );

			$allowed_types = array( 'person', 'place', 'organization', 'product', 'concept', 'event' );
			if ( ! in_array( $type, $allowed_types, true ) ) {
				$type = 'concept';
			}

			// Deterministic node ID from name + type.
			$entity_node_id = 'entity_' . substr( hash( 'sha256', strtolower( $name ) . '_' . $type ), 0, 12 );

			$nodes[] = array(
				'node_id'     => $entity_node_id,
				'label'       => $name,
				'node_type'   => 'entity',
				'source_type' => 'extracted_entity',
				'source_id'   => 0,
				'source_url'  => '',
				'metadata'    => wp_json_encode(
					array(
						'entity_type' => $type,
						'confidence'  => $confidence,
						'origin'      => 'semantic_extraction',
					)
				),
			);

			$edges[] = array(
				'source_node_id'   => $post_node_id,
				'target_node_id'   => $entity_node_id,
				'relation'         => 'mentions',
				'confidence'       => 'INFERRED',
				'confidence_score' => $confidence,
				'metadata'         => wp_json_encode(
					array( 'entity_type' => $type )
				),
			);
		}

		return array(
			'nodes' => $nodes,
			'edges' => $edges,
		);
	}

	/**
	 * Extract key topics from a single post via AI.
	 *
	 * Identifies 3–5 main topics and creates `concept` nodes with
	 * `discusses_topic` edges tagged INFERRED.
	 *
	 * @since 0.3.0
	 *
	 * @param array $post Post data array from the detector.
	 * @return array {
	 *     @type array $nodes Topic concept nodes created.
	 *     @type array $edges discusses_topic edges created.
	 * }
	 */
	public function extract_topics( $post ) {
		$nodes = array();
		$edges = array();

		$title   = isset( $post['post_title'] ) ? sanitize_text_field( $post['post_title'] ) : '';
		$content = isset( $post['post_content'] ) ? $post['post_content'] : '';
		$excerpt = $this->truncate_content( $content );

		if ( empty( $excerpt ) && empty( $title ) ) {
			return array(
				'nodes' => $nodes,
				'edges' => $edges,
			);
		}

		$prompt = sprintf(
			"Identify the 3-5 main topics discussed in this content. Return a JSON array of topic strings.\n\nTitle: %s\nContent: %s",
			$title,
			$excerpt
		);

		$response = $this->call_ai( $prompt );

		if ( is_wp_error( $response ) ) {
			return array(
				'nodes' => $nodes,
				'edges' => $edges,
			);
		}

		$topics = $this->parse_json_response( $response );

		if ( empty( $topics ) || ! is_array( $topics ) ) {
			return array(
				'nodes' => $nodes,
				'edges' => $edges,
			);
		}

		$post_node_id = 'post_' . $post['ID'];

		foreach ( $topics as $topic ) {
			if ( ! is_string( $topic ) || empty( trim( $topic ) ) ) {
				continue;
			}

			$topic_name = sanitize_text_field( trim( $topic ) );

			// Deterministic node ID from topic name.
			$topic_node_id = 'concept_' . substr( hash( 'sha256', strtolower( $topic_name ) ), 0, 12 );

			$nodes[] = array(
				'node_id'     => $topic_node_id,
				'label'       => $topic_name,
				'node_type'   => 'concept',
				'source_type' => 'extracted_entity',
				'source_id'   => 0,
				'source_url'  => '',
				'metadata'    => wp_json_encode(
					array(
						'entity_type' => 'topic',
						'origin'      => 'semantic_extraction',
					)
				),
			);

			$edges[] = array(
				'source_node_id'   => $post_node_id,
				'target_node_id'   => $topic_node_id,
				'relation'         => 'discusses_topic',
				'confidence'       => 'INFERRED',
				'confidence_score' => 0.8,
				'metadata'         => wp_json_encode( array() ),
			);
		}

		return array(
			'nodes' => $nodes,
			'edges' => $edges,
		);
	}

	/**
	 * Extract semantic similarity edges between posts.
	 *
	 * Compares post summaries pairwise via AI and creates
	 * `semantically_similar_to` edges tagged INFERRED for pairs
	 * that the AI considers related.
	 *
	 * @since 0.3.0
	 *
	 * @param array $posts Array of post data arrays from the detector.
	 * @return array Array of edge arrays.
	 */
	public function extract_semantic_similarity( $posts ) {
		$edges = array();

		$count = count( $posts );
		if ( $count < 2 ) {
			return $edges;
		}

		// Build summaries for comparison.
		$summaries = array();
		foreach ( $posts as $post ) {
			$title   = isset( $post['post_title'] ) ? sanitize_text_field( $post['post_title'] ) : '';
			$content = isset( $post['post_content'] ) ? $post['post_content'] : '';
			$excerpt = $this->truncate_content( $content, 200 );

			$summaries[] = array(
				'id'      => $post['ID'],
				'title'   => $title,
				'excerpt' => $excerpt,
			);
		}

		// Build pairs, respecting the limit.
		$pairs      = array();
		$pair_count = 0;

		for ( $i = 0; $i < $count && $pair_count < self::MAX_SIMILARITY_PAIRS; $i++ ) {
			for ( $j = $i + 1; $j < $count && $pair_count < self::MAX_SIMILARITY_PAIRS; $j++ ) {
				$pairs[] = array( $i, $j );
				++$pair_count;
			}
		}

		if ( empty( $pairs ) ) {
			return $edges;
		}

		// Build the comparison prompt.
		$items_text = '';
		foreach ( $summaries as $index => $summary ) {
			$items_text .= sprintf(
				"[%d] %s: %s\n",
				$index,
				$summary['title'],
				$summary['excerpt']
			);
		}

		$pairs_text = '';
		foreach ( $pairs as $pair ) {
			$pairs_text .= sprintf( "(%d, %d)\n", $pair[0], $pair[1] );
		}

		$prompt = sprintf(
			"Compare these content summaries pairwise and rate their semantic similarity. For each pair, return a JSON array of objects with 'pair' (array of two indices) and 'score' (0.0-1.0, where 1.0 means identical topics). Only include pairs with score >= 0.3.\n\nContent items:\n%s\nPairs to compare:\n%s",
			$items_text,
			$pairs_text
		);

		$response = $this->call_ai( $prompt );

		if ( is_wp_error( $response ) ) {
			return $edges;
		}

		$results = $this->parse_json_response( $response );

		if ( empty( $results ) || ! is_array( $results ) ) {
			return $edges;
		}

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}

			$pair_indices = isset( $result['pair'] ) ? $result['pair'] : array();
			$score        = isset( $result['score'] ) ? (float) $result['score'] : 0.0;

			if ( ! is_array( $pair_indices ) || count( $pair_indices ) < 2 ) {
				continue;
			}

			$idx_a = (int) $pair_indices[0];
			$idx_b = (int) $pair_indices[1];

			if ( ! isset( $summaries[ $idx_a ] ) || ! isset( $summaries[ $idx_b ] ) ) {
				continue;
			}

			$score = max( 0.0, min( 1.0, $score ) );

			if ( $score < 0.3 ) {
				continue;
			}

			$edges[] = array(
				'source_node_id'   => 'post_' . $summaries[ $idx_a ]['id'],
				'target_node_id'   => 'post_' . $summaries[ $idx_b ]['id'],
				'relation'         => 'semantically_similar_to',
				'confidence'       => 'INFERRED',
				'confidence_score' => $score,
				'metadata'         => wp_json_encode( array() ),
			);
		}

		return $edges;
	}

	/**
	 * Call the AI provider to generate a text response.
	 *
	 * Resolution order:
	 * 1. NV oOS `wp_mcp_ai_generate_text` filter (base plugin).
	 * 2. Direct OpenAI Chat Completions API using the stored key.
	 *
	 * @since 0.3.0
	 *
	 * @param string $prompt The prompt to send to the AI.
	 * @return string|WP_Error AI response text or error.
	 */
	public function call_ai( $prompt ) {
		/**
		 * Filters AI text generation for semantic extraction.
		 *
		 * The NV oOS base plugin hooks into this filter to route
		 * the prompt through the configured AI provider.
		 *
		 * @since 0.3.0
		 *
		 * @param string $result  Default empty string.
		 * @param string $prompt  The extraction prompt.
		 * @param array  $options Provider options (empty for defaults).
		 */
		$result = apply_filters( 'wp_mcp_ai_generate_text', '', $prompt, array() );

		if ( ! empty( $result ) && ! is_wp_error( $result ) ) {
			return $result;
		}

		// Fallback: direct OpenAI API call.
		return $this->call_openai_direct( $prompt );
	}

	/**
	 * Direct OpenAI API fallback.
	 *
	 * Uses the API key stored in the NV oOS settings to call the
	 * OpenAI Chat Completions endpoint.
	 *
	 * @since 0.3.0
	 *
	 * @param string $prompt The prompt to send.
	 * @return string|WP_Error Response text or error.
	 */
	private function call_openai_direct( $prompt ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$api_key  = isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'nvoos_graphify_no_ai',
				__( 'No AI provider available. Configure the NV oOS plugin or provide an OpenAI API key.', 'mcp-ai-wpoos' )
			);
		}

		$body = wp_json_encode(
			array(
				'model'       => 'gpt-4o-mini',
				'messages'    => array(
					array(
						'role'    => 'system',
						'content' => 'You are a structured data extraction assistant. Always respond with valid JSON only, no markdown formatting or extra text.',
					),
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				'temperature' => 0.2,
				'max_tokens'  => 2048,
			)
		);

		if ( false === $body ) {
			return new WP_Error(
				'nvoos_graphify_json_encode',
				__( 'Failed to encode the AI request payload.', 'mcp-ai-wpoos' )
			);
		}

		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'nvoos_graphify_openai_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'OpenAI API returned HTTP %d.', 'mcp-ai-wpoos' ),
					$status
				)
			);
		}

		$body_raw = wp_remote_retrieve_body( $response );
		$data     = json_decode( $body_raw, true );

		if ( empty( $data ) || ! isset( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'nvoos_graphify_openai_empty',
				__( 'OpenAI API returned an empty or malformed response.', 'mcp-ai-wpoos' )
			);
		}

		return $data['choices'][0]['message']['content'];
	}

	/**
	 * Parse a JSON response from the AI provider.
	 *
	 * Handles common AI response quirks: markdown code fences,
	 * leading/trailing whitespace, and partial JSON.
	 *
	 * @since 0.3.0
	 *
	 * @param string $response Raw AI response text.
	 * @return array|null Decoded array or null on failure.
	 */
	private function parse_json_response( $response ) {
		if ( empty( $response ) || ! is_string( $response ) ) {
			return null;
		}

		$text = trim( $response );

		// Strip markdown code fences.
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/i', '', $text );
		$text = trim( $text );

		$decoded = json_decode( $text, true );

		if ( null !== $decoded ) {
			return $decoded;
		}

		// Attempt to find a JSON array or object within the response.
		$start_bracket = strpos( $text, '[' );
		$start_brace   = strpos( $text, '{' );

		if ( false === $start_bracket && false === $start_brace ) {
			return null;
		}

		// Pick whichever comes first.
		if ( false === $start_bracket ) {
			$start = $start_brace;
		} elseif ( false === $start_brace ) {
			$start = $start_bracket;
		} else {
			$start = min( $start_bracket, $start_brace );
		}

		$substring = substr( $text, $start );
		$decoded   = json_decode( $substring, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Generate a SHA-256 hash for content cache comparison.
	 *
	 * @since 0.3.0
	 *
	 * @param string $content Content to hash.
	 * @return string 64-character hex hash.
	 */
	public function get_content_hash( $content ) {
		return hash( 'sha256', (string) $content );
	}

	/**
	 * Check whether extraction results are cached for a post.
	 *
	 * Compares the stored content hash in post meta against the
	 * current hash to determine if re-extraction is needed.
	 *
	 * @since 0.3.0
	 *
	 * @param int    $post_id WordPress post ID.
	 * @param string $hash    Current content hash.
	 * @return bool True if cached hash matches (extraction can be skipped).
	 */
	public function is_cached( $post_id, $hash ) {
		$stored = get_post_meta( $post_id, self::CACHE_META_KEY, true );

		return ! empty( $stored ) && $stored === $hash;
	}

	/**
	 * Store the extraction hash in post meta.
	 *
	 * @since 0.3.0
	 *
	 * @param int    $post_id WordPress post ID.
	 * @param string $hash    Content hash to store.
	 * @return void
	 */
	public function cache_result( $post_id, $hash ) {
		update_post_meta( $post_id, self::CACHE_META_KEY, sanitize_text_field( $hash ) );
	}

	/**
	 * Schedule background semantic extraction via WP Cron.
	 *
	 * Stores the detected content as a transient and schedules a
	 * single cron event to process it asynchronously.
	 *
	 * @since 0.3.0
	 *
	 * @param array $detected_content Output from NV_oOS_Graphify_Detector::detect().
	 * @return bool True if the event was scheduled, false otherwise.
	 */
	public function schedule_background_extraction( $detected_content ) {
		$transient_key = 'nvoos_graphify_semantic_queue_' . $this->graph_id;

		set_transient( $transient_key, $detected_content, HOUR_IN_SECONDS );

		if ( wp_next_scheduled( self::CRON_HOOK, array( $this->graph_id ) ) ) {
			return true;
		}

		return false !== wp_schedule_single_event(
			time() + 10,
			self::CRON_HOOK,
			array( $this->graph_id )
		);
	}

	/**
	 * Static callback for the WP Cron background extraction event.
	 *
	 * Retrieves queued content from the transient, runs extraction,
	 * and passes results to the builder for persistence.
	 *
	 * @since 0.3.0
	 *
	 * @param int $graph_id Graph ID to process.
	 * @return void
	 */
	public static function process_background_extraction( $graph_id ) {
		$graph_id      = (int) $graph_id;
		$transient_key = 'nvoos_graphify_semantic_queue_' . $graph_id;
		$detected      = get_transient( $transient_key );

		if ( empty( $detected ) || ! is_array( $detected ) ) {
			return;
		}

		delete_transient( $transient_key );

		$extractor = new self( $graph_id );
		$results   = $extractor->extract( $detected );

		if ( empty( $results['nodes'] ) && empty( $results['edges'] ) ) {
			return;
		}

		// Persist via the builder if available.
		if ( class_exists( 'NV_oOS_Graphify_Builder' ) ) {
			$builder = new NV_oOS_Graphify_Builder( $graph_id );
			$builder->build( $results, 'incremental' );
		}
	}

	/**
	 * Truncate content to a maximum number of words.
	 *
	 * Strips HTML tags before counting to ensure clean text is
	 * sent to the AI provider.
	 *
	 * @since 0.3.0
	 *
	 * @param string $content   Raw HTML content.
	 * @param int    $max_words Optional. Maximum words. Default MAX_CONTENT_WORDS.
	 * @return string Plain-text excerpt.
	 */
	private function truncate_content( $content, $max_words = 0 ) {
		if ( $max_words <= 0 ) {
			$max_words = self::MAX_CONTENT_WORDS;
		}

		$text  = wp_strip_all_tags( (string) $content );
		$words = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( empty( $words ) ) {
			return '';
		}

		if ( count( $words ) <= $max_words ) {
			return implode( ' ', $words );
		}

		return implode( ' ', array_slice( $words, 0, $max_words ) );
	}
}
