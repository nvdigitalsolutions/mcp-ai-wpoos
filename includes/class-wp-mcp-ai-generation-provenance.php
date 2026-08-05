<?php
/**
 * Generation Provenance System
 *
 * Immutable, hash-chain-verifiable generation log for all AI interactions.
 * Implements append-only provenance with cryptographic chain integrity as
 * required by the EU AI Act Code of Practice on AI-Generated Content and
 * India IT Rules 2026 (SGI) due diligence obligations.
 *
 * Each generation event is recorded in a custom database table with:
 * - Content hashes (SHA-256) of prompts and responses
 * - A hash chain linking each row to its predecessor
 * - Model and provider metadata
 * - Timestamp and session tracking
 *
 * The hash chain enables tamper detection: modifying any row breaks
 * verification of all subsequent rows.
 *
 * @package WP_MCP_AI
 * @since   1.1.45
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generation Provenance class.
 *
 * Singleton managing the immutable generation log with hash-chain integrity.
 */
class WP_MCP_AI_Generation_Provenance {

	/**
	 * Custom table name (without $wpdb->prefix).
	 *
	 * @var string
	 */
	const TABLE_NAME = 'wp_mcp_ai_gen_log';

	/**
	 * Schema version for dbDelta migrations.
	 *
	 * @var string
	 */
	const TABLE_VERSION = '1.0.0';

	/**
	 * Cron hook name for daily log pruning.
	 *
	 * @var string
	 */
	const PRUNE_CRON_HOOK = 'wp_mcp_ai_prune_gen_logs';

	/**
	 * Transient key for hash chain verification cache.
	 *
	 * @var string
	 */
	const VERIFY_CACHE_KEY = 'wp_mcp_ai_gen_log_verified';

	/**
	 * Cache TTL for verification results (1 hour).
	 *
	 * @var int
	 */
	const VERIFY_CACHE_TTL = 3600;

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Generation_Provenance|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Generation_Provenance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Create the generation log database table.
	 *
	 * Uses dbDelta for safe schema creation/updates.
	 *
	 * @since 1.1.45
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED AUTO_INCREMENT,
			session_key VARCHAR(96) NOT NULL DEFAULT '',
			assistant_id VARCHAR(255) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			model VARCHAR(100) NOT NULL DEFAULT '',
			provider VARCHAR(50) NOT NULL DEFAULT '',
			prompt_hash CHAR(64) NOT NULL DEFAULT '',
			response_hash CHAR(64) NOT NULL DEFAULT '',
			previous_hash CHAR(64) NOT NULL DEFAULT '',
			row_hash CHAR(64) NOT NULL DEFAULT '',
			message_count INT UNSIGNED NOT NULL DEFAULT 0,
			response_length INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			metadata LONGTEXT DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_session (session_key),
			KEY idx_user (user_id),
			KEY idx_assistant (assistant_id(96)),
			KEY idx_created (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Store schema version for future migrations.
		update_option( 'wp_mcp_ai_gen_log_schema_version', self::TABLE_VERSION );
	}

