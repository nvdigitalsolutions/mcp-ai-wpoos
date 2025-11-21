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

		$table        = $this->get_table_name();
		$user_id      = absint( $user_id );
		$assistant_id = absint( $assistant_id );
		$per_page     = max( 1, (int) $per_page );
		$page         = max( 1, (int) $page );
		$offset       = ( $page - 1 ) * $per_page;

		$where_clauses = array( 'user_id = %d' );
		$where_values  = array( $user_id );

		if ( $assistant_id > 0 ) {
			$where_clauses[] = 'assistant_id = %d';
			$where_values[]  = $assistant_id;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		$query_values   = array_merge( $where_values, array( $per_page, $offset ) );
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

		$query = $wpdb->prepare( $query_template, $query_values );

		$rows = $wpdb->get_results( $query, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$total_query_template = "SELECT COUNT(DISTINCT session_key) FROM {$table} WHERE {$where_sql}";
		$total_query          = $wpdb->prepare( $total_query_template, $where_values );

		$total = (int) $wpdb->get_var( $total_query );

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}

	/**
	 * Get a single transcript session with all messages.
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

		$table        = $this->get_table_name();
		$user_id      = absint( $user_id );
		$assistant_id = absint( $assistant_id );

		$where_clauses = array( 'session_key = %s', 'user_id = %d' );
		$where_values  = array( $session_key, $user_id );

		if ( $assistant_id > 0 ) {
			$where_clauses[] = 'assistant_id = %d';
			$where_values[]  = $assistant_id;
		}

		$where_sql = implode( ' AND ', $where_clauses );

		$select_fields  = $this->get_select_fields();
		$query_template = "SELECT {$select_fields}
         FROM {$table}
         WHERE {$where_sql}
         ORDER BY cct_created ASC, id ASC";

		$query = $wpdb->prepare( $query_template, $where_values );

		$rows = $wpdb->get_results( $query, ARRAY_A );

		// Note: Removed fallback query since user_id is now the primary field in CCT schema.

		if ( empty( $rows ) ) {
			return new WP_Error(
				'wp_mcp_ai_transcript_missing',
				__( 'The requested chat transcript could not be found.', 'wp-mcp-ai' ),
				array( 'status' => 404 )
			);
		}

		return $rows;
	}

	/**
	 * Delete transcript entries for a session and user
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

		// Delete using user_id field (the actual CCT field name).
		$deleted = $wpdb->delete(
			$table,
			array(
				'session_key' => $session_key,
				'user_id'     => $user_id,
			),
			array( '%s', '%d' )
		);

		return $deleted;
	}

	/**
	 * Get the SELECT fields for transcript queries.
	 *
	 * @return string SQL SELECT fields.
	 */
	private function get_select_fields() {
		return "request_payload,
                response_payload,
                metadata,
                request_started_at,
                response_completed_at,
                cct_created,
                assistant_id,
                assistant_model,
                latency_ms";
	}
}
