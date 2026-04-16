<?php
/**
 * NV oOS Graphify Addon — Database Class
 *
 * Manages custom database tables for storing knowledge graph
 * nodes, edges, and metadata using WordPress dbDelta().
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database management for the NV oOS Graphify Addon.
 *
 * Creates and manages the three custom tables used to store the
 * knowledge graph: nodes, edges, and graph metadata.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify_Database {

	/**
	 * Register the admin_init hook for schema version checks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_or_update_tables' ) );
	}

	/**
	 * Create or update tables if the stored DB version is outdated.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function maybe_create_or_update_tables() {
		$installed_version = get_option( NV_oOS_Graphify::DB_VERSION_OPTION, '0' );

		if ( version_compare( $installed_version, NV_oOS_Graphify::DB_VERSION, '<' ) ) {
			self::create_tables();
		}
	}

	/**
	 * Create all knowledge graph database tables using dbDelta().
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$nodes_table = self::get_nodes_table();
		$edges_table = self::get_edges_table();
		$meta_table  = self::get_meta_table();

		$sql = "CREATE TABLE {$nodes_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			graph_id INT(11) UNSIGNED NOT NULL DEFAULT 1,
			node_id VARCHAR(255) NOT NULL,
			label TEXT NOT NULL,
			node_type VARCHAR(50) NOT NULL DEFAULT 'post',
			source_type VARCHAR(50) NOT NULL DEFAULT '',
			source_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			source_url TEXT NOT NULL,
			community_id INT(11) NOT NULL DEFAULT 0,
			degree INT(11) NOT NULL DEFAULT 0,
			metadata LONGTEXT,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			UNIQUE KEY node_graph (node_id(191), graph_id),
			KEY graph_id (graph_id),
			KEY node_type (node_type),
			KEY source_id (source_id),
			KEY community_id (community_id)
		) {$charset_collate};

		CREATE TABLE {$edges_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			graph_id INT(11) UNSIGNED NOT NULL DEFAULT 1,
			source_node_id VARCHAR(255) NOT NULL,
			target_node_id VARCHAR(255) NOT NULL,
			relation VARCHAR(100) NOT NULL,
			confidence VARCHAR(20) NOT NULL DEFAULT 'EXTRACTED',
			confidence_score FLOAT NOT NULL DEFAULT 1.0,
			metadata LONGTEXT,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY graph_id (graph_id),
			KEY source_node_id (source_node_id(191)),
			KEY target_node_id (target_node_id(191)),
			KEY relation (relation),
			KEY confidence (confidence)
		) {$charset_collate};

		CREATE TABLE {$meta_table} (
			graph_id INT(11) UNSIGNED NOT NULL,
			site_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			node_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
			edge_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
			community_count INT(11) UNSIGNED NOT NULL DEFAULT 0,
			last_built DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			build_status VARCHAR(20) NOT NULL DEFAULT 'idle',
			settings LONGTEXT,
			PRIMARY KEY  (graph_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( NV_oOS_Graphify::DB_VERSION_OPTION, NV_oOS_Graphify::DB_VERSION );
	}

	/**
	 * Get the full table name for graph nodes.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function get_nodes_table() {
		global $wpdb;
		return $wpdb->prefix . 'nvoos_graph_nodes';
	}

	/**
	 * Get the full table name for graph edges.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function get_edges_table() {
		global $wpdb;
		return $wpdb->prefix . 'nvoos_graph_edges';
	}

	/**
	 * Get the full table name for graph metadata.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function get_meta_table() {
		global $wpdb;
		return $wpdb->prefix . 'nvoos_graph_meta';
	}

	/**
	 * Drop all knowledge graph tables.
	 *
	 * Used during plugin uninstallation to clean up database tables.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function drop_tables() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nvoos_graph_nodes" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nvoos_graph_edges" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}nvoos_graph_meta" );
	}
}
