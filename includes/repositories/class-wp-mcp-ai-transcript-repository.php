<?php
/**
 * Transcript Repository
 *
 * Handles database operations for chat transcripts.
 * Part of Phase 1.3 separation of concerns refactoring.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transcript Repository class
 *
 * Responsible for:
 * - Transcript data access
 * - Transcript deletion
 * - Database table management
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Transcript_Repository {

	/**
	 * Cache-key prefix for the storage-byte aggregate.
	 *
	 * @since 1.2.24
	 * @var string
	 */
	const STORAGE_BYTES_CACHE_PREFIX = 'wp_mcp_ai_tb_';

	/**
	 * User meta key holding the cache-busting version for the aggregate.
	 *
	 * @since 1.2.24
	 * @var string
	 */
	const STORAGE_BYTES_VERSION_META = '_wp_mcp_ai_tb_version';

	/**
	 * Default freshness window for the cached aggregate, in seconds.
	 *
	 * @since 1.2.24
	 * @var int
	 */
	const STORAGE_BYTES_CACHE_TTL = 300;

	/**
	 * Lifetime of the last known-good figure, in seconds.
	 *
	 * Used as a backstop when a recompute exceeds its time budget.
	 *
	 * @since 1.2.24
	 * @var int
	 */
	const STORAGE_BYTES_BACKSTOP_TTL = 604800;

	/**
	 * Default maximum time the aggregate may run, in milliseconds.
	 *
	 * @since 1.2.24
	 * @var int
	 */
	const STORAGE_BYTES_TIME_BUDGET_MS = 2000;

	/**
	 * Get the transcript table name
	 *
	 * @return string Table name or empty string if not available.
	 */
	public function get_table_name() {
		global $wpdb;

		if ( ! class_exists( 'WP_MCP_AI_JetEngine_CCT' ) ) {
			return '';
		}

		$slug = WP_MCP_AI_JetEngine_CCT::get_slug();

		if ( '' === $slug ) {
			return '';
		}

		return $wpdb->prefix . 'jet_cct_' . $slug;
	}

	/**
	 * Check if the transcript table exists
	 *
	 * @return bool True if table exists, false otherwise.
	 */
	public function table_exists() {
		global $wpdb;

		$table = $this->get_table_name();

		if ( '' === $table ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type.
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $result === $table;
	}

	/**
	 * Get transcript session summaries for a user.
	 *
	 * Retrieves grouped conversation sessions with metadata including:
	 * - Session key
	 * - Start and completion timestamps
	 * - Assistant information
	 * - Message count
	 *
	 * Sessions are sorted by most recent activity first (MAX(cct_created) DESC).
	 * Uses fallback from user_id to cct_author_id for compatibility.
	 *
	 * @param int $user_id      User identifier.
	 * @param int $per_page     Number of sessions to return.
	 * @param int $page         Results page number.
	 * @param int $assistant_id Optional assistant ID to filter by.
	 * @return array|WP_Error Array with 'items' and 'total' keys, or WP_Error on failure.
	 */
	public function get_sessions( $user_id, $per_page, $page, $assistant_id = 0 ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_unavailable',
				__( 'Chat transcripts are not available. Ensure JetEngine Custom Content Types is active and that the /wp-json/jet-cct/ai_chat_transcripts endpoint loads successfully.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		// Escape table name for defense-in-depth and to satisfy WordPress Plugin Check tool.
		// Table name is constructed from $wpdb->prefix + 'jet_cct_' + constant 'ai_chat_transcripts'.
		$table        = esc_sql( $this->get_table_name() );
		$user_id      = absint( $user_id );
		$assistant_id = absint( $assistant_id );
		$per_page     = max( 1, (int) $per_page );
		$page         = max( 1, (int) $page );
		$offset       = ( $page - 1 ) * $per_page;

		// When user_id = 0 (e.g. background mining job, no specific user), skip the
		// user filter entirely so all users' sessions for the assistant are returned.
		// When user_id > 0 we first try the custom `user_id` field, then fall back
		// to JetEngine's built-in `cct_author_id` field.
		if ( $user_id > 0 ) {
			$where_clauses = array( 'user_id = %d' );
			$where_values  = array( $user_id );
		} else {
			$where_clauses = array();
			$where_values  = array();
		}

		if ( $assistant_id > 0 ) {
			$where_clauses[] = 'assistant_id = %s';
			$where_values[]  = (string) $assistant_id;
		}

		$where_part = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

		$query_values = array_merge( $where_values, array( $per_page, $offset ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $where_part contains only hardcoded placeholders.
		$query_template = "SELECT session_key,
                MIN(request_started_at) AS started_at,
                MAX(response_completed_at) AS completed_at,
                MIN(cct_created) AS first_created,
                MAX(cct_created) AS last_created,
                MAX(assistant_id) AS assistant_id,
                MAX(assistant_model) AS assistant_model,
                COUNT(*) AS turn_count
         FROM `{$table}`
         {$where_part}
         GROUP BY session_key
         ORDER BY MAX(cct_created) DESC, session_key ASC
         LIMIT %d OFFSET %d";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template is built above with escaped table name and hardcoded placeholders.
		$query = $wpdb->prepare( $query_template, $query_values );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type. Query string built dynamically from sanitized/validated components; $wpdb->prepare() applied for all value placeholders.
		$rows = $wpdb->get_results( $query, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		// Fallback: If user_id > 0 and no rows found, try with JetEngine's built-in
		// cct_author_id field. Skipped when user_id = 0 because we already queried
		// all users above.
		if ( empty( $rows ) && $user_id > 0 ) {
			$fallback_where_clauses = array( 'cct_author_id = %d' );
			$fallback_where_values  = array( $user_id );

			if ( $assistant_id > 0 ) {
				$fallback_where_clauses[] = 'assistant_id = %s';
				$fallback_where_values[]  = (string) $assistant_id;
			}

			$fallback_where_sql    = implode( ' AND ', $fallback_where_clauses );
			$fallback_query_values = array_merge( $fallback_where_values, array( $per_page, $offset ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $fallback_where_sql contains only hardcoded placeholders.
			$fallback_query_template = "SELECT session_key,
                MIN(request_started_at) AS started_at,
                MAX(response_completed_at) AS completed_at,
                MIN(cct_created) AS first_created,
                MAX(cct_created) AS last_created,
                MAX(assistant_id) AS assistant_id,
                MAX(assistant_model) AS assistant_model,
                COUNT(*) AS turn_count
         FROM `{$table}`
         WHERE {$fallback_where_sql}
         GROUP BY session_key
         ORDER BY MAX(cct_created) DESC, session_key ASC
         LIMIT %d OFFSET %d";
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template is built above with escaped table name and hardcoded placeholders.
			$fallback_query = $wpdb->prepare( $fallback_query_template, $fallback_query_values );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type. Query string built dynamically from sanitized/validated components; $wpdb->prepare() applied for all value placeholders.
			$rows = $wpdb->get_results( $fallback_query, ARRAY_A );

			if ( ! is_array( $rows ) ) {
				$rows = array();
			}
		}

		// Get total count using the same WHERE clause as the primary query.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $where_part contains only hardcoded placeholders.
		$total_query_template = "SELECT COUNT(DISTINCT session_key) FROM `{$table}` {$where_part}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template is built above with escaped table name and hardcoded placeholders.
		$total_query = $wpdb->prepare( $total_query_template, $where_values );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type. Query string built dynamically from sanitized/validated components; $wpdb->prepare() applied for all value placeholders.
		$total = (int) $wpdb->get_var( $total_query );

		// If user_id > 0 and we got rows from the cct_author_id fallback but total
		// count is still 0, re-run the count with the fallback WHERE clause.
		if ( 0 === $total && ! empty( $rows ) && $user_id > 0 ) {
			$fallback_where_clauses = array( 'cct_author_id = %d' );
			$fallback_where_values  = array( $user_id );

			if ( $assistant_id > 0 ) {
				$fallback_where_clauses[] = 'assistant_id = %s';
				$fallback_where_values[]  = (string) $assistant_id;
			}

			$fallback_where_sql = implode( ' AND ', $fallback_where_clauses );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $fallback_where_sql contains only hardcoded placeholders.
			$fallback_total_query_template = "SELECT COUNT(DISTINCT session_key) FROM `{$table}` WHERE {$fallback_where_sql}";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template is built above with escaped table name and hardcoded placeholders.
			$fallback_total_query = $wpdb->prepare( $fallback_total_query_template, $fallback_where_values );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type. Query string built dynamically from sanitized/validated components; $wpdb->prepare() applied for all value placeholders.
			$total = (int) $wpdb->get_var( $fallback_total_query );
		}

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}

	/**
	 * Total byte size of a user's stored chat transcripts.
	 *
	 * Sums the serialized payload lengths (`request_payload` +
	 * `response_payload`) so the SPA status bar can report server-side
	 * session storage — the Pro SPA keeps transcripts in this table, not in
	 * localStorage, so a client-side quota meter always reads ~0 there.
	 *
	 * The aggregate has to read every matching `LONGTEXT` payload, so its cost
	 * grows with the corpus and a cold table can take seconds. The SPA calls the
	 * list endpoint more than once per page load, so the figure is cached per
	 * user/assistant pair and recomputed at most once per freshness window —
	 * see the `wp_mcp_ai_transcript_storage_bytes_ttl` filter. A recompute is
	 * additionally bounded by a statement time limit and falls back to the last
	 * known-good figure rather than stalling the console — see the
	 * `wp_mcp_ai_transcript_storage_bytes_time_budget_ms` filter.
	 *
	 * Uses the same custom `user_id` column with `cct_author_id` fallback
	 * as `get_sessions()`, and returns 0 when the table is unavailable.
	 *
	 * @since 1.0.0
	 * @since 1.2.24 Caches the aggregate and bounds the recompute.
	 *
	 * @param int $user_id      User identifier.
	 * @param int $assistant_id Optional assistant ID to filter by.
	 * @return int Total stored bytes (0 when unavailable or empty).
	 */
	public function get_storage_bytes( $user_id, $assistant_id = 0 ) {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		$user_id      = absint( $user_id );
		$assistant_id = absint( $assistant_id );

		/**
		 * Filters how long the transcript storage total is cached, in seconds.
		 *
		 * Return 0 to disable caching, so every read recomputes the aggregate.
		 *
		 * @since 1.2.24
		 *
		 * @param int $ttl          Freshness window in seconds. Default 300.
		 * @param int $user_id      User identifier.
		 * @param int $assistant_id Assistant identifier (0 = all assistants).
		 */
		$ttl = (int) apply_filters( 'wp_mcp_ai_transcript_storage_bytes_ttl', self::STORAGE_BYTES_CACHE_TTL, $user_id, $assistant_id );

		$cache_key = $this->get_storage_bytes_cache_key( $user_id, $assistant_id );

		if ( $ttl > 0 ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) && isset( $cached['bytes'] ) ) {
				return (int) $cached['bytes'];
			}
		}

		$bytes = $this->query_storage_bytes( $user_id, $assistant_id );

		if ( null === $bytes ) {
			// The recompute hit its time budget (or the query failed). Serve the
			// last known-good figure and back off for a minute so a pathological
			// corpus cannot stall every console load.
			$backstop = get_transient( $this->get_storage_bytes_backstop_key( $user_id, $assistant_id ) );
			$fallback = is_numeric( $backstop ) ? (int) $backstop : 0;

			if ( $ttl > 0 ) {
				set_transient(
					$cache_key,
					array(
						'bytes'       => $fallback,
						'computed_at' => time(),
						'degraded'    => true,
					),
					min( $ttl, MINUTE_IN_SECONDS )
				);
			}

			return $fallback;
		}

		$bytes = (int) $bytes;

		if ( $ttl > 0 ) {
			set_transient(
				$cache_key,
				array(
					'bytes'       => $bytes,
					'computed_at' => time(),
				),
				$ttl
			);
			set_transient(
				$this->get_storage_bytes_backstop_key( $user_id, $assistant_id ),
				$bytes,
				self::STORAGE_BYTES_BACKSTOP_TTL
			);
		}

		return $bytes;
	}

	/**
	 * Run the storage-byte aggregate without caching.
	 *
	 * Each statement runs under a statement time limit; when that limit is hit
	 * the method returns null instead of blocking the request indefinitely.
	 *
	 * @since 1.2.24
	 *
	 * @param int $user_id      User identifier.
	 * @param int $assistant_id Optional assistant ID to filter by.
	 * @return int|null Byte total, or null when the query failed or timed out.
	 */
	protected function query_storage_bytes( $user_id, $assistant_id = 0 ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return 0;
		}

		// Escape table name for defense-in-depth and to satisfy WordPress Plugin Check tool.
		$table        = esc_sql( $this->get_table_name() );
		$user_id      = absint( $user_id );
		$assistant_id = absint( $assistant_id );

		if ( $user_id > 0 ) {
			$where_clauses = array( 'user_id = %d' );
			$where_values  = array( $user_id );
		} else {
			$where_clauses = array();
			$where_values  = array();
		}

		if ( $assistant_id > 0 ) {
			$where_clauses[] = 'assistant_id = %s';
			$where_values[]  = (string) $assistant_id;
		}

		$where_part = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $where_part contains only hardcoded placeholders.
		$query_template = "SELECT COALESCE( SUM( LENGTH( COALESCE( request_payload, '' ) ) + LENGTH( COALESCE( response_payload, '' ) ) ), 0 )
         FROM `{$table}`
         {$where_part}";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$bytes = $this->run_aggregate_query( $wpdb->prepare( $query_template, $where_values ) );

		if ( null === $bytes ) {
			return null;
		}

		// Fallback to JetEngine's built-in cct_author_id field when the
		// custom user_id column yields nothing.
		if ( 0 === $bytes && $user_id > 0 ) {
			$fallback_where_clauses = array( 'cct_author_id = %d' );
			$fallback_where_values  = array( $user_id );

			if ( $assistant_id > 0 ) {
				$fallback_where_clauses[] = 'assistant_id = %s';
				$fallback_where_values[]  = (string) $assistant_id;
			}

			$fallback_where_sql = implode( ' AND ', $fallback_where_clauses );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $fallback_where_sql contains only hardcoded placeholders.
			$fallback_query_template = "SELECT COALESCE( SUM( LENGTH( COALESCE( request_payload, '' ) ) + LENGTH( COALESCE( response_payload, '' ) ) ), 0 )
             FROM `{$table}`
             WHERE {$fallback_where_sql}";
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$bytes = $this->run_aggregate_query( $wpdb->prepare( $fallback_query_template, $fallback_where_values ) );
		}

		return $bytes;
	}

	/**
	 * Execute an aggregate SELECT under a statement time limit.
	 *
	 * @since 1.2.24
	 *
	 * @param string $query Prepared SQL.
	 * @return int|null Integer result, or null on failure or timeout.
	 */
	protected function run_aggregate_query( $query ) {
		global $wpdb;

		/**
		 * Filters the maximum time the transcript storage aggregate may run.
		 *
		 * Return 0 to run without a limit.
		 *
		 * @since 1.2.24
		 *
		 * @param int $budget_ms Time budget in milliseconds. Default 2000.
		 */
		$budget_ms = (int) apply_filters( 'wp_mcp_ai_transcript_storage_bytes_time_budget_ms', self::STORAGE_BYTES_TIME_BUDGET_MS );

		$previous = $this->apply_statement_time_limit( $budget_ms );

		// The time limit reports an overrun as a database error, so silence it
		// for the duration of this statement; the null result carries the signal.
		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Query prepared by the caller; direct query required for custom-table aggregation.
		$result = $wpdb->get_var( $query );

		$wpdb->suppress_errors( $suppress );

		$this->restore_statement_time_limit( $previous );

		if ( null === $result ) {
			// Clear the interruption message so a later wpdb call in the same
			// request cannot surface it (the aggregate always returns one row,
			// so a null result means the statement failed or timed out).
			$wpdb->last_error = '';

			return null;
		}

		return (int) $result;
	}

	/**
	 * Apply a server-side statement time limit to the current connection.
	 *
	 * Handles MariaDB (`max_statement_time`, seconds) and MySQL
	 * (`max_execution_time`, milliseconds) and captures the previous value so
	 * it can be restored exactly. When neither setting is available the query
	 * simply runs unbounded.
	 *
	 * @since 1.2.24
	 *
	 * @param int $budget_ms Time budget in milliseconds.
	 * @return array|null State to pass to restore_statement_time_limit(), or null.
	 */
	protected function apply_statement_time_limit( $budget_ms ) {
		global $wpdb;

		$budget_ms = (int) $budget_ms;

		if ( $budget_ms <= 0 ) {
			return null;
		}

		if ( ! method_exists( $wpdb, 'db_server_info' ) ) {
			return null;
		}

		$server = (string) $wpdb->db_server_info();

		if ( false !== stripos( $server, 'mariadb' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Reading a session variable.
			$previous = $wpdb->get_var( 'SELECT @@SESSION.max_statement_time' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Session-scoped SET; value bound through prepare().
			$applied = $wpdb->query( $wpdb->prepare( 'SET SESSION max_statement_time = %f', $budget_ms / 1000 ) );

			if ( false === $applied ) {
				return null;
			}

			return array(
				'type'  => 'mariadb',
				'value' => null === $previous ? 0.0 : (float) $previous,
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Reading a session variable.
		$previous = $wpdb->get_var( 'SELECT @@SESSION.max_execution_time' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Session-scoped SET; value bound through prepare().
		$applied = $wpdb->query( $wpdb->prepare( 'SET SESSION max_execution_time = %d', $budget_ms ) );

		if ( false === $applied ) {
			return null;
		}

		return array(
			'type'  => 'mysql',
			'value' => null === $previous ? 0 : (int) $previous,
		);
	}

	/**
	 * Restore the statement time limit captured by apply_statement_time_limit().
	 *
	 * @since 1.2.24
	 *
	 * @param array|null $previous State returned by apply_statement_time_limit().
	 * @return void
	 */
	protected function restore_statement_time_limit( $previous ) {
		global $wpdb;

		if ( ! is_array( $previous ) || empty( $previous['type'] ) ) {
			return;
		}

		if ( 'mariadb' === $previous['type'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Session-scoped SET; value bound through prepare().
			$wpdb->query( $wpdb->prepare( 'SET SESSION max_statement_time = %f', (float) $previous['value'] ) );
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Session-scoped SET; value bound through prepare().
		$wpdb->query( $wpdb->prepare( 'SET SESSION max_execution_time = %d', (int) $previous['value'] ) );
	}

	/**
	 * Build the cache key for a user/assistant pair.
	 *
	 * The key carries a per-user version so that invalidating every
	 * assistant-scoped entry needs a single meta write rather than enumerating
	 * keys. Old entries expire on their own.
	 *
	 * @since 1.2.24
	 *
	 * @param int $user_id      User identifier.
	 * @param int $assistant_id Assistant identifier (0 = all assistants).
	 * @return string Cache key.
	 */
	protected function get_storage_bytes_cache_key( $user_id, $assistant_id ) {
		$user_id      = absint( $user_id );
		$assistant_id = absint( $assistant_id );
		$version      = 0;

		if ( $user_id > 0 ) {
			$version = (int) get_user_meta( $user_id, self::STORAGE_BYTES_VERSION_META, true );
		}

		return self::STORAGE_BYTES_CACHE_PREFIX . $user_id . '_' . $assistant_id . '_' . $version;
	}

	/**
	 * Build the version-independent backstop key for a user/assistant pair.
	 *
	 * The backstop survives cache invalidation so a timed-out recompute can
	 * still report the last figure that was successfully measured.
	 *
	 * @since 1.2.24
	 *
	 * @param int $user_id      User identifier.
	 * @param int $assistant_id Assistant identifier (0 = all assistants).
	 * @return string Cache key.
	 */
	protected function get_storage_bytes_backstop_key( $user_id, $assistant_id ) {
		return self::STORAGE_BYTES_CACHE_PREFIX . absint( $user_id ) . '_' . absint( $assistant_id ) . '_last';
	}

	/**
	 * Invalidate every cached storage total for a user.
	 *
	 * @since 1.2.24
	 *
	 * @param int $user_id User identifier.
	 * @return void
	 */
	public function flush_storage_bytes_cache( $user_id ) {
		$user_id = absint( $user_id );

		if ( $user_id <= 0 ) {
			return;
		}

		$version = (int) get_user_meta( $user_id, self::STORAGE_BYTES_VERSION_META, true );
		update_user_meta( $user_id, self::STORAGE_BYTES_VERSION_META, $version + 1 );
	}

	/**
	 * Get a single transcript session with all messages.
	 *
	 * Uses progressive fallback queries to handle various edge cases:
	 * 1. First tries with session_key + user_id + assistant_id (most specific)
	 * 2. Then tries with session_key + cct_author_id + assistant_id (JetEngine built-in)
	 * 3. Then tries without assistant_id filter (in case of mismatch)
	 * 4. Finally tries with session_key only (for legacy data issues)
	 *
	 * @param int    $user_id      User identifier.
	 * @param string $session_key  Session key.
	 * @param int    $assistant_id Optional assistant ID to filter by.
	 * @return array|WP_Error Array of transcript rows or WP_Error.
	 */
	public function get_session( $user_id, $session_key, $assistant_id = 0 ) {
		global $wpdb;

		if ( '' === $session_key ) {
			return new WP_Error(
				'wp_mcp_ai_transcript_missing',
				__( 'Session key is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->table_exists() ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_unavailable',
				__( 'Chat transcripts are not available.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		// Escape table name for defense-in-depth and to satisfy WordPress Plugin Check tool.
		// Table name is constructed from $wpdb->prefix + 'jet_cct_' + constant 'ai_chat_transcripts'.
		$table        = esc_sql( $this->get_table_name() );
		$user_id      = absint( $user_id );
		$assistant_id = absint( $assistant_id );

		$select_fields = $this->get_select_fields();

		// Query 1: Try with session_key + user_id + assistant_id (custom user_id field).
		// When user_id = 0 (background mining), skip the user filter so we match any user.
		if ( $user_id > 0 ) {
			$where_clauses = array( 'session_key = %s', 'user_id = %d' );
			$where_values  = array( $session_key, $user_id );
		} else {
			$where_clauses = array( 'session_key = %s' );
			$where_values  = array( $session_key );
		}

		if ( $assistant_id > 0 ) {
			$where_clauses[] = 'assistant_id = %s';
			$where_values[]  = (string) $assistant_id;
		}

		$where_sql = implode( ' AND ', $where_clauses );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $select_fields and $where_sql contain only hardcoded strings/placeholders.
		$query_template = "SELECT {$select_fields}
         FROM `{$table}`
         WHERE {$where_sql}
         ORDER BY cct_created ASC, _ID ASC";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template is built above with escaped table name and hardcoded field names.
		$query = $wpdb->prepare( $query_template, $where_values );

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'debug',
				'Transcript Repository: get_session query (user_id)',
				array(
					'session_key'  => $session_key,
					'user_id'      => $user_id,
					'assistant_id' => $assistant_id,
					'query'        => $query,
					'table'        => $table,
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query uses $wpdb->prepare() with proper placeholders. Table name is escaped with esc_sql(), field list contains only hardcoded column names from get_select_fields().
		$rows = $wpdb->get_results( $query, ARRAY_A );

		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
				'debug',
				'Transcript Repository: get_session query results (user_id)',
				array(
					'session_key'  => $session_key,
					'user_id'      => $user_id,
					'assistant_id' => $assistant_id,
					'row_count'    => is_array( $rows ) ? count( $rows ) : 0,
					'wpdb_error'   => $wpdb->last_error ? $wpdb->last_error : 'none',
				)
			);
		}

		// Query 2: If user_id > 0 and no rows, try with cct_author_id (JetEngine built-in field).
		// Skipped when user_id = 0 because Query 1 already matched without a user filter.
		if ( empty( $rows ) && $user_id > 0 ) {
			$author_where_clauses = array( 'session_key = %s', 'cct_author_id = %d' );
			$author_where_values  = array( $session_key, $user_id );

			if ( $assistant_id > 0 ) {
				$author_where_clauses[] = 'assistant_id = %s';
				$author_where_values[]  = (string) $assistant_id;
			}

			$author_where_sql = implode( ' AND ', $author_where_clauses );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $select_fields and $author_where_sql contain only hardcoded strings/placeholders.
			$author_query_template = "SELECT {$select_fields}
         FROM `{$table}`
         WHERE {$author_where_sql}
         ORDER BY cct_created ASC, _ID ASC";
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template is built above with escaped table name and hardcoded field names.
			$author_query = $wpdb->prepare( $author_query_template, $author_where_values );

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'Transcript Repository: get_session query (cct_author_id)',
					array(
						'session_key'  => $session_key,
						'user_id'      => $user_id,
						'assistant_id' => $assistant_id,
						'query'        => $author_query,
					)
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query uses $wpdb->prepare() with proper placeholders. Table name is escaped with esc_sql(), field list contains only hardcoded column names from get_select_fields().
			$rows = $wpdb->get_results( $author_query, ARRAY_A );

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'Transcript Repository: get_session query results (cct_author_id)',
					array(
						'session_key'  => $session_key,
						'user_id'      => $user_id,
						'assistant_id' => $assistant_id,
						'row_count'    => is_array( $rows ) ? count( $rows ) : 0,
						'wpdb_error'   => $wpdb->last_error ? $wpdb->last_error : 'none',
					)
				);
			}
		}

		// Query 3: If still no rows and assistant_id was specified, try without assistant_id.
		if ( empty( $rows ) && $assistant_id > 0 ) {
			// Try with user_id only.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $select_fields contains only hardcoded strings.
			$simple_query_template = "SELECT {$select_fields}
         FROM `{$table}`
         WHERE session_key = %s AND user_id = %d
         ORDER BY cct_created ASC, _ID ASC";
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template is built above with escaped table name and hardcoded field names.
			$simple_query = $wpdb->prepare( $simple_query_template, array( $session_key, $user_id ) );

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'Transcript Repository: get_session trying without assistant_id (user_id)',
					array(
						'session_key' => $session_key,
						'user_id'     => $user_id,
						'query'       => $simple_query,
					)
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query uses $wpdb->prepare() with proper placeholders. Table name is escaped with esc_sql(), field list contains only hardcoded column names from get_select_fields().
			$rows = $wpdb->get_results( $simple_query, ARRAY_A );

			// If still no rows, try with cct_author_id.
			if ( empty( $rows ) ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $select_fields contains only hardcoded strings.
				$simple_author_query_template = "SELECT {$select_fields}
         FROM `{$table}`
         WHERE session_key = %s AND cct_author_id = %d
         ORDER BY cct_created ASC, _ID ASC";
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template is built above with escaped table name and hardcoded field names.
				$simple_author_query = $wpdb->prepare( $simple_author_query_template, array( $session_key, $user_id ) );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query uses $wpdb->prepare() with proper placeholders. Table name is escaped with esc_sql(), field list contains only hardcoded column names from get_select_fields().
				$rows = $wpdb->get_results( $simple_author_query, ARRAY_A );
			}

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'Transcript Repository: get_session without assistant_id results',
					array(
						'session_key' => $session_key,
						'user_id'     => $user_id,
						'row_count'   => is_array( $rows ) ? count( $rows ) : 0,
						'wpdb_error'  => $wpdb->last_error ? $wpdb->last_error : 'none',
					)
				);
			}
		}

		// Query 4: Final fallback - try with session_key only.
		// This handles cases where user_id wasn't stored correctly in legacy data.
		if ( empty( $rows ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $select_fields contains only hardcoded strings.
			$session_only_query_template = "SELECT {$select_fields}
         FROM `{$table}`
         WHERE session_key = %s
         ORDER BY cct_created ASC, _ID ASC";
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template is built above with escaped table name and hardcoded field names.
			$session_only_query = $wpdb->prepare( $session_only_query_template, array( $session_key ) );

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'Transcript Repository: get_session trying with session_key only (final fallback)',
					array(
						'session_key' => $session_key,
						'user_id'     => $user_id,
						'query'       => $session_only_query,
					)
				);
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query uses $wpdb->prepare() with proper placeholders. Table name is escaped with esc_sql(), field list contains only hardcoded column names from get_select_fields().
			$rows = $wpdb->get_results( $session_only_query, ARRAY_A );

			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'Transcript Repository: get_session with session_key only results',
					array(
						'session_key' => $session_key,
						'row_count'   => is_array( $rows ) ? count( $rows ) : 0,
						'wpdb_error'  => $wpdb->last_error ? $wpdb->last_error : 'none',
					)
				);
			}

			// Security check: verify the rows belong to the expected user.
			// This prevents unauthorized access when using the session_key-only fallback.
			if ( ! empty( $rows ) && $user_id > 0 ) {
				$first_row     = $rows[0];
				$row_user_id   = isset( $first_row['user_id'] ) ? absint( $first_row['user_id'] ) : 0;
				$row_author_id = isset( $first_row['cct_author_id'] ) ? absint( $first_row['cct_author_id'] ) : 0;
				$user_matches  = ( $row_user_id === $user_id ) || ( $row_author_id === $user_id );

				if ( ! $user_matches ) {
					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_event(
							'debug',
							'Transcript Repository: get_session found rows but user_id mismatch',
							array(
								'session_key'   => $session_key,
								'expected_user' => $user_id,
								'row_user_id'   => $row_user_id,
								'row_author_id' => $row_author_id,
							)
						);
					}

					// Clear rows - user doesn't own this transcript.
					$rows = array();
				}
			}
		}

		if ( empty( $rows ) ) {
			if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'debug',
					'Transcript Repository: get_session returning 404',
					array(
						'session_key'  => $session_key,
						'user_id'      => $user_id,
						'assistant_id' => $assistant_id,
						'table_exists' => $this->table_exists(),
					)
				);
			}

			return new WP_Error(
				'wp_mcp_ai_transcript_missing',
				__( 'The requested chat transcript could not be found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		return $rows;
	}

	/**
	 * Delete transcript entries for a session and user.
	 *
	 * Tries user_id first (our custom CCT field), then falls back to
	 * cct_author_id (JetEngine built-in) for compatibility with different
	 * configurations. This order matches the query patterns in get_session()
	 * and get_sessions() which also prioritize the custom user_id field.
	 *
	 * @param string $session_key Session key.
	 * @param int    $user_id     User ID.
	 * @return int|false Number of rows deleted, or false on error.
	 */
	public function delete_transcript( $session_key, $user_id ) {
		global $wpdb;

		$table = $this->get_table_name();

		if ( '' === $table ) {
			return false;
		}

		if ( ! $this->table_exists() ) {
			return false;
		}

		// Try deleting using user_id first (custom CCT field).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type.
		$deleted = $wpdb->delete(
			$table,
			array(
				'session_key' => $session_key,
				'user_id'     => $user_id,
			),
			array( '%s', '%d' )
		);

		// If no rows deleted with user_id, try with cct_author_id (JetEngine built-in).
		if ( false !== $deleted && 0 === $deleted ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query required for performance-critical aggregation on custom plugin table; WP_Query does not support custom table queries of this type.
			$deleted = $wpdb->delete(
				$table,
				array(
					'session_key'   => $session_key,
					'cct_author_id' => $user_id,
				),
				array( '%s', '%d' )
			);
		}

		if ( false !== $deleted && $deleted > 0 ) {
			$this->flush_storage_bytes_cache( $user_id );
		}

		return $deleted;
	}

	/**
	 * Find an existing transcript record by session_key and user_id.
	 *
	 * This method is used to determine whether to create a new record or update
	 * an existing one when saving transcripts. It returns the most recent record's
	 * ID for the given session.
	 *
	 * @param string $session_key  Session key to look up.
	 * @param int    $user_id      User identifier.
	 * @param int    $assistant_id Optional assistant ID to filter by.
	 * @return int|null Record ID if found, null otherwise.
	 */
	public function find_existing_session_id( $session_key, $user_id, $assistant_id = 0 ) {
		global $wpdb;

		if ( '' === $session_key ) {
			return null;
		}

		if ( ! $this->table_exists() ) {
			return null;
		}

		// Escape table name for defense-in-depth and to satisfy WordPress Plugin Check tool.
		// Table name is constructed from $wpdb->prefix + 'jet_cct_' + constant 'ai_chat_transcripts'.
		$table        = esc_sql( $this->get_table_name() );
		$user_id      = absint( $user_id );
		$assistant_id = absint( $assistant_id );

		// Query 1: Try with session_key + user_id + assistant_id.
		$where_clauses = array( 'session_key = %s', 'user_id = %d' );
		$where_values  = array( $session_key, $user_id );

		if ( $assistant_id > 0 ) {
			$where_clauses[] = 'assistant_id = %s';
			$where_values[]  = (string) $assistant_id;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Get the most recent record ID for this session.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is escaped with esc_sql(), $where_sql contains only hardcoded placeholders; spread operator used for values.
		$query = $wpdb->prepare(
			"SELECT _ID FROM `{$table}` WHERE {$where_sql} ORDER BY cct_created DESC, _ID DESC LIMIT 1",
			$where_values
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query uses $wpdb->prepare() with proper placeholders. Table name is escaped with esc_sql(), WHERE clause contains only hardcoded placeholders.
		$record_id = $wpdb->get_var( $query );

		if ( $record_id ) {
			return absint( $record_id );
		}

		// Query 2: Fallback to cct_author_id if user_id didn't match.
		$author_where_clauses = array( 'session_key = %s', 'cct_author_id = %d' );
		$author_where_values  = array( $session_key, $user_id );

		if ( $assistant_id > 0 ) {
			$author_where_clauses[] = 'assistant_id = %s';
			$author_where_values[]  = (string) $assistant_id;
		}

		$author_where_sql = implode( ' AND ', $author_where_clauses );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table is escaped with esc_sql(), $author_where_sql contains only hardcoded placeholders; spread operator used for values.
		$author_query = $wpdb->prepare(
			"SELECT _ID FROM `{$table}` WHERE {$author_where_sql} ORDER BY cct_created DESC, _ID DESC LIMIT 1",
			$author_where_values
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query uses $wpdb->prepare() with proper placeholders. Table name is escaped with esc_sql(), WHERE clause contains only hardcoded placeholders.
		$record_id = $wpdb->get_var( $author_query );

		if ( $record_id ) {
			return absint( $record_id );
		}

		return null;
	}

	/**
	 * Get the SELECT fields for transcript queries.
	 *
	 * Includes session_key and user identification fields for proper
	 * reconstruction and debugging.
	 *
	 * @return string SQL SELECT fields.
	 */
	private function get_select_fields() {
		return 'session_key,
                request_payload,
                response_payload,
                metadata,
                request_started_at,
                response_completed_at,
                cct_created,
                cct_author_id,
                assistant_id,
                assistant_model,
                latency_ms,
                user_id';
	}

	/**
	 * Build fallback WHERE clause for user_id column.
	 *
	 * Creates WHERE clause and values for queries using the custom user_id field
	 * instead of the built-in cct_author_id column. This handles cases where
	 * JetEngine might be using a custom user_id field.
	 *
	 * @param int    $user_id          User identifier.
	 * @param int    $assistant_id     Optional assistant ID to filter by.
	 * @param string $additional_where Optional additional WHERE condition (e.g., 'session_key = %s').
	 * @param array  $additional_values Optional array of values corresponding to placeholders in $additional_where.
	 * @return array Array with 'where_sql' and 'where_values' keys.
	 */
	private function build_user_id_fallback_where( $user_id, $assistant_id = 0, $additional_where = '', $additional_values = array() ) {
		$where_clauses = array();
		$where_values  = array();

		// Add additional conditions first (e.g., session_key).
		if ( ! empty( $additional_where ) ) {
			$where_clauses[] = $additional_where;
			$where_values    = array_merge( $where_values, $additional_values );
		}

		// Add user_id filter.
		$where_clauses[] = 'user_id = %d';
		$where_values[]  = $user_id;

		// Add assistant_id filter if provided.
		if ( $assistant_id > 0 ) {
			$where_clauses[] = 'assistant_id = %s';
			$where_values[]  = (string) $assistant_id;
		}

		return array(
			'where_sql'    => implode( ' AND ', $where_clauses ),
			'where_values' => $where_values,
		);
	}
}