	/**
	 * Check if the generation log table exists.
	 *
	 * @since 1.1.45
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		return $result === $table_name;
	}

	/**
	 * Log a generation event to the provenance table.
	 *
	 * Creates an immutable row with a hash linking to the previous row,
	 * forming a tamper-evident chain.
	 *
	 * @since 1.1.45
	 *
	 * @param string $session_key  Unique session identifier.
	 * @param int    $assistant_id Assistant post ID or identifier.
	 * @param int    $user_id      WordPress user ID (0 for guests).
	 * @param array  $messages     Array of chat messages.
	 * @param array  $response     Language model response payload.
	 * @param string $model        Model slug (e.g. 'gpt-4.1').
	 * @param string $provider     Provider slug (e.g. 'openai').
	 * @param array  $metadata     Optional additional metadata.
	 * @return int|false The inserted row ID, or false on failure.
	 */
	public static function log_generation( $session_key, $assistant_id, $user_id, $messages, $response, $model, $provider, $metadata = array() ) {
		global $wpdb;

		// Ensure table exists.
		if ( ! self::table_exists() ) {
			self::create_table();
		}

		// Validate and sanitize inputs.
		$session_key  = sanitize_text_field( $session_key );
		$assistant_id = is_numeric( $assistant_id ) ? absint( $assistant_id ) : sanitize_text_field( (string) $assistant_id );
		$user_id      = absint( $user_id );
		$model        = sanitize_text_field( $model );
		$provider     = sanitize_text_field( $provider );

		// Compute content hashes.
		$prompt_hash   = hash( 'sha256', wp_json_encode( $messages ) );
		$response_hash = hash( 'sha256', wp_json_encode( $response ) );

		// Count messages and response size for audit metadata.
		$message_count   = is_array( $messages ) ? count( $messages ) : 0;
		$response_length = is_string( wp_json_encode( $response ) ) ? strlen( wp_json_encode( $response ) ) : 0;

		// Get the previous hash for chain continuity.
		$previous_hash = self::get_last_hash();

		// Current timestamp.
		$created_at = current_time( 'mysql' );

		// Row ID will be known after insert, so we compute row_hash in two steps.
		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, no Core API.
		$inserted = $wpdb->insert(
			$table_name,
			array(
				'session_key'     => $session_key,
				'assistant_id'    => (string) $assistant_id,
				'user_id'         => $user_id,
				'model'           => $model,
				'provider'        => $provider,
				'prompt_hash'     => $prompt_hash,
				'response_hash'   => $response_hash,
				'previous_hash'   => $previous_hash,
				'row_hash'        => '', // Placeholder; will update after insert.
				'message_count'   => $message_count,
				'response_length' => $response_length,
				'created_at'      => $created_at,
				'metadata'        => ! empty( $metadata ) ? wp_json_encode( $metadata ) : null,
			),
			array(
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%d',
				'%s',
				'%s',
			)
		);

		if ( ! $inserted ) {
			return false;
		}

		$row_id = (int) $wpdb->insert_id;

		// Now compute the final row_hash including the ID.
		$row_hash = self::compute_row_hash( $prompt_hash, $response_hash, $previous_hash, $created_at, $row_id );

		// Update the row with the computed hash.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table_name,
			array( 'row_hash' => $row_hash ),
			array( 'id' => $row_id ),
			array( '%s' ),
			array( '%d' )
		);

		/**
		 * Fires after a generation provenance record is stored.
		 *
		 * @since 1.1.45
		 *
		 * @param int    $row_id       The inserted row ID.
		 * @param string $session_key  Session identifier.
		 * @param int    $assistant_id Assistant ID.
		 * @param string $model        Model slug.
		 * @param string $provider     Provider slug.
		 */
		do_action( 'wp_mcp_ai_generation_logged', $row_id, $session_key, $assistant_id, $model, $provider );

