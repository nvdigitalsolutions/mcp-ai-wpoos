<?php
/**
 * Graphify Knowledge Graph — Database Layer
 *
 * Creates and manages the custom tables for storing knowledge graph
 * nodes, edges, and graph metadata. All queries use $wpdb->prepare().
 *
 * @package WP_MCP_AI
 * @since   1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database CRUD and schema management for the knowledge graph.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Graphify_Database {

	/**
	 * Option key tracking the installed DB schema version.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION_KEY = 'wp_mcp_ai_graphify_db_version';

	/**
	 * Current schema version. Bump when table structure changes.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '1.0.0';

	/**
	 * Get the nodes table name (includes WP prefix).
	 *
	 * @return string
	 */
	public static function nodes_table() {
		global $wpdb;
		return $wpdb->prefix . 'mcp_ai_graph_nodes';
	}

	/**
	 * Get the edges table name (includes WP prefix).
	 *
	 * @return string
	 */
	public static function edges_table() {
		global $wpdb;
		return $wpdb->prefix . 'mcp_ai_graph_edges';
	}

	/**
	 * Get the graph meta table name (includes WP prefix).
	 *
	 * @return string
	 */
	public static function meta_table() {
		global $wpdb;
		return $wpdb->prefix . 'mcp_ai_graph_meta';
	}

	/**
	 * Create or update the custom tables via dbDelta.
	 *
	 * Safe to call repeatedly — dbDelta is idempotent.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$nodes_table     = self::nodes_table();
		$edges_table     = self::edges_table();
		$meta_table      = self::meta_table();

		$sql = "CREATE TABLE {$nodes_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			graph_id int(11) unsigned NOT NULL DEFAULT 1,
			node_id varchar(255) NOT NULL,
			label text NOT NULL,
			node_type varchar(50) NOT NULL DEFAULT 'post',
			source_type varchar(50) NOT NULL DEFAULT '',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_url text NOT NULL,
			community_id int(11) NOT NULL DEFAULT 0,
			degree int(11) NOT NULL DEFAULT 0,
			metadata longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY node_graph (graph_id, node_id),
			KEY node_type (node_type),
			KEY community_id (community_id),
			KEY source_id (source_id),
			KEY degree (degree)
		) {$charset_collate};

		CREATE TABLE {$edges_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			graph_id int(11) unsigned NOT NULL DEFAULT 1,
			source_node_id varchar(255) NOT NULL,
			target_node_id varchar(255) NOT NULL,
			relation varchar(100) NOT NULL,
			confidence varchar(20) NOT NULL DEFAULT 'EXTRACTED',
			confidence_score float NOT NULL DEFAULT 1.0,
			metadata longtext NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY edge_unique (graph_id, source_node_id, target_node_id, relation),
			KEY source_node_id (source_node_id),
			KEY target_node_id (target_node_id),
			KEY relation (relation),
			KEY confidence (confidence)
		) {$charset_collate};

		CREATE TABLE {$meta_table} (
			graph_id int(11) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL DEFAULT 1,
			node_count int(11) unsigned NOT NULL DEFAULT 0,
			edge_count int(11) unsigned NOT NULL DEFAULT 0,
			community_count int(11) unsigned NOT NULL DEFAULT 0,
			last_built datetime DEFAULT NULL,
			build_status varchar(20) NOT NULL DEFAULT 'idle',
			settings longtext NOT NULL,
			PRIMARY KEY  (graph_id),
			KEY site_id (site_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION );
	}

	/**
	 * Drop all graphify tables. Called on uninstall.
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Intentional table drop on uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcp_ai_graph_nodes" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- Static table name, no user input.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcp_ai_graph_edges" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcp_ai_graph_meta" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching

		delete_option( self::SCHEMA_VERSION_KEY );
	}

	// ------------------------------------------------------------------
	// Graph Meta CRUD
	// ------------------------------------------------------------------

	/**
	 * Get or create the default graph meta record.
	 *
	 * @param int $graph_id Graph ID (default 1).
	 * @return array|null Row as associative array or null on failure.
	 */
	public static function get_graph_meta( $graph_id = 1 ) {
		global $wpdb;

		$table = self::meta_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE graph_id = %d", $graph_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, not cacheable by WP object cache.

		if ( null === $row ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table,
				array(
					'graph_id'     => $graph_id,
					'site_id'      => get_current_blog_id(),
					'build_status' => 'idle',
					'settings'     => '{}',
				),
				array( '%d', '%d', '%s', '%s' )
			);
			return self::get_graph_meta( $graph_id );
		}

		return $row;
	}

	/**
	 * Update graph meta fields.
	 *
	 * @param int   $graph_id Graph ID.
	 * @param array $data     Associative array of column => value to update.
	 * @return bool
	 */
	public static function update_graph_meta( $graph_id, $data ) {
		global $wpdb;

		$allowed = array( 'node_count', 'edge_count', 'community_count', 'last_built', 'build_status', 'settings' );
		$update  = array();
		$formats = array();

		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}

			$update[ $key ] = $value;

			if ( in_array( $key, array( 'node_count', 'edge_count', 'community_count' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}

		if ( empty( $update ) ) {
			return false;
		}

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::meta_table(),
			$update,
			array( 'graph_id' => $graph_id ),
			$formats,
			array( '%d' )
		);

		return false !== $result;
	}

	// ------------------------------------------------------------------
	// Node CRUD
	// ------------------------------------------------------------------

	/**
	 * Insert or update a single node (upsert).
	 *
	 * @param array $node Associative array with at minimum 'node_id' and 'label'.
	 * @return int|false Inserted/updated row ID or false on failure.
	 */
	public static function upsert_node( $node ) {
		global $wpdb;

		$table = self::nodes_table();

		$defaults = array(
			'graph_id'     => 1,
			'node_id'      => '',
			'label'        => '',
			'node_type'    => 'post',
			'source_type'  => '',
			'source_id'    => 0,
			'source_url'   => '',
			'community_id' => 0,
			'degree'       => 0,
			'metadata'     => '{}',
		);

		$node = wp_parse_args( $node, $defaults );

		if ( empty( $node['node_id'] ) ) {
			return false;
		}

		// Check if exists.
		$existing_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE graph_id = %d AND node_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$node['graph_id'],
				$node['node_id']
			)
		);

		$now = current_time( 'mysql', true );

		if ( $existing_id ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table,
				array(
					'label'        => $node['label'],
					'node_type'    => $node['node_type'],
					'source_type'  => $node['source_type'],
					'source_id'    => $node['source_id'],
					'source_url'   => $node['source_url'],
					'community_id' => $node['community_id'],
					'degree'       => $node['degree'],
					'metadata'     => $node['metadata'],
					'updated_at'   => $now,
				),
				array( 'id' => $existing_id ),
				array( '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);
			return (int) $existing_id;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'graph_id'     => $node['graph_id'],
				'node_id'      => $node['node_id'],
				'label'        => $node['label'],
				'node_type'    => $node['node_type'],
				'source_type'  => $node['source_type'],
				'source_id'    => $node['source_id'],
				'source_url'   => $node['source_url'],
				'community_id' => $node['community_id'],
				'degree'       => $node['degree'],
				'metadata'     => $node['metadata'],
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Bulk-insert nodes. Skips duplicates silently.
	 *
	 * @param array $nodes Array of node arrays.
	 * @return int Number of nodes successfully inserted/updated.
	 */
	public static function bulk_upsert_nodes( $nodes ) {
		$count = 0;
		foreach ( $nodes as $node ) {
			if ( self::upsert_node( $node ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Get a single node by node_id.
	 *
	 * @param string $node_id  Node identifier.
	 * @param int    $graph_id Graph ID (default 1).
	 * @return array|null
	 */
	public static function get_node( $node_id, $graph_id = 1 ) {
		global $wpdb;

		$table = self::nodes_table();
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE graph_id = %d AND node_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id,
				$node_id
			),
			ARRAY_A
		);
	}

	/**
	 * Get nodes with optional filters.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int    $graph_id     Graph ID. Default 1.
	 *     @type string $node_type    Filter by node type.
	 *     @type int    $community_id Filter by community.
	 *     @type string $search       Search label text.
	 *     @type string $orderby      Column to order by. Default 'degree'.
	 *     @type string $order        ASC or DESC. Default 'DESC'.
	 *     @type int    $limit        Max results. Default 100.
	 *     @type int    $offset       Offset for pagination. Default 0.
	 * }
	 * @return array Array of node rows.
	 */
	public static function get_nodes( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'graph_id'     => 1,
			'node_type'    => '',
			'community_id' => -1,
			'search'       => '',
			'orderby'      => 'degree',
			'order'        => 'DESC',
			'limit'        => 100,
			'offset'       => 0,
		);

		$args  = wp_parse_args( $args, $defaults );
		$table = self::nodes_table();

		$where   = array( 'graph_id = %d' );
		$params  = array( $args['graph_id'] );

		if ( '' !== $args['node_type'] ) {
			$where[]  = 'node_type = %s';
			$params[] = $args['node_type'];
		}

		if ( -1 !== $args['community_id'] ) {
			$where[]  = 'community_id = %d';
			$params[] = $args['community_id'];
		}

		if ( '' !== $args['search'] ) {
			$where[]  = 'label LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		}

		$allowed_orderby = array( 'degree', 'label', 'node_type', 'community_id', 'created_at', 'updated_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'degree';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$limit  = max( 1, min( absint( $args['limit'] ), 1000 ) );
		$offset = max( 0, absint( $args['offset'] ) );

		$where_clause = implode( ' AND ', $where );
		$params[]     = $limit;
		$params[]     = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table with dynamic but sanitized ORDER BY.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);
	}

	/**
	 * Count nodes in a graph.
	 *
	 * @param int $graph_id Graph ID.
	 * @return int
	 */
	public static function count_nodes( $graph_id = 1 ) {
		global $wpdb;

		$table = self::nodes_table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE graph_id = %d", $graph_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	// ------------------------------------------------------------------
	// Edge CRUD
	// ------------------------------------------------------------------

	/**
	 * Insert or update a single edge (upsert).
	 *
	 * @param array $edge Associative array with 'source_node_id', 'target_node_id', 'relation'.
	 * @return int|false Inserted/updated row ID or false.
	 */
	public static function upsert_edge( $edge ) {
		global $wpdb;

		$table = self::edges_table();

		$defaults = array(
			'graph_id'         => 1,
			'source_node_id'   => '',
			'target_node_id'   => '',
			'relation'         => '',
			'confidence'       => 'EXTRACTED',
			'confidence_score' => 1.0,
			'metadata'         => '{}',
		);

		$edge = wp_parse_args( $edge, $defaults );

		if ( empty( $edge['source_node_id'] ) || empty( $edge['target_node_id'] ) || empty( $edge['relation'] ) ) {
			return false;
		}

		// Check if exists.
		$existing_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE graph_id = %d AND source_node_id = %s AND target_node_id = %s AND relation = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$edge['graph_id'],
				$edge['source_node_id'],
				$edge['target_node_id'],
				$edge['relation']
			)
		);

		if ( $existing_id ) {
			// Update only if new confidence is higher (EXTRACTED > INFERRED > AMBIGUOUS).
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table,
				array(
					'confidence'       => $edge['confidence'],
					'confidence_score' => $edge['confidence_score'],
					'metadata'         => $edge['metadata'],
				),
				array( 'id' => $existing_id ),
				array( '%s', '%f', '%s' ),
				array( '%d' )
			);
			return (int) $existing_id;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'graph_id'         => $edge['graph_id'],
				'source_node_id'   => $edge['source_node_id'],
				'target_node_id'   => $edge['target_node_id'],
				'relation'         => $edge['relation'],
				'confidence'       => $edge['confidence'],
				'confidence_score' => $edge['confidence_score'],
				'metadata'         => $edge['metadata'],
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s' )
		);

		return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Bulk-insert edges.
	 *
	 * @param array $edges Array of edge arrays.
	 * @return int Count of edges upserted.
	 */
	public static function bulk_upsert_edges( $edges ) {
		$count = 0;
		foreach ( $edges as $edge ) {
			if ( self::upsert_edge( $edge ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Get edges for a node (both directions).
	 *
	 * @param string $node_id  Node identifier.
	 * @param int    $graph_id Graph ID.
	 * @return array Array of edge rows.
	 */
	public static function get_edges_for_node( $node_id, $graph_id = 1 ) {
		global $wpdb;

		$table = self::edges_table();
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE graph_id = %d AND (source_node_id = %s OR target_node_id = %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id,
				$node_id,
				$node_id
			),
			ARRAY_A
		);
	}

	/**
	 * Get all edges in a graph with optional filters.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of edge rows.
	 */
	public static function get_edges( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'graph_id'   => 1,
			'relation'   => '',
			'confidence' => '',
			'limit'      => 500,
			'offset'     => 0,
		);

		$args  = wp_parse_args( $args, $defaults );
		$table = self::edges_table();

		$where  = array( 'graph_id = %d' );
		$params = array( $args['graph_id'] );

		if ( '' !== $args['relation'] ) {
			$where[]  = 'relation = %s';
			$params[] = $args['relation'];
		}

		if ( '' !== $args['confidence'] ) {
			$where[]  = 'confidence = %s';
			$params[] = $args['confidence'];
		}

		$limit  = max( 1, min( absint( $args['limit'] ), 5000 ) );
		$offset = max( 0, absint( $args['offset'] ) );

		$where_clause = implode( ' AND ', $where );
		$params[]     = $limit;
		$params[]     = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_clause} ORDER BY id ASC LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);
	}

	/**
	 * Count edges in a graph.
	 *
	 * @param int $graph_id Graph ID.
	 * @return int
	 */
	public static function count_edges( $graph_id = 1 ) {
		global $wpdb;

		$table = self::edges_table();
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE graph_id = %d", $graph_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Get neighbor node_ids for a given node.
	 *
	 * Returns all nodes connected by an edge in either direction.
	 *
	 * @param string $node_id  Node identifier.
	 * @param int    $graph_id Graph ID.
	 * @return array Array of neighbor node_id strings.
	 */
	public static function get_neighbor_ids( $node_id, $graph_id = 1 ) {
		global $wpdb;

		$table = self::edges_table();

		$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT DISTINCT CASE WHEN source_node_id = %s THEN target_node_id ELSE source_node_id END AS neighbor
				FROM {$table}
				WHERE graph_id = %d AND (source_node_id = %s OR target_node_id = %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$node_id,
				$graph_id,
				$node_id,
				$node_id
			),
			ARRAY_A
		);

		return array_column( $results, 'neighbor' );
	}

	// ------------------------------------------------------------------
	// Bulk operations
	// ------------------------------------------------------------------

	/**
	 * Delete all nodes and edges for a graph.
	 *
	 * @param int $graph_id Graph ID.
	 * @return void
	 */
	public static function clear_graph( $graph_id = 1 ) {
		global $wpdb;

		$wpdb->delete( self::nodes_table(), array( 'graph_id' => $graph_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::edges_table(), array( 'graph_id' => $graph_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		self::update_graph_meta(
			$graph_id,
			array(
				'node_count'      => 0,
				'edge_count'      => 0,
				'community_count' => 0,
				'build_status'    => 'idle',
			)
		);
	}

	/**
	 * Update the degree field for all nodes in a graph based on actual edge counts.
	 *
	 * @param int $graph_id Graph ID.
	 * @return void
	 */
	public static function recalculate_degrees( $graph_id = 1 ) {
		global $wpdb;

		$nodes_table = self::nodes_table();
		$edges_table = self::edges_table();

		// Reset all degrees to 0 first.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$nodes_table} SET degree = 0 WHERE graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id
			)
		);

		// Calculate outgoing + incoming degree per node.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$nodes_table} n SET degree = (
					SELECT COUNT(*) FROM {$edges_table} e
					WHERE e.graph_id = %d AND (e.source_node_id = n.node_id OR e.target_node_id = n.node_id)
				) WHERE n.graph_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$graph_id,
				$graph_id
			)
		);
	}
}
