<?php
/**
 * Plugin Updater — GitHub Releases
 *
 * Checks GitHub releases for newer versions of the full plugin build
 * and provides an update mechanism through the plugin's settings UI.
 *
 * Only activates when NOT running the WordPress.org base-only version
 * (i.e., WP_MCP_AI_BASE_VERSION is false or undefined).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin updates from GitHub releases.
 */
class WP_MCP_AI_Plugin_Updater {

	/**
	 * GitHub API endpoint for the latest release.
	 */
	const GITHUB_API_LATEST = 'https://api.github.com/repos/nvdigitalsolutions/mcp-ai-wpoos/releases/latest';

	/**
	 * Transient key for caching the latest release info.
	 */
	const CACHE_KEY = 'wp_mcp_ai_latest_release';

	/**
	 * How long to cache the GitHub API response (12 hours).
	 */
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * The asset filename pattern for the complete package (replaces base in-place).
	 */
	const ASSET_COMPLETE = 'nvdigital-open-operator-system-oos-complete';

	/**
	 * The asset filename pattern for the base package (WordPress.org submission).
	 */
	const ASSET_BASE = 'nvdigital-open-operator-system-oos';

	/**
	 * The asset filename pattern for the full build (same folder structure as base,
	 * used when upgrading from base to complete).
	 */
	const ASSET_FULL = 'wp-mcp-ai-';

	/**
	 * The asset filename pattern for the Pro addon package.
	 */
	const ASSET_PRO = 'nvdigital-open-operator-system-oos-pro';

	/**
	 * Critical files that MUST exist after a core plugin update.
	 *
	 * These are loaded via require_once without file_exists() guards in the
	 * main plugin bootstrap. If any are missing after an update, the plugin
	 * will fatal on the next request. We verify them post-install to catch
	 * partial extractions (common on Cloudways/distributed filesystems).
	 *
	 * Paths are relative to the plugin root (WP_MCP_AI_PATH).
	 */
	const VERIFY_FILES = array(
		'nvdigital-open-operator-system-oos.php',
		'includes/class-wp-mcp-ai-plugin.php',
		'includes/class-wp-mcp-ai-container.php',
		'includes/class-wp-mcp-ai-rest.php',
		'includes/rest/class-wp-mcp-ai-rest-controller-base.php',
		'includes/rest/class-wp-mcp-ai-rest-chat-controller.php',
		'includes/rest/class-wp-mcp-ai-rest-mcp-controller.php',
		'includes/rest/class-wp-mcp-ai-rest-tools-controller.php',
		'includes/rest/class-wp-mcp-ai-rest-token-manager.php',
		'includes/rest/class-wp-mcp-ai-rest-cost-manager.php',
		'includes/rest/class-wp-mcp-ai-rest-analytics-manager.php',
		'includes/rest/class-wp-mcp-ai-rest-authenticator.php',
		'includes/rest/class-wp-mcp-ai-rest-validator.php',
		'includes/rest/class-wp-mcp-ai-sse-handler.php',
		'includes/bridge/bridge-init.php',
		'includes/bridge/class-wp-mcp-ai-wp70-bridge.php',
		'includes/bridge/class-wp-mcp-ai-credential-resolver.php',
	);

	/**
	 * Initialize the updater.
	 *
	 * Always hooks AJAX handlers — base-only installs see an upgrade path,
	 * full/GitHub builds see update checks.
	 */
	public static function init() {
		// AJAX: check for core plugin updates.
		add_action( 'wp_ajax_wp_mcp_ai_check_plugin_update', array( __CLASS__, 'ajax_check_update' ) );

		// AJAX: start core plugin update.
		add_action( 'wp_ajax_wp_mcp_ai_start_plugin_update', array( __CLASS__, 'ajax_start_update' ) );

		// AJAX: check for Pro addon updates.
		add_action( 'wp_ajax_wp_mcp_ai_check_pro_update', array( __CLASS__, 'ajax_check_pro_update' ) );

		// AJAX: start Pro addon update.
		add_action( 'wp_ajax_wp_mcp_ai_start_pro_update', array( __CLASS__, 'ajax_start_pro_update' ) );

		// AJAX: check for base plugin updates (wp.org base installs).
		add_action( 'wp_ajax_wp_mcp_ai_check_base_update', array( __CLASS__, 'ajax_check_base_update' ) );

		// AJAX: start base plugin update.
		add_action( 'wp_ajax_wp_mcp_ai_start_base_update', array( __CLASS__, 'ajax_start_base_update' ) );

		// AJAX: upgrade from base to complete.
		add_action( 'wp_ajax_wp_mcp_ai_upgrade_to_complete', array( __CLASS__, 'ajax_upgrade_to_complete' ) );

		// AJAX: check complete version availability.
		add_action( 'wp_ajax_wp_mcp_ai_check_complete', array( __CLASS__, 'ajax_check_complete' ) );
	}

