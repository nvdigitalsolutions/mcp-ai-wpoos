<?php
/**
 * Memory RRF Fusion Service — Phase 4 of the 2026 Memory Layer Enhancements.
 *
 * Hybrid retrieval over agent memory using **Reciprocal Rank Fusion** (RRF)
 * across three independent candidate streams:
 *
 *   1. **BM25** — full-text relevance over CCT `content`+`title` (MySQL
 *      `MATCH(...) AGAINST(... IN NATURAL LANGUAGE MODE)`), with a tokenised
 *      LIKE fallback when the CCT or its FULLTEXT index is unavailable.
 *   2. **Vector** — cosine similarity from
 *      {@see WP_MCP_AI_Vector_Context_Service::get_vector_candidates()}, the
 *      same embedding pipeline used by the existing booster path.
 *   3. **Graph** — Graphify `RECALLS` neighborhood expansion via
 *      {@see NV_oOS_Graphify_Memory_Bridge::retrieve_graph()} when the addon
 *      is loaded; silently skipped otherwise.
 *
 * Streams are merged with the RRF formula (Cormack, Clarke & Buettcher 2009):
 *
 *     fused_score(d) = Σ_streams 1 / ( k + rank_stream(d) + 1 )
 *
 * where `k` defaults to 60 (the empirical sweet spot reported across hybrid
 * search surveys 2020-2026: Pinecone, Weaviate, Mem0, Vespa, Vektor blog).
 *
 * After fusion we:
 *   - multiply by per-record `confidence_score` (Phase 2 schema v2 field,
 *     default 1.0 for legacy rows), and
 *   - apply a per-session diversification cap so a single chat session
 *     cannot saturate the result list.
 *
 * Backward compatibility:
 *   - The legacy {@see WP_MCP_AI_Vector_Context_Service::search_context()}
 *     code path is left untouched and remains the fallback when the master
 *     filter `wp_mcp_ai_memory_rrf_enabled` returns false.
 *   - When RRF is active, results carry BOTH the legacy `boost_breakdown`
 *     keys (all zero) AND a new `rrf_breakdown` block so existing chat-memory
 *     drawer JS continues to render without modification.
 *
 * @link    https://github.com/rohitg00/agentmemory
 * @link    https://github.com/MemPalace/mempalace
 *
 * @package WP_MCP_AI
 * @since   1.1.20
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RRF (Reciprocal Rank Fusion) hybrid retrieval service.
 *
 * Stateless / static — call {@see WP_MCP_AI_Memory_RRF_Fusion_Service::search()}
 * to run the full pipeline, or any of the per-stream helpers for unit
 * inspection.
 *
 * @since 1.1.20
 */
class WP_MCP_AI_Memory_RRF_Fusion_Service {

	/**
	 * Cache key prefix for fused-score memoisation.
	 *
	 * Bumped to `v1` so a future internal-shape change can invalidate cached
	 * entries by bumping the suffix without flushing the broader object cache.
	 */
	const CACHE_PREFIX = 'mem_rrf_v1_';

	/**
	 * Cache group used with `wp_cache_*`.
	 */
	const CACHE_GROUP = 'wp_mcp_ai_memory_rrf';

	/**
	 * Default candidate cap per stream.
	 *
	 * RRF only cares about rank order in each stream, so a generous cap is
	 * sufficient — the cap exists to bound the per-stream work, not to
	 * influence ranking quality.
	 */
	const DEFAULT_CANDIDATES_PER_STREAM = 20;

	/**
	 * Default RRF `k` constant (Cormack et al. 2009).
	 */
	const DEFAULT_K = 60;

	/**
	 * Default per-session diversification cap.
	 */
	const DEFAULT_SESSION_DIVERSITY_CAP = 3;

	/**
	 * Default graph BFS depth.
	 */
	const DEFAULT_GRAPH_MAX_DEPTH = 2;

	/**
	 * Default cache TTL in seconds.
	 */
	const DEFAULT_CACHE_TTL = 300;

	/**
	 * Default minimum query length before BM25 stream fires.
	 */
	const DEFAULT_BM25_MIN_CHARS = 3;

