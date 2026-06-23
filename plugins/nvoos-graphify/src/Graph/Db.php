<?php
declare(strict_types=1);

namespace NvoosGraphify\Graph;

use NvoosGraphify\Schema;
use function absint;
use function current_time;
use function esc_url_raw;
use function max;
use function min;
use function sanitize_key;
use function sanitize_text_field;
use function wp_json_encode;

/**
 * Database access object for the NV oOS Graphify plugin.
 *
 * Manages five custom tables:
 *   - nvoos_graphify_nodes            — graph entities (posts, terms, users, media, topics)
 *   - nvoos_graphify_edges            — relationships between nodes
 *   - nvoos_graphify_meta             — key/value addon metadata
 *   - nvoos_graphify_remote_sources   — external data source configurations
 *   - nvoos_graphify_embeddings       — vector embeddings for RAG
 *
 * Uses dbDelta() for safe, incremental schema migrations.
 * All public methods validate + sanitize their inputs and use
 * $wpdb->prepare() for variable-interpolated queries.
 *
 * @since 1.0.0
 */
class Db {

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	// This entire class is a dedicated data-access layer for the plugin's custom
	// database tables. Every query intentionally uses $wpdb directly because
	// WordPress core APIs (WP_Query, WP_Term_Query, etc.) do not operate on
	// custom plugin tables.

	// ─── Table name helpers ────────────────────────────────────

	/** @return string */
	public static function nodesTable(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::TABLE_NODES;
	}

	/** @return string */
	public static function edgesTable(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::TABLE_EDGES;
	}

	/** @return string */
	public static function metaTable(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::TABLE_META;
	}

	/** @return string */
	public static function remoteSourcesTable(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::TABLE_REMOTE_SOURCES;
	}

	/** @return string */
	public static function embeddingsTable(): string {
		global $wpdb;
		return $wpdb->prefix . Schema::TABLE_EMBEDDINGS;
	}

	// ─── Schema install / upgrade ──────────────────────────────

	/**
	 * Install or upgrade the database schema.
	 *
	 * Safe to call multiple times — dbDelta() only applies changes.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		$charsetCollate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$nodes = self::nodesTable();
		$edges = self::edgesTable();
		$meta  = self::metaTable();

		$sqlNodes = "CREATE TABLE {$nodes} (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            node_id      VARCHAR(191)         NOT NULL,
            label        VARCHAR(512)         NOT NULL,
            type         VARCHAR(64)          NOT NULL DEFAULT 'post',
            post_id      BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
            url          VARCHAR(512)         NOT NULL DEFAULT '',
            properties   LONGTEXT,
            degree       INT(11)              NOT NULL DEFAULT 0,
            community_id VARCHAR(64)          NOT NULL DEFAULT '',
            content_hash VARCHAR(64)          NOT NULL DEFAULT '',
            external_id  VARCHAR(512)         NOT NULL DEFAULT '',
            source_slug  VARCHAR(128)         NOT NULL DEFAULT '',
            confidence   FLOAT                NOT NULL DEFAULT 1.0,
            expires_at   DATETIME             DEFAULT NULL,
            created_at   DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at   DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   node_id (node_id),
            KEY          type (type),
            KEY          post_id (post_id),
            KEY          community_id (community_id),
            KEY          external_id (external_id(64)),
            KEY          source_slug (source_slug)
        ) {$charsetCollate};";

		$sqlEdges = "CREATE TABLE {$edges} (
            id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_node_id VARCHAR(191)        NOT NULL,
            target_node_id VARCHAR(191)        NOT NULL,
            relation       VARCHAR(128)        NOT NULL,
            confidence     FLOAT               NOT NULL DEFAULT 1.0,
            provenance     VARCHAR(32)         NOT NULL DEFAULT 'EXTRACTED',
            properties     LONGTEXT,
            source_slug    VARCHAR(128)        NOT NULL DEFAULT '',
            fetched_at     DATETIME            DEFAULT NULL,
            created_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   edge_unique (source_node_id, target_node_id, relation(64)),
            KEY          source_node_id (source_node_id),
            KEY          target_node_id (target_node_id),
            KEY          relation (relation),
            KEY          provenance (provenance),
            KEY          source_slug (source_slug)
        ) {$charsetCollate};";

		$sqlMeta = "CREATE TABLE {$meta} (
            id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            meta_key   VARCHAR(191)        NOT NULL,
            meta_value LONGTEXT,
            updated_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY  meta_key (meta_key)
        ) {$charsetCollate};";

		dbDelta( $sqlNodes );
		dbDelta( $sqlEdges );
		dbDelta( $sqlMeta );

		$remote    = self::remoteSourcesTable();
		$sqlRemote = "CREATE TABLE {$remote} (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            slug          VARCHAR(128)        NOT NULL,
            driver        VARCHAR(64)         NOT NULL,
            label         VARCHAR(255)        NOT NULL DEFAULT '',
            config_json   LONGTEXT,
            enabled       TINYINT(1)          NOT NULL DEFAULT 1,
            rate_limit    INT(11)             NOT NULL DEFAULT 60,
            last_sync_at  DATETIME            DEFAULT NULL,
            last_error    TEXT,
            circuit_state VARCHAR(16)         NOT NULL DEFAULT 'closed',
            created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug)
        ) {$charsetCollate};";
		dbDelta( $sqlRemote );

		$embeddings    = self::embeddingsTable();
		$sqlEmbeddings = "CREATE TABLE {$embeddings} (
            id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            node_id    VARCHAR(191)        NOT NULL,
            model      VARCHAR(128)        NOT NULL DEFAULT 'text-embedding-3-small',
            dim        INT(11)             NOT NULL DEFAULT 0,
            embedding_vector LONGBLOB,
            updated_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY node_model (node_id, model(64))
        ) {$charsetCollate};";
		dbDelta( $sqlEmbeddings );

		update_option( Schema::OPTION_DB_VERSION, NVOOS_GRAPHIFY_DB_VERSION );
	}

	/** @return void */
	public static function upgrade(): void {
		self::install();
	}

