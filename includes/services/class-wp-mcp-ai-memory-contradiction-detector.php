<?php
/**
 * Memory Contradiction Detector — Phase 5 of the 2026 Memory Layer Enhancements.
 *
 * Detects when a newly-stored memory record materially disagrees with an
 * existing record so the chat surface (Phase 7a Memory Health UI) can warn the
 * operator, and — when opted in — so that the older record can be marked
 * `superseded_by` the new one.
 *
 * Detection signal is the union of three checks against the top-K candidates
 * returned by Phase 4's RRF fusion (or, when RRF is not yet loaded, a recall
 * candidate filter that mirrors the same pool):
 *
 *   1. **Semantic similarity** — candidate must be cosine-similar enough that
 *      its content is "about the same thing" as the new record. The threshold
 *      filters out unrelated rows that happened to share a word or two.
 *   2. **Key/value conflict** — when both records carry a structured
 *      `metadata.key` field with different `metadata.value` values, the older
 *      record is flagged as a contradiction.
 *   3. **Title-match + content diverges** — same title (case-insensitive)
 *      with Jaccard token similarity below a tuneable threshold flags the
 *      older record as a contradiction.
 *
 * Default behaviour is **detection only** — the detector emits the
 * `wp_mcp_ai_memory_contradiction_detected` action but does NOT mutate any
 * stored record. Auto-supersession is gated by an off-by-default filter
 * because supersession demotes the older record to `archival` (effectively
 * removing it from active recall), which is a data-destruction-adjacent
 * operation. The Phase 7a Memory Health UI surfaces detection events and
 * offers manual resolution before this becomes safe to enable site-wide.
 *
 * @link    https://github.com/rohitg00/agentmemory
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
 * Contradiction detection service.
 *
 * Singleton. Stateless across requests. `bootstrap()` is idempotent so the
 * loader sequence can re-call it safely.
 */
class WP_MCP_AI_Memory_Contradiction_Detector {

	/**
	 * Default top-K candidates examined per detection pass.
	 */
	const DEFAULT_TOP_K = 3;

	/**
	 * Default cosine-similarity threshold. Pairs below this score are
	 * considered semantically unrelated and skipped.
	 */
	const DEFAULT_SIMILARITY_THRESHOLD = 0.85;

	/**
	 * Default Jaccard threshold. Below this, two same-title records are
	 * considered "content diverges" and the older record is flagged.
	 */
	const DEFAULT_JACCARD_THRESHOLD = 0.4;

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Memory_Contradiction_Detector|null
	 */
	private static $instance = null;

	/**
	 * Guard against double-bootstrap when the loader re-includes the file.
	 *
	 * @var bool
	 */
	private static $bootstrapped = false;

