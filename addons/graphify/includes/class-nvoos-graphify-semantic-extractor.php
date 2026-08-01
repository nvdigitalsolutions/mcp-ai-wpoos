<?php
/**
 * NV oOS Graphify — Semantic Extractor
 *
 * Uses the oOS AI provider (or a direct OpenAI API fallback) to extract
 * named entities and topics from post content.
 *
 * Edges produced (provenance = INFERRED):
 *   discusses_topic   — post → topic node
 *   mentions_entity   — post → entity node (person/place/org/product/concept/event)
 *   similar_to        — post → post (pairwise semantic similarity, confidence < 1.0)
 *
 * Each call is gated by a SHA256 content hash stored in the node row:
 * if the content hasn't changed since last build, extraction is skipped.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI-powered semantic relationship extraction.
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_Semantic_Extractor {

	/**
	 * Max number of posts to send to the AI in a single batch.
	 *
	 * @var int
	 */
	const BATCH_SIZE = 5;

	/**
	 * Transient key prefix for per-post extraction results.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'nvoos_graphify_sem_';

	/**
	 * Transient key prefix for per-CCT-item extraction results.
	 *
	 * Kept distinct from {@see CACHE_PREFIX} so that a `post_id` and a
	 * `cct_item_id` with the same numeric value never collide.
	 *
	 * @var string
	 */
	const CACHE_PREFIX_CCT = 'nvoos_graphify_semc_';

	/**
	 * Cron action that processes a batch of CCT items.
	 *
	 * @var string
	 */
	const CRON_ACTION_CCT = 'nvoos_graphify_cron_semantic_extract_ccts';

	/**
	 * Run semantic extraction for a set of posts.
	 *
	 * When $async is true, posts are dispatched to WP Cron instead of
	 * being processed inline; the method returns immediately with an
	 * empty nodes/edges array.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_Post[] $posts  Posts to analyse.
	 * @param bool      $async  Process via WP Cron (default: false).
	 * @return array {
	 *     @type array $nodes Extra entity/topic nodes to upsert.
	 *     @type array $edges Inferred edges to upsert.
	 * }
	 */
	public static function extract( array $posts, $async = false ) {
		if ( empty( $posts ) ) {
			return array(
				'nodes' => array(),
				'edges' => array(),
			);
		}

		if ( $async ) {
			self::schedule_async( $posts );
			return array(
				'nodes' => array(),
				'edges' => array(),
			);
		}

		return self::process_posts( $posts );
	}

	// -------------------------------------------------------------------------
	// Async scheduling
	// -------------------------------------------------------------------------

	/**
	 * Schedule semantic extraction via WP Cron.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_Post[] $posts Posts to process.
	 * @return void
	 */
	private static function schedule_async( array $posts ) {
		$post_ids = array_map( 'absint', wp_list_pluck( $posts, 'ID' ) );
		$batches  = array_chunk( $post_ids, self::BATCH_SIZE );
		$delay    = 0;
		foreach ( $batches as $batch ) {
			wp_schedule_single_event(
				time() + $delay,
				'nvoos_graphify_cron_semantic_extract',
				array( $batch )
			);
			$delay += 10;
		}
	}

	/**
	 * Cron handler: process one batch of post IDs.
	 *
	 * @since 0.5.0
	 *
	 * @param int[] $post_ids Post IDs to process.
	 * @return void
	 */
	public static function handle_cron_batch( array $post_ids ) {
		$posts = array_filter(
			array_map( 'get_post', array_map( 'absint', $post_ids ) )
		);
		if ( empty( $posts ) ) {
			return;
		}

		$result = self::process_posts( array_values( $posts ) );

		// Persist extracted data to the graph.
		if ( ! empty( $result['nodes'] ) ) {
			NV_oOS_Graphify_DB::batch_upsert_nodes( $result['nodes'] );
		}
		if ( ! empty( $result['edges'] ) ) {
			NV_oOS_Graphify_DB::batch_upsert_edges( $result['edges'] );
		}
	}

	// -------------------------------------------------------------------------
	// Core extraction
	// -------------------------------------------------------------------------

	/**
	 * Process a set of posts and return nodes + edges.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_Post[] $posts Posts to process.
	 * @return array
	 */
	private static function process_posts( array $posts ) {
		$nodes = array();
		$edges = array();

		foreach ( $posts as $post ) {
			$content_hash = hash( 'sha256', $post->post_content . $post->post_title );
			$cached       = self::get_cached( $post->ID, $content_hash );

			if ( false !== $cached ) {
				// Merge cached results.
				$nodes = array_merge( $nodes, $cached['nodes'] );
				$edges = array_merge( $edges, $cached['edges'] );
				continue;
			}

			$result = self::extract_single( $post );
			self::set_cached( $post->ID, $content_hash, $result );
			$nodes = array_merge( $nodes, $result['nodes'] );
			$edges = array_merge( $edges, $result['edges'] );
		}

		return compact( 'nodes', 'edges' );
	}

	/**
	 * Extract entities/topics from a single post using the AI provider.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_Post $post Post to process.
	 * @return array { nodes, edges }
	 */
	private static function extract_single( WP_Post $post ) {
		$nodes = array();
		$edges = array();

		$source_node_id = NV_oOS_Graphify_Detector::post_node_id( $post->ID, $post->post_type );

		// Build a compact representation of the post to send to the AI.
		$text = wp_strip_all_tags( $post->post_title . "\n\n" . wp_trim_words( $post->post_content, 400 ) );

		$api_result = self::call_ai_provider( $text );
		if ( is_wp_error( $api_result ) || empty( $api_result ) ) {
			return compact( 'nodes', 'edges' );
		}

		// Process topics.
		if ( ! empty( $api_result['topics'] ) && is_array( $api_result['topics'] ) ) {
			foreach ( $api_result['topics'] as $topic ) {
				$topic = sanitize_text_field( $topic );
				if ( ! $topic ) {
					continue;
				}
				$topic_node_id = NV_oOS_Graphify_Detector::entity_node_id( $topic, 'topic' );
				$nodes[]       = array(
					'node_id' => $topic_node_id,
					'label'   => $topic,
					'type'    => 'topic',
					'post_id' => 0,
					'url'     => '',
				);
				$edges[]       = array(
					'source_node_id' => $source_node_id,
					'target_node_id' => $topic_node_id,
					'relation'       => 'discusses_topic',
					'confidence'     => isset( $api_result['topic_confidence'] ) ? floatval( $api_result['topic_confidence'] ) : 0.85,
					'provenance'     => 'INFERRED',
				);
			}
		}

		// Process named entities.
		if ( ! empty( $api_result['entities'] ) && is_array( $api_result['entities'] ) ) {
			foreach ( $api_result['entities'] as $entity ) {
				$label = sanitize_text_field( isset( $entity['label'] ) ? $entity['label'] : '' );
				$type  = sanitize_text_field( isset( $entity['type'] ) ? $entity['type'] : 'entity' );
				if ( ! $label ) {
					continue;
				}
				$entity_node_id = NV_oOS_Graphify_Detector::entity_node_id( $label, $type );
				$nodes[]        = array(
					'node_id'    => $entity_node_id,
					'label'      => $label,
					'type'       => $type,
					'post_id'    => 0,
					'url'        => '',
					'properties' => array( 'entity_type' => $type ),
				);
				$edges[]        = array(
					'source_node_id' => $source_node_id,
					'target_node_id' => $entity_node_id,
					'relation'       => 'mentions_entity',
					'confidence'     => isset( $entity['confidence'] ) ? floatval( $entity['confidence'] ) : 0.8,
					'provenance'     => 'INFERRED',
				);
			}
		}

		return compact( 'nodes', 'edges' );
	}

	// -------------------------------------------------------------------------
	// CCT extraction
	// -------------------------------------------------------------------------

	/**
	 * Run semantic extraction for a set of JetEngine CCT items.
	 *
	 * Mirrors {@see extract()} but operates on the row shape returned by
	 * {@see NV_oOS_Graphify_Detector::detect_ccts()} (array of
	 * `[ 'type' => slug, 'name' => string, 'item' => array ]`). Each row
	 * is keyed by `cct_{slug}_{id}` so cache entries never collide with
	 * post extraction.
	 *
	 * @since 0.7.1
	 *
	 * @param array[] $cct_rows CCT rows from the detector.
	 * @param bool    $async    Process via WP Cron (default: false).
	 * @return array {
	 *     @type array $nodes Extra entity/topic nodes to upsert.
	 *     @type array $edges Inferred edges to upsert.
	 * }
	 */
	public static function extract_ccts( array $cct_rows, $async = false ) {
		if ( empty( $cct_rows ) ) {
			return array(
				'nodes' => array(),
				'edges' => array(),
			);
		}

		if ( $async ) {
			self::schedule_async_ccts( $cct_rows );
			return array(
				'nodes' => array(),
				'edges' => array(),
			);
		}

		return self::process_ccts( $cct_rows );
	}

	/**
	 * Schedule semantic extraction for CCT rows via WP Cron.
	 *
	 * The cron payload is intentionally a list of compact `[ slug, id ]`
	 * pairs — the worker re-fetches each row through
	 * {@see NV_oOS_Graphify_Detector::get_cct_item()} so we never store
	 * full item snapshots in `wp_options` (cron arg payloads).
	 *
	 * @since 0.7.1
	 *
	 * @param array[] $cct_rows Rows from the detector.
	 * @return void
	 */
	private static function schedule_async_ccts( array $cct_rows ) {
		$identifiers = array();
		foreach ( $cct_rows as $row ) {
			if ( empty( $row['type'] ) || empty( $row['item']['_ID'] ) ) {
				continue;
			}
			$identifiers[] = array(
				'slug' => sanitize_key( $row['type'] ),
				'id'   => absint( $row['item']['_ID'] ),
			);
		}
		if ( empty( $identifiers ) ) {
			return;
		}

		$batches = array_chunk( $identifiers, self::BATCH_SIZE );
		$delay   = 0;
		foreach ( $batches as $batch ) {
			wp_schedule_single_event(
				time() + $delay,
				self::CRON_ACTION_CCT,
				array( $batch )
			);
			$delay += 10;
		}
	}

	/**
	 * Cron handler: process one batch of CCT identifiers.
	 *
	 * @since 0.7.1
	 *
	 * @param array $identifiers List of `[ 'slug' => string, 'id' => int ]` pairs.
	 * @return void
	 */
	public static function handle_cron_batch_ccts( $identifiers ) {
		if ( ! is_array( $identifiers ) || empty( $identifiers ) ) {
			return;
		}

		$rows = array();
		foreach ( $identifiers as $ident ) {
			if ( ! is_array( $ident ) || empty( $ident['slug'] ) || empty( $ident['id'] ) ) {
				continue;
			}
			$row = NV_oOS_Graphify_Detector::get_cct_item( $ident['slug'], $ident['id'] );
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		if ( empty( $rows ) ) {
			return;
		}

		$result = self::process_ccts( $rows );

		if ( ! empty( $result['nodes'] ) ) {
			NV_oOS_Graphify_DB::batch_upsert_nodes( $result['nodes'] );
		}
		if ( ! empty( $result['edges'] ) ) {
			NV_oOS_Graphify_DB::batch_upsert_edges( $result['edges'] );
		}
	}

	/**
	 * Process a set of CCT rows and return nodes + edges.
	 *
	 * @since 0.7.1
	 *
	 * @param array[] $cct_rows Rows from the detector.
	 * @return array
	 */
	private static function process_ccts( array $cct_rows ) {
		$nodes = array();
		$edges = array();

		foreach ( $cct_rows as $row ) {
			if ( empty( $row['type'] ) || empty( $row['item']['_ID'] ) ) {
				continue;
			}
			$slug    = sanitize_key( $row['type'] );
			$item    = $row['item'];
			$item_id = absint( $item['_ID'] );
			if ( '' === $slug || 0 === $item_id ) {
				continue;
			}

			$type_name = isset( $row['name'] ) ? (string) $row['name'] : '';
			$label     = NV_oOS_Graphify_Structural_Extractor::resolve_cct_label( $slug, $item, $type_name );
			$content   = NV_oOS_Graphify_Structural_Extractor::resolve_cct_content( $item );

			// Skip rows that have nothing meaningful to send to the AI —
			// avoids burning tokens on autogenerated `{Type Name} #{ID}`
			// placeholders with no content.
			if ( '' === $content && '' === trim( $label ) ) {
				continue;
			}

			$content_hash = hash( 'sha256', $label . '|' . $content );
			$cache_key    = self::CACHE_PREFIX_CCT . $slug . '_' . $item_id;
			$cached       = self::get_cached_keyed( $cache_key, $content_hash );

			if ( false !== $cached ) {
				$nodes = array_merge( $nodes, $cached['nodes'] );
				$edges = array_merge( $edges, $cached['edges'] );
				continue;
			}

			$result = self::extract_single_cct( $slug, $item_id, $label, $content );
			self::set_cached_keyed( $cache_key, $content_hash, $result );
			$nodes = array_merge( $nodes, $result['nodes'] );
			$edges = array_merge( $edges, $result['edges'] );
		}

		return compact( 'nodes', 'edges' );
	}

	/**
	 * Extract entities/topics from a single CCT item using the AI provider.
	 *
	 * @since 0.7.1
	 *
	 * @param string $slug    CCT slug.
	 * @param int    $item_id CCT item ID.
	 * @param string $label   Resolved label (used as the title equivalent).
	 * @param string $content Resolved content text.
	 * @return array { nodes, edges }
	 */
	private static function extract_single_cct( $slug, $item_id, $label, $content ) {
		$nodes = array();
		$edges = array();

		$source_node_id = NV_oOS_Graphify_Detector::cct_node_id( $slug, $item_id );

		// Compose a compact representation: `{label}\n\n{content}` is the
		// CCT analogue of `{post_title}\n\n{post_content}` and lets the
		// downstream prompt stay identical between code paths.
		$text = wp_strip_all_tags( trim( $label ) . "\n\n" . wp_trim_words( (string) $content, 400 ) );
		$text = trim( $text );
		if ( '' === $text ) {
			return compact( 'nodes', 'edges' );
		}

		$api_result = self::call_ai_provider( $text );
		if ( is_wp_error( $api_result ) || empty( $api_result ) ) {
			return compact( 'nodes', 'edges' );
		}

		// Process topics.
		if ( ! empty( $api_result['topics'] ) && is_array( $api_result['topics'] ) ) {
			foreach ( $api_result['topics'] as $topic ) {
				$topic = sanitize_text_field( $topic );
				if ( ! $topic ) {
					continue;
				}
				$topic_node_id = NV_oOS_Graphify_Detector::entity_node_id( $topic, 'topic' );
				$nodes[]       = array(
					'node_id' => $topic_node_id,
					'label'   => $topic,
					'type'    => 'topic',
					'post_id' => 0,
					'url'     => '',
				);
				$edges[]       = array(
					'source_node_id' => $source_node_id,
					'target_node_id' => $topic_node_id,
					'relation'       => 'discusses_topic',
					'confidence'     => isset( $api_result['topic_confidence'] ) ? floatval( $api_result['topic_confidence'] ) : 0.85,
					'provenance'     => 'INFERRED',
				);
			}
		}

		// Process named entities.
		if ( ! empty( $api_result['entities'] ) && is_array( $api_result['entities'] ) ) {
			foreach ( $api_result['entities'] as $entity ) {
				$ent_label = sanitize_text_field( isset( $entity['label'] ) ? $entity['label'] : '' );
				$ent_type  = sanitize_text_field( isset( $entity['type'] ) ? $entity['type'] : 'entity' );
				if ( ! $ent_label ) {
					continue;
				}
				$entity_node_id = NV_oOS_Graphify_Detector::entity_node_id( $ent_label, $ent_type );
				$nodes[]        = array(
					'node_id'    => $entity_node_id,
					'label'      => $ent_label,
					'type'       => $ent_type,
					'post_id'    => 0,
					'url'        => '',
					'properties' => array( 'entity_type' => $ent_type ),
				);
				$edges[]        = array(
					'source_node_id' => $source_node_id,
					'target_node_id' => $entity_node_id,
					'relation'       => 'mentions_entity',
					'confidence'     => isset( $entity['confidence'] ) ? floatval( $entity['confidence'] ) : 0.8,
					'provenance'     => 'INFERRED',
				);
			}
		}

		return compact( 'nodes', 'edges' );
	}

	// -------------------------------------------------------------------------
	// AI provider call
	// -------------------------------------------------------------------------

	/**
	 * Call the AI provider for entity/topic extraction.
	 *
	 * Prefers the oOS AI provider; falls back to direct OpenAI REST if the
	 * API key is configured in Graphify settings.
	 *
	 * @since 0.5.0
	 *
	 * @param string $text Content to analyse.
	 * @return array|WP_Error Decoded API response array or WP_Error on failure.
	 */
	private static function call_ai_provider( $text ) {
		$settings = NV_oOS_Graphify::get_settings();

		$prompt = "You are a knowledge graph extraction engine. Analyse the following text and return a JSON object with exactly two keys:\n"
			. "- \"topics\": array of 1–5 short topic strings (3 words max each)\n"
			. "- \"entities\": array of objects, each with keys \"label\" (string) and \"type\" (one of: person, place, organization, product, concept, event)\n\n"
			. "Return ONLY valid JSON. No markdown, no explanation.\n\nTEXT:\n" . $text;

		// Try oOS AI provider first.
		if ( function_exists( 'wp_mcp_ai_chat_completion' ) ) {
			$response = wp_mcp_ai_chat_completion(
				array(
					array(
						'role'    => 'user',
						'content' => $prompt,
					),
				),
				array(
					'max_tokens'      => 512,
					'temperature'     => 0,
					'response_format' => array( 'type' => 'json_object' ),
				)
			);

			if ( ! is_wp_error( $response ) && isset( $response['content'] ) ) {
				$decoded = json_decode( $response['content'], true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		// Direct OpenAI fallback.
		$api_key = ! empty( $settings['openai_api_key'] )
			? sanitize_text_field( $settings['openai_api_key'] )
			: wp_mcp_ai_get_api_key( 'openai_api_key', '' );

		if ( $api_key ) {
			return self::call_openai_direct( $prompt, $api_key );
		}

		return new WP_Error( 'nvoos_graphify_no_provider', __( 'No AI provider available for semantic extraction.', 'nvoos-graphify' ) );
	}

	/**
	 * Direct OpenAI chat completion call.
	 *
	 * @since 0.5.0
	 *
	 * @param string $prompt  User prompt.
	 * @param string $api_key OpenAI API key.
	 * @return array|WP_Error
	 */
	private static function call_openai_direct( $prompt, $api_key ) {
		$body = array(
			'model'           => 'gpt-4o-mini',
			'messages'        => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'max_tokens'      => 512,
			'temperature'     => 0,
			'response_format' => array( 'type' => 'json_object' ),
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $data['choices'][0]['message']['content'] ) ) {
			$decoded = json_decode( $data['choices'][0]['message']['content'], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return new WP_Error( 'nvoos_graphify_openai_error', __( 'OpenAI returned an unexpected response.', 'nvoos-graphify' ) );
	}

	// -------------------------------------------------------------------------
	// Per-post cache
	// -------------------------------------------------------------------------

	/**
	 * Return cached extraction result if the content hash still matches.
	 *
	 * @since 0.5.0
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $content_hash Current content hash.
	 * @return array|false Cached data or false on miss.
	 */
	private static function get_cached( $post_id, $content_hash ) {
		return self::get_cached_keyed( self::CACHE_PREFIX . absint( $post_id ), $content_hash );
	}

	/**
	 * Cache extraction result for a post.
	 *
	 * @since 0.5.0
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $content_hash Content hash at time of extraction.
	 * @param array  $data         Extraction result.
	 * @return void
	 */
	private static function set_cached( $post_id, $content_hash, array $data ) {
		self::set_cached_keyed( self::CACHE_PREFIX . absint( $post_id ), $content_hash, $data );
	}

	/**
	 * Generic transient-backed cache reader keyed by an arbitrary string.
	 *
	 * Used by both the post and CCT extraction paths so they can share
	 * the same hash-gating semantics without colliding on numeric IDs.
	 *
	 * @since 0.7.1
	 *
	 * @param string $cache_key    Full transient key.
	 * @param string $content_hash Current content hash.
	 * @return array|false
	 */
	private static function get_cached_keyed( $cache_key, $content_hash ) {
		$cached = get_transient( $cache_key );
		if ( false === $cached ) {
			return false;
		}
		if ( ! isset( $cached['hash'] ) || $cached['hash'] !== $content_hash ) {
			return false;
		}
		return $cached['data'];
	}

	/**
	 * Generic transient-backed cache writer keyed by an arbitrary string.
	 *
	 * @since 0.7.1
	 *
	 * @param string $cache_key    Full transient key.
	 * @param string $content_hash Content hash at time of extraction.
	 * @param array  $data         Extraction result.
	 * @return void
	 */
	private static function set_cached_keyed( $cache_key, $content_hash, array $data ) {
		set_transient(
			$cache_key,
			array(
				'hash' => $content_hash,
				'data' => $data,
			),
			WEEK_IN_SECONDS
		);
	}
}
