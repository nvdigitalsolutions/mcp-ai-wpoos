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

/**
 * Remove all plugin data.
 *
 * Wrapped in a prefixed function so every variable is function-scoped
 * (avoids WordPress.org Plugin Check "non-prefixed global variable"
 * findings from bare file-scope code).
 *
 * @since 0.4.3
 *
 * @return void
 */
function nvoos_docs_hub_uninstall() {
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
	$nvoos_docs_hub_upload_info = wp_upload_dir();
	$nvoos_docs_hub_cache_dir   = $nvoos_docs_hub_upload_info['basedir'] . DIRECTORY_SEPARATOR . 'nvoos-docs-hub';

	if ( is_dir( $nvoos_docs_hub_cache_dir ) ) {
		// Helper: recursively delete a directory.
		$nvoos_docs_hub_rm_rf = null;
		$nvoos_docs_hub_rm_rf = static function ( $nvoos_docs_hub_dir ) use ( &$nvoos_docs_hub_rm_rf ) {
			if ( ! is_dir( $nvoos_docs_hub_dir ) ) {
				return;
			}
			$nvoos_docs_hub_entries = array_diff( scandir( $nvoos_docs_hub_dir ), array( '.', '..' ) );
			foreach ( $nvoos_docs_hub_entries as $nvoos_docs_hub_entry ) {
				$nvoos_docs_hub_path = $nvoos_docs_hub_dir . DIRECTORY_SEPARATOR . $nvoos_docs_hub_entry;
				if ( is_dir( $nvoos_docs_hub_path ) ) {
					$nvoos_docs_hub_rm_rf( $nvoos_docs_hub_path );
				} else {
					wp_delete_file( $nvoos_docs_hub_path );
				}
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- no WP API for rmdir; suppressed, non-empty dirs fall through.
			@rmdir( $nvoos_docs_hub_dir );
		};

		// Delete the entire cache directory tree (includes pages/, remote/, _staging/,
		// .htaccess, web.config, index.php, and all JSON files).
		$nvoos_docs_hub_rm_rf( $nvoos_docs_hub_cache_dir );
	}
}

nvoos_docs_hub_uninstall();
