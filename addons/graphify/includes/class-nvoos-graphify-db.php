<?php
/**
 * NV oOS Graphify Addon — Database Table Management
 *
 * Creates, queries, and drops the three custom tables that store
 * knowledge-graph nodes, edges, and per-graph metadata.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database helper for the NV oOS Graphify addon.
 *
 * Provides static methods to install, query, and uninstall the
 * custom tables used by the knowledge-graph subsystem.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_DB {

	/**
	 * Database schema version.
	 *
	 * Bump when migrating columns or adding tables.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Create (or update) the three graph tables via dbDelta().
	 *
	 * Safe to call multiple times — dbDelta only applies the diff.
	 * Stores the current DB_VERSION in the `nvoos_graphify_db_version` option.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$tables          = self::get_table_names();

		$nodes_table = $tables['nodes'];
		$edges_table = $tables['edges'];
		$meta_table  = $tables['meta'];

		// -- Nodes ----------------------------------------------------------
		$sql_nodes = "CREATE TABLE {$nodes_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			graph_id INT UNSIGNED NOT NULL DEFAULT 1,
			node_id VARCHAR(255) NOT NULL,
			label TEXT NOT NULL,
			node_type VARCHAR(50) NOT NULL,
			source_type VARCHAR(50) DEFAULT NULL,
			source_id BIGINT UNSIGNED DEFAULT NULL,
			source_url TEXT DEFAULT NULL,
			community_id INT DEFAULT NULL,
			degree INT UNSIGNED NOT NULL DEFAULT 0,
			metadata LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY graph_node (graph_id, node_id),
			KEY node_type (node_type),
			KEY source (source_type, source_id),
			KEY community (community_id)
		) {$charset_collate};";

		// -- Edges ----------------------------------------------------------
		$sql_edges = "CREATE TABLE {$edges_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			graph_id INT UNSIGNED NOT NULL DEFAULT 1,
			source_node_id VARCHAR(255) NOT NULL,
			target_node_id VARCHAR(255) NOT NULL,
			relation VARCHAR(100) NOT NULL,
			confidence VARCHAR(20) NOT NULL DEFAULT 'EXTRACTED',
			confidence_score FLOAT NOT NULL DEFAULT 1.0,
			metadata LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY graph_source (graph_id, source_node_id),
			KEY graph_target (graph_id, target_node_id),
			KEY relation (relation),
			KEY confidence (confidence)
		) {$charset_collate};";

		// -- Graph meta -----------------------------------------------------
		$sql_meta = "CREATE TABLE {$meta_table} (
			graph_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
			site_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
			node_count INT UNSIGNED NOT NULL DEFAULT 0,
			edge_count INT UNSIGNED NOT NULL DEFAULT 0,
			community_count INT UNSIGNED NOT NULL DEFAULT 0,
			last_built DATETIME DEFAULT NULL,
			build_status VARCHAR(20) NOT NULL DEFAULT 'idle',
			settings LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (graph_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql_nodes );
		dbDelta( $sql_edges );
		dbDelta( $sql_meta );

		update_option( 'nvoos_graphify_db_version', self::DB_VERSION );
	}

	/**
	 * Return the full (prefixed) table names used by the addon.
	 *
	 * @since 0.1.0
	 *
	 * @return array{nodes: string, edges: string, meta: string}
	 */
	public static function get_table_names() {
		global $wpdb;

		return array(
			'nodes' => $wpdb->prefix . 'nvoos_graph_nodes',
			'edges' => $wpdb->prefix . 'nvoos_graph_edges',
			'meta'  => $wpdb->prefix . 'nvoos_graph_meta',
		);
	}

	/**
	 * Check whether the graph tables have been installed.
	 *
	 * Verifies the nodes table exists as a proxy for all three tables.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the tables exist.
	 */
	public static function table_exists() {
		global $wpdb;

		$tables = self::get_table_names();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tables['nodes'] ) );

		return ( null !== $result );
	}

	/**
	 * Drop all three graph tables and remove the DB version option.
	 *
	 * Called during full uninstall. Use with care — this is destructive.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		$tables = self::get_table_names();

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		delete_option( 'nvoos_graphify_db_version' );
	}

	/**
	 * Retrieve the graph meta row, creating it if it does not exist.
	 *
	 * @since 0.1.0
	 *
	 * @param int $graph_id Graph identifier. Default 1.
	 * @return object|null Database row object on success, null on failure.
	 */
	public static function get_or_create_graph_meta( $graph_id = 1 ) {
		global $wpdb;

		$tables   = self::get_table_names();
		$graph_id = absint( $graph_id );

		if ( 0 === $graph_id ) {
			$graph_id = 1;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE graph_id = %d',
				$tables['meta'],
				$graph_id
			)
		);

		if ( null !== $row ) {
			return $row;
		}

		// Row does not exist — insert a default one.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$tables['meta'],
			array(
				'graph_id'     => $graph_id,
				'site_id'      => get_current_blog_id(),
				'node_count'   => 0,
				'edge_count'   => 0,
				'build_status' => 'idle',
			),
			array( '%d', '%d', '%d', '%d', '%s' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE graph_id = %d',
				$tables['meta'],
				$graph_id
			)
		);
	}

	/**
	 * Update one or more columns in the graph meta row.
	 *
	 * Accepts any subset of the meta columns as an associative array.
	 * Only the following keys are written: node_count, edge_count,
	 * community_count, last_built, build_status, settings.
	 *
	 * @since 0.1.0
	 *
	 * @param int   $graph_id Graph identifier.
	 * @param array $data     Associative array of column => value pairs.
	 * @return int|false Number of rows updated, or false on error.
	 */
	public static function update_graph_meta( $graph_id, $data ) {
		global $wpdb;

		$tables   = self::get_table_names();
		$graph_id = absint( $graph_id );

		if ( 0 === $graph_id ) {
			return false;
		}

		$allowed = array(
			'node_count'      => '%d',
			'edge_count'      => '%d',
			'community_count' => '%d',
			'last_built'      => '%s',
			'build_status'    => '%s',
			'settings'        => '%s',
		);

		$update_data   = array();
		$update_format = array();

		foreach ( $allowed as $column => $format ) {
			if ( array_key_exists( $column, $data ) ) {
				$update_data[ $column ] = $data[ $column ];
				$update_format[]        = $format;
			}
		}

		if ( empty( $update_data ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->update(
			$tables['meta'],
			$update_data,
			array( 'graph_id' => $graph_id ),
			$update_format,
			array( '%d' )
		);
	}
}
