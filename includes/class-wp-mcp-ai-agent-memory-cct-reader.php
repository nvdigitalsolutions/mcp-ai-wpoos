<?php
/**
 * Read-side companion to {@see WP_MCP_AI_Agent_Memory_CCT_Bridge}.
 *
 * The bridge mirrors every successful memory write into the durable
 * `{prefix}jet_cct_ai_agent_memories` Custom Content Type table. Until this
 * reader landed, **nothing read those rows back into the chat surface**:
 *
 *   - `WP_MCP_AI_Tool_Recall_Memory` pulled candidates exclusively from the
 *     `wp_mcp_ai_recall_memory_candidates` filter, which has zero production
 *     listeners — only the test suite subscribes — so the drawer's recall
 *     pane always rendered empty regardless of how many CCT rows existed.
 *   - `WP_MCP_AI_Tool_Retrieve_Agent_Memory` read only the per-agent transient
 *     index, so the moment Redis flushed or the object cache evicted the
 *     index transient, the drawer went blank even though the CCT row
 *     survived.
 *
 * This class fixes both paths additively:
 *
 *   1. {@see on_recall_memory_candidates()} hooks the recall filter and
 *      returns CCT-backed candidate rows in exactly the shape
 *      `recall_memory` expects (wing / room / tier / content / importance /
 *      valid_from / valid_until / etc.). Existing candidates passed in are
 *      preserved so test fixtures that pre-seed the filter keep working.
 *   2. {@see get_transient_shaped_records_for_agent()} is a public static
 *      helper consumed by `retrieve_agent_memory`'s CCT fallback when its
 *      transient index is empty. It returns records in the
 *      `transient_record` shape that
 *      {@see WP_MCP_AI_Tool_Retrieve_Agent_Memory::format_context_result()}
 *      already understands.
 *
 * Safety:
 *
 *   - All direct SQL is funnelled through one helper, uses `$wpdb->prepare()`
 *     exclusively, and treats query errors as "table missing" so a half-
 *     provisioned site silently degrades to an empty candidate pool instead
 *     of raising. `SHOW TABLES LIKE` was considered but rejected because it
 *     does not list MySQL TEMPORARY tables (used by the WordPress PHPUnit
 *     harness for test isolation), which would prevent the test suite from
 *     exercising this path at all.
 *   - The candidate cap is filterable
 *     ({@see wp_mcp_ai_agent_memory_cct_reader_limit}) and defaults to 500
 *     because `recall_memory` applies its own wing / room / tier / bi-temporal
 *     filtering on top — the cap exists purely to bound worst-case memory
 *     use on agents with very large memory stores.
 *
 * @package WP_MCP_AI
 * @since 1.6.1
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hydrate recall_memory / retrieve_agent_memory from the durable CCT.
 */
class WP_MCP_AI_Agent_Memory_CCT_Reader {

	/**
	 * Default candidate cap. `recall_memory` re-filters by wing/room/tier so
	 * a generous slice is fine; the cap exists to bound worst-case memory
	 * use on agents with multi-thousand-row stores.
	 */
	const DEFAULT_LIMIT = 500;

	/**
	 * Hook into the recall candidate filter.
	 */
	public static function bootstrap() {
		add_filter( 'wp_mcp_ai_recall_memory_candidates', array( __CLASS__, 'on_recall_memory_candidates' ), 10, 2 );
	}

	/**
	 * Filter callback for `wp_mcp_ai_recall_memory_candidates`.
	 *
	 * Merges CCT-backed rows into whatever candidates are already in the
	 * filter input (test fixtures, custom stores, etc.). De-duplicates on
	 * `context_id` so a record present in both layers isn't ranked twice.
	 *
	 * @param mixed $candidates Incoming candidate list (may be array or any
	 *                          other type if a third-party filter mis-typed it).
	 * @param array $args       Recall arguments. Must include `agent_id`.
	 * @return array Merged candidate list in recall_memory shape.
	 */
	public static function on_recall_memory_candidates( $candidates, $args ) {
		$existing = is_array( $candidates ) ? $candidates : array();

		if ( ! is_array( $args ) || empty( $args['agent_id'] ) ) {
			return $existing;
		}

		$agent_id = is_scalar( $args['agent_id'] ) ? (string) $args['agent_id'] : '';
		if ( '' === $agent_id ) {
			return $existing;
		}

		$rows = self::fetch_rows_for_agent( $agent_id );
		if ( empty( $rows ) ) {
			return $existing;
		}

		$cct_candidates = array();
		foreach ( $rows as $row ) {
			$cct_candidates[] = self::map_row_to_recall_candidate( $row );
		}

		return self::merge_unique_by_context_id( $existing, $cct_candidates );
	}

