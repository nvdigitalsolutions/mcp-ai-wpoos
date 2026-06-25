<?php
/**
 * NV oOS Docs Hub — Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes all options, transients, cron events, and cached files.
 *
 * @package NV_oOS_Docs_Hub
 * @since   1.0.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove addon settings.
delete_option( 'nvoos_docs_hub_settings' );

// Remove rebuild state.
delete_option( 'nvoos_docs_hub_rebuild_state' );

// Clear known transients.
delete_transient( 'nvoos_dh_manifest' );
delete_transient( 'nvoos_dh_search' );

// Clear picker cache transients (keyed by md5 hash — use wildcard cleanup).
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_nvoos_docs_hub_tree_' ) . '%'
	)
);
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_timeout_nvoos_docs_hub_tree_' ) . '%'
	)
);

// Note: page transients use md5 hashes so we cannot enumerate them here.
// They will expire naturally or be cleaned by WP transient maintenance.

// Unschedule all rebuild-related cron events.
wp_clear_scheduled_hook( 'nvoos_docs_hub_rebuild_cron' );
wp_clear_scheduled_hook( 'nvoos_docs_hub_rebuild_tick' );

// Delete cached JSON files from the upload directory.
$upload_info = wp_upload_dir();
$cache_dir   = $upload_info['basedir'] . DIRECTORY_SEPARATOR . 'nvoos-docs-hub';

if ( is_dir( $cache_dir ) ) {
	// Helper: recursively delete a directory.
	$rm_rf = null;
	$rm_rf = static function ( $dir ) use ( &$rm_rf ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$entries = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $entries as $entry ) {
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			if ( is_dir( $path ) ) {
				$rm_rf( $path );
			} else {
				wp_delete_file( $path );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	};

	// Delete the entire cache directory tree (includes pages/, remote/, _staging/,
	// .htaccess, web.config, index.php, and all JSON files).
	$rm_rf( $cache_dir );
}
