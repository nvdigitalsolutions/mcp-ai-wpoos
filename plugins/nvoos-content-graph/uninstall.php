<?php
/**
 * Uninstall handler for NV oOS Content Graph.
 *
 * Standalone file — no autoloader, no plugin bootstrap.
 * Runs when the plugin is deleted via WordPress admin.
 *
 * @package NvoosContentGraph
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
// This is a standalone uninstall script — direct DB access is the only option for cleaning up custom tables and transients.

global $wpdb;

// Drop custom tables.
$tables = array(
	'nvoos_content_graph_nodes',
	'nvoos_content_graph_edges',
	'nvoos_content_graph_meta',
	'nvoos_content_graph_remote_sources',
	'nvoos_content_graph_embeddings',
);

foreach ( $tables as $table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
}

// Delete options.
delete_option( 'nvoos_content_graph_settings' );
delete_option( 'nvoos_content_graph_db_version' );

// Clear transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_nvoos_content_graph_' ) . '%'
	)
);
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_timeout_nvoos_content_graph_' ) . '%'
	)
);

// Clear scheduled hooks.
wp_unschedule_hook( 'nvoos_content_graph/cron_build' );
wp_unschedule_hook( 'nvoos_content_graph/cron_enrich' );
wp_unschedule_hook( 'nvoos_content_graph/initial_build' );
