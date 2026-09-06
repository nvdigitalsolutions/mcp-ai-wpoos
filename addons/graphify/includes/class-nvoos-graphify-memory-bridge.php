<?php
/**
 * NV oOS Graphify — Agent Memory Bridge
 *
 * Subscribes to the `wp_mcp_ai_memory_stored` action emitted by the agent
 * memory system and mirrors each memory into the Graphify knowledge graph
 * as a `memory` node together with associative edges:
 *
 *   - MEMBER_OF      → wing-node    (one per wing slug)
 *   - MEMBER_OF      → room-node    (one per (wing, room) pair)
 *   - DERIVED_FROM   → post-node    (when the memory was ingested from a WP post)
 *   - OBSERVED_BY    → agent-node   (one per agent_id)
 *
 * The bridge is **advisory** — failures here must never break the agent
 * memory write. The transient store remains the source of truth in Phase 4a.
 *
 * Embedding generation reuses the existing
 * {@see NV_oOS_Graphify_Embeddings_On_Ingest} cron pipeline so memory
 * vectors land in the same `embeddings` table as content vectors and use
 * the same OpenAI/Ollama provider configuration.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mirrors agent memories into the Graphify graph.
 *
 * @since 0.7.0
 */
class NV_oOS_Graphify_Memory_Bridge {

	/**
	 * Node-id prefix for memory nodes.
	 *
	 * @var string
	 */
	const NODE_PREFIX_MEMORY = 'memory:';

	/**
	 * Node-id prefix for wing-scope nodes.
	 *
	 * @var string
	 */
	const NODE_PREFIX_WING = 'wing:';

	/**
	 * Node-id prefix for room-scope nodes (composite with wing).
	 *
	 * @var string
	 */
	const NODE_PREFIX_ROOM = 'room:';

	/**
	 * Node-id prefix for agent-author nodes.
	 *
	 * @var string
	 */
	const NODE_PREFIX_AGENT = 'agent:';

	/**
	 * Maximum verbatim content length stored on the node (chars).
	 *
	 * Mirrors `WP_MCP_AI_Tool_Store_Agent_Context::MAX_INGESTED_CONTENT_LENGTH`.
	 *
	 * @var int
	 */
	const MAX_CONTENT_LEN = 8000;

