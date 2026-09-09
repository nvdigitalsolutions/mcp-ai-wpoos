<?php
declare(strict_types=1);

namespace NvoosContentGraph\Memory;

use NvoosContentGraph\Graph\Db;
use NvoosContentGraph\Schema;

use function absint;
use function apply_filters;
use function current_time;
use function in_array;
use function is_array;
use function sanitize_key;
use function sanitize_text_field;
use function sanitize_title_with_dashes;

/**
 * Agent memory bridge.
 *
 * Connects agent memories to the knowledge graph. Subscribes to two
 * memory-stored events:
 *
 *   - `wp_mcp_ai_memory_stored` — the canonical event emitted by the NV oOS
 *     base+Pro plugin's memory capture service. This is the primary producer
 *     when both plugins are installed (hooking it here is harmless when the
 *     base plugin is absent — the event simply never fires).
 *   - `nvoos_content_graph/memory_stored` — the ecosystem-native event for
 *     producers that live inside the Content Graph family of plugins.
 *
 * Each memory is projected into the graph as a `memory:*` node together with
 * associative edges:
 *
 *   - MEMBER_OF    → wing-node    (one per wing slug)
 *   - MEMBER_OF    → room-node    (one per (wing, room) pair)
 *   - DERIVED_FROM → post-node    (when the memory was ingested from a WP post)
 *   - OBSERVED_BY  → agent-node   (one per agent_id)
 *
 * The bridge is **advisory** — failures here must never break the agent
 * memory write. The source store (the base plugin's transient store or the
 * JetEngine CCT) remains the source of truth.
 *
 * The bridge also registers the graph-retrieval seam consumed by the base
 * plugin's `wake_up_context` tool (`wp_mcp_ai_wake_up_context_graph_retriever`),
 * so a site running NV oOS together with this plugin gets graph-ranked memory
 * wake-ups without the bundled Graphify addon. When the base plugin is
 * absent the filter is never invoked, so the registration is inert.
 *
 * Embedding generation is deliberately not enqueued here: the core plugin's
 * `EmbeddingsOnIngest` cron is a stub whose real backend ships with the
 * `nvoos-content-graph-ai` addon.
 *
 * @since 1.0.0
 */
class Bridge {

	/** Node-id prefix for memory nodes. */
	public const NODE_PREFIX_MEMORY = 'memory:';

	/** Node-id prefix for wing-scope nodes. */
	public const NODE_PREFIX_WING = 'wing:';

	/** Node-id prefix for room-scope nodes (composite with wing). */
	public const NODE_PREFIX_ROOM = 'room:';

	/** Node-id prefix for agent-author nodes. */
	public const NODE_PREFIX_AGENT = 'agent:';

	/**
	 * Maximum verbatim content length stored on the node (chars).
	 *
	 * Mirrors the NV oOS `store_agent_context` ingestion cap so node rows
	 * never grow unbounded.
	 */
	public const MAX_CONTENT_LEN = 8000;

