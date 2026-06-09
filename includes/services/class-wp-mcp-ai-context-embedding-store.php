<?php
/**
 * Context Embedding Store — Persistent context-embedding storage and retrieval.
 *
 * Stores pre-computed embedding vectors for agent context records so the
 * vector context service can rank contexts by semantic similarity without
 * calling the embedding API on every search request.
 *
 * Pattern follows {@see WP_MCP_AI_Tool_Embedding_Store} and
 * {@see WP_MCP_AI_Content_Embedding_Store} — embeddings are keyed by
 * (context_id, agent_id, provider_id, model).
 *
 * @package WP_MCP_AI
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent store for context embedding vectors.
 *
 * Each context's embedding is keyed by (context_id, agent_id, provider_id,
 * model) so switching providers or models triggers a re-computation.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_Context_Embedding_Store {

	/**
	 * Default embedding model.
	 *
	 * @var string
	 */
	const DEFAULT_MODEL = 'text-embedding-3-small';

	/**
	 * Return the full table name for context embeddings.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wp_mcp_ai_context_embeddings';
	}

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	/**
	 * Install the context-embeddings table.
	 *
	 * Safe to call multiple times — dbDelta only applies changes.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::table_name();

		$sql = "CREATE TABLE {$table} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			context_id   VARCHAR(191)        NOT NULL,
			agent_id     BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			provider_id  VARCHAR(64)         NOT NULL DEFAULT 'openai',
			model        VARCHAR(128)        NOT NULL DEFAULT 'text-embedding-3-small',
			dim          INT(11)             NOT NULL DEFAULT 0,
			vector       LONGBLOB,
			text_hash    VARCHAR(64)         NOT NULL DEFAULT '',
			created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY   ctx_agent_model (context_id, agent_id, provider_id, model),
			KEY          agent_id (agent_id),
			KEY          text_hash (text_hash)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	// -------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------

	/**
	 * Store a context embedding vector.
	 *
	 * Upserts the row — if a vector already exists for (context_id, agent_id,
	 * provider_id, model) it is replaced.
	 *
	 * @since 1.9.0
	 *
	 * @param string  $context_id  Context identifier.
	 * @param int     $agent_id    Agent identifier.
	 * @param float[] $vector      Embedding vector (float array).
	 * @param string  $provider_id Embedding provider id ("openai", "ollama", …).
	 * @param string  $model       Embedding model id.
	 * @param string  $source_text Text that was embedded (used for invalidation).
	 * @return bool True on success.
	 */
	public static function store( $context_id, $agent_id, array $vector, $provider_id, $model, $source_text = '' ) {
		global $wpdb;

		$context_id  = sanitize_text_field( $context_id );
		$agent_id    = absint( $agent_id );
		$provider_id = sanitize_key( $provider_id );
		$model       = sanitize_text_field( $model );

		if ( '' === $context_id || '' === $provider_id || '' === $model || empty( $vector ) ) {
			return false;
		}

		// Pack as float32 binary (consistent with Tool_Embedding_Store and Graphify pattern).
		$binary = '';
		foreach ( $vector as $v ) {
			$binary .= pack( 'f', (float) $v );
		}

		$dim       = count( $vector );
		$text_hash = '' !== $source_text ? md5( $source_text ) : '';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a hardcoded name from self::table_name()
		$table = self::table_name();

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
					(context_id, agent_id, provider_id, model, dim, vector, text_hash)
				VALUES (%s, %d, %s, %s, %d, %s, %s)
				ON DUPLICATE KEY UPDATE
					dim = VALUES(dim),
					vector = VALUES(vector),
					text_hash = VALUES(text_hash),
					updated_at = CURRENT_TIMESTAMP",
				$context_id,
				$agent_id,
				$provider_id,
				$model,
				$dim,
				$binary,
				$text_hash
			)
		);
		// phpcs:enable

		return false !== $result;
	}

	/**
	 * Retrieve a stored embedding for a single context.
	 *
	 * @since 1.9.0
	 *
	 * @param string $context_id  Context identifier.
	 * @param int    $agent_id    Agent identifier.
	 * @param string $provider_id Embedding provider id.
	 * @param string $model       Embedding model id.
	 * @return float[]|null Float array or null if not found.
	 */
	public static function get( $context_id, $agent_id, $provider_id, $model ) {
		global $wpdb;

		$context_id  = sanitize_text_field( $context_id );
		$agent_id    = absint( $agent_id );
		$provider_id = sanitize_key( $provider_id );
		$model       = sanitize_text_field( $model );

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT vector FROM {$table} WHERE context_id = %s AND agent_id = %d AND provider_id = %s AND model = %s LIMIT 1",
				$context_id,
				$agent_id,
				$provider_id,
				$model
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row || empty( $row->vector ) ) {
			return null;
		}

		return self::unpack_vector( $row->vector );
	}

	/**
	 * Retrieve all stored embeddings for a given agent / provider / model.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $agent_id    Agent identifier.
	 * @param string $provider_id Embedding provider id.
	 * @param string $model       Embedding model id.
	 * @return array<int, array{context_id: string, vector: float[]}>
	 */
	public static function get_all_for_agent( $agent_id, $provider_id, $model ) {
		global $wpdb;

		$agent_id    = absint( $agent_id );
		$provider_id = sanitize_key( $provider_id );
		$model       = sanitize_text_field( $model );

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT context_id, vector FROM {$table} WHERE agent_id = %d AND provider_id = %s AND model = %s",
				$agent_id,
				$provider_id,
				$model
			)
		);
		// phpcs:enable

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$results = array();
		foreach ( $rows as $row ) {
			if ( empty( $row->vector ) ) {
				continue;
			}
			$vec = self::unpack_vector( $row->vector );
			if ( null === $vec ) {
				continue;
			}
			$results[] = array(
				'context_id' => $row->context_id,
				'vector'     => $vec,
			);
		}

		return $results;
	}

	/**
	 * Check whether an embedding exists and is current.
	 *
	 * Compares the stored text_hash with a hash of the current source text.
	 *
	 * @since 1.9.0
	 *
	 * @param string $context_id  Context identifier.
	 * @param int    $agent_id    Agent identifier.
	 * @param string $provider_id Embedding provider id.
	 * @param string $model       Embedding model id.
	 * @param string $source_text Current source text to compare against.
	 * @return bool True if a fresh embedding exists.
	 */
	public static function is_fresh( $context_id, $agent_id, $provider_id, $model, $source_text ) {
		global $wpdb;

		$context_id  = sanitize_text_field( $context_id );
		$agent_id    = absint( $agent_id );
		$provider_id = sanitize_key( $provider_id );
		$model       = sanitize_text_field( $model );
		$text_hash   = md5( (string) $source_text );

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stored_hash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT text_hash FROM {$table} WHERE context_id = %s AND agent_id = %d AND provider_id = %s AND model = %s AND text_hash = %s LIMIT 1",
				$context_id,
				$agent_id,
				$provider_id,
				$model,
				$text_hash
			)
		);
		// phpcs:enable

		return null !== $stored_hash;
	}

	/**
	 * Delete all embeddings for a specific context.
	 *
	 * @since 1.9.0
	 *
	 * @param string $context_id Context identifier.
	 * @param int    $agent_id   Agent identifier.
	 * @return int Number of rows deleted.
	 */
	public static function delete( $context_id, $agent_id = 0 ) {
		global $wpdb;

		$context_id = sanitize_text_field( $context_id );
		$table      = self::table_name();

		if ( $agent_id > 0 ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->delete(
				$table,
				array(
					'context_id' => $context_id,
					'agent_id'   => absint( $agent_id ),
				),
				array( '%s', '%d' )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->delete( $table, array( 'context_id' => $context_id ), array( '%s' ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Delete all embeddings for an agent.
	 *
	 * @since 1.9.0
	 *
	 * @param int $agent_id Agent identifier.
	 * @return int Number of rows deleted.
	 */
	public static function delete_for_agent( $agent_id ) {
		global $wpdb;

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->delete( $table, array( 'agent_id' => absint( $agent_id ) ), array( '%d' ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count how many contexts have embeddings stored.
	 *
	 * @since 1.9.0
	 *
	 * @param int    $agent_id    Agent identifier (0 = all).
	 * @param string $provider_id Embedding provider id (empty = all).
	 * @param string $model       Embedding model id (empty = all).
	 * @return int Number of stored embeddings.
	 */
	public static function count( $agent_id = 0, $provider_id = '', $model = '' ) {
		global $wpdb;

		$table  = self::table_name();
		$where  = array();
		$params = array();

		if ( $agent_id > 0 ) {
			$where[]  = 'agent_id = %d';
			$params[] = absint( $agent_id );
		}

		if ( '' !== $provider_id ) {
			$where[]  = 'provider_id = %s';
			$params[] = sanitize_key( $provider_id );
		}

		if ( '' !== $model ) {
			$where[]  = 'model = %s';
			$params[] = sanitize_text_field( $model );
		}

		$where_clause = '';
		if ( ! empty( $where ) ) {
			$where_clause = 'WHERE ' . implode( ' AND ', $where );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $params ) ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_clause}", ...$params ) );
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:enable
	}

	/**
	 * Clear all stored embeddings.
	 *
	 * Used when switching embedding providers or models.
	 *
	 * @since 1.9.0
	 *
	 * @param string $provider_id If set, only clear for this provider.
	 * @return int Number of rows deleted.
	 */
	public static function clear_all( $provider_id = '' ) {
		global $wpdb;

		$table = self::table_name();

		if ( '' !== $provider_id ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->delete( $table, array( 'provider_id' => $provider_id ), array( '%s' ) );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->query( "TRUNCATE TABLE {$table}" );
		// phpcs:enable
	}

	// -------------------------------------------------------------------------
	// Vector helpers
	// -------------------------------------------------------------------------

	/**
	 * Unpack a binary float32 blob into a float array.
	 *
	 * @since 1.9.0
	 *
	 * @param string $binary Packed binary string.
	 * @return float[]|null Float array or null on error.
	 */
	private static function unpack_vector( $binary ) {
		if ( empty( $binary ) ) {
			return null;
		}
		$len      = strlen( $binary );
		$count    = (int) ( $len / 4 );
		$unpacked = unpack( 'f' . $count, $binary );
		if ( false === $unpacked || empty( $unpacked ) ) {
			return null;
		}
		return array_values( $unpacked );
	}
}