	/**
	 * Retrieve the singleton instance.
	 *
	 * @return WP_MCP_AI_Memory_Contradiction_Detector
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Idempotent bootstrap. Currently a no-op aside from priming the
	 * singleton — detection is invoked imperatively from
	 * `store_agent_context`, not via a passive WordPress hook — but
	 * declared for parity with the rest of the Phase-by-Phase service set.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;
		self::get_instance();
	}

	/**
	 * Detect contradictions between a new memory record and the existing store.
	 *
	 * @since 1.1.20
	 *
	 * @param array<string,mixed> $new_record Normalised envelope. Recognised keys:
	 *                                       context_id, agent_id, wing, room,
	 *                                       title, content, metadata (array).
	 * @return array<int,array<string,mixed>> List of contradictions. Each entry:
	 *   - existing_context_id (string)
	 *   - new_context_id      (string)
	 *   - reason              (string) `key_value_conflict` | `title_match_content_diverges`
	 *   - similarity          (float)
	 */
	public function detect( $new_record ) {
		if ( ! (bool) apply_filters( 'wp_mcp_ai_memory_contradiction_detection_enabled', true ) ) {
			return array();
		}

		$new_record = is_array( $new_record ) ? $new_record : array();
		if ( empty( $new_record['content'] ) && empty( $new_record['title'] ) ) {
			return array();
		}

		$top_k             = max( 1, (int) apply_filters( 'wp_mcp_ai_memory_contradiction_top_k', self::DEFAULT_TOP_K ) );
		$sim_threshold     = (float) apply_filters( 'wp_mcp_ai_memory_contradiction_similarity_threshold', self::DEFAULT_SIMILARITY_THRESHOLD );
		$jaccard_threshold = (float) apply_filters( 'wp_mcp_ai_memory_contradiction_jaccard_threshold', self::DEFAULT_JACCARD_THRESHOLD );

		$candidates = $this->gather_candidates( $new_record, $top_k );
		if ( empty( $candidates ) ) {
			return array();
		}

		// Cap *strictly* at top_k so the detector never scans more of the corpus
		// than the filter promised, regardless of how generous the candidate
		// provider was.
		if ( count( $candidates ) > $top_k ) {
			$candidates = array_slice( $candidates, 0, $top_k );
		}

		$new_context_id = isset( $new_record['context_id'] ) ? (string) $new_record['context_id'] : '';
		$auto_supersede = (bool) apply_filters( 'wp_mcp_ai_memory_contradiction_auto_supersede', false );

		$contradictions = array();
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$existing_context_id = isset( $candidate['context_id'] ) ? (string) $candidate['context_id'] : '';
			if ( '' === $existing_context_id || $existing_context_id === $new_context_id ) {
				// Don't compare a record against itself — happens when callers
				// pass the new record through the same filter pool.
				continue;
			}

			$similarity = $this->resolve_similarity( $new_record, $candidate );
			if ( $similarity <= $sim_threshold ) {
				continue;
			}

			$reason = $this->classify_conflict( $new_record, $candidate, $jaccard_threshold );
			if ( null === $reason ) {
				continue;
			}

			/**
			 * Fires when the detector flags a candidate as contradicting the
			 * new record. Listeners can use this to populate the Phase 7a
			 * Memory Health UI without consuming the return value.
			 *
			 * @since 1.1.20
			 *
			 * @param string $existing_context_id Older record's context_id.
			 * @param string $new_context_id      Newly stored record's context_id.
			 * @param string $reason              One of `key_value_conflict`,
			 *                                    `title_match_content_diverges`.
			 */
			do_action( 'wp_mcp_ai_memory_contradiction_detected', $existing_context_id, $new_context_id, $reason );

			$contradictions[] = array(
				'existing_context_id' => $existing_context_id,
				'new_context_id'      => $new_context_id,
				'reason'              => $reason,
				'similarity'          => round( $similarity, 4 ),
			);

			if ( $auto_supersede ) {
				$this->resolve_supersession( $candidate, $new_record );
			}
		}

