<?php
/**
 * Transcript Repository
 *
 * Handles database operations for chat transcripts.
 * Part of Phase 1.3 separation of concerns refactoring.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
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
				__( 'Chat transcripts are not available. Ensure JetEngine Custom Content Types is active and that the /wp-json/jet-cct/ai_chat_transcripts endpoint loads successfully.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		$table        = esc_sql( $this->get_table_name() );
		$user_id      = absint( $user_id );
		$assistant_id = absint( $assistant_id );
		$per_page     = max( 1, (int) $per_page );
		$page         = max( 1, (int) $page );
		$offset       = ( $page - 1 ) * $per_page;

		// Try with user_id first (custom field defined in CCT schema).
		$where_clauses = array( 'user_id = %d' );
		$where_values  = array( $user_id );

		if ( $assistant_id > 0 ) {
			$where_clauses[] = 'assistant_id = %s';
			$where_values[]  = (string) $assistant_id;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		$query_values   = array_merge( $where_values, array( $per_page, $offset ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $where_sql contains only hardcoded placeholders.
		$query_template = "SELECT session_key,
                MIN(request_started_at) AS started_at,
                MAX(response_completed_at) AS completed_at,
                MIN(cct_created) AS first_created,
                MAX(cct_created) AS last_created,
                MAX(assistant_id) AS assistant_id,
                MAX(assistant_model) AS assistant_model,
                COUNT(*) AS turn_count
         FROM {$table}
         WHERE {$where_sql}
         GROUP BY session_key
         ORDER BY MAX(cct_created) DESC, session_key ASC
         LIMIT %d OFFSET %d";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template is built above with escaped table name and hardcoded placeholders.
		$query = $wpdb->prepare( $query_template, $query_values );

		$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		// Fallback: If no rows found with user_id, try with cct_author_id.
		// This handles JetEngine's built-in author tracking field.
		if ( empty( $rows ) ) {
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
         FROM {$table}
         WHERE {$fallback_where_sql}
         GROUP BY session_key
         ORDER BY MAX(cct_created) DESC, session_key ASC
         LIMIT %d OFFSET %d";
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template is built above with escaped table name and hardcoded placeholders.
			$fallback_query = $wpdb->prepare( $fallback_query_template, $fallback_query_values );

			$rows = $wpdb->get_results( $fallback_query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			if ( ! is_array( $rows ) ) {
				$rows = array();
			}
		}

		// Get total count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $where_sql contains only hardcoded placeholders.
		$total_query_template = "SELECT COUNT(DISTINCT session_key) FROM {$table} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template is built above with escaped table name and hardcoded placeholders.
		$total_query          = $wpdb->prepare( $total_query_template, $where_values );

		$total = (int) $wpdb->get_var( $total_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// If we used fallback for rows, also use fallback for total count.
		if ( 0 === $total && ! empty( $rows ) ) {
			$fallback_where_clauses = array( 'cct_author_id = %d' );
			$fallback_where_values  = array( $user_id );

			if ( $assistant_id > 0 ) {
				$fallback_where_clauses[] = 'assistant_id = %s';
				$fallback_where_values[]  = (string) $assistant_id;
			}

			$fallback_where_sql            = implode( ' AND ', $fallback_where_clauses );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $fallback_where_sql contains only hardcoded placeholders.
			$fallback_total_query_template = "SELECT COUNT(DISTINCT session_key) FROM {$table} WHERE {$fallback_where_sql}";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template is built above with escaped table name and hardcoded placeholders.
			$fallback_total_query          = $wpdb->prepare( $fallback_total_query_template, $fallback_where_values );

			$total = (int) $wpdb->get_var( $fallback_total_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return array(
			'items' => $rows,
			'total' => $total,
		);
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
				__( 'Session key is required.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->table_exists() ) {
			return new WP_Error(
				'wp_mcp_ai_transcripts_unavailable',
				__( 'Chat transcripts are not available.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		$table        = esc_sql( $this->get_table_name() );
		$user_id      = absint( $user_id );
		$assistant_id = absint( $assistant_id );

		$select_fields = $this->get_select_fields();

		// Query 1: Try with session_key + user_id + assistant_id (custom user_id field).
		$where_clauses = array( 'session_key = %s', 'user_id = %d' );
		$where_values  = array( $session_key, $user_id );

		if ( $assistant_id > 0 ) {
			$where_clauses[] = 'assistant_id = %s';
			$where_values[]  = (string) $assistant_id;
		}

		$where_sql      = implode( ' AND ', $where_clauses );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $select_fields and $where_sql contain only hardcoded strings/placeholders.
		$query_template = "SELECT {$select_fields}
         FROM {$table}
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

		$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

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

		// Query 2: If no rows, try with cct_author_id (JetEngine built-in field).
		if ( empty( $rows ) ) {
			$author_where_clauses = array( 'session_key = %s', 'cct_author_id = %d' );
			$author_where_values  = array( $session_key, $user_id );

			if ( $assistant_id > 0 ) {
				$author_where_clauses[] = 'assistant_id = %s';
				$author_where_values[]  = (string) $assistant_id;
			}

			$author_where_sql      = implode( ' AND ', $author_where_clauses );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $select_fields and $author_where_sql contain only hardcoded strings/placeholders.
			$author_query_template = "SELECT {$select_fields}
         FROM {$table}
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

			$rows = $wpdb->get_results( $author_query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

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
         FROM {$table}
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

			$rows = $wpdb->get_results( $simple_query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			// If still no rows, try with cct_author_id.
			if ( empty( $rows ) ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $select_fields contains only hardcoded strings.
				$simple_author_query_template = "SELECT {$select_fields}
         FROM {$table}
         WHERE session_key = %s AND cct_author_id = %d
         ORDER BY cct_created ASC, _ID ASC";
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query template is built above with escaped table name and hardcoded field names.
				$simple_author_query = $wpdb->prepare( $simple_author_query_template, array( $session_key, $user_id ) );

				$rows = $wpdb->get_results( $simple_author_query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
         FROM {$table}
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

			$rows = $wpdb->get_results( $session_only_query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

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
				__( 'The requested chat transcript could not be found.', 'wp-mcp-ai' ),
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
			$deleted = $wpdb->delete(
				$table,
				array(
					'session_key'   => $session_key,
					'cct_author_id' => $user_id,
				),
				array( '%s', '%d' )
			);
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
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $where_sql contains only hardcoded placeholders.
		$query = $wpdb->prepare(
			"SELECT _ID FROM {$table} WHERE {$where_sql} ORDER BY cct_created DESC, _ID DESC LIMIT 1",
			$where_values
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$record_id = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

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

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is escaped with esc_sql(), $author_where_sql contains only hardcoded placeholders.
		$author_query = $wpdb->prepare(
			"SELECT _ID FROM {$table} WHERE {$author_where_sql} ORDER BY cct_created DESC, _ID DESC LIMIT 1",
			$author_where_values
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$record_id = $wpdb->get_var( $author_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

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