	// ─── Node CRUD ─────────────────────────────────────────────

	/**
	 * Upsert a node. If node_id exists, updates mutable columns.
	 *
	 * @param array<string,mixed> $node Node data.
	 * @return int|false Row ID or false on failure.
	 */
	public static function upsertNode( array $node ) {
		global $wpdb;
		$table = self::nodesTable();

		$data = array(
			'node_id'      => sanitize_text_field( $node['node_id'] ),
			'label'        => sanitize_text_field( $node['label'] ),
			'type'         => sanitize_text_field( isset( $node['type'] ) ? $node['type'] : 'post' ),
			'post_id'      => absint( isset( $node['post_id'] ) ? $node['post_id'] : 0 ),
			'url'          => esc_url_raw( isset( $node['url'] ) ? $node['url'] : '' ),
			'properties'   => wp_json_encode( isset( $node['properties'] ) ? $node['properties'] : array() ),
			'content_hash' => sanitize_text_field( isset( $node['content_hash'] ) ? $node['content_hash'] : '' ),
			'external_id'  => sanitize_text_field( isset( $node['external_id'] ) ? $node['external_id'] : '' ),
			'source_slug'  => sanitize_key( isset( $node['source_slug'] ) ? $node['source_slug'] : '' ),
			'confidence'   => isset( $node['confidence'] ) ? max( 0.0, min( 1.0, (float) $node['confidence'] ) ) : 1.0,
			'expires_at'   => isset( $node['expires_at'] ) ? sanitize_text_field( $node['expires_at'] ) : null,
		);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existingId = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE node_id = %s LIMIT 1", $data['node_id'] )
		);
        // phpcs:enable

		if ( $existingId ) {
			$wpdb->update(
				$table,
				array(
					'label'        => $data['label'],
					'type'         => $data['type'],
					'post_id'      => $data['post_id'],
					'url'          => $data['url'],
					'properties'   => $data['properties'],
					'content_hash' => $data['content_hash'],
				),
				array( 'node_id' => $data['node_id'] ),
				array( '%s', '%s', '%d', '%s', '%s', '%s' ),
				array( '%s' )
			);
			return absint( $existingId );
		}

