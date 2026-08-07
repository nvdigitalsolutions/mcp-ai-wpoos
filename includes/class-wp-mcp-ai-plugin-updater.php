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
	 * The asset filename pattern for the full build (same folder structure as base,
	 * used when upgrading from base to complete).
	 */
	const ASSET_FULL = 'wp-mcp-ai-';

	/**
	 * The asset filename pattern for the Pro addon package.
	 */
	const ASSET_PRO = 'nvdigital-open-operator-system-oos-pro';

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

		// AJAX: upgrade from base to complete.
		add_action( 'wp_ajax_wp_mcp_ai_upgrade_to_complete', array( __CLASS__, 'ajax_upgrade_to_complete' ) );

		// AJAX: check complete version availability.
		add_action( 'wp_ajax_wp_mcp_ai_check_complete', array( __CLASS__, 'ajax_check_complete' ) );
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
		if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
			'installed'        => WP_MCP_AI_PRO_VERSION,
			'latest'           => $release['latest_version'],
			'update_available' => version_compare( $release['latest_version'], WP_MCP_AI_PRO_VERSION, '>' ),
			'download_url'     => $download_url,
			'release_notes'    => $release['release_notes'],
			'published_at'     => $release['published_at'],
			'checked_at'       => $release['checked_at'],
		);
	}

	/**
	 * Download and install the update using WordPress's Plugin_Upgrader.
	 *
	 * The upgrader handles deactivation, safe file replacement via WP_Filesystem,
	 * and reactivation — avoiding the crash that occurs when rename() swaps the
	 * plugin directory out from under the running PHP process.
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

		// Load WordPress upgrade internals.
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Get the plugin basename (e.g. 'mcp-ai-wpoos/mcp-ai-wpoos.php').
		$plugin_basename = plugin_basename( WP_MCP_AI_FILE );

		// Download the ZIP.
		$temp_file = download_url( $download_url, 300, false );
		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		// Use Plugin_Upgrader to perform the update safely.
		// It handles: deactivation → file replacement → reactivation.
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		$result = $upgrader->upgrade(
			$plugin_basename,
			array(
				'package'                     => $temp_file,
				'clear_destination'           => true,
				'abort_if_destination_exists' => false,
				'is_multi'                    => false,
				'hook_extra'                  => array(),
			)
		);

		// Clean up the temp file if it still exists.
		if ( file_exists( $temp_file ) ) {
			unlink( $temp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temp file cleanup after upgrader.
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			$messages = $skin->get_upgrade_messages();
			$last_msg = ! empty( $messages ) ? end( $messages ) : '';
			return new WP_Error(
				'upgrade_failed',
				$last_msg ? $last_msg : __( 'Plugin upgrade failed for an unknown reason.', 'mcp-ai-wpoos' )
			);
		}

		// Force reactivation if the upgrader deactivated us.
		if ( ! is_plugin_active( $plugin_basename ) ) {
			activate_plugin( $plugin_basename, '', false, true );
		}

		// Clear the update cache.
		delete_transient( self::CACHE_KEY );

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

		$download_url = self::find_asset_url( $release['assets'], self::ASSET_FULL );

		return array(
			'installed'    => WP_MCP_AI_VERSION,
			'latest'       => $release['latest_version'],
			'available'    => ! empty( $download_url ),
			'download_url' => $download_url,
			'published_at' => $release['published_at'],
			'checked_at'   => $release['checked_at'],
		);
	}

	/**
	 * Upgrade from base to complete version.
	 *
	 * Downloads the full build ZIP (wp-mcp-ai-{version}-full.zip) which has
	 * the same folder structure as the base plugin, so Plugin_Upgrader can
	 * replace it in-place.
	 *
	 * @param string $download_url URL of the full build ZIP.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public static function upgrade_to_complete( $download_url ) {
		if ( empty( $download_url ) ) {
			return new WP_Error(
				'no_download_url',
				__( 'No download URL found for the complete build.', 'mcp-ai-wpoos' )
			);
		}

		// Load WordPress upgrade internals.
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! class_exists( 'Plugin_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_basename = plugin_basename( WP_MCP_AI_FILE );

		$temp_file = download_url( $download_url, 300, false );
		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		$result = $upgrader->upgrade(
			$plugin_basename,
			array(
				'package'                     => $temp_file,
				'clear_destination'           => true,
				'abort_if_destination_exists' => false,
				'is_multi'                    => false,
				'hook_extra'                  => array(),
			)
		);

		if ( file_exists( $temp_file ) ) {
			unlink( $temp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- temp file cleanup after upgrade.
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			$messages = $skin->get_upgrade_messages();
			$last_msg = ! empty( $messages ) ? end( $messages ) : '';
			return new WP_Error(
				'upgrade_failed',
				$last_msg ? $last_msg : __( 'Upgrade to complete version failed.', 'mcp-ai-wpoos' )
			);
		}

		// Force reactivation if the upgrader deactivated us.
		if ( ! is_plugin_active( $plugin_basename ) ) {
			activate_plugin( $plugin_basename, '', false, true );
		}

		// Clear the update cache.
		delete_transient( self::CACHE_KEY );

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
					__( 'Upgraded to complete version %s. The plugin now includes all Pro toolkits and addons. Please reload the page.', 'mcp-ai-wpoos' ),
					$check['latest']
				),
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
