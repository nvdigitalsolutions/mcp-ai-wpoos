<?php
/**
 * Tool Embedding Store — Persistent tool-embedding storage and retrieval.
 *
 * Stores pre-computed embedding vectors for every registered tool so the
 * attention router can rank tools by semantic similarity without calling
 * the embedding API on every chat request.
 *
 * Inspired by Transformer architecture: tool definitions are the "Keys"
 * that the query "attends" to. Pre-computing them is analogous to the KV
 * cache — compute once, reuse across requests.
 *
 * @package WP_MCP_AI
 * @since   1.8.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent store for tool embedding vectors.
 *
 * Each tool's embedding is keyed by (tool_slug, provider_id, model) so
 * switching embedding providers or models triggers a re-computation.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_Tool_Embedding_Store {

	/**
	 * Default embedding model.
	 *
	 * @var string
	 */
	const DEFAULT_MODEL = 'text-embedding-3-small';

	/**
	 * Minimum cosine similarity for attention routing.
	 *
	 * Tools scoring below this threshold are excluded from the candidate set.
	 *
	 * @var float
	 */
	const ATTENTION_THRESHOLD = 0.25;

	/**
	 * Return the full table name for tool embeddings.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wp_mcp_ai_tool_embeddings';
	}

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	/**
	 * Install the tool-embeddings table.
	 *
	 * Safe to call multiple times — dbDelta only applies changes.
	 *
	 * @since 1.8.0
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = self::table_name();

		$sql = "CREATE TABLE {$table} (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			tool_slug    VARCHAR(128)        NOT NULL,
			provider_id  VARCHAR(64)         NOT NULL DEFAULT 'openai',
			model        VARCHAR(128)        NOT NULL DEFAULT 'text-embedding-3-small',
			dim          INT(11)             NOT NULL DEFAULT 0,
			vector       LONGBLOB,
			text_hash    VARCHAR(64)         NOT NULL DEFAULT '',
			created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY   tool_model (tool_slug, provider_id, model),
			KEY          provider_id (provider_id),
			KEY          text_hash (text_hash)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	// -------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------

	/**
	 * Store a tool embedding vector.
	 *
	 * Upserts the row — if a vector already exists for (slug, provider, model)
	 * it is replaced.
	 *
	 * @since 1.8.0
	 *
	 * @param string  $tool_slug   Tool slug.
	 * @param float[] $vector      Embedding vector (float array).
	 * @param string  $provider_id Embedding provider id ("openai", "ollama", …).
	 * @param string  $model       Embedding model id.
	 * @param string  $source_text Text that was embedded (used for invalidation).
	 * @return bool True on success.
	 */
	public static function store( $tool_slug, array $vector, $provider_id, $model, $source_text = '' ) {
		global $wpdb;

		$tool_slug   = sanitize_key( $tool_slug );
		$provider_id = sanitize_key( $provider_id );
		$model       = sanitize_text_field( $model );

		if ( '' === $tool_slug || '' === $provider_id || '' === $model || empty( $vector ) ) {
			return false;
		}

		// Pack as float32 binary (consistent with Graphify pattern).
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
					(tool_slug, provider_id, model, dim, vector, text_hash)
				VALUES (%s, %s, %s, %d, %s, %s)
				ON DUPLICATE KEY UPDATE
					dim = VALUES(dim),
					vector = VALUES(vector),
					text_hash = VALUES(text_hash),
					updated_at = CURRENT_TIMESTAMP",
				$tool_slug,
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
	 * Retrieve a stored embedding for a single tool.
	 *
	 * @since 1.8.0
	 *
	 * @param string $tool_slug   Tool slug.
	 * @param string $provider_id Embedding provider id.
	 * @param string $model       Embedding model id.
	 * @return float[]|null Float array or null if not found.
	 */
	public static function get( $tool_slug, $provider_id, $model ) {
		global $wpdb;

		$tool_slug   = sanitize_key( $tool_slug );
		$provider_id = sanitize_key( $provider_id );
		$model       = sanitize_text_field( $model );

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT vector FROM {$table} WHERE tool_slug = %s AND provider_id = %s AND model = %s LIMIT 1",
				$tool_slug,
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
	 * Retrieve all stored embeddings for a given provider/model pair.
	 *
	 * @since 1.8.0
	 *
	 * @param string $provider_id Embedding provider id.
	 * @param string $model       Embedding model id.
	 * @return array<int, array{tool_slug: string, vector: float[]}>
	 */
	public static function get_all( $provider_id, $model ) {
		global $wpdb;

		$provider_id = sanitize_key( $provider_id );
		$model       = sanitize_text_field( $model );

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tool_slug, vector FROM {$table} WHERE provider_id = %s AND model = %s",
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
				'tool_slug' => $row->tool_slug,
				'vector'    => $vec,
			);
		}

		return $results;
	}

	/**
	 * Check whether an embedding exists and is current.
	 *
	 * Compares the stored text_hash with a hash of the current source text.
	 *
	 * @since 1.8.0
	 *
	 * @param string $tool_slug   Tool slug.
	 * @param string $provider_id Embedding provider id.
	 * @param string $model       Embedding model id.
	 * @param string $source_text Current source text to compare against.
	 * @return bool True if a fresh embedding exists.
	 */
	public static function is_fresh( $tool_slug, $provider_id, $model, $source_text ) {
		global $wpdb;

		$tool_slug   = sanitize_key( $tool_slug );
		$provider_id = sanitize_key( $provider_id );
		$model       = sanitize_text_field( $model );
		$text_hash   = md5( (string) $source_text );

		$table = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stored_hash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT text_hash FROM {$table} WHERE tool_slug = %s AND provider_id = %s AND model = %s AND text_hash = %s LIMIT 1",
				$tool_slug,
				$provider_id,
				$model,
				$text_hash
			)
		);
		// phpcs:enable

		return null !== $stored_hash;
	}

	/**
	 * Count how many tools have embeddings stored.
	 *
	 * @since 1.8.0
	 *
	 * @param string $provider_id Embedding provider id (empty = all).
	 * @param string $model       Embedding model id (empty = all).
	 * @return int Number of stored embeddings.
	 */
	public static function count( $provider_id = '', $model = '' ) {
		global $wpdb;

		$table  = self::table_name();
		$where  = array();
		$params = array();

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
	 * Delete all embeddings for a specific tool slug.
	 *
	 * @since 1.8.0
	 *
	 * @param string $tool_slug Tool slug.
	 * @return int Number of rows deleted.
	 */
	public static function delete( $tool_slug ) {
		global $wpdb;

		$tool_slug = sanitize_key( $tool_slug );
		$table     = self::table_name();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->delete( $table, array( 'tool_slug' => $tool_slug ), array( '%s' ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Clear all stored embeddings.
	 *
	 * Used when switching embedding providers or models.
	 *
	 * @since 1.8.0
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
	 * @since 1.8.0
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