	/**
	 * Whether the master switch is on for the current request.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		/**
		 * Master kill-switch for RRF fusion retrieval.
		 *
		 * When this returns false the entry points all fall through to the
		 * existing cosine + booster pipeline in
		 * {@see WP_MCP_AI_Vector_Context_Service::search_context()}.
		 *
		 * @since 1.1.20
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'wp_mcp_ai_memory_rrf_enabled', true );
	}

	/**
	 * Run the full RRF pipeline.
	 *
	 * @since 1.1.20
	 *
	 * @param string     $query    Natural-language query.
	 * @param int|string $agent_id Owning agent.
	 * @param int        $limit    Maximum hydrated results to return.
	 * @param array      $filters  Optional retrieval filters (wing, room, tags, etc.).
	 * @return array {
	 *     Result envelope identical in shape to
	 *     {@see WP_MCP_AI_Vector_Context_Service::search_context()} plus a
	 *     per-record `rrf_breakdown` key.
	 *
	 *     @type bool   $success  Always true on a non-error path.
	 *     @type array  $contexts Ordered hits.
	 *     @type int    $count    Number of hits returned.
	 *     @type string $query    Echoed query.
	 *     @type string $method   `"rrf_hybrid"` so callers can tell the source apart.
	 * }
	 */
	public static function search( $query, $agent_id, $limit = 10, $filters = array() ) {
		$query   = is_scalar( $query ) ? (string) $query : '';
		$limit   = max( 1, (int) $limit );
		$filters = is_array( $filters ) ? $filters : array();

		$cache_key = self::CACHE_PREFIX . md5( $query . '|' . (string) $agent_id . '|' . wp_json_encode( $filters ) );
		$cache_ttl = (int) apply_filters( 'wp_mcp_ai_memory_rrf_cache_ttl', self::DEFAULT_CACHE_TTL );
		/**
		 * Filter to disable the candidate cache entirely.
		 *
		 * Useful in tests and for debugging.
		 *
		 * @since 1.1.20
		 *
		 * @param bool   $bypass   Default false (cache enabled).
		 * @param string $query    Search query.
		 * @param mixed  $agent_id Agent identifier.
		 * @param array  $filters  Filters payload.
		 */
		$bypass_cache = (bool) apply_filters( 'wp_mcp_ai_memory_rrf_cache_bypass', false, $query, $agent_id, $filters );

		$cached = null;
		if ( ! $bypass_cache && $cache_ttl > 0 ) {
			$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( is_array( $cached ) && isset( $cached['fused_scores'], $cached['stream_rankings'] ) ) {
				return self::hydrate_response(
					$query,
					$agent_id,
					$limit,
					$filters,
					$cached['fused_scores'],
					$cached['stream_rankings']
				);
			}
		}

		// --- Resolve enabled streams -------------------------------------.
		/**
		 * Filter the active stream list.
		 *
		 * @since 1.1.20
		 *
		 * @param array $streams Default ['bm25', 'vector', 'graph'].
		 */
		$streams = apply_filters( 'wp_mcp_ai_memory_rrf_streams', array( 'bm25', 'vector', 'graph' ) );
		$streams = is_array( $streams ) ? array_values( array_intersect( $streams, array( 'bm25', 'vector', 'graph' ) ) ) : array();

		$per_stream = (int) apply_filters( 'wp_mcp_ai_memory_rrf_candidates_per_stream', self::DEFAULT_CANDIDATES_PER_STREAM );
		if ( $per_stream < 1 ) {
			$per_stream = self::DEFAULT_CANDIDATES_PER_STREAM;
		}

		$stream_rankings = array();

		// --- BM25 stream --------------------------------------------------.
		if ( in_array( 'bm25', $streams, true ) ) {
			$min_chars = (int) apply_filters( 'wp_mcp_ai_memory_rrf_bm25_min_chars', self::DEFAULT_BM25_MIN_CHARS );
			if ( strlen( trim( $query ) ) >= max( 1, $min_chars ) ) {
				$stream_rankings['bm25'] = self::get_bm25_candidates( $query, $agent_id, $filters, $per_stream );
			} else {
				$stream_rankings['bm25'] = array();
			}
		}

		// --- Vector stream -----------------------------------------------.
		if ( in_array( 'vector', $streams, true ) ) {
			$stream_rankings['vector'] = self::get_vector_candidates( $query, $agent_id, $filters, $per_stream );
		}

		// --- Graph stream ------------------------------------------------.
		if ( in_array( 'graph', $streams, true ) ) {
			$stream_rankings['graph'] = self::get_graph_candidates( $query, $agent_id, $filters, $per_stream );
		}

		// --- Fuse -------------------------------------------------------.
		$rank_lists = array();
		foreach ( $stream_rankings as $stream_label => $records ) {
			$rank_lists[ $stream_label ] = self::extract_context_ids( $records );
		}
		$fused_scores = self::rrf_fuse( $rank_lists, (int) apply_filters( 'wp_mcp_ai_memory_rrf_k', self::DEFAULT_K ) );

		// Persist a slim cache payload (no full records — they re-hydrate).
		if ( ! $bypass_cache && $cache_ttl > 0 ) {
			wp_cache_set(
				$cache_key,
				array(
					'fused_scores'    => $fused_scores,
					'stream_rankings' => $rank_lists,
				),
				self::CACHE_GROUP,
				$cache_ttl
			);
		}

		return self::hydrate_response( $query, $agent_id, $limit, $filters, $fused_scores, $rank_lists, $stream_rankings );
	}

