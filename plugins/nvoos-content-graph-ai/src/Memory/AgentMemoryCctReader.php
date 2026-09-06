<?php
/**
 * Agent memory CCT reader for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's `WP_MCP_AI_Agent_Memory_CCT_Reader`
 * (behaviour-preserving; base copies retained permanently — ecosystem
 * port plan D-NOBASE). Hydrates the `wp_mcp_ai_recall_memory_candidates`
 * filter from the durable JetEngine `ai_agent_memories` CCT and exposes
 * transient-shaped records for `retrieve_agent_memory` fallback, with the
 * same row mappings, caps, and error-tolerant query handling.
 *
 * Decoupling (documented, additive):
 * - `bootstrap()` is called standalone-only by `Plugin.php` — the base
 *   plugin owns the same filter listener in monolith installs and a
 *   second subscription would double-merge CCT rows into candidates.
 * - The CCT class resolves per install mode (base
 *   `WP_MCP_AI_JetEngine_Agent_Memories_CCT` monolith / the ported
 *   `AgentMemoriesCct` standalone).
 * - In CG-AI the recall tools have not ported yet, so the filter has no
 *   consumer — the reader is dormant until the tools wave (tracked gap).
 *
 * @package NvoosContentGraphAi\Memory
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Memory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hydrates recall_memory / retrieve_agent_memory from the durable CCT.
 *
 * @since 1.1.0
 */
class AgentMemoryCctReader {

	/**
	 * Default candidate cap.
	 */
	const DEFAULT_LIMIT = 500;

	/**
	 * Hook into the recall candidate filter.
	 *
	 * @return void
	 */
	public static function bootstrap(): void {
		add_filter( 'wp_mcp_ai_recall_memory_candidates', array( __CLASS__, 'on_recall_memory_candidates' ), 10, 2 );
	}

	/**
	 * Filter callback for `wp_mcp_ai_recall_memory_candidates`.
	 *
	 * Merges CCT-backed rows into whatever candidates are already in the
	 * filter input. De-duplicates on `context_id`.
	 *
	 * @param mixed $candidates Incoming candidate list.
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
	 * `retrieve_agent_memory`.
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
	 * Returns an empty array when the CCT class is not loaded or the
	 * underlying table has not yet been provisioned.
	 *
	 * @param string $agent_id Agent identifier (already string-cast).
	 * @return array<int,array<string,mixed>> Raw rows from the CCT table.
	 */
	protected static function fetch_rows_for_agent( $agent_id ) {
		$cct_class = self::cct_class();

		if ( ! class_exists( $cct_class ) ) {
			return array();
		}

		global $wpdb;
		$slug  = $cct_class::get_slug();
		$table = $wpdb->prefix . 'jet_cct_' . $slug;

		/**
		 * Filter the candidate cap applied to CCT-backed recall hydration.
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

		// Issue the query with errors suppressed so a missing table silently
		// degrades to an empty candidate pool instead of raising.
		$suppress_state = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is constructed from the CCT slug + $wpdb->prefix, both trusted; row values go through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is trusted (see comment above).
				"SELECT * FROM `{$table}` WHERE agent_id = %s AND ( cct_status IS NULL OR cct_status = 'publish' ) ORDER BY transaction_time DESC LIMIT %d",
				$agent_id,
				$limit
			),
			ARRAY_A
		);

		$wpdb->suppress_errors( $suppress_state );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Map a raw CCT row into a recall_memory candidate.
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
	 * `retrieve_agent_memory`.
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
	 * Best-effort JSON decode for the `tags` column.
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
	 * from `$primary` take precedence.
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

	/**
	 * Resolve the CCT class name for the active install mode.
	 *
	 * @return string
	 */
	protected static function cct_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_JetEngine_Agent_Memories_CCT' ) ) {
			return 'WP_MCP_AI_JetEngine_Agent_Memories_CCT';
		}

		return __NAMESPACE__ . '\\AgentMemoriesCct';
	}
}
