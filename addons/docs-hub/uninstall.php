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

	// Clear page transients (md5-keyed — wildcard cleanup).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_nvoos_dh_p_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_nvoos_dh_p_' ) . '%'
		)
	);

	// Unschedule all rebuild-related cron events.
	wp_clear_scheduled_hook( 'nvoos_docs_hub_rebuild_cron' );
	wp_clear_scheduled_hook( 'nvoos_docs_hub_rebuild_tick' );

	// Delete cached JSON files from the upload directory.
	$nvoos_docs_hub_upload_info = wp_upload_dir();
	$nvoos_docs_hub_cache_dir   = $nvoos_docs_hub_upload_info['basedir'] . DIRECTORY_SEPARATOR . 'nvoos-docs-hub';

	if ( is_link( $nvoos_docs_hub_cache_dir ) ) {
		// The cache directory itself is a symlink. Never follow it: remove
		// only the link so its external target is left untouched. realpath()
		// would otherwise resolve the external target as the containment
		// root, letting deletion escape the plugin cache tree.
		wp_delete_file( $nvoos_docs_hub_cache_dir );
	} elseif ( is_dir( $nvoos_docs_hub_cache_dir ) ) {
		// Helper: recursively delete a directory. Symlink-aware and
		// containment-checked so a symlinked directory can never
		// redirect deletion outside the plugin cache tree.
		$nvoos_docs_hub_rm_rf = static function ( $nvoos_docs_hub_dir ) use ( &$nvoos_docs_hub_rm_rf, $nvoos_docs_hub_cache_dir ) {
			if ( is_link( $nvoos_docs_hub_dir ) ) {
				// Delete the link itself — never follow it into its target.
				wp_delete_file( $nvoos_docs_hub_dir );
				return;
			}
			if ( ! is_dir( $nvoos_docs_hub_dir ) ) {
				return;
			}

			// Containment guard: every target must resolve inside the
			// plugin cache directory. The root is resolved from the
			// top-level cache path, which the is_link() checks above
			// guarantee is a real directory, so an external target can
			// never be promoted to the containment root.
			$nvoos_docs_hub_root = realpath( $nvoos_docs_hub_cache_dir );
			$nvoos_docs_hub_real = realpath( $nvoos_docs_hub_dir );
			if ( false === $nvoos_docs_hub_root || false === $nvoos_docs_hub_real ) {
				return;
			}
			if ( $nvoos_docs_hub_real !== $nvoos_docs_hub_root && 0 !== strpos( $nvoos_docs_hub_real, $nvoos_docs_hub_root . DIRECTORY_SEPARATOR ) ) {
				return;
			}

			$nvoos_docs_hub_entries = array_diff( scandir( $nvoos_docs_hub_real ), array( '.', '..' ) );
			foreach ( $nvoos_docs_hub_entries as $nvoos_docs_hub_entry ) {
				$nvoos_docs_hub_path = $nvoos_docs_hub_real . DIRECTORY_SEPARATOR . $nvoos_docs_hub_entry;
				if ( is_link( $nvoos_docs_hub_path ) ) {
					// Delete the link itself — never follow it into its target.
					wp_delete_file( $nvoos_docs_hub_path );
				} elseif ( is_dir( $nvoos_docs_hub_path ) ) {
					$nvoos_docs_hub_rm_rf( $nvoos_docs_hub_path );
				} else {
					wp_delete_file( $nvoos_docs_hub_path );
				}
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- no WP API for rmdir; suppressed, non-empty dirs fall through.
			@rmdir( $nvoos_docs_hub_real );
		};

		// Delete the entire cache directory tree (includes pages/, remote/, _staging/,
		// .htaccess, web.config, index.php, and all JSON files).
		$nvoos_docs_hub_rm_rf( $nvoos_docs_hub_cache_dir );
	}
}

nvoos_docs_hub_uninstall();