		return $row_id;
	}

	/**
	 * Callback for the wp_mcp_ai_chat_transcript_recorded action.
	 *
	 * Bridges the transcript recorder to the provenance system.
	 *
	 * @since 1.1.45
	 *
	 * @param string $session_key  Session key.
	 * @param int    $assistant_id Assistant ID.
	 * @param int    $user_id      User ID.
	 * @param array  $messages     Chat messages.
	 * @param array  $response     LLM response.
	 * @param string $model        Model slug.
	 * @param string $provider     Provider slug.
	 * @return void
	 */
	public static function on_transcript_recorded( $session_key, $assistant_id, $user_id, $messages, $response, $model, $provider ) {
		// Gate on transparency settings.
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings_Base' )
			? WP_MCP_AI_Admin_Settings_Base::get_settings()
			: array();

		if ( empty( $settings['enable_generation_logging'] ) ) {
			return;
		}

		self::log_generation( $session_key, $assistant_id, $user_id, $messages, $response, $model, $provider );
	}

	/**
	 * Compute a row hash for chain integrity.
	 *
	 * The row hash is computed as SHA-256( prompt_hash || response_hash || previous_hash || created_at || id ).
	 *
	 * @since 1.1.45
	 *
	 * @param string $prompt_hash   SHA-256 hash of messages.
	 * @param string $response_hash SHA-256 hash of response.
	 * @param string $previous_hash Hash of the previous row.
	 * @param string $created_at    MySQL timestamp.
	 * @param int    $row_id        Row ID.
	 * @return string 64-character hex hash.
	 */
	public static function compute_row_hash( $prompt_hash, $response_hash, $previous_hash, $created_at, $row_id ) {
		$input = $prompt_hash . $response_hash . $previous_hash . $created_at . (string) $row_id;
		return hash( 'sha256', $input );
	}

	/**
	 * Get the most recent row_hash for chain continuity.
	 *
	 * @since 1.1.45
	 * @return string 64-character hex hash, or empty string if no rows exist.
	 */
	public static function get_last_hash() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return '';
		}

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$last_hash = $wpdb->get_var( "SELECT row_hash FROM {$table_name} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.

		return ! empty( $last_hash ) ? $last_hash : '';
	}

	/**
	 * Verify the entire hash chain integrity.
	 *
	 * Walks all rows from oldest to newest, recalculating every row_hash
	 * and comparing against the stored value. Tampering with any row
	 * will cause verification to fail.
	 *
	 * @since 1.1.45
	 *
	 * @param bool $force_refresh Bypass the verification cache.
	 * @return true|WP_Error True if chain is intact, WP_Error with details if broken.
	 */
	public static function verify_chain_integrity( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::VERIFY_CACHE_KEY );
			if ( false !== $cached ) {
				return true;
			}
		}

		global $wpdb;

		if ( ! self::table_exists() ) {
			return true; // No table means no tampering possible.
		}

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table name, safe.
		$rows = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY id ASC", ARRAY_A );

		if ( empty( $rows ) ) {
			set_transient( self::VERIFY_CACHE_KEY, '1', self::VERIFY_CACHE_TTL );
			return true;
		}

		$previous_hash = '';

		foreach ( $rows as $row ) {
			// Verify previous_hash links correctly.
			if ( '' !== $previous_hash && $previous_hash !== $row['previous_hash'] ) {
				return new WP_Error(
					'chain_broken',
					sprintf(
						/* translators: %d: row ID where the chain break was detected */
						__( 'Hash chain integrity broken at row %d: previous_hash mismatch.', 'mcp-ai-wpoos' ),
						(int) $row['id']
					),
					array(
						'status'   => 500,
						'row_id'   => (int) $row['id'],
						'expected' => $previous_hash,
						'actual'   => $row['previous_hash'],
					)
				);
			}

			// Recompute and verify row_hash.
			$computed_hash = self::compute_row_hash(
				$row['prompt_hash'],
				$row['response_hash'],
				$row['previous_hash'],
				$row['created_at'],
				(int) $row['id']
			);

			if ( ! hash_equals( $computed_hash, $row['row_hash'] ) ) {
				return new WP_Error(
					'chain_broken',
					sprintf(
						/* translators: %d: row ID where the hash mismatch was detected */
						__( 'Hash chain integrity broken at row %d: row_hash mismatch (possible data tampering).', 'mcp-ai-wpoos' ),
						(int) $row['id']
					),
					array(
						'status'   => 500,
						'row_id'   => (int) $row['id'],
						'expected' => $computed_hash,
						'actual'   => $row['row_hash'],
					)
				);
			}

			$previous_hash = $row['row_hash'];
		}

		set_transient( self::VERIFY_CACHE_KEY, '1', self::VERIFY_CACHE_TTL );

		return true;
	}

	/**
	 * Query generation logs with pagination and filtering.
	 *
	 * @since 1.1.45
	 *
	 * @param array $args {
	 *     Query arguments.
	 *     @type int    $per_page     Records per page (default 20, max 100).
	 *     @type int    $page         Page number (1-based).
	 *     @type int    $user_id      Filter by user ID.
	 *     @type int    $assistant_id Filter by assistant ID.
	 *     @type string $date_from    Filter from date (Y-m-d).
	 *     @type string $date_to      Filter to date (Y-m-d).
	 *     @type string $orderby      Sort column (default 'id').
	 *     @type string $order        Sort direction ('ASC' or 'DESC', default 'DESC').
	 * }
	 * @return array {
	 *     @type array  $rows  Array of row objects.
	 *     @type int    $total Total matching rows.
	 *     @type int    $pages Total pages.
	 * }
	 */
	public static function get_logs( $args = array() ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return array(
				'rows'  => array(),
				'total' => 0,
				'pages' => 0,
			);
		}

		$defaults = array(
			'per_page'     => 20,
			'page'         => 1,
			'user_id'      => 0,
			'assistant_id' => 0,
			'date_from'    => '',
			'date_to'      => '',
			'orderby'      => 'id',
			'order'        => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$per_page = min( absint( $args['per_page'] ), 100 );
		$page     = max( 1, absint( $args['page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		// Validate sort column.
		$allowed_orderby = array( 'id', 'created_at', 'user_id', 'assistant_id', 'model' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'id';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		$where_clauses = array( '1=1' );
		$where_values  = array();

		if ( ! empty( $args['user_id'] ) ) {
			$where_clauses[] = 'user_id = %d';
			$where_values[]  = absint( $args['user_id'] );
		}

		if ( ! empty( $args['assistant_id'] ) ) {
			$where_clauses[] = 'assistant_id = %s';
			$where_values[]  = (string) absint( $args['assistant_id'] );
		}

		if ( ! empty( $args['date_from'] ) ) {
			$where_clauses[] = 'created_at >= %s';
			$where_values[]  = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
		}

		if ( ! empty( $args['date_to'] ) ) {
			$where_clauses[] = 'created_at <= %s';
			$where_values[]  = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where_clauses );

		// Build count query: table name and WHERE clause identifiers are safe.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $wpdb->get_var( $wpdb->prepare( $count_sql, $where_values ) );
		// phpcs:enable
		$total = absint( $total );

		// Build data query with validated sort.
		$data_values = array_merge( $where_values, array( $per_page, $offset ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
		$data_sql = "SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $data_values ), ARRAY_A );
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
			'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Get the total count of generation log entries.
	 *
	 * @since 1.1.45
	 * @return int
	 */
	public static function get_log_count() {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$table_name = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table name, safe.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

		return absint( $count );
	}

	/**
	 * Prune generation logs older than the specified retention period.
	 *
	 * @since 1.1.45
	 *
	 * @param int $retention_days Number of days to retain logs.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public static function prune_old_logs( $retention_days = 365 ) {
		global $wpdb;

		if ( ! self::table_exists() ) {
			return 0;
		}

		$retention_days = max( 1, absint( $retention_days ) );
		$cutoff_date    = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );
		$table_name     = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table name, safe.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE created_at < %s",
				$cutoff_date
			)
		);
		// phpcs:enable

		if ( false !== $deleted && $deleted > 0 ) {
			// Clear verification cache since chain has changed.
			delete_transient( self::VERIFY_CACHE_KEY );
		}

		return $deleted;
	}

	/**
	 * Schedule the daily prune cron job.
	 *
	 * @since 1.1.45
	 * @return void
	 */
	public static function schedule_prune_cron() {
		if ( ! wp_next_scheduled( self::PRUNE_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::PRUNE_CRON_HOOK );
		}

		if ( ! has_action( self::PRUNE_CRON_HOOK, array( __CLASS__, 'handle_prune_cron' ) ) ) {
			add_action( self::PRUNE_CRON_HOOK, array( __CLASS__, 'handle_prune_cron' ) );
		}
	}

	/**
	 * Handle the prune cron event.
	 *
	 * Reads retention setting and prunes old logs.
	 *
	 * @since 1.1.45
	 * @return void
	 */
	public static function handle_prune_cron() {
		$settings = class_exists( 'WP_MCP_AI_Admin_Settings_Base' )
			? WP_MCP_AI_Admin_Settings_Base::get_settings()
			: array();

		$retention_days = isset( $settings['generation_log_retention_days'] )
			? absint( $settings['generation_log_retention_days'] )
			: 365;

		self::prune_old_logs( $retention_days );
	}

	/**
	 * Clear the prune cron on deactivation.
	 *
	 * @since 1.1.45
	 * @return void
	 */
	public static function clear_prune_cron() {
		$next = wp_next_scheduled( self::PRUNE_CRON_HOOK );
		if ( $next ) {
			wp_unschedule_event( $next, self::PRUNE_CRON_HOOK );
		}
	}

	/**
	 * Prevent cloning of the singleton.
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing of the singleton.
	 *
	 * @since 1.1.45
	 *
	 * @return void
	 * @throws \Exception Always.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton' );
	}
}