		return $contradictions;
	}

	/**
	 * Gather candidate records for comparison.
	 *
	 * Resolution chain:
	 *   1. `wp_mcp_ai_memory_contradiction_candidates` filter (highest precedence).
	 *   2. Phase 4 RRF fusion service when loaded.
	 *   3. The recall_memory candidate filter pool (which already includes the
	 *      CCT mirror via {@see WP_MCP_AI_Agent_Memory_CCT_Reader}).
	 *
	 * @since 1.1.20
	 *
	 * @param array<string,mixed> $new_record Normalised envelope.
	 * @param int                 $top_k      Top-K cap to request from RRF.
	 * @return array<int,array<string,mixed>>
	 */
	protected function gather_candidates( array $new_record, $top_k ) {
		$candidates = array();

		// Phase 4 RRF service (when present).
		if ( class_exists( 'WP_MCP_AI_Memory_RRF_Fusion_Service' ) && ! empty( $new_record['content'] ) ) {
			try {
				$service = call_user_func( array( 'WP_MCP_AI_Memory_RRF_Fusion_Service', 'get_instance' ) );
				if ( is_object( $service ) && method_exists( $service, 'search' ) ) {
					$result = $service->search(
						(string) $new_record['content'],
						array(
							'agent_id' => isset( $new_record['agent_id'] ) ? $new_record['agent_id'] : '',
							'wing'     => isset( $new_record['wing'] ) ? (string) $new_record['wing'] : '',
							'room'     => isset( $new_record['room'] ) ? (string) $new_record['room'] : '',
							'limit'    => (int) $top_k * 2, // Over-fetch; we cap later.
						)
					);
					if ( is_array( $result ) ) {
						$candidates = $result;
					}
				}
			} catch ( Throwable $exception ) {
				if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
					WP_MCP_AI_Logger::log_warning(
						'Contradiction detector: RRF lookup threw; falling back to recall candidates.',
						array( 'message' => $exception->getMessage() )
					);
				}
				$candidates = array();
			}
		}

		// Fallback to the recall_memory candidate pool when RRF isn't available
		// (Phase 4 ships in parallel; Phase 5 must work even before that PR
		// lands).
		if ( empty( $candidates ) ) {
			/** This filter is documented in includes/tools/class-wp-mcp-ai-tool-recall-memory.php */
			$pool       = apply_filters(
				'wp_mcp_ai_recall_memory_candidates',
				array(),
				array(
					'agent_id' => isset( $new_record['agent_id'] ) ? $new_record['agent_id'] : '',
					'wing'     => isset( $new_record['wing'] ) ? (string) $new_record['wing'] : '',
					'room'     => isset( $new_record['room'] ) ? (string) $new_record['room'] : '',
				)
			);
			$candidates = is_array( $pool ) ? $pool : array();
		}

		/**
		 * Filter the candidate list considered for contradiction detection.
		 *
		 * Listeners receive the candidates resolved from RRF / the recall pool
		 * and can replace, augment, or pre-score them with a `similarity` key
		 * (in `[0, 1]`) that the detector will respect instead of computing
		 * its own fallback similarity.
		 *
		 * @since 1.1.20
		 *
		 * @param array $candidates Resolved candidate records.
		 * @param array $new_record New memory record being stored.
		 * @param int   $top_k      Effective top-K cap.
		 */
		$candidates = apply_filters( 'wp_mcp_ai_memory_contradiction_candidates', $candidates, $new_record, $top_k );

		return is_array( $candidates ) ? $candidates : array();
	}

	/**
	 * Resolve the similarity score for a (new, candidate) pair.
	 *
	 * Preference order:
	 *   1. Pre-computed `similarity` key on the candidate (RRF / test fixtures).
	 *   2. Pre-computed `final` key on the candidate (legacy vector service
	 *      shape).
	 *   3. Token-Jaccard similarity over normalised content as a portable
	 *      fallback when no vector pipeline is wired up.
	 *
	 * The fallback intentionally errs on the side of returning a *low* score
	 * so that disabled vector pipelines don't generate spurious contradiction
	 * events.
	 *
	 * @since 1.1.20
	 *
	 * @param array<string,mixed> $new_record New memory record.
	 * @param array<string,mixed> $candidate  Candidate row.
	 * @return float Similarity in `[0, 1]`.
	 */
	protected function resolve_similarity( array $new_record, array $candidate ) {
		foreach ( array( 'similarity', 'final', 'similarity_score', 'rrf_score' ) as $key ) {
			if ( isset( $candidate[ $key ] ) && is_numeric( $candidate[ $key ] ) ) {
				return max( 0.0, min( 1.0, (float) $candidate[ $key ] ) );
			}
		}

		// Portable fallback: tokenised Jaccard. Returns 0 when either side has
		// no tokens, which is the desired behaviour because contradiction
		// detection MUST NOT fire on records that haven't been semantically
		// compared by an embeddings pipeline yet.
		return $this->jaccard_token_similarity(
			isset( $new_record['content'] ) ? (string) $new_record['content'] : '',
			$this->candidate_content( $candidate )
		);
	}

	/**
	 * Determine the contradiction reason for a candidate, or null when no
	 * conflict is present.
	 *
	 * @since 1.1.20
	 *
	 * @param array<string,mixed> $new_record        New memory record.
	 * @param array<string,mixed> $candidate         Candidate row.
	 * @param float               $jaccard_threshold Jaccard threshold for title-match conflicts.
	 * @return string|null One of the recognised reason keys, or null.
	 */
	protected function classify_conflict( array $new_record, array $candidate, $jaccard_threshold ) {
		$new_meta       = $this->extract_metadata( $new_record );
		$candidate_meta = $this->extract_metadata( $candidate );

		if ( isset( $new_meta['key'], $candidate_meta['key'], $new_meta['value'], $candidate_meta['value'] )
			&& '' !== (string) $new_meta['key']
			&& (string) $new_meta['key'] === (string) $candidate_meta['key']
			&& (string) $new_meta['value'] !== (string) $candidate_meta['value']
		) {
			return 'key_value_conflict';
		}

		$new_title       = isset( $new_record['title'] ) ? (string) $new_record['title'] : '';
		$candidate_title = isset( $candidate['title'] ) ? (string) $candidate['title'] : '';
		if ( '' !== $new_title && 0 === strcasecmp( $new_title, $candidate_title ) ) {
			$jaccard = $this->jaccard_token_similarity(
				isset( $new_record['content'] ) ? (string) $new_record['content'] : '',
				$this->candidate_content( $candidate )
			);
			if ( $jaccard < (float) $jaccard_threshold ) {
				return 'title_match_content_diverges';
			}
		}

		return null;
	}

	/**
	 * Apply auto-supersession to a flagged candidate.
	 *
	 * Updates the existing CCT row's `superseded_by` field and demotes the
	 * `memory_tier` to `archival`. Best-effort — silently no-ops when
	 * JetEngine isn't available; the resolution event still fires so listeners
	 * (audit logs, tests) can observe the decision.
	 *
	 * @since 1.1.20
	 *
	 * @param array<string,mixed> $existing   Candidate record (the loser).
	 * @param array<string,mixed> $new_record New memory record (the winner).
	 * @return void
	 */
	protected function resolve_supersession( array $existing, array $new_record ) {
		$existing_context_id = isset( $existing['context_id'] ) ? (string) $existing['context_id'] : '';
		$new_context_id      = isset( $new_record['context_id'] ) ? (string) $new_record['context_id'] : '';

		if ( '' === $existing_context_id || '' === $new_context_id ) {
			return;
		}

		/**
		 * Fires when the detector finishes the supersession path. Note this is
		 * a different action from `wp_mcp_ai_memory_contradiction_detected` —
		 * `detected` fires for every contradiction even when auto-supersession
		 * is off, whereas `resolved` only fires when auto-supersession ran.
		 *
		 * @since 1.1.20
		 *
		 * @param string $existing_context_id Older record's context_id.
		 * @param string $new_context_id      Newly stored record's context_id.
		 */
		do_action( 'wp_mcp_ai_memory_contradiction_resolved', $existing_context_id, $new_context_id );

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return;
		}

		$handler = WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_item_handler();
		if ( ! is_object( $handler ) || ! method_exists( $handler, 'update_item' ) ) {
			return;
		}

		$row_id = isset( $existing['_ID'] ) ? (int) $existing['_ID'] : 0;
		if ( $row_id <= 0 ) {
			$row_id = $this->resolve_row_id( $existing_context_id );
		}
		if ( $row_id <= 0 ) {
			return;
		}

		try {
			$result = $handler->update_item(
				array(
					'_ID'           => $row_id,
					'superseded_by' => $new_context_id,
					'memory_tier'   => class_exists( 'WP_MCP_AI_Memory_Capture_Service' )
						? WP_MCP_AI_Memory_Capture_Service::TIER_ARCHIVAL
						: 'archival',
				)
			);
			if ( is_wp_error( $result ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_warning(
					'Contradiction detector: supersession CCT update failed.',
					array(
						'existing_context_id' => $existing_context_id,
						'new_context_id'      => $new_context_id,
						'error_code'          => $result->get_error_code(),
					)
				);
			}
		} catch ( Throwable $exception ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_warning(
					'Contradiction detector: supersession threw during CCT update.',
					array(
						'existing_context_id' => $existing_context_id,
						'message'             => $exception->getMessage(),
					)
				);
			}
		}
	}

	/**
	 * Read a candidate's content, decoding from common storage shapes.
	 *
	 * Accepts:
	 *   - Flat shape: `$candidate['content']` (string).
	 *   - Nested shape: `$candidate['data']['content']` (legacy transient
	 *     record shape used by `retrieve_agent_memory`).
	 *
	 * @since 1.1.20
	 *
	 * @param array<string,mixed> $candidate Candidate row.
	 * @return string Content string (possibly empty).
	 */
	protected function candidate_content( array $candidate ) {
		if ( isset( $candidate['content'] ) && is_scalar( $candidate['content'] ) ) {
			return (string) $candidate['content'];
		}
		if ( isset( $candidate['data']['content'] ) && is_scalar( $candidate['data']['content'] ) ) {
			return (string) $candidate['data']['content'];
		}
		return '';
	}

	/**
	 * Extract a metadata array from a record, decoding JSON when necessary.
	 *
	 * Records mirrored from CCT carry `metadata` as a JSON-encoded string;
	 * records still in their normalised envelope carry it as a native array.
	 * Both shapes flow through the same store path, so the detector must
	 * accept either.
	 *
	 * @since 1.1.20
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array<string,mixed> Decoded metadata (possibly empty).
	 */
	protected function extract_metadata( array $record ) {
		// Direct `metadata` field.
		if ( isset( $record['metadata'] ) ) {
			if ( is_array( $record['metadata'] ) ) {
				return $record['metadata'];
			}
			if ( is_string( $record['metadata'] ) && '' !== $record['metadata'] ) {
				$decoded = json_decode( $record['metadata'], true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}
		// Legacy nested shape.
		if ( isset( $record['data']['metadata'] ) ) {
			if ( is_array( $record['data']['metadata'] ) ) {
				return $record['data']['metadata'];
			}
			if ( is_string( $record['data']['metadata'] ) && '' !== $record['data']['metadata'] ) {
				$decoded = json_decode( $record['data']['metadata'], true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}
		return array();
	}

	/**
	 * Token-level Jaccard similarity over two strings.
	 *
	 * Tokenisation: lower-cased, split on any non-alphanumeric run, deduped.
	 * Returns 0.0 when either side has no tokens.
	 *
	 * @since 1.1.20
	 *
	 * @param string $a First string.
	 * @param string $b Second string.
	 * @return float Jaccard score in `[0, 1]`.
	 */
	protected function jaccard_token_similarity( $a, $b ) {
		$tokens_a = $this->tokenise( $a );
		$tokens_b = $this->tokenise( $b );

		if ( empty( $tokens_a ) || empty( $tokens_b ) ) {
			return 0.0;
		}

		$intersection = array_intersect( $tokens_a, $tokens_b );
		$union        = array_unique( array_merge( $tokens_a, $tokens_b ) );

		if ( empty( $union ) ) {
			return 0.0;
		}

		return (float) count( $intersection ) / (float) count( $union );
	}

	/**
	 * Tokenise a string into a deduped list of lower-cased alphanumeric tokens.
	 *
	 * @since 1.1.20
	 *
	 * @param string $text Input text.
	 * @return array<int,string>
	 */
	protected function tokenise( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return array();
		}
		if ( function_exists( 'mb_strtolower' ) ) {
			$text = mb_strtolower( $text, 'UTF-8' );
		} else {
			$text = strtolower( $text );
		}
		$parts = preg_split( '/[^a-z0-9]+/u', $text );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$tokens = array();
		foreach ( $parts as $token ) {
			if ( '' !== $token ) {
				$tokens[ $token ] = true;
			}
		}
		return array_keys( $tokens );
	}

	/**
	 * Resolve a CCT row id for a context_id without depending on private
	 * helpers in the bridge / tier manager.
	 *
	 * @since 1.1.20
	 *
	 * @param string $context_id Memory context identifier.
	 * @return int Row id, or 0 when not found.
	 */
	protected function resolve_row_id( $context_id ) {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return 0;
		}

		global $wpdb;
		$slug  = WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_slug();
		$table = $wpdb->prefix . 'jet_cct_' . $slug;

		$suppress_state = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name composed from a trusted slug + $wpdb->prefix; value passed via prepare().
		$row_id = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name trusted (see comment above).
				"SELECT _ID FROM `{$table}` WHERE context_id = %s LIMIT 1",
				$context_id
			)
		);

		$wpdb->suppress_errors( $suppress_state );

		return $row_id ? (int) $row_id : 0;
	}
}