	/**
	 * Get the installed Pro addon version.
	 *
	 * Prefers the Version header of the Pro addon's main plugin file, which
	 * the build script stamps with the actual release version. The
	 * WP_MCP_AI_PRO_VERSION constant is maintained manually and has drifted
	 * from the shipped version in the past (e.g. Pro releases built at 1.1.54
	 * still carried the constant value 1.1.50).
	 *
	 * Falls back to the constant for bundled Pro builds, which ship without a
	 * plugin header.
	 *
	 * @return string|false Installed Pro version, or false when the Pro addon is not loaded.
	 */
	public static function get_pro_installed_version() {
		static $version = null;

		if ( null !== $version ) {
			return $version;
		}

		$version = false;

		if ( defined( 'WP_MCP_AI_PRO_FILE' ) && file_exists( WP_MCP_AI_PRO_FILE ) ) {
			if ( ! function_exists( 'get_file_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$header = get_file_data( WP_MCP_AI_PRO_FILE, array( 'Version' => 'Version' ) );
			$header = isset( $header['Version'] ) ? trim( (string) $header['Version'] ) : '';

			if ( '' !== $header ) {
				$version = $header;
			}
		}

		if ( false === $version && defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			$version = WP_MCP_AI_PRO_VERSION;
		}

		return $version;
	}

	/**
	 * Fetch the latest release data from the GitHub API.
	 *
	 * Returns cached data when available (12h TTL).
	 *
	 * @return array|WP_Error Release data or error.
	 */
	private static function fetch_latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			self::GITHUB_API_LATEST,
			array(
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WP-MCP-AI/' . WP_MCP_AI_VERSION,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			set_transient( self::CACHE_KEY, $response, self::CACHE_TTL );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$error = new WP_Error(
				'github_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'GitHub API returned status %d.', 'mcp-ai-wpoos' ),
					$code
				)
			);
			set_transient( self::CACHE_KEY, $error, self::CACHE_TTL );
			return $error;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! $data || empty( $data['tag_name'] ) ) {
			$error = new WP_Error(
				'github_api_parse',
				__( 'Failed to parse GitHub release data.', 'mcp-ai-wpoos' )
			);
			set_transient( self::CACHE_KEY, $error, self::CACHE_TTL );
			return $error;
		}

		$result = array(
			// Strip common tag prefixes: 'v', 'nvdigital-oos-v', etc.
				'latest_version' => preg_replace( '/^(nvdigital-(?:open-operator-system-)?oos-)?v/i', '', $data['tag_name'] ),
			'assets'             => isset( $data['assets'] ) ? $data['assets'] : array(),
			'release_notes'      => isset( $data['body'] ) ? $data['body'] : '',
			'published_at'       => isset( $data['published_at'] ) ? $data['published_at'] : '',
			'checked_at'         => time(),
		);

		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Find a download URL in the release assets matching a pattern.
	 *
	 * @param array  $assets  Release assets array.
	 * @param string $pattern Substring to match in the asset name.
	 * @return string Download URL or empty string.
	 */
	private static function find_asset_url( $assets, $pattern ) {
		foreach ( $assets as $asset ) {
			$name = isset( $asset['name'] ) ? $asset['name'] : '';
			if ( false === strpos( $name, '.zip' ) ) {
				continue;
			}
			$base = basename( $name, '.zip' );

			if ( self::ASSET_FULL === $pattern ) {
				// Match 'wp-mcp-ai-{version}-full' — the full build with same folder
				// structure as base (used for base→complete upgrades).
				if ( 0 === strpos( $base, self::ASSET_FULL ) && false !== strpos( $base, '-full' ) ) {
					return $asset['browser_download_url'];
				}
			} elseif ( self::ASSET_COMPLETE === $pattern ) {
				if ( false !== strpos( $base, self::ASSET_COMPLETE ) ) {
					return $asset['browser_download_url'];
				}
			} elseif ( self::ASSET_PRO === $pattern ) {
				// Match '-pro-' but not '-pro-something-' and not 'complete'.
				if ( false !== strpos( $base, self::ASSET_PRO . '-' ) && false === strpos( $base, 'complete' ) ) {
					return $asset['browser_download_url'];
				}
			} elseif ( self::ASSET_BASE === $pattern ) {
				// Match the plain base package (nvdigital-open-operator-system-oos-{version}.zip)
				// but not the complete/pro/core variants which share the same prefix.
				$is_base = 0 === strpos( $base, self::ASSET_BASE . '-' )
					&& false === strpos( $base, '-complete' )
					&& false === strpos( $base, '-pro' )
					&& false === strpos( $base, '-core' );
				// Legacy naming: mcp-ai-wpoos-base-{version}.zip.
				$is_legacy_base = 0 === strpos( $base, 'mcp-ai-wpoos-base-' );
				if ( $is_base || $is_legacy_base ) {
					return $asset['browser_download_url'];
				}
			}
		}
		return '';
	}