	/**
	 * Register the memory subscribers.
	 *
	 * Idempotent — safe to call multiple times.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( Schema::ACTION_MEMORY_STORED, array( __CLASS__, 'onMemoryStored' ), 10, 1 );
		add_action( 'wp_mcp_ai_memory_stored', array( __CLASS__, 'onMemoryStored' ), 10, 1 );
		add_filter( 'wp_mcp_ai_wake_up_context_graph_retriever', array( __CLASS__, 'onWakeUpGraphRetriever' ), 10, 2 );
	}

	/**
	 * Handle a single memory-stored event.
	 *
	 * @param array<string,mixed> $payload Event payload — see the NV oOS
	 *                                    `store_agent_context` contract:
	 *                                    `context_id`, `agent_id`, `wing`,
	 *                                    `room`, `title`, `content`,
	 *                                    `importance`, `context_type`,
	 *                                    `verbatim`, `source_post_id`,
	 *                                    `tags`, `stored_at`, `expires_at`.
	 * @return void
	 */
	public static function onMemoryStored( array $payload ): void {
		if ( empty( $payload['context_id'] ) ) {
			return;
		}

		// Defensive: never let a bug here bubble up into the memory write path.
		try {
			self::projectMemory( $payload );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[nvoos-content-graph] memory bridge failure: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Project a memory event into nodes and edges.
	 *
	 * @param array<string,mixed> $payload Sanitized event payload.
	 * @return void
	 */
	private static function projectMemory( array $payload ): void {
		// The schema may not be installed yet (for example in CI or before
		// first activation). Skip silently instead of spamming database
		// errors on every memory write.
		if ( ! Db::tablesInstalled() ) {
			return;
		}

		$contextId  = sanitize_text_field( (string) ( $payload['context_id'] ?? '' ) );
		$agentId    = isset( $payload['agent_id'] ) ? (string) $payload['agent_id'] : '';
		$wing       = isset( $payload['wing'] ) ? sanitize_text_field( (string) $payload['wing'] ) : '';
		$room       = isset( $payload['room'] ) ? sanitize_text_field( (string) $payload['room'] ) : '';
		$title      = isset( $payload['title'] ) ? (string) $payload['title'] : '';
		$content    = isset( $payload['content'] ) ? (string) $payload['content'] : '';
		$importance = isset( $payload['importance'] ) ? sanitize_key( (string) $payload['importance'] ) : 'medium';
		$ctxType    = isset( $payload['context_type'] ) ? sanitize_key( (string) $payload['context_type'] ) : 'memory';
		$verbatim   = ! empty( $payload['verbatim'] );
		$sourcePost = isset( $payload['source_post_id'] ) ? absint( $payload['source_post_id'] ) : 0;
		$tags       = ( isset( $payload['tags'] ) && is_array( $payload['tags'] ) )
			? array_map( 'sanitize_text_field', $payload['tags'] )
			: array();
		$storedAt   = isset( $payload['stored_at'] ) ? (string) $payload['stored_at'] : current_time( 'mysql' );
		$expiresAt  = isset( $payload['expires_at'] ) ? (string) $payload['expires_at'] : '';

		if ( '' === $contextId || '' === $agentId ) {
			return;
		}

		// Truncate verbatim content for the node properties so we don't blow
		// out the node row size.
		$shortContent = $content;
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $shortContent ) > self::MAX_CONTENT_LEN ) {
			$shortContent = mb_substr( $shortContent, 0, self::MAX_CONTENT_LEN ) . '…';
		} elseif ( strlen( $shortContent ) > self::MAX_CONTENT_LEN ) {
			$shortContent = substr( $shortContent, 0, self::MAX_CONTENT_LEN ) . '…';
		}

		$memoryNodeId = self::NODE_PREFIX_MEMORY . $contextId;
		$agentNodeId  = self::NODE_PREFIX_AGENT . sanitize_title_with_dashes( $agentId );

		// 1. Memory node.
		Db::upsertNode(
			array(
				'node_id'     => $memoryNodeId,
				'label'       => '' !== $title ? $title : 'memory ' . $contextId,
				'type'        => 'memory',
				'post_id'     => 0,
				'url'         => '',
				'properties'  => array(
					'context_id'   => $contextId,
					'agent_id'     => $agentId,
					'wing'         => $wing,
					'room'         => $room,
					'importance'   => $importance,
					'context_type' => $ctxType,
					// `memory_type` mirrors `context_type` under the
					// LangMem-style taxonomy (semantic / episodic /
					// procedural / fact / etc.) so downstream graph
					// consumers can filter by either name.
					'memory_type'  => $ctxType,
					'verbatim'     => $verbatim ? 1 : 0,
					'tags'         => $tags,
					'content'      => $shortContent,
					'stored_at'    => $storedAt,
					// `created_at` is the conventional name across
					// MemGPT/Letta, mem0, and LangMem. We keep `stored_at`
					// for parity with the NV oOS payload and surface
					// `created_at` as an alias.
					'created_at'   => $storedAt,
					'expires_at'   => $expiresAt,
				),
				'expires_at'  => '' !== $expiresAt ? $expiresAt : null,
				'source_slug' => 'agent_memory',
			)
		);

		// 2. Agent node + OBSERVED_BY edge.
		Db::upsertNode(
			array(
				'node_id'    => $agentNodeId,
				'label'      => 'agent:' . $agentId,
				'type'       => 'agent',
				'properties' => array( 'agent_id' => $agentId ),
			)
		);
		Db::upsertEdge(
			array(
				'source_node_id' => $memoryNodeId,
				'target_node_id' => $agentNodeId,
				'relation'       => 'OBSERVED_BY',
				'confidence'     => 1.0,
				'provenance'     => 'AGENT_MEMORY',
			)
		);

		// 3. Wing scope.
		if ( '' !== $wing ) {
			$wingNodeId = self::NODE_PREFIX_WING . sanitize_title_with_dashes( $wing );
			Db::upsertNode(
				array(
					'node_id'    => $wingNodeId,
					'label'      => $wing,
					'type'       => 'wing',
					'properties' => array( 'wing' => $wing ),
				)
			);
			Db::upsertEdge(
				array(
					'source_node_id' => $memoryNodeId,
					'target_node_id' => $wingNodeId,
					'relation'       => 'MEMBER_OF',
					'confidence'     => 1.0,
					'provenance'     => 'AGENT_MEMORY',
				)
			);

			// 4. Room scope (only meaningful inside a wing).
			if ( '' !== $room ) {
				$roomNodeId = self::NODE_PREFIX_ROOM . sanitize_title_with_dashes( $wing ) . ':' . sanitize_title_with_dashes( $room );
				Db::upsertNode(
					array(
						'node_id'    => $roomNodeId,
						'label'      => $room,
						'type'       => 'room',
						'properties' => array(
							'wing' => $wing,
							'room' => $room,
						),
					)
				);
				Db::upsertEdge(
					array(
						'source_node_id' => $memoryNodeId,
						'target_node_id' => $roomNodeId,
						'relation'       => 'MEMBER_OF',
						'confidence'     => 1.0,
						'provenance'     => 'AGENT_MEMORY',
					)
				);
				Db::upsertEdge(
					array(
						'source_node_id' => $roomNodeId,
						'target_node_id' => $wingNodeId,
						'relation'       => 'MEMBER_OF',
						'confidence'     => 1.0,
						'provenance'     => 'AGENT_MEMORY',
					)
				);
			}
		}

		// 5. DERIVED_FROM source post.
		if ( $sourcePost > 0 ) {
			$postNode = Db::getNodeByPostId( $sourcePost );
			if ( $postNode && ! empty( $postNode->node_id ) ) {
				Db::upsertEdge(
					array(
						'source_node_id' => $memoryNodeId,
						'target_node_id' => $postNode->node_id,
						'relation'       => 'DERIVED_FROM',
						'confidence'     => 1.0,
						'provenance'     => 'AGENT_MEMORY',
					)
				);
			}
		}
	}