	/**
	 * Retrieve CCT rows in the *transient* record shape consumed by
	 * {@see WP_MCP_AI_Tool_Retrieve_Agent_Memory::format_context_result()}.
	 *
	 * Used as a fallback when the per-agent transient index is empty (e.g.
	 * after an object-cache flush). Returns at most `$limit` records.
	 *
	 * @param int|string $agent_id Agent identifier.
	 * @param int        $limit    Maximum number of records to return.
	 * @return array<int,array<string,mixed>> Records in transient shape.
	 */
	public static function get_transient_shaped_records_for_agent( $agent_id, $limit = 50 ) {
		$agent_id = is_scalar( $agent_id ) ? (string) $agent_id : '';
		if ( '' === $agent_id ) {
			return array();
		}

		$rows = self::fetch_rows_for_agent( $agent_id );
		if ( empty( $rows ) ) {
			return array();
		}

		$limit = max( 1, (int) $limit );
		$out   = array();
		foreach ( $rows as $row ) {
			$out[] = self::map_row_to_transient_record( $row );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Direct SELECT against `{prefix}jet_cct_ai_agent_memories` for one
	 * agent, ordered by transaction_time DESC.
	 *
	 * Returns an empty array when:
	 *   - the JetEngine CCT class is not loaded (Base build without
	 *     JetEngine), or
	 *   - the underlying table has not yet been provisioned (JetEngine
	 *     present but the data-stores module hasn't created the table yet).
	 *
	 * Both cases are normal during early bootstrap or on sites where the
	 * durable store isn't available, so we silently no-op rather than
	 * raising.
	 *
	 * @param string $agent_id Agent identifier (already string-cast).
	 * @return array<int,array<string,mixed>> Raw rows from the CCT table.
	 */
	protected static function fetch_rows_for_agent( $agent_id ) {
		if ( ! class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return array();
		}

		global $wpdb;
		$slug  = WP_MCP_AI_JetEngine_Agent_Memories_CCT::get_slug();
		$table = $wpdb->prefix . 'jet_cct_' . $slug;

		/**
		 * Filter the candidate cap applied to CCT-backed recall hydration.
		 *
		 * `recall_memory` performs its own wing / room / tier / bi-temporal
		 * filtering on top of the candidate pool, so this cap only needs to
		 * be large enough that an agent's hot working set comfortably fits.
		 *
		 * @since 1.6.1
		 *
		 * @param int    $limit    Default candidate cap (500).
		 * @param string $agent_id Agent identifier the rows are being read for.
		 */
		$limit = (int) apply_filters(
			'wp_mcp_ai_agent_memory_cct_reader_limit',
			self::DEFAULT_LIMIT,
			$agent_id
		);
		if ( $limit < 1 ) {
			$limit = self::DEFAULT_LIMIT;
		}

		// Issue the query with errors suppressed so a missing table (JetEngine
		// not installed, or the data-stores module hasn't created it yet)
		// silently degrades to an empty candidate pool instead of raising.
		// `SHOW TABLES LIKE` would also work but does **not** list MySQL
		// TEMPORARY tables — which the WordPress PHPUnit harness uses for
		// test isolation — so suppressing errors here is more portable.
		$suppress_state = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is constructed from the CCT slug + $wpdb->prefix, both trusted; row values go through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is trusted (see comment above).
				"SELECT * FROM `{$table}` WHERE agent_id = %s AND ( cct_status IS NULL OR cct_status = 'publish' ) ORDER BY transaction_time DESC LIMIT %d",
				$agent_id,
				$limit
			),
			ARRAY_A
		);

		$wpdb->suppress_errors( $suppress_state );
		// Restore the previous suppression state so we never leak the
		// "errors hidden" mode to unrelated queries on the same request.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Map a raw CCT row into a recall_memory candidate.
	 *
	 * The recall tool reads:
	 *   - `wing`, `room` (string equality pre-filter)
	 *   - `valid_from`, `valid_until` (bi-temporal filter via strtotime)
	 *   - `tier` (split into core vs ranked)
	 *   - `context_id` (uniqueness key)
	 *   - `importance`, `content` (ranking inputs)
	 *
	 * Plus we surface a few extra fields that downstream consumers may want.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	protected static function map_row_to_recall_candidate( array $row ) {
		return array(
			'context_id'  => isset( $row['context_id'] ) ? (string) $row['context_id'] : '',
			'agent_id'    => isset( $row['agent_id'] ) ? (string) $row['agent_id'] : '',
			'wing'        => isset( $row['wing'] ) ? (string) $row['wing'] : '',
			'room'        => isset( $row['room'] ) ? (string) $row['room'] : '',
			'title'       => isset( $row['title'] ) ? (string) $row['title'] : '',
			'content'     => isset( $row['content'] ) ? (string) $row['content'] : '',
			'tags'        => self::decode_tags( isset( $row['tags'] ) ? $row['tags'] : '' ),
			'tier'        => isset( $row['memory_tier'] ) ? (string) $row['memory_tier'] : 'recall',
			'importance'  => isset( $row['importance'] ) ? (string) $row['importance'] : 'medium',
			'verbatim'    => ! empty( $row['verbatim'] ),
			'valid_from'  => isset( $row['valid_from'] ) ? (string) $row['valid_from'] : '',
			'valid_until' => isset( $row['valid_until'] ) ? (string) $row['valid_until'] : '',
			'expires_at'  => isset( $row['expires_at'] ) ? (string) $row['expires_at'] : '',
			'stored_at'   => isset( $row['transaction_time'] ) ? (string) $row['transaction_time'] : '',
			'source'      => isset( $row['source'] ) ? (string) $row['source'] : '',
		);
	}

	/**
	 * Map a raw CCT row into the transient-record shape used by
	 * {@see WP_MCP_AI_Tool_Retrieve_Agent_Memory}.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	protected static function map_row_to_transient_record( array $row ) {
		return array(
			'context_id'   => isset( $row['context_id'] ) ? (string) $row['context_id'] : '',
			'agent_id'     => isset( $row['agent_id'] ) ? (string) $row['agent_id'] : '',
			'context_type' => isset( $row['context_type'] ) ? (string) $row['context_type'] : 'generic',
			'wing'         => isset( $row['wing'] ) ? (string) $row['wing'] : '',
			'room'         => isset( $row['room'] ) ? (string) $row['room'] : '',
			'verbatim'     => ! empty( $row['verbatim'] ),
			'stored_at'    => isset( $row['transaction_time'] ) ? (string) $row['transaction_time'] : '',
			'expires_at'   => isset( $row['expires_at'] ) ? (string) $row['expires_at'] : '',
			'data'         => array(
				'title'      => isset( $row['title'] ) ? (string) $row['title'] : '',
				'content'    => isset( $row['content'] ) ? (string) $row['content'] : '',
				'tags'       => self::decode_tags( isset( $row['tags'] ) ? $row['tags'] : '' ),
				'importance' => isset( $row['importance'] ) ? (string) $row['importance'] : 'medium',
				'metadata'   => self::decode_metadata( isset( $row['metadata'] ) ? $row['metadata'] : '' ),
			),
		);
	}

	/**
	 * Best-effort JSON decode for the `tags` column. Returns [] when the
	 * stored value is missing, malformed, or not an array.
	 *
	 * @param mixed $raw Raw tags value (typically a JSON string).
	 * @return array<int,string>
	 */
	protected static function decode_tags( $raw ) {
		if ( is_array( $raw ) ) {
			$out = array();
			foreach ( $raw as $tag ) {
				if ( is_scalar( $tag ) && '' !== (string) $tag ) {
					$out[] = (string) $tag;
				}
			}
			return $out;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $tag ) {
			if ( is_scalar( $tag ) && '' !== (string) $tag ) {
				$out[] = (string) $tag;
			}
		}
		return $out;
	}

	/**
	 * Best-effort JSON decode for the `metadata` column.
	 *
	 * @param mixed $raw Raw metadata value.
	 * @return array<string,mixed>
	 */
	protected static function decode_metadata( $raw ) {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Merge two candidate lists, de-duplicating on `context_id`. Records
	 * from `$primary` take precedence so test fixtures or custom stores
	 * that pre-seed the filter always win over the CCT mirror.
	 *
	 * @param array $primary   Records already in the filter input.
	 * @param array $secondary CCT-derived records.
	 * @return array
	 */
	protected static function merge_unique_by_context_id( array $primary, array $secondary ) {
		$seen   = array();
		$merged = array();
		foreach ( $primary as $rec ) {
			if ( ! is_array( $rec ) ) {
				continue;
			}
			$cid = isset( $rec['context_id'] ) ? (string) $rec['context_id'] : '';
			if ( '' !== $cid ) {
				$seen[ $cid ] = true;
			}
			$merged[] = $rec;
		}
		foreach ( $secondary as $rec ) {
			if ( ! is_array( $rec ) ) {
				continue;
			}
			$cid = isset( $rec['context_id'] ) ? (string) $rec['context_id'] : '';
			if ( '' !== $cid && isset( $seen[ $cid ] ) ) {
				continue;
			}
			if ( '' !== $cid ) {
				$seen[ $cid ] = true;
			}
			$merged[] = $rec;
		}
		return $merged;
	}
}

WP_MCP_AI_Agent_Memory_CCT_Reader::bootstrap();