	/**
	 * Check if a core plugin update is available.
	 *
	 * @return array|WP_Error Array with version info, or WP_Error on failure.
	 */
	public static function check_for_update() {
		$release = self::fetch_latest_release();
		if ( is_wp_error( $release ) ) {
			return $release;
		}

		$download_url = self::find_asset_url( $release['assets'], self::ASSET_COMPLETE );

		return array(
			'installed'        => WP_MCP_AI_VERSION,
			'latest'           => $release['latest_version'],
			'update_available' => version_compare( $release['latest_version'], WP_MCP_AI_VERSION, '>' ),
			'download_url'     => $download_url,
			'release_notes'    => $release['release_notes'],
			'published_at'     => $release['published_at'],
			'checked_at'       => $release['checked_at'],
		);
	}

	/**
	 * Check if a Pro addon update is available.
	 *
	 * @return array|WP_Error Array with version info, or WP_Error on failure.
	 */
	public static function check_for_pro_update() {
		$installed = self::get_pro_installed_version();
		if ( false === $installed ) {
			return new WP_Error(
				'pro_not_installed',
				__( 'Pro addon is not installed.', 'mcp-ai-wpoos' )
			);
		}

		$release = self::fetch_latest_release();
		if ( is_wp_error( $release ) ) {
			return $release;
		}

		$download_url = self::find_asset_url( $release['assets'], self::ASSET_PRO );

		return array(
			'installed'        => $installed,
			'latest'           => $release['latest_version'],
			'update_available' => version_compare( $release['latest_version'], $installed, '>' ),
			'download_url'     => $download_url,
			'release_notes'    => $release['release_notes'],
			'published_at'     => $release['published_at'],
			'checked_at'       => $release['checked_at'],
		);
	}

	/**
	 * Check if a base plugin update is available.
	 *
	 * Used by WordPress.org base installs to update the base plugin in place
	 * without upgrading to the complete build.
	 *
	 * @return array|WP_Error Array with version info, or WP_Error on failure.
	 */
	public static function check_for_base_update() {
		$release = self::fetch_latest_release();
		if ( is_wp_error( $release ) ) {
			return $release;
		}

		$download_url = self::find_asset_url( $release['assets'], self::ASSET_BASE );

		return array(
			'installed'        => WP_MCP_AI_VERSION,
			'latest'           => $release['latest_version'],
			'update_available' => ! empty( $download_url ) && version_compare( $release['latest_version'], WP_MCP_AI_VERSION, '>' ),
			'download_url'     => $download_url,
			'release_notes'    => $release['release_notes'],
			'published_at'     => $release['published_at'],
			'checked_at'       => $release['checked_at'],
		);
	}

	/**
	 * Notify listeners that plugin files were replaced in place.
	 *
	 * This updater copies files over the live plugin directory instead of
	 * going through the WordPress Plugin_Upgrader flow, so core never fires
	 * `upgrader_process_complete` for these updates. Addons (e.g. the Docs
	 * Hub) subscribe to this namespaced action to rebuild their caches after
	 * an update.
	 *
	 * @param string $updated_file Absolute path to the updated plugin's main file.
	 * @return void
	 */
	private static function notify_plugin_updated( $updated_file ) {
		/**
		 * Fires after the NV oOS plugin performs an in-place update.
		 *
		 * @param string $basename Updated plugin's file path relative to the plugins directory.
		 */
		do_action( 'wp_mcp_ai_plugin_updated', plugin_basename( $updated_file ) );
	}