	/**
	 * Reciprocal Rank Fusion arithmetic.
	 *
	 * Pure function — exposed publicly for unit testing.
	 *
	 * @since 1.1.20
	 *
	 * @param array<string, array<int, string>> $stream_rankings Map of stream label => ordered list of context_ids.
	 * @param int                               $k               RRF constant (default 60).
	 * @return array<string, float> context_id => fused score (descending).
	 */
	public static function rrf_fuse( array $stream_rankings, $k = self::DEFAULT_K ) {
		$k      = max( 1, (int) $k );
		$scores = array();
		foreach ( $stream_rankings as $ranked_ids ) {
			if ( ! is_array( $ranked_ids ) ) {
				continue;
			}
			$rank = 0;
			foreach ( $ranked_ids as $context_id ) {
				if ( ! is_string( $context_id ) || '' === $context_id ) {
					++$rank;
					continue;
				}
				$scores[ $context_id ] = ( isset( $scores[ $context_id ] ) ? (float) $scores[ $context_id ] : 0.0 )
					+ ( 1.0 / ( $k + $rank + 1 ) );
				++$rank;
			}
		}
		arsort( $scores );
		return $scores;
	}

	/**
	 * BM25 candidate stream.
	 *
	 * Tries `MATCH(...) AGAINST(... IN NATURAL LANGUAGE MODE)` against the
	 * CCT table first; falls back to a tokenised LIKE-based scorer over the
	 * transient store when the CCT is missing or the FULLTEXT index is not
	 * present.
	 *
	 * @since 1.1.20
	 *
	 * @param string     $query    Search query.
	 * @param int|string $agent_id Agent identifier.
	 * @param array      $filters  Retrieval filters.
	 * @param int        $limit    Per-stream cap.
	 * @return array<int, array> Ordered records (most relevant first).
	 */
	public static function get_bm25_candidates( $query, $agent_id, $filters, $limit ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return array();
		}
		$limit = max( 1, (int) $limit );

		// Try CCT-backed FULLTEXT first.
		$cct_rows = self::query_bm25_cct( $query, $agent_id, $filters, $limit );
		if ( is_array( $cct_rows ) && ! empty( $cct_rows ) ) {
			return $cct_rows;
		}

