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
			return array( 'nodes' => array(), 'edges' => array() );
		}

		if ( $async ) {
			self::schedule_async( $posts );
			return array( 'nodes' => array(), 'edges' => array() );
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

			if ( $cached !== false ) {
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
					'node_id'  => $topic_node_id,
					'label'    => $topic,
					'type'     => 'topic',
					'post_id'  => 0,
					'url'      => '',
				);
				$edges[] = array(
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
				$edges[] = array(
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
					array( 'role' => 'user', 'content' => $prompt ),
				),
				array(
					'max_tokens'  => 512,
					'temperature' => 0,
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
			: get_option( 'wp_mcp_ai_openai_api_key', '' );

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
				array( 'role' => 'user', 'content' => $prompt ),
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
		$cached = get_transient( self::CACHE_PREFIX . absint( $post_id ) );
		if ( false === $cached ) {
			return false;
		}
		if ( ! isset( $cached['hash'] ) || $cached['hash'] !== $content_hash ) {
			return false;
		}
		return $cached['data'];
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
		set_transient(
			self::CACHE_PREFIX . absint( $post_id ),
			array(
				'hash' => $content_hash,
				'data' => $data,
			),
			WEEK_IN_SECONDS
		);
	}
}
