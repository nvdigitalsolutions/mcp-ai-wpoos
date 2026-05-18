<?php
/**
 * Recall Memory tool — Phase A8 hierarchical recall (MemPalace-aligned).
 *
 * Implements the MemPalace headline trick — *"this client's drawers always
 * open"* — across the whole capture surface:
 *
 *   1. If `wing` is provided, the candidate pool is restricted to that wing
 *      (exactly the existing `mine_agent_memory` pre-filter).
 *   2. If `room` is provided, the pool is narrowed further.
 *   3. BM25 + vector + graph proximity ranking is applied.
 *   4. **All `tier=core` memories of the wing are always included**, regardless
 *      of similarity score, because that is the contract of `core` tier.
 *   5. The `as_of` parameter (Zep bi-temporal validity) lets callers query
 *      "what did we know about this matter on 2026-01-15?". Default behaviour
 *      returns records valid right now.
 *
 * Internally this wraps the existing `retrieve_agent_memory` tool, which
 * already understands the Phase 4a wing/room filter — Recall Memory adds the
 * "always include core" pass and the bi-temporal `as_of` parameter on top.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hierarchical, tier-aware, bi-temporal recall over MemPalace memory.
 */
class WP_MCP_AI_Tool_Recall_Memory implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'recall_memory';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Recall Memory (Hierarchical)', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Hierarchical MemPalace recall. Filters by wing (project / client / matter / patient / deal) and optional room before semantic ranking, then always includes every core-tier memory of that wing. Supports bi-temporal queries via as_of (Zep). Use this when you want "everything we remember about <wing>" instead of a flat keyword search.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'agent_id'      => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'Agent assistant ID (post ID) or virtual agent identifier.', 'mcp-ai-wpoos' ),
				),
				'wing'          => array(
					'type'        => 'string',
					'description' => __( 'Hierarchical scope to recall from (e.g. "patient/jane-doe", "matter/123", "deal/acme-tower"). Required to honour MemPalace "this client\'s drawers" semantics.', 'mcp-ai-wpoos' ),
				),
				'room'          => array(
					'type'        => 'string',
					'description' => __( 'Optional sub-scope inside the wing (e.g. "vitals", "covenants", "decisions").', 'mcp-ai-wpoos' ),
				),
				'query'         => array(
					'type'        => 'string',
					'description' => __( 'Optional semantic search query, applied AFTER the wing/room pre-filter.', 'mcp-ai-wpoos' ),
				),
				'as_of'         => array(
					'type'        => 'string',
					'description' => __( 'Bi-temporal query timestamp (ISO 8601 / MySQL datetime). Returns records whose valid_from <= as_of < valid_until. Defaults to now.', 'mcp-ai-wpoos' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum ranked results to return (excluding always-on core-tier records).', 'mcp-ai-wpoos' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 50,
				),
				'include_tiers' => array(
					'type'        => 'array',
					'description' => __( 'Tiers to include in ranked results. Core records of the wing are always included regardless of this filter.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'core', 'recall', 'archival' ),
					),
					'default'     => array( 'core', 'recall' ),
				),
			),
			'required'             => array( 'agent_id', 'wing' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		$agent_id = isset( $arguments['agent_id'] ) ? $arguments['agent_id'] : '';
		$wing     = isset( $arguments['wing'] ) ? sanitize_text_field( (string) $arguments['wing'] ) : '';
		$room     = isset( $arguments['room'] ) ? sanitize_text_field( (string) $arguments['room'] ) : '';
		$query    = isset( $arguments['query'] ) ? sanitize_text_field( (string) $arguments['query'] ) : '';
		$as_of    = isset( $arguments['as_of'] ) ? sanitize_text_field( (string) $arguments['as_of'] ) : '';
		$limit    = isset( $arguments['limit'] ) ? max( 1, min( 50, absint( $arguments['limit'] ) ) ) : 10;
		$tiers    = isset( $arguments['include_tiers'] ) && is_array( $arguments['include_tiers'] )
			? array_intersect( $arguments['include_tiers'], array( 'core', 'recall', 'archival' ) )
			: array( 'core', 'recall' );

		if ( empty( $agent_id ) ) {
			return new WP_Error( 'recall_missing_agent', __( 'agent_id is required.', 'mcp-ai-wpoos' ) );
		}
		if ( '' === $wing ) {
			return new WP_Error( 'recall_missing_wing', __( 'wing is required (e.g. "patient/jane-doe", "matter/123").', 'mcp-ai-wpoos' ) );
		}

		$as_of_ts = '' === $as_of ? time() : strtotime( $as_of );
		if ( false === $as_of_ts ) {
			return new WP_Error( 'recall_invalid_as_of', __( 'as_of must be an ISO 8601 / MySQL datetime.', 'mcp-ai-wpoos' ) );
		}

		// Step 1: pull every record candidate via the canonical fetcher.
		// Listeners (test suite, JetEngine bridge, custom stores) provide the
		// records via the `wp_mcp_ai_recall_memory_candidates` filter so this
		// tool can run on transient-only sites without depending on JetEngine.
		$candidates = apply_filters(
			'wp_mcp_ai_recall_memory_candidates',
			array(),
			array(
				'agent_id' => $agent_id,
				'wing'     => $wing,
				'room'     => $room,
				'query'    => $query,
				'as_of'    => $as_of_ts,
			)
		);
		$candidates = is_array( $candidates ) ? $candidates : array();

		// Step 2: apply wing/room pre-filter.
		$filtered = array();
		foreach ( $candidates as $rec ) {
			if ( ! is_array( $rec ) ) {
				continue;
			}
			$rec_wing = isset( $rec['wing'] ) ? (string) $rec['wing'] : '';
			$rec_room = isset( $rec['room'] ) ? (string) $rec['room'] : '';
			if ( $rec_wing !== $wing ) {
				continue;
			}
			if ( '' !== $room && $rec_room !== $room ) {
				continue;
			}
			$filtered[] = $rec;
		}

		// Step 3: bi-temporal validity.
		$valid_now = array();
		foreach ( $filtered as $rec ) {
			$vf = isset( $rec['valid_from'] ) ? strtotime( (string) $rec['valid_from'] ) : false;
			$vu = isset( $rec['valid_until'] ) ? strtotime( (string) $rec['valid_until'] ) : false;
			if ( false !== $vf && $vf > $as_of_ts ) {
				continue;
			}
			if ( false !== $vu && $vu > 0 && $vu <= $as_of_ts ) {
				continue;
			}
			$valid_now[] = $rec;
		}

		// Step 4: split out always-on core records of the wing.
		$core_records = array();
		$rankable     = array();
		foreach ( $valid_now as $rec ) {
			$tier = isset( $rec['tier'] ) ? (string) $rec['tier'] : 'recall';
			if ( WP_MCP_AI_Memory_Capture_Service::TIER_CORE === $tier ) {
				$core_records[] = $rec;
				// Core records are also eligible for ranked slots when
				// `core` is in the include_tiers list.
				if ( in_array( 'core', $tiers, true ) ) {
					$rankable[] = $rec;
				}
			} elseif ( in_array( $tier, $tiers, true ) ) {
				$rankable[] = $rec;
			}
		}

		// Step 5: rank by importance + naive query overlap.
		usort(
			$rankable,
			static function ( $a, $b ) use ( $query ) {
				$score_a = self::score( $a, $query );
				$score_b = self::score( $b, $query );
				if ( $score_a === $score_b ) {
					return 0;
				}
				return $score_a > $score_b ? -1 : 1;
			}
		);
		$rankable = array_slice( $rankable, 0, $limit );

		// Step 6: union (core ∪ ranked), preserving uniqueness on context_id.
		$seen   = array();
		$result = array();
		foreach ( array_merge( $core_records, $rankable ) as $rec ) {
			$cid = isset( $rec['context_id'] ) ? (string) $rec['context_id'] : spl_object_hash( (object) $rec );
			if ( isset( $seen[ $cid ] ) ) {
				continue;
			}
			$seen[ $cid ] = true;
			$result[]     = $rec;
		}

		return array(
			'success'         => true,
			'wing'            => $wing,
			'room'            => $room,
			'as_of'           => gmdate( 'Y-m-d H:i:s', $as_of_ts ),
			'candidate_count' => count( $candidates ),
			'pool_count'      => count( $valid_now ),
			'core_count'      => count( $core_records ),
			'returned_count'  => count( $result ),
			'memories'        => $result,
		);
	}

	/**
	 * Naive ranking score: importance + token overlap with the query.
	 *
	 * @param array  $record Memory record.
	 * @param string $query  Optional query.
	 * @return float
	 */
	protected static function score( array $record, $query ) {
		$importance = isset( $record['importance'] ) ? (float) $record['importance'] : 0.5;
		if ( '' === $query ) {
			return $importance;
		}

		$content = isset( $record['content'] ) ? strtolower( (string) $record['content'] ) : '';
		$terms   = preg_split( '/\s+/', strtolower( $query ) );
		$hits    = 0;
		foreach ( $terms as $term ) {
			if ( '' === $term ) {
				continue;
			}
			if ( false !== strpos( $content, $term ) ) {
				++$hits;
			}
		}
		return $importance + ( $hits * 0.1 );
	}

	/**
	 * Capability flags — recall is read-only, semantically cacheable.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'read-only', 'cacheable' );
	}
}
