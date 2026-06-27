<?php
/**
 * Uninstall handler for NV oOS Graphify.
 *
 * Standalone file — no autoloader, no plugin bootstrap.
 * Runs when the plugin is deleted via WordPress admin.
 *
 * @package NvoosGraphify
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
// This is a standalone uninstall script — direct DB access is the only option for cleaning up custom tables and transients.

global $wpdb;

// Drop custom tables.
$tables = array(
	'nvoos_graphify_nodes',
	'nvoos_graphify_edges',
	'nvoos_graphify_meta',
	'nvoos_graphify_remote_sources',
	'nvoos_graphify_embeddings',
);

foreach ( $tables as $table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
}

// Delete options.
delete_option( 'nvoos_graphify_settings' );
delete_option( 'nvoos_graphify_db_version' );

// Clear transients.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_nvoos_graphify_' ) . '%'
	)
);
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_timeout_nvoos_graphify_' ) . '%'
	)
);

// Clear scheduled hooks.
wp_unschedule_hook( 'nvoos_graphify/cron_build' );
wp_unschedule_hook( 'nvoos_graphify/cron_enrich' );
wp_unschedule_hook( 'nvoos_graphify/initial_build' );