	/**
	 * Register the subscriber on the canonical memory-stored action.
	 *
	 * Idempotent: safe to call multiple times.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( 'wp_mcp_ai_memory_stored', array( __CLASS__, 'on_memory_stored' ), 10, 1 );
	}

	/**
	 * Handle a single memory-stored event.
	 *
	 * @param array $payload Event payload — see store_agent_context for the contract.
	 * @return void
	 */
	public static function on_memory_stored( $payload ) {
		if ( ! is_array( $payload ) || empty( $payload['context_id'] ) ) {
			return;
		}

		// Defensive: never let a bug here bubble up into the memory write path.
		try {
			self::project_memory( $payload );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[Graphify] memory bridge failure: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Project a memory event into nodes and edges.
	 *
	 * @param array $payload Sanitized event payload.
	 * @return void
	 */
	private static function project_memory( array $payload ) {
		if ( ! class_exists( 'NV_oOS_Graphify_DB' ) ) {
			return;
		}

		// The addon can be loaded while its schema is not installed yet (for
		// example in CI or before first activation). Skip silently instead of
		// spamming database errors on every memory write.
		if ( ! NV_oOS_Graphify_DB::tables_installed() ) {
			return;
		}

		$context_id  = sanitize_text_field( (string) $payload['context_id'] );
		$agent_id    = isset( $payload['agent_id'] ) ? (string) $payload['agent_id'] : '';
		$wing        = isset( $payload['wing'] ) ? sanitize_text_field( (string) $payload['wing'] ) : '';
		$room        = isset( $payload['room'] ) ? sanitize_text_field( (string) $payload['room'] ) : '';
		$title       = isset( $payload['title'] ) ? (string) $payload['title'] : '';
		$content     = isset( $payload['content'] ) ? (string) $payload['content'] : '';
		$importance  = isset( $payload['importance'] ) ? sanitize_key( (string) $payload['importance'] ) : 'medium';
		$ctx_type    = isset( $payload['context_type'] ) ? sanitize_key( (string) $payload['context_type'] ) : 'memory';
		$verbatim    = ! empty( $payload['verbatim'] );
		$source_post = isset( $payload['source_post_id'] ) ? absint( $payload['source_post_id'] ) : 0;
		$tags        = isset( $payload['tags'] ) && is_array( $payload['tags'] ) ? array_map( 'sanitize_text_field', $payload['tags'] ) : array();
		$stored_at   = isset( $payload['stored_at'] ) ? (string) $payload['stored_at'] : current_time( 'mysql' );
		$expires_at  = isset( $payload['expires_at'] ) ? (string) $payload['expires_at'] : '';

		if ( '' === $context_id || '' === $agent_id ) {
			return;
		}

		// Truncate verbatim content for the node label/properties so we don't
		// blow out node row size.
		$short_content = $content;
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $short_content ) > self::MAX_CONTENT_LEN ) {
			$short_content = mb_substr( $short_content, 0, self::MAX_CONTENT_LEN ) . '…';
		} elseif ( strlen( $short_content ) > self::MAX_CONTENT_LEN ) {
			$short_content = substr( $short_content, 0, self::MAX_CONTENT_LEN ) . '…';
		}

		$memory_node_id = self::NODE_PREFIX_MEMORY . $context_id;
		$agent_node_id  = self::NODE_PREFIX_AGENT . sanitize_title_with_dashes( (string) $agent_id );

		// 1. Memory node.
		NV_oOS_Graphify_DB::upsert_node(
			array(
				'node_id'     => $memory_node_id,
				'label'       => '' !== $title ? $title : sprintf( 'memory %s', $context_id ),
				'type'        => 'memory',
				'post_id'     => 0,
				'url'         => '',
				'properties'  => array(
					'context_id'   => $context_id,
					'agent_id'     => (string) $agent_id,
					'wing'         => $wing,
					'room'         => $room,
					'importance'   => $importance,
					'context_type' => $ctx_type,
					// `memory_type` mirrors `context_type` under the LangMem-style
					// taxonomy (semantic / episodic / procedural / fact / etc.) so
					// downstream graph consumers can filter by either name.
					'memory_type'  => $ctx_type,
					'verbatim'     => $verbatim ? 1 : 0,
					'tags'         => $tags,
					'content'      => $short_content,
					'stored_at'    => $stored_at,
					// `created_at` is the conventional name across MemGPT/Letta,
					// mem0, and LangMem. We keep `stored_at` for backwards
					// compatibility and surface `created_at` as an alias.
					'created_at'   => $stored_at,
					'expires_at'   => $expires_at,
				),
				'expires_at'  => '' !== $expires_at ? $expires_at : null,
				'source_slug' => 'agent_memory',
			)
		);

		// 2. Agent node + OBSERVED_BY edge.
		NV_oOS_Graphify_DB::upsert_node(
			array(
				'node_id'    => $agent_node_id,
				'label'      => sprintf( 'agent:%s', $agent_id ),
				'type'       => 'agent',
				'properties' => array( 'agent_id' => (string) $agent_id ),
			)
		);
		NV_oOS_Graphify_DB::upsert_edge(
			array(
				'source_node_id' => $memory_node_id,
				'target_node_id' => $agent_node_id,
				'relation'       => 'OBSERVED_BY',
				'confidence'     => 1.0,
				'provenance'     => 'AGENT_MEMORY',
			)
		);

		// 3. Wing scope.
		if ( '' !== $wing ) {
			$wing_node_id = self::NODE_PREFIX_WING . sanitize_title_with_dashes( $wing );
			NV_oOS_Graphify_DB::upsert_node(
				array(
					'node_id'    => $wing_node_id,
					'label'      => $wing,
					'type'       => 'wing',
					'properties' => array( 'wing' => $wing ),
				)
			);
			NV_oOS_Graphify_DB::upsert_edge(
				array(
					'source_node_id' => $memory_node_id,
					'target_node_id' => $wing_node_id,
					'relation'       => 'MEMBER_OF',
					'confidence'     => 1.0,
					'provenance'     => 'AGENT_MEMORY',
				)
			);

			// 4. Room scope (only meaningful inside a wing).
			if ( '' !== $room ) {
				$room_node_id = self::NODE_PREFIX_ROOM . sanitize_title_with_dashes( $wing ) . ':' . sanitize_title_with_dashes( $room );
				NV_oOS_Graphify_DB::upsert_node(
					array(
						'node_id'    => $room_node_id,
						'label'      => $room,
						'type'       => 'room',
						'properties' => array(
							'wing' => $wing,
							'room' => $room,
						),
					)
				);
				NV_oOS_Graphify_DB::upsert_edge(
					array(
						'source_node_id' => $memory_node_id,
						'target_node_id' => $room_node_id,
						'relation'       => 'MEMBER_OF',
						'confidence'     => 1.0,
						'provenance'     => 'AGENT_MEMORY',
					)
				);
				NV_oOS_Graphify_DB::upsert_edge(
					array(
						'source_node_id' => $room_node_id,
						'target_node_id' => $wing_node_id,
						'relation'       => 'MEMBER_OF',
						'confidence'     => 1.0,
						'provenance'     => 'AGENT_MEMORY',
					)
				);
			}
		}

		// 5. DERIVED_FROM source post.
		if ( $source_post > 0 ) {
			$post_node = NV_oOS_Graphify_DB::get_node_by_post_id( $source_post );
			if ( $post_node && ! empty( $post_node->node_id ) ) {
				NV_oOS_Graphify_DB::upsert_edge(
					array(
						'source_node_id' => $memory_node_id,
						'target_node_id' => $post_node->node_id,
						'relation'       => 'DERIVED_FROM',
						'confidence'     => 1.0,
						'provenance'     => 'AGENT_MEMORY',
					)
				);
			}
		}

		// 6. Embedding — best-effort, async via the existing cron pipeline.
		self::enqueue_embedding( $memory_node_id, $title, $short_content );
	}