		// Fallback: LIKE-based tokenised scorer over the transient store.
		return self::query_bm25_transient( $query, $agent_id, $filters, $limit );
	}

	/**
	 * MySQL FULLTEXT BM25-style ranking against the CCT mirror.
	 *
	 * Returns `null` when the CCT or its FULLTEXT index is unavailable so
	 * the caller can fall back to the LIKE scorer. Returns an array (possibly
	 * empty) when the query succeeded — empty means "no hits", not "failed".
	 *
	 * @param string     $query    Query.
	 * @param int|string $agent_id Agent.
	 * @param array      $filters  Filters.
	 * @param int        $limit    Limit.
	 * @return array<int, array>|null
	 */
	protected static function query_bm25_cct( $query, $agent_id, $filters, $limit ) {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return null;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return null;
		}

		$slug  = WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_slug();
		$table = $wpdb->prefix . 'jet_cct_' . $slug;

		$agent_id_s = is_scalar( $agent_id ) ? (string) $agent_id : '';
		if ( '' === $agent_id_s ) {
			return null;
		}

		$suppress = $wpdb->suppress_errors( true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name comes from a constant CCT slug + $wpdb->prefix; values are parameterised.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT *, MATCH(title, content) AGAINST(%s IN NATURAL LANGUAGE MODE) AS relevance '
				. "FROM `{$table}` "
				. 'WHERE agent_id = %s '
				. "AND ( cct_status IS NULL OR cct_status = 'publish' ) "
				. 'AND MATCH(title, content) AGAINST(%s IN NATURAL LANGUAGE MODE) '
				. 'ORDER BY relevance DESC LIMIT %d',
				$query,
				$agent_id_s,
				$query,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$had_error = ! empty( $wpdb->last_error );
		$wpdb->suppress_errors( $suppress );

		if ( $had_error || ! is_array( $rows ) ) {
			// Most common cause: no FULLTEXT index on the CCT columns. Caller
			// falls back to the LIKE scorer.
			return null;
		}
		if ( empty( $rows ) ) {
			// FULLTEXT worked but produced no hits — return empty so the
			// caller does NOT fall through to the LIKE scorer (otherwise the
			// `bm25` stream would always silently include LIKE matches).
			return array();
		}

		$records = array();
		foreach ( $rows as $row ) {
			$records[] = self::map_cct_row_to_record( $row );
		}
		return self::apply_filters_to_records( $records, $filters );
	}

	/**
	 * Tokenised LIKE-based BM25 fallback over the transient store.
	 *
	 * Each query token contributes a flat score per matching record (count of
	 * token hits), which is enough to give RRF a stable rank order even
	 * without FULLTEXT.
	 *
	 * @param string     $query    Query.
	 * @param int|string $agent_id Agent.
	 * @param array      $filters  Filters.
	 * @param int        $limit    Limit.
	 * @return array<int, array>
	 */
	protected static function query_bm25_transient( $query, $agent_id, $filters, $limit ) {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Context_Manager' ) ) {
			return array();
		}
		$mgr     = WP_MCP_AI_Agent_Context_Manager::get_instance();
		$records = $mgr->search_contexts( $agent_id, $filters, 200, false );
		if ( empty( $records ) ) {
			return array();
		}

		$tokens = self::tokenise_query( $query );
		if ( empty( $tokens ) ) {
			return array();
		}

		$scored = array();
		foreach ( $records as $record ) {
			$haystack = strtolower( self::extract_text( $record ) );
			$hits     = 0;
			foreach ( $tokens as $token ) {
				if ( '' === $token ) {
					continue;
				}
				$count = substr_count( $haystack, $token );
				if ( $count > 0 ) {
					$hits += $count;
				}
			}
			if ( $hits > 0 ) {
				$scored[] = array(
					'record' => $record,
					'score'  => $hits,
				);
			}
		}

		if ( empty( $scored ) ) {
			return array();
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		$out = array();
		foreach ( array_slice( $scored, 0, $limit ) as $entry ) {
			$out[] = $entry['record'];
		}
		return $out;
	}

	/**
	 * Vector stream candidates — delegates to the existing vector service.
	 *
	 * @since 1.1.20
	 *
	 * @param string     $query    Query.
	 * @param int|string $agent_id Agent.
	 * @param array      $filters  Filters.
	 * @param int        $limit    Per-stream cap.
	 * @return array<int, array>
	 */
	public static function get_vector_candidates( $query, $agent_id, $filters, $limit ) {
		if ( ! class_exists( 'WP_MCP_AI_Vector_Context_Service' ) ) {
			return array();
		}
		$svc = WP_MCP_AI_Vector_Context_Service::get_instance();
		if ( ! method_exists( $svc, 'get_vector_candidates' ) ) {
			return array();
		}
		$records = $svc->get_vector_candidates( (string) $query, $agent_id, (int) $limit, is_array( $filters ) ? $filters : array() );
		return is_array( $records ) ? $records : array();
	}

	/**
	 * Graph stream candidates via the Graphify bridge.
	 *
	 * Silently returns an empty list when Graphify is not installed/active.
	 *
	 * @since 1.1.20
	 *
	 * @param string     $query    Query (used for entity extraction).
	 * @param int|string $agent_id Agent.
	 * @param array      $filters  Filters (notably wing/room).
	 * @param int        $limit    Per-stream cap.
	 * @return array<int, array>
	 */
	public static function get_graph_candidates( $query, $agent_id, $filters, $limit ) {
		if ( ! class_exists( 'NV_oOS_Graphify_Memory_Bridge' ) ) {
			return array();
		}
		if ( ! method_exists( 'NV_oOS_Graphify_Memory_Bridge', 'retrieve_graph' ) ) {
			return array();
		}

		// Entity extraction — basic token heuristic (nouns >= 4 chars). The
		// bridge accepts the raw query too, but passing the extracted token
		// list mimics the BFS-from-entities semantics described in the spec.
		$entities = self::extract_entities( (string) $query );
		$entity_q = empty( $entities ) ? (string) $query : implode( ' ', $entities );

		$max_depth = (int) apply_filters( 'wp_mcp_ai_memory_rrf_graph_max_depth', self::DEFAULT_GRAPH_MAX_DEPTH );

		$args = array(
			'agent_id'  => (string) $agent_id,
			'wing'      => isset( $filters['wing'] ) ? (string) $filters['wing'] : '',
			'room'      => isset( $filters['room'] ) ? (string) $filters['room'] : '',
			'query'     => $entity_q,
			'limit'     => max( 1, (int) $limit ),
			// Forwarded for forward-compat with future Graphify versions; the
			// current bridge ignores unknown keys.
			'max_depth' => max( 1, $max_depth ),
		);

		$rows = NV_oOS_Graphify_Memory_Bridge::retrieve_graph( $args );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return array();
		}

		// Hydrate context_ids into records via the same lookup the response
		// hydrator uses — for the graph stream we attach a minimal record
		// stub so the fusion stage can still extract context_ids and the
		// hydrator can fill in the rest from the canonical store.
		$out = array();
		foreach ( $rows as $row ) {
			$context_id = is_array( $row ) && isset( $row['context_id'] ) ? (string) $row['context_id'] : '';
			if ( '' === $context_id ) {
				continue;
			}
			$out[] = array(
				'context_id'  => $context_id,
				'_graph_meta' => is_array( $row ) ? $row : array(),
			);
		}
		return $out;
	}

	/**
	 * Apply session diversification — at most N records per session_id.
	 *
	 * @since 1.1.20
	 *
	 * @param array<string, float> $scores         Map of context_id => fused score (descending).
	 * @param array<string, array> $records_by_id  Hydrated records keyed by context_id.
	 * @param int                  $cap            Max records per session.
	 * @return array<string, float> Diversified scores in same order.
	 */
	public static function apply_session_diversity( array $scores, array $records_by_id, $cap ) {
		$cap         = max( 1, (int) $cap );
		$per_session = array();
		$out         = array();
		$unique      = 0;

		foreach ( $scores as $context_id => $score ) {
			$record     = isset( $records_by_id[ $context_id ] ) ? $records_by_id[ $context_id ] : array();
			$session_id = self::extract_session_id( $record );

			if ( '' === $session_id ) {
				// Unknown session — treat each record as belonging to its
				// own unique session so the cap never collapses these rows.
				++$unique;
				$session_id = '__rrf_unknown_' . $unique;
			}

			$per_session[ $session_id ] = isset( $per_session[ $session_id ] ) ? $per_session[ $session_id ] + 1 : 1;
			if ( $per_session[ $session_id ] > $cap ) {
				continue;
			}
			$out[ $context_id ] = $score;
		}

		return $out;
	}

	/**
	 * Multiply fused scores by per-record confidence_score.
	 *
	 * Phase 2 schema v2 introduced the field; legacy rows that don't carry
	 * it default to 1.0.
	 *
	 * @since 1.1.20
	 *
	 * @param array<string, float> $scores         Map of context_id => fused score.
	 * @param array<string, array> $records_by_id  Hydrated records keyed by context_id.
	 * @return array<string, float> Weighted scores (still descending).
	 */
	public static function apply_confidence_weighting( array $scores, array $records_by_id ) {
		$weighted = array();
		foreach ( $scores as $context_id => $score ) {
			$record                  = isset( $records_by_id[ $context_id ] ) ? $records_by_id[ $context_id ] : array();
			$confidence              = self::extract_confidence_score( $record );
			$weighted[ $context_id ] = (float) $score * (float) $confidence;
		}
		arsort( $weighted );
		return $weighted;
	}

	/*
	----------------------------------------------------------------------
	 * Internal helpers
	 * -------------------------------------------------------------------
	 */

	/**
	 * Build the final response envelope from fused scores.
	 *
	 * Re-hydrates records from the canonical stores, applies confidence
	 * weighting, then session diversification, slices to $limit, and shapes
	 * the response identically to the legacy `search_context()` path with
	 * `rrf_breakdown` added.
	 *
	 * @param string     $query             Echoed query.
	 * @param int|string $agent_id          Agent identifier.
	 * @param int        $limit             Max results.
	 * @param array      $filters           Filters (for any post-hoc filtering).
	 * @param array      $fused_scores      RRF-fused score map.
	 * @param array      $stream_rankings   Per-stream context_id lists (for rrf_breakdown).
	 * @param array|null $stream_records    Optional pre-built per-stream records, used for hydration shortcut.
	 * @return array
	 */
	protected static function hydrate_response( $query, $agent_id, $limit, $filters, array $fused_scores, array $stream_rankings, $stream_records = null ) {
		if ( empty( $fused_scores ) ) {
			return array(
				'success'  => true,
				'contexts' => array(),
				'count'    => 0,
				'query'    => (string) $query,
				'method'   => 'rrf_hybrid',
			);
		}

		// Build a record hydration cache from any pre-loaded stream rows.
		$records_by_id = array();
		if ( is_array( $stream_records ) ) {
			foreach ( $stream_records as $stream_label => $rows ) {
				if ( ! is_array( $rows ) ) {
					continue;
				}
				foreach ( $rows as $row ) {
					$cid = is_array( $row ) && isset( $row['context_id'] ) ? (string) $row['context_id'] : '';
					if ( '' === $cid || isset( $records_by_id[ $cid ] ) ) {
						continue;
					}
					$records_by_id[ $cid ] = $row;
				}
			}
		}

		// Fill in any missing records via the canonical stores.
		foreach ( array_keys( $fused_scores ) as $cid ) {
			if ( ! isset( $records_by_id[ (string) $cid ] ) ) {
				$rec = self::lookup_record( $agent_id, (string) $cid );
				if ( is_array( $rec ) ) {
					$records_by_id[ (string) $cid ] = $rec;
				}
			}
		}

		// Drop any rows we couldn't hydrate at all.
		$fused_scores = array_intersect_key( $fused_scores, $records_by_id );

		// Confidence weighting.
		$apply_confidence = (bool) apply_filters( 'wp_mcp_ai_memory_rrf_use_confidence', true );
		$final_scores     = $apply_confidence
			? self::apply_confidence_weighting( $fused_scores, $records_by_id )
			: $fused_scores;

		// Session diversification.
		$session_cap = (int) apply_filters( 'wp_mcp_ai_memory_rrf_session_diversity_cap', self::DEFAULT_SESSION_DIVERSITY_CAP );
		$diversified = self::apply_session_diversity( $final_scores, $records_by_id, $session_cap );

		// Top-N slice.
		$top = array_slice( $diversified, 0, max( 1, (int) $limit ), true );

		// Shape the response.
		$contexts = array();
		foreach ( $top as $context_id => $final_score ) {
			$record  = isset( $records_by_id[ $context_id ] ) ? $records_by_id[ $context_id ] : array();
			$fused   = isset( $fused_scores[ $context_id ] ) ? (float) $fused_scores[ $context_id ] : 0.0;
			$conf    = self::extract_confidence_score( $record );
			$session = self::extract_session_id( $record );

			$bm25_rank   = self::rank_in_stream( $stream_rankings, 'bm25', $context_id );
			$vector_rank = self::rank_in_stream( $stream_rankings, 'vector', $context_id );
			$graph_rank  = self::rank_in_stream( $stream_rankings, 'graph', $context_id );

			$contexts[] = array(
				'context_id'       => (string) $context_id,
				'context_type'     => self::extract_field( $record, 'context_type', 'generic' ),
				'title'            => self::extract_data_field( $record, 'title', '' ),
				'content'          => self::extract_data_field( $record, 'content', '' ),
				'importance'       => self::extract_data_field( $record, 'importance', 'medium' ),
				'tags'             => self::extract_tags( $record ),
				'wing'             => self::extract_field( $record, 'wing', '' ),
				'room'             => self::extract_field( $record, 'room', '' ),
				'stored_at'        => self::extract_field( $record, 'stored_at', '' ),
				// Legacy keys preserved at 0.0 — the chat-memory-drawer JS
				// keeps rendering without modification.
				'similarity_score' => 0.0,
				'boost_score'      => 0.0,
				'final_score'      => round( (float) $final_score, 4 ),
				'boost_breakdown'  => array(
					'keyword'     => 0,
					'temporal'    => 0,
					'exact_match' => 0,
				),
				'rrf_breakdown'    => array(
					'bm25_rank'        => $bm25_rank,
					'vector_rank'      => $vector_rank,
					'graph_rank'       => $graph_rank,
					'fused_score'      => round( $fused, 6 ),
					'confidence_score' => round( (float) $conf, 4 ),
					'final_score'      => round( (float) $final_score, 6 ),
					'session_id'       => $session,
				),
			);
		}

		return array(
			'success'  => true,
			'contexts' => $contexts,
			'count'    => count( $contexts ),
			'query'    => (string) $query,
			'method'   => 'rrf_hybrid',
		);
	}

	/**
	 * Look up a record by context_id across the canonical stores.
	 *
	 * @param int|string $agent_id  Agent.
	 * @param string     $context_id Record id.
	 * @return array|null
	 */
	protected static function lookup_record( $agent_id, $context_id ) {
		// Try transient store first (cheap, in-memory).
		if ( class_exists( 'WP_MCP_AI_Agent_Context_Manager' ) ) {
			$mgr = WP_MCP_AI_Agent_Context_Manager::get_instance();
			if ( method_exists( $mgr, 'retrieve_context' ) ) {
				$rec = $mgr->retrieve_context( $agent_id, $context_id, true );
				if ( is_array( $rec ) ) {
					return $rec;
				}
			}
		}

		// Fall back to the CCT mirror (handles transient-cache flushes).
		if ( class_exists( 'WP_MCP_AI_Agent_Memory_CCT_Reader' )
			&& method_exists( 'WP_MCP_AI_Agent_Memory_CCT_Reader', 'get_transient_shaped_records_for_agent' ) ) {
			$rows = WP_MCP_AI_Agent_Memory_CCT_Reader::get_transient_shaped_records_for_agent( $agent_id, 500 );
			foreach ( $rows as $row ) {
				if ( isset( $row['context_id'] ) && (string) $row['context_id'] === (string) $context_id ) {
					return $row;
				}
			}
		}

		return null;
	}

	/**
	 * Pull context_ids out of a record list.
	 *
	 * @param array $records Records.
	 * @return array<int, string>
	 */
	protected static function extract_context_ids( array $records ) {
		$out = array();
		foreach ( $records as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			if ( isset( $r['context_id'] ) && '' !== $r['context_id'] ) {
				$out[] = (string) $r['context_id'];
			}
		}
		return $out;
	}

	/**
	 * 0-based rank lookup or null when absent.
	 *
	 * @param array  $stream_rankings All stream rank lists.
	 * @param string $stream          Stream label.
	 * @param string $context_id      Record id.
	 * @return int|null
	 */
	protected static function rank_in_stream( array $stream_rankings, $stream, $context_id ) {
		if ( ! isset( $stream_rankings[ $stream ] ) || ! is_array( $stream_rankings[ $stream ] ) ) {
			return null;
		}
		$idx = array_search( (string) $context_id, $stream_rankings[ $stream ], true );
		return false === $idx ? null : (int) $idx;
	}

	/**
	 * Map a raw CCT row into a record shape the hydrator can consume.
	 *
	 * @param array $row CCT row.
	 * @return array
	 */
	protected static function map_cct_row_to_record( array $row ) {
		$tags = array();
		if ( isset( $row['tags'] ) ) {
			if ( is_array( $row['tags'] ) ) {
				$tags = $row['tags'];
			} elseif ( is_string( $row['tags'] ) && '' !== $row['tags'] ) {
				$decoded = json_decode( $row['tags'], true );
				$tags    = is_array( $decoded ) ? $decoded : array();
			}
		}

		$metadata = array();
		if ( isset( $row['metadata'] ) ) {
			if ( is_array( $row['metadata'] ) ) {
				$metadata = $row['metadata'];
			} elseif ( is_string( $row['metadata'] ) && '' !== $row['metadata'] ) {
				$decoded  = json_decode( $row['metadata'], true );
				$metadata = is_array( $decoded ) ? $decoded : array();
			}
		}

		return array(
			'context_id'       => isset( $row['context_id'] ) ? (string) $row['context_id'] : '',
			'agent_id'         => isset( $row['agent_id'] ) ? (string) $row['agent_id'] : '',
			'context_type'     => isset( $row['context_type'] ) ? (string) $row['context_type'] : 'generic',
			'wing'             => isset( $row['wing'] ) ? (string) $row['wing'] : '',
			'room'             => isset( $row['room'] ) ? (string) $row['room'] : '',
			'stored_at'        => isset( $row['transaction_time'] ) ? (string) $row['transaction_time'] : '',
			'expires_at'       => isset( $row['expires_at'] ) ? (string) $row['expires_at'] : '',
			'confidence_score' => isset( $row['confidence_score'] ) ? $row['confidence_score'] : '',
			'data'             => array(
				'title'      => isset( $row['title'] ) ? (string) $row['title'] : '',
				'content'    => isset( $row['content'] ) ? (string) $row['content'] : '',
				'tags'       => $tags,
				'importance' => isset( $row['importance'] ) ? (string) $row['importance'] : 'medium',
				'metadata'   => $metadata,
			),
		);
	}

	/**
	 * Post-filter a record set against the optional retrieval filters.
	 *
	 * The CCT BM25 query does not apply wing/room/tag filters server-side
	 * because they're rarely indexed; we apply them here.
	 *
	 * @param array $records Records.
	 * @param array $filters Filters.
	 * @return array
	 */
	protected static function apply_filters_to_records( array $records, array $filters ) {
		if ( empty( $filters ) ) {
			return $records;
		}
		$out = array();
		foreach ( $records as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			if ( ! empty( $filters['wing'] ) ) {
				$wing = isset( $r['wing'] ) ? (string) $r['wing'] : '';
				if ( 0 !== strcasecmp( $wing, (string) $filters['wing'] ) ) {
					continue;
				}
			}
			if ( ! empty( $filters['room'] ) ) {
				$room = isset( $r['room'] ) ? (string) $r['room'] : '';
				if ( 0 !== strcasecmp( $room, (string) $filters['room'] ) ) {
					continue;
				}
			}
			$out[] = $r;
		}
		return $out;
	}

	/**
	 * Pull title+content from a record for keyword scoring.
	 *
	 * @param array $record Record.
	 * @return string
	 */
	protected static function extract_text( $record ) {
		if ( ! is_array( $record ) ) {
			return '';
		}
		$title   = isset( $record['data']['title'] ) ? (string) $record['data']['title'] : '';
		$content = isset( $record['data']['content'] ) ? (string) $record['data']['content'] : '';
		if ( '' === $title && '' === $content ) {
			// CCT-row shape.
			$title   = isset( $record['title'] ) ? (string) $record['title'] : '';
			$content = isset( $record['content'] ) ? (string) $record['content'] : '';
		}
		return trim( $title . ' ' . $content );
	}

	/**
	 * Tokenise a query string (lowercase, words >= 2 chars).
	 *
	 * @param string $query Query.
	 * @return array<int, string>
	 */
	protected static function tokenise_query( $query ) {
		$query = strtolower( (string) $query );
		$parts = preg_split( '/[^a-z0-9_\\-]+/i', $query );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$tokens = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( '' === $p || strlen( $p ) < 2 ) {
				continue;
			}
			$tokens[] = $p;
		}
		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Very lightweight entity extraction — tokens >= 4 chars, deduped.
	 *
	 * @param string $query Query.
	 * @return array<int, string>
	 */
	protected static function extract_entities( $query ) {
		$tokens = self::tokenise_query( $query );
		$out    = array();
		foreach ( $tokens as $t ) {
			if ( strlen( $t ) >= 4 ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	/**
	 * Pull the confidence_score off a record with documented fallbacks.
	 *
	 * @param array $record Record.
	 * @return float [0.0, 1.0]
	 */
	protected static function extract_confidence_score( $record ) {
		if ( ! is_array( $record ) ) {
			return 1.0;
		}
		$candidates = array();
		if ( isset( $record['confidence_score'] ) ) {
			$candidates[] = $record['confidence_score'];
		}
		if ( isset( $record['data']['confidence_score'] ) ) {
			$candidates[] = $record['data']['confidence_score'];
		}
		if ( isset( $record['data']['metadata']['confidence_score'] ) ) {
			$candidates[] = $record['data']['metadata']['confidence_score'];
		}
		if ( isset( $record['metadata']['confidence_score'] ) ) {
			$candidates[] = $record['metadata']['confidence_score'];
		}
		foreach ( $candidates as $c ) {
			if ( '' === $c || null === $c ) {
				continue;
			}
			$f = (float) $c;
			if ( $f < 0.0 ) {
				$f = 0.0;
			}
			if ( $f > 1.0 ) {
				$f = 1.0;
			}
			return $f;
		}
		return 1.0;
	}

	/**
	 * Pull session_id off a record metadata bag.
	 *
	 * @param array $record Record.
	 * @return string Session id or '' when unknown.
	 */
	protected static function extract_session_id( $record ) {
		if ( ! is_array( $record ) ) {
			return '';
		}
		// Common locations.
		$paths = array(
			array( 'metadata', 'session_id' ),
			array( 'data', 'metadata', 'session_id' ),
			array( 'metadata', 'context', 'session_id' ),
			array( 'data', 'metadata', 'context', 'session_id' ),
			array( 'session_id' ),
		);
		foreach ( $paths as $path ) {
			$cursor = $record;
			$ok     = true;
			foreach ( $path as $key ) {
				if ( ! is_array( $cursor ) || ! array_key_exists( $key, $cursor ) ) {
					$ok = false;
					break;
				}
				$cursor = $cursor[ $key ];
			}
			if ( $ok && is_scalar( $cursor ) && '' !== (string) $cursor ) {
				return (string) $cursor;
			}
		}
		return '';
	}

	/**
	 * Read a top-level record field with default.
	 *
	 * @param array  $record   Record.
	 * @param string $key      Field key.
	 * @param mixed  $default_value Default.
	 * @return mixed
	 */
	protected static function extract_field( $record, $key, $default_value ) {
		if ( is_array( $record ) && isset( $record[ $key ] ) ) {
			return $record[ $key ];
		}
		return $default_value;
	}

	/**
	 * Read a `data.*` record field with default.
	 *
	 * @param array  $record   Record.
	 * @param string $key      Field key under `data`.
	 * @param mixed  $default_value Default.
	 * @return mixed
	 */
	protected static function extract_data_field( $record, $key, $default_value ) {
		if ( is_array( $record ) && isset( $record['data'][ $key ] ) ) {
			return $record['data'][ $key ];
		}
		// Support flat CCT-row shape (no nested `data`).
		if ( is_array( $record ) && isset( $record[ $key ] ) ) {
			return $record[ $key ];
		}
		return $default_value;
	}

	/**
	 * Pull the tag list off a record with shape normalisation.
	 *
	 * @param array $record Record.
	 * @return array<int, string>
	 */
	protected static function extract_tags( $record ) {
		$raw = self::extract_data_field( $record, 'tags', array() );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $t ) {
			if ( is_scalar( $t ) && '' !== (string) $t ) {
				$out[] = (string) $t;
			}
		}
		return $out;
	}
}