	/**
	 * Filter callback for the NV oOS `wp_mcp_ai_wake_up_context_graph_retriever`
	 * seam.
	 *
	 * Serves graph-ranked memory rows to the base plugin's `wake_up_context`
	 * tool. Returns `null` when the request cannot be served (missing
	 * agent id), which lets the base tool fall through to the bundled
	 * Graphify bridge or the transient path.
	 *
	 * @param mixed $ranked Incoming value from earlier filters (ignored —
	 *                      first provider wins by contract).
	 * @param array $args   Retrieval args: agent_id, wing, room, query, limit.
	 * @return array<int,array<string,mixed>>|null Ranked rows or null.
	 */
	public static function onWakeUpGraphRetriever( $ranked, array $args ) {
		if ( ! is_array( $args ) || empty( $args['agent_id'] ) ) {
			return null;
		}
		return self::retrieveGraph( $args );
	}

	/**
	 * Graph-backed retrieval used by the NV oOS `wake_up_context` graph mode.
	 *
	 * Combines keyword search + 1-hop expansion from anchor nodes (wing /
	 * room / agent) into a blended score. Returns a deduplicated list of
	 * `memory:*` node ids ordered by score. Vector similarity is not part of
	 * the core-plugin blend — the embeddings backend ships with the
	 * `nvoos-content-graph-ai` addon.
	 *
	 * Pure-PHP implementation — no pgvector or Neo4j required.
	 *
	 * @since 1.0.5
	 *
	 * @param array<string,mixed> $args {
	 *     Retrieval arguments.
	 *
	 *     @type string $agent_id Agent identifier.
	 *     @type string $wing     Optional wing scope.
	 *     @type string $room     Optional room scope.
	 *     @type string $query    Optional natural-language query for keyword boost.
	 *     @type int    $limit    Max memory nodes to return (default 20).
	 * }
	 * @return array<int,array{context_id:string,score:float,via:array<int,string>}>
	 */
	public static function retrieveGraph( array $args ): array {
		if ( ! Db::tablesInstalled() ) {
			return array();
		}

		$agentId = isset( $args['agent_id'] ) ? (string) $args['agent_id'] : '';
		$wing    = isset( $args['wing'] ) ? (string) $args['wing'] : '';
		$room    = isset( $args['room'] ) ? (string) $args['room'] : '';
		$query   = isset( $args['query'] ) ? (string) $args['query'] : '';
		$limit   = isset( $args['limit'] ) ? max( 1, min( 200, absint( $args['limit'] ) ) ) : 20;

		if ( '' === $agentId ) {
			return array();
		}

		/**
		 * Filters the linear-combination weights used to merge the retrieval
		 * signals (anchor expansion + keyword match). Shares the hook name
		 * with the NV oOS bundled Graphify bridge so operators tune both
		 * implementations with one filter.
		 *
		 * @since 1.0.5
		 *
		 * @param array<string,float> $weights {
		 *     @type float $agent   Per-memory boost for agent ownership.
		 *     @type float $wing    Per-memory boost for wing membership.
		 *     @type float $room    Per-memory boost for room membership.
		 *     @type float $keyword Per-hit boost for label `LIKE` matches.
		 * }
		 * @param array<string,mixed> $args Original retrieveGraph arguments.
		 */
		$weights        = apply_filters(
			'wp_mcp_ai_graph_score_weights',
			array(
				'agent'   => 0.1,
				'wing'    => 0.4,
				'room'    => 0.6,
				'keyword' => 0.5,
			),
			$args
		);
		$defaultWeights = array(
			'agent'   => 0.1,
			'wing'    => 0.4,
			'room'    => 0.6,
			'keyword' => 0.5,
		);
		if ( ! is_array( $weights ) ) {
			$weights = $defaultWeights;
		}
		$weights = array_merge( $defaultWeights, $weights );
		// Caller-supplied weights override the filter (per-query tuning).
		if ( isset( $args['weights'] ) && is_array( $args['weights'] ) ) {
			$weights = array_merge( $weights, array_filter( $args['weights'], 'is_numeric' ) );
		}
		$weights = array_map( 'floatval', $weights );

		// Accumulated scores keyed by memory node id: score + provenance signals.
		$scores = array();

		$bump = static function ( array &$scores, string $nodeId, float $delta, string $via ): void {
			if ( '' === $nodeId || 0 !== strpos( $nodeId, self::NODE_PREFIX_MEMORY ) ) {
				return;
			}
			if ( ! isset( $scores[ $nodeId ] ) ) {
				$scores[ $nodeId ] = array(
					'score' => 0.0,
					'via'   => array(),
				);
			}
			$scores[ $nodeId ]['score'] += $delta;
			if ( ! in_array( $via, $scores[ $nodeId ]['via'], true ) ) {
				$scores[ $nodeId ]['via'][] = $via;
			}
		};

		// 1. Anchor: agent node — every memory the agent owns is a candidate,
		// weighted lowest so that scope/keyword still dominate.
		$agentNodeId = self::NODE_PREFIX_AGENT . sanitize_title_with_dashes( $agentId );
		foreach ( Db::getNeighborIds( $agentNodeId, 'OBSERVED_BY' ) as $nid ) {
			$bump( $scores, (string) $nid, $weights['agent'], 'agent' );
		}

		// 2. Anchor: wing.
		if ( '' !== $wing ) {
			$wingNodeId = self::NODE_PREFIX_WING . sanitize_title_with_dashes( $wing );
			foreach ( Db::getNeighborIds( $wingNodeId, 'MEMBER_OF' ) as $nid ) {
				$bump( $scores, (string) $nid, $weights['wing'], 'wing' );
			}
		}

		// 3. Anchor: room.
		if ( '' !== $wing && '' !== $room ) {
			$roomNodeId = self::NODE_PREFIX_ROOM . sanitize_title_with_dashes( $wing ) . ':' . sanitize_title_with_dashes( $room );
			foreach ( Db::getNeighborIds( $roomNodeId, 'MEMBER_OF' ) as $nid ) {
				$bump( $scores, (string) $nid, $weights['room'], 'room' );
			}
		}

		// 4. Keyword search — apply against memory nodes only.
		if ( '' !== $query ) {
			$rows = Db::searchNodes( $query, 'memory', max( 50, $limit * 4 ) );
			foreach ( $rows as $row ) {
				if ( ! empty( $row->node_id ) ) {
					$bump( $scores, (string) $row->node_id, $weights['keyword'], 'keyword' );
				}
			}
		}

		if ( empty( $scores ) ) {
			return array();
		}

		// Sort descending by score.
		uasort(
			$scores,
			static function ( array $a, array $b ): int {
				return $b['score'] <=> $a['score'];
			}
		);

		$out = array();
		foreach ( $scores as $nodeId => $data ) {
			$out[] = array(
				'context_id' => substr( $nodeId, strlen( self::NODE_PREFIX_MEMORY ) ),
				'score'      => (float) $data['score'],
				'via'        => $data['via'],
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}
}
