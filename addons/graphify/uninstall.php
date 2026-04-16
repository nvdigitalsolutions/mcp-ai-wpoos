<?php
/**
 * NV oOS Graphify — Uninstall
 *
 * Cleans up all plugin data when the addon is uninstalled.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Drop custom tables.
$tables = array(
	$wpdb->prefix . 'nvoos_graph_nodes',
	$wpdb->prefix . 'nvoos_graph_edges',
	$wpdb->prefix . 'nvoos_graph_meta',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
}

// Remove options.
delete_option( 'nvoos_graphify_settings' );
delete_option( 'nvoos_graphify_db_version' );
delete_option( 'nvoos_graphify_report' );

// Remove transients.
delete_transient( 'nvoos_graphify_activated' );
delete_transient( 'nvoos_graphify_report_cache' );
