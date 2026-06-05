<?php
/**
 * Database layer for the NV oOS Graphify plugin.
 *
 * Manages five custom tables backing the knowledge graph:
 *   nvoos_graphify_nodes           — content entities (posts, terms, users, media, topics)
 *   nvoos_graphify_edges           — relationships between nodes
 *   nvoos_graphify_meta            — key/value addon metadata
 *   nvoos_graphify_remote_sources  — remote data source configuration
 *   nvoos_graphify_embeddings      — FLOAT32 vector embeddings
 *
 * Uses dbDelta() for safe, incremental schema migrations.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Graph;

use NvoosGraphify\Schema;
use WP_Error;

/**
 * Database access object.
 *
 * All public methods validate + sanitize inputs and use
 * $wpdb->prepare() for any variable-interpolated queries.
 *
 * @since 1.0.0
 */
final class Db
{
    // ─── Table name helpers ─────────────────────────────────────

    public static function nodesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . Schema::TABLE_NODES;
    }

    public static function edgesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . Schema::TABLE_EDGES;
    }

    public static function metaTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . Schema::TABLE_META;
    }

    public static function remoteSourcesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . Schema::TABLE_REMOTE_SOURCES;
    }

    public static function embeddingsTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . Schema::TABLE_EMBEDDINGS;
    }

    // ─── Schema install / upgrade ───────────────────────────────

    /**
     * Install or upgrade the database schema.
     *
     * Safe to call multiple times — dbDelta only applies changes.
     *
     * @since 1.0.0
     * @return void
     */
    public static function install(): void
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $nodes = self::nodesTable();
        $edges = self::edgesTable();
        $meta  = self::metaTable();

        // Nodes table.
        $sql_nodes = "CREATE TABLE {$nodes} (
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
        ) {$charset_collate};";

        // Edges table.
        $sql_edges = "CREATE TABLE {$edges} (
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
        ) {$charset_collate};";

        // Meta table.
        $sql_meta = "CREATE TABLE {$meta} (
            id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            meta_key   VARCHAR(191)        NOT NULL,
            meta_value LONGTEXT,
            updated_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY  meta_key (meta_key)
        ) {$charset_collate};";

        dbDelta($sql_nodes);
        dbDelta($sql_edges);
        dbDelta($sql_meta);

        // Remote sources table.
        $remote     = self::remoteSourcesTable();
        $sql_remote = "CREATE TABLE {$remote} (
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
        ) {$charset_collate};";
        dbDelta($sql_remote);

        // Node embeddings table.
        $embeddings     = self::embeddingsTable();
        $sql_embeddings = "CREATE TABLE {$embeddings} (
            id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            node_id    VARCHAR(191)        NOT NULL,
            model      VARCHAR(128)        NOT NULL DEFAULT 'text-embedding-3-small',
            dim        INT(11)             NOT NULL DEFAULT 0,
            vector     LONGBLOB,
            updated_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY node_model (node_id, model(64))
        ) {$charset_collate};";
        dbDelta($sql_embeddings);

        update_option(Schema::OPTION_DB_VERSION, NVOOS_GRAPHIFY_DB_VERSION);
    }

    /**
     * Drop all plugin tables (called on uninstall).
     *
     * Note: uninstall.php handles this standalone; kept here for programmatic use.
     *
     * @since 1.0.0
     * @return void
     */
    public static function uninstall(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', self::embeddingsTable()));
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', self::remoteSourcesTable()));
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', self::edgesTable()));
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', self::nodesTable()));
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', self::metaTable()));
        delete_option(Schema::OPTION_DB_VERSION);
    }

    /**
     * Upgrade the database schema from a previous version.
     *
     * Safe to call multiple times — dbDelta only applies missing changes.
     *
     * @since 1.0.0
     * @return void
     */
    public static function upgrade(): void
    {
        self::install();
    }

    // ─── Node CRUD ──────────────────────────────────────────────

    /**
     * Upsert a node. If node_id already exists, updates label/properties/url/type.
     *
     * @since 1.0.0
     * @param array<string,mixed> $node {
     *     @type string $node_id      Unique identifier.
     *     @type string $label        Human-readable name.
     *     @type string $type         Entity type: post|page|term|user|media|topic|entity.
     *     @type int    $post_id      WordPress post ID (0 for non-post nodes).
     *     @type string $url          Canonical URL.
     *     @type array  $properties   Additional metadata (JSON-encoded internally).
     *     @type string $content_hash SHA256 hash of source content.
     *     @type string $external_id  External identifier (Wikidata QID, etc.).
     *     @type string $source_slug  Remote source slug.
     *     @type float  $confidence   Confidence score (0–1).
     *     @type string $expires_at   Expiration datetime.
     * }
     * @return int|false Row ID on success, false on failure.
     */
    public static function upsertNode(array $node): int|false
    {
        global $wpdb;
        $table = self::nodesTable();

        $data = array(
            'node_id'      => sanitize_text_field($node['node_id']),
            'label'        => sanitize_text_field($node['label']),
            'type'         => sanitize_text_field($node['type'] ?? 'post'),
            'post_id'      => absint($node['post_id'] ?? 0),
            'url'          => esc_url_raw($node['url'] ?? ''),
            'properties'   => wp_json_encode($node['properties'] ?? array()),
            'content_hash' => sanitize_text_field($node['content_hash'] ?? ''),
            'external_id'  => sanitize_text_field($node['external_id'] ?? ''),
            'source_slug'  => sanitize_key($node['source_slug'] ?? ''),
            'confidence'   => isset($node['confidence']) ? max(0.0, min(1.0, (float) $node['confidence'])) : 1.0,
            'expires_at'   => isset($node['expires_at']) ? sanitize_text_field($node['expires_at']) : null,
        );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing_id = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE node_id = %s LIMIT 1", $data['node_id'])
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($existing_id) {
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
                array('node_id' => $data['node_id']),
                array('%s', '%s', '%d', '%s', '%s', '%s'),
                array('%s')
            );
            return absint($existing_id);
        }

        $result = $wpdb->insert($table, $data, array('%s', '%s', '%s', '%d', '%s', '%s', '%s'));
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Batch-upsert an array of node definitions.
     *
     * @since 1.0.0
     * @param array<int,array<string,mixed>> $nodes Array of node arrays.
     * @param int                            $chunk Batch size.
     * @return int Number of nodes successfully upserted.
     */
    public static function batchUpsertNodes(array $nodes, int $chunk = 100): int
    {
        $count   = 0;
        $batches = array_chunk($nodes, $chunk);
        foreach ($batches as $batch) {
            foreach ($batch as $node) {
                if (self::upsertNode($node) !== false) {
                    ++$count;
                }
            }
        }
        return $count;
    }

    /**
     * Get a single node by node_id.
     *
     * @since 1.0.0
     * @param string $nodeId Node identifier.
     * @return object|null Row object or null.
     */
    public static function getNode(string $nodeId): ?object
    {
        global $wpdb;
        $table = self::nodesTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE node_id = %s LIMIT 1", sanitize_text_field($nodeId))
        ) ?: null;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Get all nodes from the graph.
     *
     * @since 1.0.0
     * @param int $limit Maximum rows (0 = no limit).
     * @return array<int,array<string,mixed>>
     */
    public static function getAllNodes(int $limit = 0): array
    {
        global $wpdb;
        $table = self::nodesTable();
        $sql   = "SELECT * FROM {$table}";
        if ($limit > 0) {
            $sql .= $wpdb->prepare(' LIMIT %d', absint($limit));
        }
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results($sql, ARRAY_A);
        // phpcs:enable
        return is_array($rows) ? $rows : array();
    }

    /**
     * Get a node by WordPress post ID.
     *
     * @since 1.0.0
     * @param int $postId WordPress post ID.
     * @return object|null Row object or null.
     */
    public static function getNodeByPostId(int $postId): ?object
    {
        global $wpdb;
        $table = self::nodesTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", absint($postId))
        ) ?: null;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Search nodes by label (case-insensitive LIKE).
     *
     * @since 1.0.0
     * @param string $search Search string.
     * @param string $type   Optional node type filter.
     * @param int    $limit  Max results (default 20, capped at 200).
     * @return array<int,object>
     */
    public static function searchNodes(string $search, string $type = '', int $limit = 20): array
    {
        global $wpdb;
        $table = self::nodesTable();
        $limit = max(1, min(200, absint($limit)));
        $like  = '%' . $wpdb->esc_like(sanitize_text_field($search)) . '%';

        if ($type) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE label LIKE %s AND type = %s ORDER BY degree DESC LIMIT %d",
                    $like,
                    sanitize_text_field($type),
                    $limit
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return is_array($results) ? $results : array();
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE label LIKE %s ORDER BY degree DESC LIMIT %d",
                $like,
                $limit
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return is_array($results) ? $results : array();
    }

    /**
     * List nodes, optionally filtered by type, community, or search.
     *
     * @since 1.0.0
     * @param array<string,mixed> $args {
     *     @type string $type         Node type filter.
     *     @type string $community_id Community filter.
     *     @type string $search       Label search.
     *     @type int    $limit        Max results (default 50, capped at 200).
     *     @type int    $offset       Pagination offset.
     *     @type string $order_by     Column to sort by (degree|label|created_at|updated_at|type).
     *     @type string $order        ASC|DESC (default: DESC).
     * }
     * @return array<int,object>
     */
    public static function listNodes(array $args = array()): array
    {
        global $wpdb;
        $table  = self::nodesTable();
        $limit  = max(1, min(200, absint($args['limit'] ?? 50)));
        $offset = absint($args['offset'] ?? 0);

        $allowedOrderBy = array('degree', 'label', 'created_at', 'updated_at', 'type');
        $orderBy        = (isset($args['order_by']) && in_array($args['order_by'], $allowedOrderBy, true))
            ? $args['order_by'] : 'degree';
        $order          = (isset($args['order']) && 'ASC' === strtoupper($args['order'])) ? 'ASC' : 'DESC';

        $where  = array();
        $params = array();

        if (! empty($args['type'])) {
            $where[]  = 'type = %s';
            $params[] = sanitize_text_field($args['type']);
        }
        if (! empty($args['community_id'])) {
            $where[]  = 'community_id = %s';
            $params[] = sanitize_text_field($args['community_id']);
        }
        if (! empty($args['search'])) {
            $where[]  = 'label LIKE %s';
            $params[] = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $params[] = $limit;
        $params[] = $offset;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} {$whereSql} ORDER BY {$orderBy} {$order} LIMIT %d OFFSET %d",
                $params
            )
        );
        // phpcs:enable
        return is_array($results) ? $results : array();
    }

    /**
     * Update the cached degree count for a node.
     *
     * @since 1.0.0
     * @param string $nodeId Node identifier.
     * @return void
     */
    public static function recalculateDegree(string $nodeId): void
    {
        global $wpdb;
        $nodes   = self::nodesTable();
        $edges   = self::edgesTable();
        $nodeId  = sanitize_text_field($nodeId);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $degree = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$edges} WHERE source_node_id = %s OR target_node_id = %s",
                $nodeId,
                $nodeId
            )
        );
        $wpdb->update(
            $nodes,
            array('degree' => $degree),
            array('node_id' => $nodeId),
            array('%d'),
            array('%s')
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Update community_id for a node.
     *
     * @since 1.0.0
     * @param string $nodeId      Node identifier.
     * @param string $communityId Community identifier.
     * @return void
     */
    public static function setCommunity(string $nodeId, string $communityId): void
    {
        global $wpdb;
        $wpdb->update(
            self::nodesTable(),
            array('community_id' => sanitize_text_field($communityId)),
            array('node_id' => sanitize_text_field($nodeId)),
            array('%s'),
            array('%s')
        );
    }

    /**
     * Delete all nodes.
     *
     * @since 1.0.0
     * @return void
     */
    public static function truncateNodes(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('TRUNCATE TABLE ' . self::nodesTable());
    }

    // ─── Edge CRUD ──────────────────────────────────────────────

    /**
     * Upsert an edge. On duplicate (source, target, relation), keeps highest
     * confidence between existing and incoming rows.
     *
     * @since 1.0.0
     * @param array<string,mixed> $edge {
     *     @type string $source_node_id Source node identifier.
     *     @type string $target_node_id Target node identifier.
     *     @type string $relation       Relationship type.
     *     @type float  $confidence     Confidence score (0–1).
     *     @type string $provenance     EXTRACTED|INFERRED|AMBIGUOUS.
     *     @type array  $properties     Additional metadata.
     *     @type string $source_slug    Remote source slug.
     *     @type string $fetched_at     Fetch datetime.
     * }
     * @return int|false Row ID on success, false on failure.
     */
    public static function upsertEdge(array $edge): int|false
    {
        global $wpdb;
        $table = self::edgesTable();

        $source     = sanitize_text_field($edge['source_node_id']);
        $target     = sanitize_text_field($edge['target_node_id']);
        $relation   = sanitize_text_field($edge['relation']);
        $confidence = isset($edge['confidence']) ? (float) $edge['confidence'] : 1.0;
        $confidence = max(0.0, min(1.0, $confidence));
        $provenance = isset($edge['provenance']) ? sanitize_text_field($edge['provenance']) : 'EXTRACTED';
        $properties = wp_json_encode($edge['properties'] ?? array());
        $sourceSlug = isset($edge['source_slug']) ? sanitize_key($edge['source_slug']) : '';
        $fetchedAt  = isset($edge['fetched_at']) ? sanitize_text_field($edge['fetched_at']) : null;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, confidence FROM {$table} WHERE source_node_id = %s AND target_node_id = %s AND relation = %s LIMIT 1",
                $source,
                $target,
                $relation
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($existing) {
            $keepConfidence = max((float) $existing->confidence, $confidence);
            $updateData     = array(
                'confidence'  => $keepConfidence,
                'provenance'  => $provenance,
                'properties'  => $properties,
                'source_slug' => $sourceSlug,
            );
            if (null !== $fetchedAt) {
                $updateData['fetched_at'] = $fetchedAt;
            }
            $wpdb->update(
                $table,
                $updateData,
                array('id' => $existing->id),
                array('%f', '%s', '%s', '%s'),
                array('%d')
            );
            return absint($existing->id);
        }

        $insertData = array(
            'source_node_id' => $source,
            'target_node_id' => $target,
            'relation'       => $relation,
            'confidence'     => $confidence,
            'provenance'     => $provenance,
            'properties'     => $properties,
            'source_slug'    => $sourceSlug,
        );
        if (null !== $fetchedAt) {
            $insertData['fetched_at'] = $fetchedAt;
        }
        $result = $wpdb->insert(
            $table,
            $insertData,
            array('%s', '%s', '%s', '%f', '%s', '%s', '%s')
        );
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Batch-upsert edges in chunks.
     *
     * @since 1.0.0
     * @param array<int,array<string,mixed>> $edges Array of edge arrays.
     * @param int                            $chunk Batch size.
     * @return int Number of edges successfully upserted.
     */
    public static function batchUpsertEdges(array $edges, int $chunk = 100): int
    {
        $count   = 0;
        $batches = array_chunk($edges, $chunk);
        foreach ($batches as $batch) {
            foreach ($batch as $edge) {
                if (self::upsertEdge($edge) !== false) {
                    ++$count;
                }
            }
        }
        return $count;
    }

    /**
     * Get all edges for a node (as source or target).
     *
     * @since 1.0.0
     * @param string $nodeId   Node identifier.
     * @param string $relation Optional relation filter.
     * @param int    $limit    Maximum edges (default 500, hard-capped at 2000).
     * @return array<int,object>
     */
    public static function getEdgesForNode(string $nodeId, string $relation = '', int $limit = 500): array
    {
        global $wpdb;
        $table  = self::edgesTable();
        $limit  = max(1, min(2000, absint($limit)));
        $nodeId = sanitize_text_field($nodeId);

        if ($relation) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE (source_node_id = %s OR target_node_id = %s) AND relation = %s ORDER BY confidence DESC LIMIT %d",
                    $nodeId,
                    $nodeId,
                    sanitize_text_field($relation),
                    $limit
                )
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return is_array($results) ? $results : array();
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE source_node_id = %s OR target_node_id = %s ORDER BY confidence DESC LIMIT %d",
                $nodeId,
                $nodeId,
                $limit
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return is_array($results) ? $results : array();
    }

    /**
     * Get neighbor node IDs for a given node.
     *
     * @since 1.0.0
     * @param string $nodeId   Node identifier.
     * @param string $relation Optional relation filter.
     * @return string[] Array of neighbor node IDs.
     */
    public static function getNeighborIds(string $nodeId, string $relation = ''): array
    {
        $edges  = self::getEdgesForNode($nodeId, $relation);
        $nodeId = sanitize_text_field($nodeId);
        $ids    = array();
        foreach ($edges as $edge) {
            if ($edge->source_node_id === $nodeId) {
                $ids[] = $edge->target_node_id;
            } else {
                $ids[] = $edge->source_node_id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Delete all edges.
     *
     * @since 1.0.0
     * @return void
     */
    public static function truncateEdges(): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('TRUNCATE TABLE ' . self::edgesTable());
    }

    // ─── Graph-level statistics ─────────────────────────────────

    /**
     * Return aggregate graph statistics.
     *
     * @since 1.0.0
     * @return array<string,mixed> {
     *     @type int   $node_count        Total nodes.
     *     @type int   $edge_count        Total edges.
     *     @type int   $community_count   Distinct communities.
     *     @type array $nodes_by_type     Breakdown by type.
     *     @type array $edges_by_relation Breakdown by relation.
     *     @type array $confidence_dist   Edge confidence histogram.
     * }
     */
    public static function getStats(): array
    {
        global $wpdb;
        $nodes = self::nodesTable();
        $edges = self::edgesTable();

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $nodeCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$nodes}");
        $edgeCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$edges}");
        $commCount = (int) $wpdb->get_var("SELECT COUNT(DISTINCT community_id) FROM {$nodes} WHERE community_id != ''");

        $nodesByType   = $wpdb->get_results(
            "SELECT type, COUNT(*) AS cnt FROM {$nodes} GROUP BY type ORDER BY cnt DESC",
            ARRAY_A
        );
        $edgesByRel    = $wpdb->get_results(
            "SELECT relation, COUNT(*) AS cnt FROM {$edges} GROUP BY relation ORDER BY cnt DESC",
            ARRAY_A
        );
        $confidenceDist = $wpdb->get_results(
            "SELECT ROUND(confidence, 1) AS bucket, COUNT(*) AS cnt FROM {$edges} GROUP BY bucket ORDER BY bucket",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return array(
            'node_count'      => $nodeCount,
            'edge_count'      => $edgeCount,
            'community_count' => $commCount,
            'nodes_by_type'   => $nodesByType,
            'edges_by_relation' => $edgesByRel,
            'confidence_dist' => $confidenceDist,
        );
    }

    // ─── Meta ───────────────────────────────────────────────────

    /**
     * Get a meta value.
     *
     * @since 1.0.0
     * @param string $key     Meta key.
     * @param mixed  $default Default if key is not set.
     * @return mixed
     */
    public static function getMeta(string $key, mixed $default = null): mixed
    {
        global $wpdb;
        $table = self::metaTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $value = $wpdb->get_var(
            $wpdb->prepare("SELECT meta_value FROM {$table} WHERE meta_key = %s LIMIT 1", sanitize_text_field($key))
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if (null === $value) {
            return $default;
        }
        $decoded = json_decode($value, true);
        return (null !== $decoded) ? $decoded : $value;
    }

    /**
     * Set a meta value.
     *
     * @since 1.0.0
     * @param string $key   Meta key.
     * @param mixed  $value Value (will be JSON-encoded).
     * @return void
     */
    public static function setMeta(string $key, mixed $value): void
    {
        global $wpdb;
        $table      = self::metaTable();
        $key        = sanitize_text_field($key);
        $serialized = wp_json_encode($value);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE meta_key = %s LIMIT 1", $key)
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($exists) {
            $wpdb->update(
                $table,
                array('meta_value' => $serialized),
                array('meta_key' => $key),
                array('%s'),
                array('%s')
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'meta_key'   => $key,
                    'meta_value' => $serialized,
                ),
                array('%s', '%s')
            );
        }
    }

    // ─── External ID helpers ────────────────────────────────────

    /**
     * Get a node by external ID (Wikidata QID, URL, etc.).
     *
     * @since 1.0.0
     * @param string $externalId External identifier.
     * @return object|null Row object or null.
     */
    public static function getNodeByExternalId(string $externalId): ?object
    {
        global $wpdb;
        $table = self::nodesTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE external_id = %s LIMIT 1", sanitize_text_field($externalId))
        ) ?: null;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Update the external_id field for a node.
     *
     * @since 1.0.0
     * @param string $nodeId     Node identifier.
     * @param string $externalId External identifier to set.
     * @return bool True on success.
     */
    public static function updateNodeExternalId(string $nodeId, string $externalId): bool
    {
        global $wpdb;
        $result = $wpdb->update(
            self::nodesTable(),
            array('external_id' => sanitize_text_field($externalId)),
            array('node_id' => sanitize_text_field($nodeId)),
            array('%s'),
            array('%s')
        );
        return false !== $result;
    }

    // ─── Remote sources CRUD ────────────────────────────────────

    /**
     * Save (upsert) a remote source record.
     *
     * Sensitive config fields are encrypted via the Crypto helper when available.
     *
     * @since 1.0.0
     * @param array<string,mixed> $data {
     *     @type string $slug    Source slug (required).
     *     @type string $driver  Driver type slug (required).
     *     @type string $label   Human-readable name.
     *     @type int    $enabled 1|0.
     *     @type array  $config  Config array.
     * }
     * @return true|WP_Error
     */
    public static function saveRemoteSource(array $data): true|WP_Error
    {
        global $wpdb;
        $table = self::remoteSourcesTable();

        $slug    = sanitize_key($data['slug']);
        $driver  = sanitize_key($data['driver']);
        $label   = sanitize_text_field($data['label'] ?? '');
        $enabled = ! empty($data['enabled']) ? 1 : 0;
        $config  = isset($data['config']) && is_array($data['config']) ? $data['config'] : array();

        if (empty($slug) || empty($driver)) {
            return new WP_Error('invalid_data', __('slug and driver are required.', 'nvoos-graphify'));
        }

        // Encrypt sensitive config fields if Crypto helper is available.
        $config = self::encryptSensitiveConfig($config);

        $configJson = wp_json_encode($config);

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE slug = %s LIMIT 1", $slug)
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($exists) {
            $wpdb->update(
                $table,
                array(
                    'driver'      => $driver,
                    'label'       => $label,
                    'enabled'     => $enabled,
                    'config_json' => $configJson,
                ),
                array('slug' => $slug),
                array('%s', '%s', '%d', '%s'),
                array('%s')
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
                array('%s', '%s', '%s', '%d', '%s')
            );
        }

        return true;
    }

    /**
     * Get a single remote source by slug.
     *
     * @since 1.0.0
     * @param string $slug Source slug.
     * @return object|null Row object or null.
     */
    public static function getRemoteSource(string $slug): ?object
    {
        global $wpdb;
        $table = self::remoteSourcesTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s LIMIT 1", sanitize_key($slug))
        ) ?: null;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * List all remote sources, optionally filtered.
     *
     * @since 1.0.0
     * @param array<string,mixed> $args {
     *     @type int $enabled Filter by enabled status (1 or 0). Omit for all.
     * }
     * @return array<int,object>
     */
    public static function listRemoteSources(array $args = array()): array
    {
        global $wpdb;
        $table = self::remoteSourcesTable();

        if (isset($args['enabled'])) {
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $results = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE enabled = %d ORDER BY label ASC", absint($args['enabled']))
            );
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return is_array($results) ? $results : array();
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results("SELECT * FROM {$table} ORDER BY label ASC");
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return is_array($results) ? $results : array();
    }

    /**
     * Delete a remote source by slug.
     *
     * @since 1.0.0
     * @param string $slug Source slug.
     * @return void
     */
    public static function deleteRemoteSource(string $slug): void
    {
        global $wpdb;
        $wpdb->delete(
            self::remoteSourcesTable(),
            array('slug' => sanitize_key($slug)),
            array('%s')
        );
    }

    /**
     * Update last_sync_at and optionally last_error for a source.
     *
     * @since 1.0.0
     * @param string      $slug         Source slug.
     * @param string|null $error        Error message or null for success.
     * @param string|null $circuitState 'open'|'closed'|'half-open' or null to leave unchanged.
     * @return void
     */
    public static function updateRemoteSourceSync(string $slug, ?string $error = null, ?string $circuitState = null): void
    {
        global $wpdb;
        $data   = array('last_sync_at' => current_time('mysql', true));
        $format = array('%s');

        if (null !== $error) {
            $data['last_error'] = sanitize_text_field($error);
            $format[]           = '%s';
        }
        if (null !== $circuitState) {
            $allowed               = array('open', 'closed', 'half-open');
            $data['circuit_state'] = in_array($circuitState, $allowed, true) ? $circuitState : 'closed';
            $format[]              = '%s';
        }

        $wpdb->update(
            self::remoteSourcesTable(),
            $data,
            array('slug' => sanitize_key($slug)),
            $format,
            array('%s')
        );
    }

    // ─── Embedding CRUD ─────────────────────────────────────────

    /**
     * Upsert an embedding record.
     *
     * @since 1.0.0
     * @param array<string,mixed> $data {
     *     @type string $node_id Node identifier.
     *     @type string $model   Model identifier.
     *     @type int    $dim     Vector dimensions.
     *     @type string $vector  Packed binary vector.
     * }
     * @return int|false Row ID or false.
     */
    public static function upsertEmbedding(array $data): int|false
    {
        global $wpdb;
        $table   = self::embeddingsTable();
        $nodeId  = sanitize_text_field($data['node_id']);
        $model   = sanitize_text_field($data['model'] ?? 'text-embedding-3-small');
        $dim     = absint($data['dim'] ?? 0);
        $vector  = $data['vector'] ?? '';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existingId = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE node_id = %s AND model = %s LIMIT 1", $nodeId, $model)
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        if ($existingId) {
            $wpdb->update(
                $table,
                array(
                    'dim'    => $dim,
                    'vector' => $vector,
                ),
                array('id' => absint($existingId)),
                array('%d', '%s'),
                array('%d')
            );
            return absint($existingId);
        }

        $result = $wpdb->insert(
            $table,
            array(
                'node_id' => $nodeId,
                'model'   => $model,
                'dim'     => $dim,
                'vector'  => $vector,
            ),
            array('%s', '%s', '%d', '%s')
        );
        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get an embedding record for a node.
     *
     * @since 1.0.0
     * @param string $nodeId Node identifier.
     * @param string $model  Model identifier.
     * @return object|null Row object or null.
     */
    public static function getEmbedding(string $nodeId, string $model = 'text-embedding-3-small'): ?object
    {
        global $wpdb;
        $table = self::embeddingsTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE node_id = %s AND model = %s LIMIT 1",
                sanitize_text_field($nodeId),
                sanitize_text_field($model)
            )
        ) ?: null;
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    /**
     * Get all embeddings for a given model.
     *
     * @since 1.0.0
     * @param string $model Model identifier.
     * @return array<int,object>
     */
    public static function getAllEmbeddings(string $model = 'text-embedding-3-small'): array
    {
        global $wpdb;
        $table = self::embeddingsTable();
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare("SELECT node_id, vector FROM {$table} WHERE model = %s", sanitize_text_field($model))
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return is_array($results) ? $results : array();
    }

    // ─── Internal helpers ───────────────────────────────────────

    /**
     * Encrypt sensitive config keys if the Crypto helper is available.
     *
     * Tries the new NvoosGraphify\Remote\Crypto class first,
     * falls back to the legacy addon class for backward compatibility.
     *
     * @since 1.0.0
     * @param array<string,mixed> $config Raw config.
     * @return array<string,mixed> Config with sensitive fields encrypted.
     */
    private static function encryptSensitiveConfig(array $config): array
    {
        $cryptoClass = null;

        if (class_exists('NvoosGraphify\\Remote\\Crypto')) {
            $cryptoClass = 'NvoosGraphify\\Remote\\Crypto';
        } elseif (class_exists('NV_oOS_Graphify_Crypto')) {
            $cryptoClass = 'NV_oOS_Graphify_Crypto';
        }

        if (null === $cryptoClass) {
            return $config;
        }

        foreach ($config as $k => $v) {
            if ($cryptoClass::is_sensitive_key($k)) {
                $config[$k] = $cryptoClass::encrypt((string) $v);
            }
        }

        return $config;
    }
}
