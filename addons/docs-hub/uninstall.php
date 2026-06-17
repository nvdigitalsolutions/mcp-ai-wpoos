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

// Clear known transients.
delete_transient( 'nvoos_dh_manifest' );
delete_transient( 'nvoos_dh_search' );

// Note: page transients use md5 hashes so we cannot enumerate them here.
// They will expire naturally or be cleaned by WP transient maintenance.

// Unschedule the rebuild cron.
wp_clear_scheduled_hook( 'nvoos_docs_hub_rebuild_cron' );

// Delete cached JSON files from the upload directory.
$upload_info = wp_upload_dir();
$cache_dir   = $upload_info['basedir'] . DIRECTORY_SEPARATOR . 'nvoos-docs-hub';

if ( is_dir( $cache_dir ) ) {
	// Delete JSON files in root of cache dir.
	$json_files = glob( $cache_dir . DIRECTORY_SEPARATOR . '*.json' );
	if ( ! empty( $json_files ) ) {
		foreach ( $json_files as $file ) {
			wp_delete_file( $file );
		}
	}

	// Delete JSON files in pages subdirectory.
	$pages_dir  = $cache_dir . DIRECTORY_SEPARATOR . 'pages';
	$page_files = is_dir( $pages_dir ) ? glob( $pages_dir . DIRECTORY_SEPARATOR . '*.json' ) : array();
	if ( ! empty( $page_files ) ) {
		foreach ( $page_files as $file ) {
			wp_delete_file( $file );
		}
	}

	// Remove security guard files.
	$htaccess    = $cache_dir . DIRECTORY_SEPARATOR . '.htaccess';
	$index_guard = $cache_dir . DIRECTORY_SEPARATOR . 'index.php';

	if ( file_exists( $htaccess ) ) {
		wp_delete_file( $htaccess );
	}
	if ( file_exists( $index_guard ) ) {
		wp_delete_file( $index_guard );
	}

	// Attempt to remove the now-empty directories.
	if ( is_dir( $pages_dir ) ) {
		@rmdir( $pages_dir );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	@rmdir( $cache_dir );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