	/**
	 * Best-effort enqueue of an embedding job for the memory node.
	 *
	 * Reuses the same on-ingest pipeline used by remote sources so that
	 * memory vectors share the embeddings table and provider configuration.
	 *
	 * @param string $node_id Memory node id.
	 * @param string $title   Memory title.
	 * @param string $content Memory content.
	 * @return void
	 */
	private static function enqueue_embedding( $node_id, $title, $content ) {
		if ( ! class_exists( 'NV_oOS_Graphify_Embeddings_On_Ingest' ) ) {
			return;
		}
		// Skip when there is nothing meaningful to embed.
		if ( '' === trim( $title ) && '' === trim( $content ) ) {
			return;
		}
		// The on-ingest helper inspects label + properties to decide whether
		// to embed — pass a minimal node array shaped like an upserted record.
		NV_oOS_Graphify_Embeddings_On_Ingest::auto_enqueue_remote_nodes(
			array(
				'node_id'    => $node_id,
				'label'      => $title,
				'properties' => array( 'content' => $content ),
			)
		);
	}

	/**
	 * Public retrieval helper used by the agent-side `wake_up_context` graph mode.
	 *
	 * Combines: keyword search + 1-hop BFS from anchor nodes (wing / room /
	 * agent / DERIVED_FROM source) + cosine vector similarity. Returns
	 * a deduplicated list of `memory:*` node ids ordered by a blended score.
	 *
	 * Pure-PHP implementation — does not require any pgvector or Neo4j.
	 *
	 * @since 0.7.0
	 *
	 * @param array $args {
	 *     Retrieval arguments.
	 *
	 *     @type string $agent_id Agent identifier.
	 *     @type string $wing     Optional wing scope.
	 *     @type string $room     Optional room scope.
	 *     @type string $query    Optional natural-language query for keyword + vector boost.
	 *     @type int    $limit    Max memory nodes to return (default 20).
	 * }
	 * @return array<int, array{context_id:string,score:float,via:array<int,string>}>
	 */
	public static function retrieve_graph( array $args ) {
		if ( ! class_exists( 'NV_oOS_Graphify_DB' ) ) {
			return array();
		}

		// See project_memory(): skip queries while the schema is absent.
		if ( ! NV_oOS_Graphify_DB::tables_installed() ) {
			return array();
		}

		$agent_id = isset( $args['agent_id'] ) ? (string) $args['agent_id'] : '';
		$wing     = isset( $args['wing'] ) ? (string) $args['wing'] : '';
		$room     = isset( $args['room'] ) ? (string) $args['room'] : '';
		$query    = isset( $args['query'] ) ? (string) $args['query'] : '';
		$limit    = isset( $args['limit'] ) ? max( 1, min( 200, absint( $args['limit'] ) ) ) : 20;

		if ( '' === $agent_id ) {
			return array();
		}

		/**
		 * Filters the linear-combination weights used to merge the three
		 * GraphRAG signals (anchor expansion, keyword match, vector cosine).
		 *
		 * Production GraphRAG systems (Microsoft GraphRAG, Neo4j, LlamaIndex
		 * PropertyGraphIndex) all use a weighted-sum merge with tunable
		 * weights — keyword 0.4–0.5, graph 0.2–0.3, vector 0.3–0.4 are the
		 * common defaults in the 2025 surveys. We keep the three anchor tiers
		 * (room > wing > agent) separate so room scoping can dominate when an
		 * operator passes one.
		 *
		 * @since 0.7.0
		 *
		 * @param array $weights {
		 *     @type float $agent   Per-memory boost for agent ownership.
		 *     @type float $wing    Per-memory boost for wing membership.
		 *     @type float $room    Per-memory boost for room membership.
		 *     @type float $keyword Per-hit boost for label `LIKE` matches.
		 *     @type float $vector  Multiplier applied to the cosine score.
		 * }
		 * @param array $args    Original retrieve_graph arguments.
		 */
		$weights = apply_filters(
			'wp_mcp_ai_graph_score_weights',
			array(
				'agent'   => 0.1,
				'wing'    => 0.4,
				'room'    => 0.6,
				'keyword' => 0.5,
				'vector'  => 1.0,
			),
			$args
		);
		// Caller-supplied weights override the filter (per-query tuning, mirroring LlamaIndex's `top_k`/weight knobs).
		if ( isset( $args['weights'] ) && is_array( $args['weights'] ) ) {
			$weights = array_merge( $weights, array_filter( $args['weights'], 'is_numeric' ) );
		}
		$weights = array_map( 'floatval', $weights );

		$scores = array(); // node_id => array(score, via[]).

		$bump = static function ( &$scores, $node_id, $delta, $via ) {
			if ( '' === $node_id || 0 !== strpos( $node_id, self::NODE_PREFIX_MEMORY ) ) {
				return;
			}
			if ( ! isset( $scores[ $node_id ] ) ) {
				$scores[ $node_id ] = array(
					'score' => 0.0,
					'via'   => array(),
				);
			}
			$scores[ $node_id ]['score'] += (float) $delta;
			if ( ! in_array( $via, $scores[ $node_id ]['via'], true ) ) {
				$scores[ $node_id ]['via'][] = $via;
			}
		};

		// 1. Anchor: agent node — every memory the agent owns is a candidate,
		// weighted lowest so that scope/keyword/vector still dominate.
		$agent_node_id = self::NODE_PREFIX_AGENT . sanitize_title_with_dashes( $agent_id );
		foreach ( NV_oOS_Graphify_DB::get_neighbor_ids( $agent_node_id, 'OBSERVED_BY' ) as $nid ) {
			$bump( $scores, $nid, $weights['agent'], 'agent' );
		}

		// 2. Anchor: wing.
		if ( '' !== $wing ) {
			$wing_node_id = self::NODE_PREFIX_WING . sanitize_title_with_dashes( $wing );
			foreach ( NV_oOS_Graphify_DB::get_neighbor_ids( $wing_node_id, 'MEMBER_OF' ) as $nid ) {
				$bump( $scores, $nid, $weights['wing'], 'wing' );
			}
		}

		// 3. Anchor: room.
		if ( '' !== $wing && '' !== $room ) {
			$room_node_id = self::NODE_PREFIX_ROOM . sanitize_title_with_dashes( $wing ) . ':' . sanitize_title_with_dashes( $room );
			foreach ( NV_oOS_Graphify_DB::get_neighbor_ids( $room_node_id, 'MEMBER_OF' ) as $nid ) {
				$bump( $scores, $nid, $weights['room'], 'room' );
			}
		}

		// 4. Keyword search — apply against memory nodes only.
		if ( '' !== $query ) {
			$rows = NV_oOS_Graphify_DB::search_nodes( $query, 'memory', max( 50, $limit * 4 ) );
			foreach ( $rows as $row ) {
				if ( ! empty( $row->node_id ) ) {
					$bump( $scores, $row->node_id, $weights['keyword'], 'keyword' );
				}
			}
		}

		// 5. Vector similarity — only when we have a query and embeddings are usable.
		if ( '' !== $query && class_exists( 'NV_oOS_Graphify_Embeddings' ) && self::is_embeddings_enabled() ) {
			$query_vec = self::embed_query( $query );
			if ( is_array( $query_vec ) && ! empty( $query_vec ) ) {
				$matches = NV_oOS_Graphify_Embeddings::search( $query_vec, max( 20, $limit * 2 ) );
				foreach ( $matches as $m ) {
					if ( ! empty( $m['node_id'] ) ) {
						// Cosine score is in -1..1 — clamp to a positive boost, then apply weight.
						$bump( $scores, (string) $m['node_id'], max( 0.0, (float) $m['score'] ) * $weights['vector'], 'vector' );
					}
				}
			}
		}

		if ( empty( $scores ) ) {
			return array();
		}

		// Sort descending by score.
		uasort(
			$scores,
			static function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return 0;
				}
				return ( $a['score'] < $b['score'] ) ? 1 : -1;
			}
		);

		$out = array();
		foreach ( $scores as $node_id => $data ) {
			$out[] = array(
				'context_id' => substr( $node_id, strlen( self::NODE_PREFIX_MEMORY ) ),
				'score'      => (float) $data['score'],
				'via'        => $data['via'],
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Whether embedding generation is enabled in Graphify settings.
	 *
	 * @return bool
	 */
	private static function is_embeddings_enabled() {
		if ( ! class_exists( 'NV_oOS_Graphify' ) ) {
			return false;
		}
		$settings = NV_oOS_Graphify::get_settings();
		return ! empty( $settings['embeddings_enabled'] );
	}

	/**
	 * Generate an embedding vector for the query string.
	 *
	 * Delegates to the agent-side {@see WP_MCP_AI_Vector_Context_Service}
	 * (Phase 3 pluggable provider) so the same provider is used end-to-end.
	 *
	 * @param string $text Query text.
	 * @return array<int, float>|null Vector or null on failure.
	 */
	private static function embed_query( $text ) {
		if ( ! class_exists( 'WP_MCP_AI_Vector_Context_Service' ) ) {
			return null;
		}
		$svc = WP_MCP_AI_Vector_Context_Service::get_instance();
		if ( ! method_exists( $svc, 'embed_context' ) ) {
			return null;
		}
		$vec = $svc->embed_context( (string) $text );
		if ( is_wp_error( $vec ) || ! is_array( $vec ) ) {
			return null;
		}
		return $vec;
	}
}