	/**
	 * Download and install a core plugin update from the GitHub release asset.
	 *
	 * Replaces the plugin files in place by copying the extracted ZIP over the
	 * live plugin directory. Unlike Plugin_Upgrader, this does not depend on
	 * the ZIP's top-level folder name matching the installed plugin folder
	 * (e.g. the complete package extracts to nvdigital-open-operator-system-oos-complete
	 * while wp.org base installs live in nvdigital-open-operator-system-oos).
	 * A backup snapshot is taken before files are replaced and restored on failure.
	 * Used for both complete-build updates and base-only updates.
	 *
	 * @param string $download_url URL of the ZIP to download.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function install_update( $download_url ) {
		if ( empty( $download_url ) ) {
			return new WP_Error(
				'no_download_url',
				__( 'No download URL found for the latest release.', 'mcp-ai-wpoos' )
			);
		}

		$result = self::replace_plugin_from_zip( $download_url, false );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Clear the update cache.
		delete_transient( self::CACHE_KEY );

		// Notify addons so they can rebuild caches derived from plugin files.
		self::notify_plugin_updated( WP_MCP_AI_FILE );

		return true;
	}

	/**
	 * Replace the current plugin files with the contents of a downloaded ZIP,
	 * in place.
	 *
	 * The live plugin directory is never renamed or deleted, so the running
	 * request can keep autoloading plugin classes until it finishes. The old
	 * files are snapshotted to a sibling backup directory first and restored
	 * if the copy or the post-install integrity check fails.
	 *
	 * @param string $download_url              URL of the ZIP to download.
	 * @param bool   $deactivate_standalone_pro Whether to deactivate a separately
	 *                                          installed Pro addon before replacing
	 *                                          files. Used when upgrading base →
	 *                                          complete, which bundles Pro inside
	 *                                          addons/pro/ and would otherwise
	 *                                          double-load Pro code on the next request.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	private static function replace_plugin_from_zip( $download_url, $deactivate_standalone_pro = false ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Download the ZIP.
		$temp_file = download_url( $download_url, 300, false );
		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		// Extract to a temp directory next to the plugin folder.
		$plugin_dir = untrailingslashit( WP_MCP_AI_PATH );
		$temp_dir   = $plugin_dir . '.tmp-update/';
		if ( is_dir( $temp_dir ) ) {
			self::rmdir_recursive( $temp_dir );
		}
		wp_mkdir_p( $temp_dir );

		$unzip_result = unzip_file( $temp_file, $temp_dir );
		if ( file_exists( $temp_file ) ) {
			unlink( $temp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temp file cleanup after extraction.
		}

		if ( is_wp_error( $unzip_result ) ) {
			self::rmdir_recursive( $temp_dir );
			return $unzip_result;
		}

		// Find the extracted top-level directory.
		$extracted_dirs = glob( $temp_dir . '*', GLOB_ONLYDIR );
		if ( empty( $extracted_dirs ) ) {
			self::rmdir_recursive( $temp_dir );
			return new WP_Error(
				'extract_failed',
				__( 'Failed to locate the extracted plugin directory.', 'mcp-ai-wpoos' )
			);
		}
		$source_dir = untrailingslashit( $extracted_dirs[0] );

		// The package must contain the same main plugin file as the currently
		// installed plugin; otherwise replacing the files would orphan the
		// active entry point.
		$main_file = basename( WP_MCP_AI_FILE );
		if ( ! file_exists( $source_dir . '/' . $main_file ) ) {
			self::rmdir_recursive( $temp_dir );
			return new WP_Error(
				'main_file_mismatch',
				sprintf(
					/* translators: %s: expected main plugin file name */
					__( 'The update package does not contain the expected main plugin file (%s). The update was cancelled and nothing was changed.', 'mcp-ai-wpoos' ),
					$main_file
				)
			);
		}

		// Snapshot the current plugin directory for rollback.
		$backup_dir = $plugin_dir . '.backup-' . gmdate( 'YmdHis' );
		if ( ! self::copy_dir_recursive( $plugin_dir, $backup_dir ) ) {
			self::rmdir_recursive( $temp_dir );
			return new WP_Error(
				'backup_failed',
				__( 'Failed to create a backup of the existing plugin files.', 'mcp-ai-wpoos' )
			);
		}

		// Deactivate a separately-installed Pro addon before replacing files
		// when the new build bundles Pro.
		$standalone_pro_basename = '';
		$pro_is_network_active   = false;
		if ( $deactivate_standalone_pro && defined( 'WP_MCP_AI_PRO_FILE' ) && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$pro_base  = untrailingslashit( wp_normalize_path( WP_MCP_AI_PRO_PATH ) );
			$main_base = untrailingslashit( wp_normalize_path( WP_MCP_AI_PATH ) );
			if ( 0 !== strpos( $pro_base, $main_base . '/' ) ) {
				$standalone_pro_basename = plugin_basename( WP_MCP_AI_PRO_FILE );
				$pro_is_network_active   = is_multisite() && is_plugin_active_for_network( $standalone_pro_basename );

				if ( $pro_is_network_active ) {
					// Network-wide deactivation needs a network administrator.
					if ( ! current_user_can( 'manage_network' ) ) {
						self::rmdir_recursive( $backup_dir );
						self::rmdir_recursive( $temp_dir );
						return new WP_Error(
							'network_pro_active',
							__( 'The Pro addon is network-activated. Only a network administrator can install the complete version.', 'mcp-ai-wpoos' )
						);
					}
					deactivate_plugins( $standalone_pro_basename, true, true );
				} elseif ( is_plugin_active( $standalone_pro_basename ) ) {
					deactivate_plugins( $standalone_pro_basename, true );
				}
			}
		}

		// Copy the new files over the live plugin directory.
		$copied = self::copy_dir_recursive( $source_dir, $plugin_dir );
		if ( ! $copied ) {
			// Restore the snapshot (copy back over, leaving the live dir in place).
			self::copy_dir_recursive( $backup_dir, $plugin_dir );
			if ( '' !== $standalone_pro_basename ) {
				activate_plugin( $standalone_pro_basename, '', $pro_is_network_active, true );
			}
			self::rmdir_recursive( $backup_dir );
			self::rmdir_recursive( $temp_dir );
			return new WP_Error(
				'install_failed',
				__( 'Failed to install the update. The previous version has been restored.', 'mcp-ai-wpoos' )
			);
		}

		// Clear PHP's stat cache before verifying integrity: files were replaced
		// within this same PHP process, so file_exists() may return stale results
		// from the old inodes unless we force a fresh stat of the filesystem.
		clearstatcache( true );

		// Verify that all critical files were extracted correctly.
		// On distributed filesystems (e.g. Cloudways), unzip can silently drop
		// files without reporting an error.
		$integrity = self::verify_installation_integrity();
		if ( is_wp_error( $integrity ) ) {
			// Restore the snapshot before surfacing the error.
			self::copy_dir_recursive( $backup_dir, $plugin_dir );
			if ( '' !== $standalone_pro_basename ) {
				activate_plugin( $standalone_pro_basename, '', $pro_is_network_active, true );
			}
			self::rmdir_recursive( $backup_dir );
			self::rmdir_recursive( $temp_dir );
			return $integrity;
		}

		// Clean up.
		self::rmdir_recursive( $temp_dir );
		self::rmdir_recursive( $backup_dir );

		return true;
	}

	/**
	 * Download and install the Pro addon update from the GitHub release asset.
	 *
	 * Uses a safe copy-from-temp approach — copies new files into addons/pro/
	 * while preserving files that only exist in the current installation.
	 * The main plugin stays active throughout.
	 *
	 * @param string $download_url URL of the Pro ZIP to download.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function install_pro_update( $download_url ) {
		if ( empty( $download_url ) ) {
			return new WP_Error(
				'no_download_url',
				__( 'No download URL found for the Pro addon release.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			return new WP_Error(
				'pro_not_installed',
				__( 'Pro addon path is not defined.', 'mcp-ai-wpoos' )
			);
		}

		// Load WordPress file system.
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		// Download the ZIP.
		$temp_file = download_url( $download_url, 300, false );
		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		// Extract to a temp directory.
		$temp_dir = WP_MCP_AI_PATH . '.tmp-pro-update/';
		if ( is_dir( $temp_dir ) ) {
			self::rmdir_recursive( $temp_dir );
		}
		wp_mkdir_p( $temp_dir );

		$unzip_result = unzip_file( $temp_file, $temp_dir );
		unlink( $temp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink

		if ( is_wp_error( $unzip_result ) ) {
			self::rmdir_recursive( $temp_dir );
			return $unzip_result;
		}

		// Find the extracted directory.
		$extracted_dirs = glob( $temp_dir . '*', GLOB_ONLYDIR );
		if ( empty( $extracted_dirs ) ) {
			self::rmdir_recursive( $temp_dir );
			return new WP_Error(
				'extract_failed',
				__( 'Failed to locate extracted Pro addon directory.', 'mcp-ai-wpoos' )
			);
		}
		$source_dir = $extracted_dirs[0];

		// Verify it looks like a Pro addon.
		$pro_main_files = glob( $source_dir . '/*.php' );
		if ( empty( $pro_main_files ) ) {
			self::rmdir_recursive( $temp_dir );
			return new WP_Error(
				'invalid_package',
				__( 'Downloaded package does not appear to be a valid Pro addon.', 'mcp-ai-wpoos' )
			);
		}

		$pro_dir = untrailingslashit( WP_MCP_AI_PRO_PATH );

		// Create a backup of the current Pro addon.
		$backup_dir = $pro_dir . '.backup-' . gmdate( 'YmdHis' );
		if ( ! rename( $pro_dir, $backup_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- backup before update.
			self::rmdir_recursive( $temp_dir );
			return new WP_Error(
				'backup_failed',
				__( 'Failed to create backup of existing Pro addon files.', 'mcp-ai-wpoos' )
			);
		}

		// Copy the new Pro files into place.
		$copied = self::copy_dir_recursive( $source_dir, $pro_dir );
		if ( ! $copied ) {
			// Restore from backup.
			self::rmdir_recursive( $pro_dir );
			rename( $backup_dir, $pro_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- restore from backup.
			self::rmdir_recursive( $temp_dir );
			return new WP_Error(
				'install_failed',
				__( 'Failed to install updated Pro addon files. The previous version has been restored.', 'mcp-ai-wpoos' )
			);
		}

		// Clean up.
		self::rmdir_recursive( $temp_dir );
		self::rmdir_recursive( $backup_dir );

		// Clear the update cache.
		delete_transient( self::CACHE_KEY );

		// Notify addons so they can rebuild caches derived from Pro files.
		self::notify_plugin_updated( defined( 'WP_MCP_AI_PRO_FILE' ) ? WP_MCP_AI_PRO_FILE : WP_MCP_AI_FILE );

		return true;
	}

	/**
	 * AJAX handler: check for plugin updates from the settings UI.
	 */
	public static function ajax_check_update() {
		check_ajax_referer( 'wp_mcp_ai_plugin_update', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		// Force a fresh check — bypass the 12h cache so the user sees live data.
		delete_transient( self::CACHE_KEY );

		$result = self::check_for_update();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: start the plugin update process.
	 */
	public static function ajax_start_update() {
		check_ajax_referer( 'wp_mcp_ai_plugin_update', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		// Force a fresh check (bypass cache).
		delete_transient( self::CACHE_KEY );
		$check = self::check_for_update();

		if ( is_wp_error( $check ) ) {
			wp_send_json_error( array( 'message' => $check->get_error_message() ) );
		}

		if ( empty( $check['update_available'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No update available.', 'mcp-ai-wpoos' ) ) );
		}

		$result = self::install_update( $check['download_url'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: new version number */
					__( 'Plugin updated to version %s. Please reload the page.', 'mcp-ai-wpoos' ),
					$check['latest']
				),
			)
		);
	}

	/**
	 * AJAX handler: check for Pro addon updates from the settings UI.
	 */
	public static function ajax_check_pro_update() {
		check_ajax_referer( 'wp_mcp_ai_plugin_update', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		// Force a fresh check — bypass the 12h cache so the user sees live data.
		delete_transient( self::CACHE_KEY );

		$result = self::check_for_pro_update();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: start the Pro addon update process.
	 */
	public static function ajax_start_pro_update() {
		check_ajax_referer( 'wp_mcp_ai_plugin_update', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		// Force a fresh check (bypass cache).
		delete_transient( self::CACHE_KEY );
		$check = self::check_for_pro_update();

		if ( is_wp_error( $check ) ) {
			wp_send_json_error( array( 'message' => $check->get_error_message() ) );
		}

		if ( empty( $check['update_available'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No Pro addon update available.', 'mcp-ai-wpoos' ) ) );
		}

		$result = self::install_pro_update( $check['download_url'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: new version number */
					__( 'Pro addon updated to version %s. Please reload the page.', 'mcp-ai-wpoos' ),
					$check['latest']
				),
			)
		);
	}

	/**
	 * AJAX handler: check for base plugin updates (wp.org base installs).
	 */
	public static function ajax_check_base_update() {
		check_ajax_referer( 'wp_mcp_ai_plugin_update', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		// Force a fresh check — bypass the 12h cache so the user sees live data.
		delete_transient( self::CACHE_KEY );

		$result = self::check_for_base_update();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: start the base plugin update process.
	 */
	public static function ajax_start_base_update() {
		check_ajax_referer( 'wp_mcp_ai_plugin_update', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		// Force a fresh check (bypass cache).
		delete_transient( self::CACHE_KEY );
		$check = self::check_for_base_update();

		if ( is_wp_error( $check ) ) {
			wp_send_json_error( array( 'message' => $check->get_error_message() ) );
		}

		if ( empty( $check['update_available'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No base plugin update available.', 'mcp-ai-wpoos' ) ) );
		}

		// The install routine is identical to the complete-build update: the
		// extracted files are copied over the live plugin directory in place,
		// with a backup snapshot and rollback on failure. A separately
		// installed Pro addon lives in its own plugin folder and is untouched.
		$result = self::install_update( $check['download_url'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: new version number */
					__( 'Base plugin updated to version %s. Please reload the page.', 'mcp-ai-wpoos' ),
					$check['latest']
				),
			)
		);
	}

	/**
	 * Check if the complete version is available from GitHub.
	 *
	 * Used for base-only installs to show the upgrade path.
	 *
	 * @return array|WP_Error Array with version info, or WP_Error on failure.
	 */
	public static function check_complete_availability() {
		$release = self::fetch_latest_release();
		if ( is_wp_error( $release ) ) {
			return $release;
		}

		// Prefer the legacy '-full' asset (same folder structure as base) when
		// present, then fall back to the complete package
		// (nvdigital-open-operator-system-oos-complete-*.zip), which is the
		// asset actually shipped by current releases.
		$download_url = self::find_asset_url( $release['assets'], self::ASSET_FULL );
		if ( empty( $download_url ) ) {
			$download_url = self::find_asset_url( $release['assets'], self::ASSET_COMPLETE );
		}

		$is_newer = version_compare( $release['latest_version'], WP_MCP_AI_VERSION, '>' );

		// Determine why the complete version is/isn't available for download.
		if ( empty( $download_url ) ) {
			$reason = $is_newer
				? __( 'A newer version exists but no complete-build download asset was found in the GitHub release. The complete build may not have been uploaded for this release yet.', 'mcp-ai-wpoos' )
				: __( 'No complete-build download asset found in the GitHub release.', 'mcp-ai-wpoos' );
		} elseif ( ! $is_newer ) {
			$reason = __( 'You are already running the latest version.', 'mcp-ai-wpoos' );
		} else {
			$reason = '';
		}

		return array(
			'installed'    => WP_MCP_AI_VERSION,
			'latest'       => $release['latest_version'],
			'available'    => ! empty( $download_url ) && $is_newer,
			'download_url' => $download_url,
			'reason'       => $reason,
			'published_at' => $release['published_at'],
			'checked_at'   => $release['checked_at'],
		);
	}

	/**
	 * Upgrade from base to complete version.
	 *
	 * Downloads the complete build ZIP (nvdigital-open-operator-system-oos-complete-{version}.zip)
	 * and replaces the base plugin files in place. A separately-installed Pro
	 * addon is deactivated first, because the complete build bundles Pro inside
	 * addons/pro/ and an active standalone Pro plugin would double-load Pro code.
	 *
	 * @param string $download_url URL of the complete build ZIP.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function upgrade_to_complete( $download_url ) {
		if ( empty( $download_url ) ) {
			return new WP_Error(
				'no_download_url',
				__( 'No download URL found for the complete build.', 'mcp-ai-wpoos' )
			);
		}

		$result = self::replace_plugin_from_zip( $download_url, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// A complete build must bundle the Pro addon.
		if ( ! file_exists( untrailingslashit( WP_MCP_AI_PATH ) . '/addons/pro/mcp-ai-wpoos-pro.php' ) ) {
			return new WP_Error(
				'pro_addon_missing',
				__( 'The complete build was installed but the bundled Pro addon could not be found. The update may have been partially extracted.', 'mcp-ai-wpoos' )
			);
		}

		// Clear the update cache.
		delete_transient( self::CACHE_KEY );

		// Notify addons so they can rebuild caches derived from plugin files.
		self::notify_plugin_updated( WP_MCP_AI_FILE );

		return true;
	}

	/**
	 * AJAX handler: check complete version availability (for base-only installs).
	 */
	public static function ajax_check_complete() {
		check_ajax_referer( 'wp_mcp_ai_plugin_update', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		// Force a fresh check — bypass the 12h cache so the user sees live data,
		// matching the behaviour of ajax_check_update().
		delete_transient( self::CACHE_KEY );

		$result = self::check_complete_availability();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX handler: upgrade from base to complete version.
	 */
	public static function ajax_upgrade_to_complete() {
		check_ajax_referer( 'wp_mcp_ai_plugin_update', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
		}

		// Force a fresh check (bypass cache).
		delete_transient( self::CACHE_KEY );
		$check = self::check_complete_availability();

		if ( is_wp_error( $check ) ) {
			wp_send_json_error( array( 'message' => $check->get_error_message() ) );
		}

		if ( empty( $check['available'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Complete version is not available for download.', 'mcp-ai-wpoos' ) ) );
		}

		$result = self::upgrade_to_complete( $check['download_url'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %s: new version number */
					__( 'Upgraded to complete version %s. The plugin now includes all Pro toolkits and addons. Any separately installed Pro addon has been deactivated. Please reload the page.', 'mcp-ai-wpoos' ),
					$check['latest']
				),
			)
		);
	}

	/**
	 * Verify that critical plugin files exist after an update.
	 *
	 * Checks the safelist in VERIFY_FILES against the plugin directory.
	 * Returns a WP_Error listing any missing files, or true if all pass.
	 *
	 * @return true|WP_Error True if all files present, WP_Error with missing list otherwise.
	 */
	private static function verify_installation_integrity() {
		$missing = array();

		foreach ( self::VERIFY_FILES as $relative_path ) {
			$absolute_path = WP_MCP_AI_PATH . $relative_path;
			if ( ! file_exists( $absolute_path ) ) {
				$missing[] = $relative_path;
			}
		}

		if ( empty( $missing ) ) {
			return true;
		}

		return new WP_Error(
			'integrity_check_failed',
			sprintf(
				/* translators: %s: comma-separated list of missing file paths */
				__( 'Update appears to be incomplete — the following required files are missing: %s. The update may have been partially extracted. Please try updating again.', 'mcp-ai-wpoos' ),
				implode( ', ', $missing )
			)
		);
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private static function rmdir_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			is_dir( $path ) ? self::rmdir_recursive( $path ) : unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- cleanup after update.
	}

	/**
	 * Recursively copy a directory.
	 *
	 * @param string $src  Source directory.
	 * @param string $dest Destination directory.
	 * @return bool True on success.
	 */
	private static function copy_dir_recursive( $src, $dest ) {
		if ( ! is_dir( $src ) ) {
			return false;
		}

		if ( ! is_dir( $dest ) ) {
			wp_mkdir_p( $dest );
		}

		$dir = opendir( $src );
		if ( ! $dir ) {
			return false;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_readdir
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		// phpcs:disable Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( false !== ( $file = readdir( $dir ) ) ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}

			$src_path  = $src . '/' . $file;
			$dest_path = $dest . '/' . $file;

			if ( is_dir( $src_path ) ) {
				if ( ! self::copy_dir_recursive( $src_path, $dest_path ) ) {
					closedir( $dir );
					return false;
				}
			} elseif ( ! copy( $src_path, $dest_path ) ) {
					closedir( $dir );
					return false;
			}
		}
		// phpcs:enable

		closedir( $dir );
		return true;
	}
}
