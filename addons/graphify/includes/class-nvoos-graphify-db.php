<?php
/**
 * NV oOS Graphify — Database Layer
 *
 * Manages the three custom tables that back the knowledge graph:
 *   nvoos_graph_nodes  — content entities (posts, terms, users, media, topics)
 *   nvoos_graph_edges  — relationships between nodes
 *   nvoos_graph_meta   — key/value addon metadata (schema version, last build, etc.)
 *
 * Uses dbDelta() for safe, incremental schema migrations.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database access object for the Graphify addon.
 *
 * All public methods validate + sanitize their inputs and use
 * $wpdb->prepare() for any variable-interpolated queries.
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify_DB {

	// -------------------------------------------------------------------------
	// Table name helpers
	// -------------------------------------------------------------------------

	/**
	 * Return the full table name for nodes.
	 *
	 * @return string
	 */
	public static function nodes_table() {
		global $wpdb;
		return $wpdb->prefix . 'nvoos_graph_nodes';
	}

	/**
	 * Return the full table name for edges.
	 *
	 * @return string
	 */
	public static function edges_table() {
		global $wpdb;
		return $wpdb->prefix . 'nvoos_graph_edges';
	}

	/**
	 * Return the full table name for addon meta.
	 *
	 * @return string
	 */
	public static function meta_table() {
		global $wpdb;
		return $wpdb->prefix . 'nvoos_graph_meta';
	}

	// -------------------------------------------------------------------------
	// Schema install / upgrade
	// -------------------------------------------------------------------------

	/**
	 * Install or upgrade the database schema.
	 *
	 * Safe to call multiple times — dbDelta only applies changes.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$nodes = self::nodes_table();
		$edges = self::edges_table();
		$meta  = self::meta_table();

		// Nodes table.
		$sql_nodes = "CREATE TABLE {$nodes} (
			id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			node_id     VARCHAR(191)         NOT NULL,
			label       VARCHAR(512)         NOT NULL,
			type        VARCHAR(64)          NOT NULL DEFAULT 'post',
			post_id     BIGINT(20) UNSIGNED  NOT NULL DEFAULT 0,
			url         VARCHAR(512)         NOT NULL DEFAULT '',
			properties  LONGTEXT,
			degree      INT(11)              NOT NULL DEFAULT 0,
			community_id VARCHAR(64)         NOT NULL DEFAULT '',
			content_hash VARCHAR(64)         NOT NULL DEFAULT '',
			created_at  DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at  DATETIME             NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY   node_id (node_id),
			KEY          type (type),
			KEY          post_id (post_id),
			KEY          community_id (community_id)
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
			created_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY   edge_unique (source_node_id, target_node_id, relation(64)),
			KEY          source_node_id (source_node_id),
			KEY          target_node_id (target_node_id),
			KEY          relation (relation),
			KEY          provenance (provenance)
		) {$charset_collate};";

		// Addon meta table.
		$sql_meta = "CREATE TABLE {$meta} (
			id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			meta_key   VARCHAR(191)        NOT NULL,
			meta_value LONGTEXT,
			updated_at DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY  meta_key (meta_key)
		) {$charset_collate};";

		dbDelta( $sql_nodes );
		dbDelta( $sql_edges );
		dbDelta( $sql_meta );

		update_option( 'nvoos_graphify_db_version', NVOOS_GRAPHIFY_DB_VERSION );
	}

	/**
	 * Drop all addon tables (called on uninstall).
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;
		// Use %i identifier placeholder (WordPress 6.2+) so the table names are
		// safely quoted by $wpdb->prepare() rather than concatenated. The names
		// themselves are server-controlled ($wpdb->prefix . 'graphify_*'), but
		// using %i is the project's required pattern and guards against any
		// future regression where a tool argument might reach this path.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::edges_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::nodes_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::meta_table() ) );
		delete_option( 'nvoos_graphify_db_version' );
	}

	// -------------------------------------------------------------------------
	// Node CRUD
	// -------------------------------------------------------------------------

	/**
	 * Upsert a node. If node_id already exists, updates label/properties/url/type.
	 *
	 * @since 0.5.0
	 *
	 * @param array $node {
	 *     @type string $node_id      Unique identifier (sha256 of label+type or post_id prefix).
	 *     @type string $label        Human-readable name.
	 *     @type string $type         Entity type: post|page|term|user|media|topic|entity.
	 *     @type int    $post_id      WordPress post ID (0 for non-post nodes).
	 *     @type string $url          Canonical URL.
	 *     @type array  $properties   Additional metadata (JSON-encoded internally).
	 *     @type string $content_hash SHA256 hash of source content.
	 * }
	 * @return int|false Row ID on success, false on failure.
	 */
	public static function upsert_node( array $node ) {
		global $wpdb;
		$table = self::nodes_table();

		$data = array(
			'node_id'      => sanitize_text_field( $node['node_id'] ),
			'label'        => sanitize_text_field( $node['label'] ),
			'type'         => sanitize_text_field( isset( $node['type'] ) ? $node['type'] : 'post' ),
			'post_id'      => absint( isset( $node['post_id'] ) ? $node['post_id'] : 0 ),
			'url'          => esc_url_raw( isset( $node['url'] ) ? $node['url'] : '' ),
			'properties'   => wp_json_encode( isset( $node['properties'] ) ? $node['properties'] : array() ),
			'content_hash' => sanitize_text_field( isset( $node['content_hash'] ) ? $node['content_hash'] : '' ),
		);

		// Try to insert; on duplicate key, update mutable columns.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE node_id = %s LIMIT 1", $data['node_id'] )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing_id ) {
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
			return absint( $existing_id );
		}

		$result = $wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' ) );
		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Batch-upsert an array of node definitions.
	 *
	 * @since 0.5.0
	 *
	 * @param array $nodes Array of node arrays (same keys as upsert_node()).
	 * @param int   $chunk Batch size.
	 * @return int Number of nodes successfully upserted.
	 */
	public static function batch_upsert_nodes( array $nodes, $chunk = 100 ) {
		$count   = 0;
		$batches = array_chunk( $nodes, $chunk );
		foreach ( $batches as $batch ) {
			foreach ( $batch as $node ) {
				if ( self::upsert_node( $node ) !== false ) {
					$count++;
				}
			}
		}
		return $count;
	}

	/**
	 * Get a single node by node_id.
	 *
	 * @since 0.5.0
	 *
	 * @param string $node_id Node identifier.
	 * @return object|null Row object or null.
	 */
	public static function get_node( $node_id ) {
		global $wpdb;
		$table = self::nodes_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE node_id = %s LIMIT 1", sanitize_text_field( $node_id ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get a node by WordPress post ID.
	 *
	 * @since 0.5.0
	 *
	 * @param int $post_id WordPress post ID.
	 * @return object|null Row object or null.
	 */
	public static function get_node_by_post_id( $post_id ) {
		global $wpdb;
		$table = self::nodes_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", absint( $post_id ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Search nodes by label (case-insensitive LIKE).
	 *
	 * @since 0.5.0
	 *
	 * @param string $search  Search string.
	 * @param string $type    Optional node type filter.
	 * @param int    $limit   Max results (default 20).
	 * @return array
	 */
	public static function search_nodes( $search, $type = '', $limit = 20 ) {
		global $wpdb;
		$table = self::nodes_table();
		$limit = max( 1, min( 200, absint( $limit ) ) );
		$like  = '%' . $wpdb->esc_like( sanitize_text_field( $search ) ) . '%';

		if ( $type ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE label LIKE %s AND type = %s ORDER BY degree DESC LIMIT %d",
					$like,
					sanitize_text_field( $type ),
					$limit
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE label LIKE %s ORDER BY degree DESC LIMIT %d",
				$like,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * List nodes, optionally filtered by type or community.
	 *
	 * @since 0.5.0
	 *
	 * @param array $args {
	 *     @type string $type         Node type filter.
	 *     @type string $community_id Community filter.
	 *     @type int    $limit        Max results (default 50).
	 *     @type int    $offset       Pagination offset.
	 *     @type string $order_by     Column to sort by (default: degree).
	 *     @type string $order        ASC|DESC (default: DESC).
	 * }
	 * @return array
	 */
	public static function list_nodes( array $args = array() ) {
		global $wpdb;
		$table  = self::nodes_table();
		$limit  = max( 1, min( 200, absint( isset( $args['limit'] ) ? $args['limit'] : 50 ) ) );
		$offset = absint( isset( $args['offset'] ) ? $args['offset'] : 0 );

		$allowed_order_by = array( 'degree', 'label', 'created_at', 'updated_at', 'type' );
		$order_by = isset( $args['order_by'] ) && in_array( $args['order_by'], $allowed_order_by, true )
			? $args['order_by'] : 'degree';
		$order = ( isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC';

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

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$params[]  = $limit;
		$params[]  = $offset;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where_sql} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d",
				$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Update the cached degree count for a node.
	 *
	 * @since 0.5.0
	 *
	 * @param string $node_id Node identifier.
	 * @return void
	 */
	public static function recalculate_degree( $node_id ) {
		global $wpdb;
		$nodes = self::nodes_table();
		$edges = self::edges_table();
		$node_id = sanitize_text_field( $node_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$degree = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$edges} WHERE source_node_id = %s OR target_node_id = %s",
				$node_id,
				$node_id
			)
		);
		$wpdb->update(
			$nodes,
			array( 'degree' => $degree ),
			array( 'node_id' => $node_id ),
			array( '%d' ),
			array( '%s' )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Update community_id for a node.
	 *
	 * @since 0.5.0
	 *
	 * @param string $node_id      Node identifier.
	 * @param string $community_id Community identifier.
	 * @return void
	 */
	public static function set_community( $node_id, $community_id ) {
		global $wpdb;
		$wpdb->update(
			self::nodes_table(),
			array( 'community_id' => sanitize_text_field( $community_id ) ),
			array( 'node_id' => sanitize_text_field( $node_id ) ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Delete all nodes.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function truncate_nodes() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variable input.
		$wpdb->query( 'TRUNCATE TABLE ' . self::nodes_table() );
	}

	// -------------------------------------------------------------------------
	// Edge CRUD
	// -------------------------------------------------------------------------

	/**
	 * Upsert an edge. On duplicate (source, target, relation) keeps highest
	 * confidence between existing and incoming rows.
	 *
	 * @since 0.5.0
	 *
	 * @param array $edge {
	 *     @type string $source_node_id Source node identifier.
	 *     @type string $target_node_id Target node identifier.
	 *     @type string $relation       Relationship type.
	 *     @type float  $confidence     Confidence score (0–1).
	 *     @type string $provenance     EXTRACTED|INFERRED|AMBIGUOUS.
	 *     @type array  $properties     Additional metadata.
	 * }
	 * @return int|false Row ID on success, false on failure.
	 */
	public static function upsert_edge( array $edge ) {
		global $wpdb;
		$table = self::edges_table();

		$source     = sanitize_text_field( $edge['source_node_id'] );
		$target     = sanitize_text_field( $edge['target_node_id'] );
		$relation   = sanitize_text_field( $edge['relation'] );
		$confidence = isset( $edge['confidence'] ) ? floatval( $edge['confidence'] ) : 1.0;
		$confidence = max( 0.0, min( 1.0, $confidence ) );
		$provenance = isset( $edge['provenance'] ) ? sanitize_text_field( $edge['provenance'] ) : 'EXTRACTED';
		$properties = wp_json_encode( isset( $edge['properties'] ) ? $edge['properties'] : array() );

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

		if ( $existing ) {
			// Keep highest confidence.
			$keep_confidence = max( floatval( $existing->confidence ), $confidence );
			$wpdb->update(
				$table,
				array(
					'confidence' => $keep_confidence,
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

	/**
	 * Batch-upsert edges in chunks.
	 *
	 * @since 0.5.0
	 *
	 * @param array $edges Array of edge arrays.
	 * @param int   $chunk Batch size.
	 * @return int Number of edges successfully upserted.
	 */
	public static function batch_upsert_edges( array $edges, $chunk = 100 ) {
		$count   = 0;
		$batches = array_chunk( $edges, $chunk );
		foreach ( $batches as $batch ) {
			foreach ( $batch as $edge ) {
				if ( self::upsert_edge( $edge ) !== false ) {
					$count++;
				}
			}
		}
		return $count;
	}

	/**
	 * Get all edges for a node (as source or target).
	 *
	 * @since 0.5.0
	 *
	 * @param string $node_id  Node identifier.
	 * @param string $relation Optional relation filter.
	 * @return array
	 */
	public static function get_edges_for_node( $node_id, $relation = '' ) {
		global $wpdb;
		$table   = self::edges_table();
		$node_id = sanitize_text_field( $node_id );

		if ( $relation ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE (source_node_id = %s OR target_node_id = %s) AND relation = %s ORDER BY confidence DESC",
					$node_id,
					$node_id,
					sanitize_text_field( $relation )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE source_node_id = %s OR target_node_id = %s ORDER BY confidence DESC",
				$node_id,
				$node_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get neighbor node IDs for a given node.
	 *
	 * @since 0.5.0
	 *
	 * @param string $node_id  Node identifier.
	 * @param string $relation Optional relation filter.
	 * @return string[] Array of neighbor node IDs.
	 */
	public static function get_neighbor_ids( $node_id, $relation = '' ) {
		$edges   = self::get_edges_for_node( $node_id, $relation );
		$node_id = sanitize_text_field( $node_id );
		$ids     = array();
		foreach ( $edges as $edge ) {
			if ( $edge->source_node_id === $node_id ) {
				$ids[] = $edge->target_node_id;
			} else {
				$ids[] = $edge->source_node_id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Delete all edges.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function truncate_edges() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no variable input.
		$wpdb->query( 'TRUNCATE TABLE ' . self::edges_table() );
	}

	// -------------------------------------------------------------------------
	// Graph-level statistics
	// -------------------------------------------------------------------------

	/**
	 * Return aggregate graph statistics.
	 *
	 * @since 0.5.0
	 *
	 * @return array {
	 *     @type int   $node_count       Total nodes.
	 *     @type int   $edge_count       Total edges.
	 *     @type int   $community_count  Distinct communities.
	 *     @type array $nodes_by_type    Breakdown by type.
	 *     @type array $edges_by_relation Breakdown by relation.
	 *     @type array $confidence_dist  Edge confidence histogram.
	 * }
	 */
	public static function get_stats() {
		global $wpdb;
		$nodes = self::nodes_table();
		$edges = self::edges_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$node_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$nodes}" );
		$edge_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$edges}" );
		$comm_count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT community_id) FROM {$nodes} WHERE community_id != ''" );

		$nodes_by_type = $wpdb->get_results(
			"SELECT type, COUNT(*) AS cnt FROM {$nodes} GROUP BY type ORDER BY cnt DESC",
			ARRAY_A
		);
		$edges_by_rel = $wpdb->get_results(
			"SELECT relation, COUNT(*) AS cnt FROM {$edges} GROUP BY relation ORDER BY cnt DESC",
			ARRAY_A
		);
		$confidence_dist = $wpdb->get_results(
			"SELECT ROUND(confidence, 1) AS bucket, COUNT(*) AS cnt FROM {$edges} GROUP BY bucket ORDER BY bucket",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'node_count'        => $node_count,
			'edge_count'        => $edge_count,
			'community_count'   => $comm_count,
			'nodes_by_type'     => $nodes_by_type,
			'edges_by_relation' => $edges_by_rel,
			'confidence_dist'   => $confidence_dist,
		);
	}

	// -------------------------------------------------------------------------
	// Addon meta
	// -------------------------------------------------------------------------

	/**
	 * Get an addon meta value.
	 *
	 * @since 0.5.0
	 *
	 * @param string $key     Meta key.
	 * @param mixed  $default Default if key is not set.
	 * @return mixed
	 */
	public static function get_meta( $key, $default = null ) {
		global $wpdb;
		$table = self::meta_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT meta_value FROM {$table} WHERE meta_key = %s LIMIT 1", sanitize_text_field( $key ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $value ) {
			return $default;
		}
		$decoded = json_decode( $value, true );
		return ( null !== $decoded ) ? $decoded : $value;
	}

	/**
	 * Set an addon meta value.
	 *
	 * @since 0.5.0
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Value (will be JSON-encoded).
	 * @return void
	 */
	public static function set_meta( $key, $value ) {
		global $wpdb;
		$table      = self::meta_table();
		$key        = sanitize_text_field( $key );
		$serialized = wp_json_encode( $value );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE meta_key = %s LIMIT 1", $key )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $exists ) {
			$wpdb->update(
				$table,
				array( 'meta_value' => $serialized ),
				array( 'meta_key'   => $key ),
				array( '%s' ),
				array( '%s' )
			);
		} else {
			$wpdb->insert(
				$table,
				array(
					'meta_key'   => $key,
					'meta_value' => $serialized,
				),
				array( '%s', '%s' )
			);
		}
	}
}