		$result = $wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s' ) );
		return $result ? $wpdb->insert_id : false;
	}

	/** @return int */
	public static function batchUpsertNodes( array $nodes, int $chunk = 100 ): int {
		$count = 0;
		foreach ( array_chunk( $nodes, $chunk ) as $batch ) {
			foreach ( $batch as $node ) {
				if ( self::upsertNode( $node ) !== false ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/** @return object|null */
	public static function getNode( string $nodeId ) {
		global $wpdb;
		$table = self::nodesTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE node_id = %s LIMIT 1", sanitize_text_field( $nodeId ) )
		);
        // phpcs:enable
	}

	/** @return array<int,array<string,mixed>> */
	public static function getAllNodes( int $limit = 0 ): array {
		global $wpdb;
		$table = self::nodesTable();
		$sql   = "SELECT * FROM {$table}";
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', absint( $limit ) );
		}
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
        // phpcs:enable
		return is_array( $rows ) ? $rows : array();
	}

	/** @return object|null */
	public static function getNodeByPostId( int $postId ) {
		global $wpdb;
		$table = self::nodesTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", absint( $postId ) )
		);
        // phpcs:enable
	}

	/** @return array<int,object> */
	public static function searchNodes( string $search, string $type = '', int $limit = 20 ): array {
		global $wpdb;
		$table = self::nodesTable();
		$limit = max( 1, min( 200, absint( $limit ) ) );
		$like  = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $type ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE label LIKE %s AND type = %s ORDER BY degree DESC LIMIT %d",
					$like,
					sanitize_text_field( $type ),
					$limit
				)
			);
			return is_array( $results ) ? $results : array();
		}
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE label LIKE %s ORDER BY degree DESC LIMIT %d",
				$like,
				$limit
			)
		);
        // phpcs:enable
		return is_array( $results ) ? $results : array();
	}

	/** @return array<int,object> */
	public static function listNodes( array $args = array() ): array {
		global $wpdb;
		$table  = self::nodesTable();
		$limit  = max( 1, min( 200, absint( $args['limit'] ?? 50 ) ) );
		$offset = absint( $args['offset'] ?? 0 );

		$allowedOrderBy = array( 'degree', 'label', 'created_at', 'updated_at', 'type' );
		$orderBy        = ( isset( $args['order_by'] ) && in_array( $args['order_by'], $allowedOrderBy, true ) )
			? $args['order_by'] : 'degree';
		$order          = ( isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

		$where  = array();
		$params = array();
		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = sanitize_text_field( $args['type'] );
		}
		if ( ! empty( $args['community_id'] ) ) {
			$where[]  = 'community_id = %s';
			$params[] = sanitize_text_field( $args['community_id'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'label LIKE %s';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		}

		$whereSql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$params[] = $limit;
		$params[] = $offset;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$whereSql} ORDER BY {$orderBy} {$order} LIMIT %d OFFSET %d",
				...$params
			)
		);
        // phpcs:enable
		return is_array( $results ) ? $results : array();
	}

	/** @return void */
	public static function recalculateDegree( string $nodeId ): void {
		global $wpdb;
		$nodes = self::nodesTable();
		$edges = self::edgesTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$degree = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$edges} WHERE source_node_id = %s OR target_node_id = %s",
				$nodeId,
				$nodeId
			)
		);
		$wpdb->update( $nodes, array( 'degree' => $degree ), array( 'node_id' => $nodeId ), array( '%d' ), array( '%s' ) );
        // phpcs:enable
	}

	/** @return void */
	public static function setCommunity( string $nodeId, string $communityId ): void {
		global $wpdb;
		$wpdb->update(
			self::nodesTable(),
			array( 'community_id' => sanitize_text_field( $communityId ) ),
			array( 'node_id' => sanitize_text_field( $nodeId ) ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/** @return void */
	public static function truncateNodes(): void {
		global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'TRUNCATE TABLE ' . self::nodesTable() );
	}

	/** @return void */
	public static function deleteNode( string $nodeId ): void {
		global $wpdb;
		$wpdb->delete( self::nodesTable(), array( 'node_id' => sanitize_text_field( $nodeId ) ), array( '%s' ) );
	}

	/** @return int */
	public static function countNodes(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::nodesTable() );
	}

	// ─── Edge CRUD ─────────────────────────────────────────────

	/** @return int|false */
	public static function upsertEdge( array $edge ) {
		global $wpdb;
		$table = self::edgesTable();

		$source     = sanitize_text_field( $edge['source_node_id'] );
		$target     = sanitize_text_field( $edge['target_node_id'] );
		$relation   = sanitize_text_field( $edge['relation'] );
		$confidence = max( 0.0, min( 1.0, (float) ( $edge['confidence'] ?? 1.0 ) ) );
		$provenance = sanitize_text_field( $edge['provenance'] ?? 'EXTRACTED' );
		$properties = wp_json_encode( $edge['properties'] ?? array() );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, confidence FROM {$table} WHERE source_node_id = %s AND target_node_id = %s AND relation = %s LIMIT 1",
				$source,
				$target,
				$relation
			)
		);
        // phpcs:enable

		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'confidence' => max( (float) $existing->confidence, $confidence ),
					'provenance' => $provenance,
					'properties' => $properties,
				),
				array( 'id' => $existing->id ),
				array( '%f', '%s', '%s' ),
				array( '%d' )
			);
			return absint( $existing->id );
		}

		$result = $wpdb->insert(
			$table,
			array(
				'source_node_id' => $source,
				'target_node_id' => $target,
				'relation'       => $relation,
				'confidence'     => $confidence,
				'provenance'     => $provenance,
				'properties'     => $properties,
			),
			array( '%s', '%s', '%s', '%f', '%s', '%s' )
		);
		return $result ? $wpdb->insert_id : false;
	}

	/** @return int */
	public static function batchUpsertEdges( array $edges, int $chunk = 100 ): int {
		$count = 0;
		foreach ( array_chunk( $edges, $chunk ) as $batch ) {
			foreach ( $batch as $edge ) {
				if ( self::upsertEdge( $edge ) !== false ) {
					++$count;
				}
			}
		}
		return $count;
	}

	/** @return array<int,object> */
	public static function getEdgesForNode( string $nodeId, string $relation = '', int $limit = 500 ): array {
		global $wpdb;
		$table = self::edgesTable();
		$limit = max( 1, min( 2000, absint( $limit ) ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $relation ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE (source_node_id = %s OR target_node_id = %s) AND relation = %s ORDER BY confidence DESC LIMIT %d",
					$nodeId,
					$nodeId,
					sanitize_text_field( $relation ),
					$limit
				)
			);
			return is_array( $results ) ? $results : array();
		}
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_node_id = %s OR target_node_id = %s ORDER BY confidence DESC LIMIT %d",
				$nodeId,
				$nodeId,
				$limit
			)
		);
        // phpcs:enable
		return is_array( $results ) ? $results : array();
	}

	/** @return string[] */
	public static function getNeighborIds( string $nodeId, string $relation = '' ): array {
		$edges = self::getEdgesForNode( $nodeId, $relation );
		$ids   = array();
		foreach ( $edges as $edge ) {
			$ids[] = ( $edge->source_node_id === $nodeId ) ? $edge->target_node_id : $edge->source_node_id;
		}
		return array_values( array_unique( $ids ) );
	}

	/** @return void */
	public static function truncateEdges(): void {
		global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'TRUNCATE TABLE ' . self::edgesTable() );
	}

	/** @return int */
	public static function countEdges(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::edgesTable() );
	}

	// ─── Graph stats ───────────────────────────────────────────

	/** @return array<string,mixed> */
	public static function getStats(): array {
		global $wpdb;
		$nodes = self::nodesTable();
		$edges = self::edgesTable();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$nodeCount      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$nodes}" );
		$edgeCount      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$edges}" );
		$commCount      = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT community_id) FROM {$nodes} WHERE community_id != ''" );
		$nodesByType    = $wpdb->get_results( "SELECT type, COUNT(*) AS cnt FROM {$nodes} GROUP BY type ORDER BY cnt DESC", ARRAY_A );
		$edgesByRel     = $wpdb->get_results( "SELECT relation, COUNT(*) AS cnt FROM {$edges} GROUP BY relation ORDER BY cnt DESC", ARRAY_A );
		$confidenceDist = $wpdb->get_results( "SELECT ROUND(confidence,1) AS bucket, COUNT(*) AS cnt FROM {$edges} GROUP BY bucket ORDER BY bucket", ARRAY_A );
        // phpcs:enable

		return array(
			'node_count'        => $nodeCount,
			'edge_count'        => $edgeCount,
			'community_count'   => $commCount,
			'nodes_by_type'     => is_array( $nodesByType ) ? $nodesByType : array(),
			'edges_by_relation' => is_array( $edgesByRel ) ? $edgesByRel : array(),
			'confidence_dist'   => is_array( $confidenceDist ) ? $confidenceDist : array(),
		);
	}

	// ─── Meta ──────────────────────────────────────────────────

	/** @return mixed */
	public static function getMeta( string $key, $default = null ) {
		global $wpdb;
		$table = self::metaTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT meta_value FROM {$table} WHERE meta_key = %s LIMIT 1", sanitize_text_field( $key ) )
		);
        // phpcs:enable
		if ( null === $value ) {
			return $default;
		}
		$decoded = json_decode( $value, true );
		return ( null !== $decoded ) ? $decoded : $value;
	}

	/** @return void */
	public static function setMeta( string $key, $value ): void {
		global $wpdb;
		$table      = self::metaTable();
		$serialized = wp_json_encode( $value );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE meta_key = %s LIMIT 1", sanitize_text_field( $key ) )
		);
        // phpcs:enable

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Targeting plugin's custom nvoos_graphify_meta table, not wp_postmeta.
		if ( $exists ) {
			$wpdb->update( $table, array( 'meta_value' => $serialized ), array( 'meta_key' => sanitize_text_field( $key ) ), array( '%s' ), array( '%s' ) );
		} else {
			$wpdb->insert(
				$table,
				array(
					'meta_key'   => sanitize_text_field( $key ),
					'meta_value' => $serialized,
				),
				array( '%s', '%s' )
			);
		}
	}

	// ─── External IDs ──────────────────────────────────────────

	/** @return object|null */
	public static function getNodeByExternalId( string $externalId ) {
		global $wpdb;
		$table = self::nodesTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE external_id = %s LIMIT 1", sanitize_text_field( $externalId ) )
		);
        // phpcs:enable
	}

	/** @return bool */
	public static function updateNodeExternalId( string $nodeId, string $externalId ): bool {
		global $wpdb;
		return false !== $wpdb->update(
			self::nodesTable(),
			array( 'external_id' => sanitize_text_field( $externalId ) ),
			array( 'node_id' => sanitize_text_field( $nodeId ) ),
			array( '%s' ),
			array( '%s' )
		);
	}

	// ─── Remote sources CRUD ───────────────────────────────────

	/** @return true|\WP_Error */
	public static function saveRemoteSource( array $data ) {
		global $wpdb;
		$table = self::remoteSourcesTable();

		$slug    = sanitize_key( $data['slug'] );
		$driver  = sanitize_key( $data['driver'] );
		$label   = sanitize_text_field( $data['label'] ?? '' );
		$enabled = ! empty( $data['enabled'] ) ? 1 : 0;
		$config  = is_array( $data['config'] ?? null ) ? $data['config'] : array();

		if ( empty( $slug ) || empty( $driver ) ) {
			return new \WP_Error( 'invalid_data', __( 'slug and driver are required.', 'nvoos-graphify' ) );
		}

		$configJson = wp_json_encode( $config );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug )
		);
        // phpcs:enable

		if ( $exists ) {
			$wpdb->update(
				$table,
				array(
					'driver'      => $driver,
					'label'       => $label,
					'enabled'     => $enabled,
					'config_json' => $configJson,
				),
				array( 'slug' => $slug ),
				array( '%s', '%s', '%d', '%s' ),
				array( '%s' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'slug'        => $slug,
					'driver'      => $driver,
					'label'       => $label,
					'enabled'     => $enabled,
					'config_json' => $configJson,
				),
				array( '%s', '%s', '%s', '%d', '%s' )
			);
		}
		return true;
	}

	/** @return object|null */
	public static function getRemoteSource( string $slug ) {
		global $wpdb;
		$table = self::remoteSourcesTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s LIMIT 1", sanitize_key( $slug ) )
		);
        // phpcs:enable
	}

	/** @return array<int,object> */
	public static function listRemoteSources( array $args = array() ): array {
		global $wpdb;
		$table = self::remoteSourcesTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( isset( $args['enabled'] ) ) {
			$results = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE enabled = %d ORDER BY label ASC", absint( $args['enabled'] ) )
			);
			return is_array( $results ) ? $results : array();
		}
		$results = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY label ASC" );
        // phpcs:enable
		return is_array( $results ) ? $results : array();
	}

	/** @return void */
	public static function deleteRemoteSource( string $slug ): void {
		global $wpdb;
		$wpdb->delete( self::remoteSourcesTable(), array( 'slug' => sanitize_key( $slug ) ), array( '%s' ) );
	}

	/** @return void */
	public static function updateRemoteSourceSync( string $slug, ?string $error = null, ?string $circuitState = null ): void {
		global $wpdb;
		$data   = array( 'last_sync_at' => current_time( 'mysql', true ) );
		$format = array( '%s' );
		if ( null !== $error ) {
			$data['last_error'] = sanitize_text_field( $error );
			$format[]           = '%s';
		}
		if ( null !== $circuitState ) {
			$allowed               = array( 'open', 'closed', 'half-open' );
			$data['circuit_state'] = in_array( $circuitState, $allowed, true ) ? $circuitState : 'closed';
			$format[]              = '%s';
		}
		$wpdb->update( self::remoteSourcesTable(), $data, array( 'slug' => sanitize_key( $slug ) ), $format, array( '%s' ) );
	}

	// ─── Embedding CRUD ───────────────────────────────────────

	/** @return int|false */
	public static function upsertEmbedding( array $data ) {
		global $wpdb;
		$table  = self::embeddingsTable();
		$nodeId = sanitize_text_field( $data['node_id'] );
		$model  = sanitize_text_field( $data['model'] ?? 'text-embedding-3-small' );
		$dim    = absint( $data['dim'] ?? 0 );
		$vector = $data['vector'] ?? '';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existingId = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE node_id = %s AND model = %s LIMIT 1", $nodeId, $model )
		);
        // phpcs:enable

		if ( $existingId ) {
			$wpdb->update(
				$table,
				array(
					'dim'              => $dim,
					'embedding_vector' => $vector,
				),
				array( 'id' => absint( $existingId ) ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			return absint( $existingId );
		}
		$result = $wpdb->insert(
			$table,
			array(
				'node_id'          => $nodeId,
				'model'            => $model,
				'dim'              => $dim,
				'embedding_vector' => $vector,
			),
			array( '%s', '%s', '%d', '%s' )
		);
		return $result ? $wpdb->insert_id : false;
	}

	/** @return object|null */
	public static function getEmbedding( string $nodeId, string $model = 'text-embedding-3-small' ) {
		global $wpdb;
		$table = self::embeddingsTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE node_id = %s AND model = %s LIMIT 1", sanitize_text_field( $nodeId ), sanitize_text_field( $model ) )
		);
        // phpcs:enable
	}

	/** @return array<int,object> */
	public static function getAllEmbeddings( string $model = 'text-embedding-3-small' ): array {
		global $wpdb;
		$table = self::embeddingsTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT node_id, embedding_vector FROM {$table} WHERE model = %s", sanitize_text_field( $model ) )
		);
        // phpcs:enable
		return is_array( $results ) ? $results : array();
	}
}
