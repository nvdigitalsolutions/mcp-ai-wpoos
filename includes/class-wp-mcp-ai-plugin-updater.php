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
	 * The asset filename pattern for the complete package.
	 */
	const ASSET_COMPLETE = 'nvdigital-open-operator-system-oos-complete';

	/**
	 * The asset filename pattern for the Pro addon package.
	 */
	const ASSET_PRO = 'nvdigital-open-operator-system-oos-pro';

	/**
	 * Initialize the updater.
	 *
	 * Only hooks in when this is the full/GitHub build, not the
	 * WordPress.org base-only version.
	 */
	public static function init() {
		// Only active for the full build (not WordPress.org base-only).
		if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
			return;
		}

		// AJAX: check for core plugin updates.
		add_action( 'wp_ajax_wp_mcp_ai_check_plugin_update', array( __CLASS__, 'ajax_check_update' ) );

		// AJAX: start core plugin update.
		add_action( 'wp_ajax_wp_mcp_ai_start_plugin_update', array( __CLASS__, 'ajax_start_update' ) );

		// AJAX: check for Pro addon updates.
		add_action( 'wp_ajax_wp_mcp_ai_check_pro_update', array( __CLASS__, 'ajax_check_pro_update' ) );

		// AJAX: start Pro addon update.
		add_action( 'wp_ajax_wp_mcp_ai_start_pro_update', array( __CLASS__, 'ajax_start_pro_update' ) );
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
				'timeout'  => 15,
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
			'latest_version'  => ltrim( $data['tag_name'], 'vV' ),
			'assets'          => isset( $data['assets'] ) ? $data['assets'] : array(),
			'release_notes'   => isset( $data['body'] ) ? $data['body'] : '',
			'published_at'    => isset( $data['published_at'] ) ? $data['published_at'] : '',
			'checked_at'      => time(),
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
			// Match pattern but exclude the sibling packages (e.g. match 'pro'
			// but not 'pro-extension' or 'complete' when looking for 'pro').
			if ( false !== strpos( $name, $pattern ) && false !== strpos( $name, '.zip' ) ) {
				// Ensure we're matching a standalone package, not a substring.
				// 'pro' should match '...-pro-1.0.0.zip' but NOT '...-complete-1.0.0.zip'.
				$base = basename( $name, '.zip' );
				if ( self::ASSET_COMPLETE === $pattern ) {
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
	 * Get the installed plugin's directory path.
	 *
	 * @return string Plugin directory path without trailing slash.
	 */
	public static function get_plugin_dir() {
		return untrailingslashit( WP_MCP_AI_PATH );
	}

	/**
	 * Download and install the update from the GitHub release asset.
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

		// Load WordPress file system if needed.
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Download the ZIP.
		$temp_file = download_url( $download_url, 300, false );

		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		// Extract to a temp directory.
		$temp_dir = WP_MCP_AI_PATH . '.tmp-update/';
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

		// Find the extracted directory (it may be nested inside the ZIP).
		$extracted_dirs = glob( $temp_dir . '*', GLOB_ONLYDIR );
		if ( empty( $extracted_dirs ) ) {
			self::rmdir_recursive( $temp_dir );
			return new WP_Error(
				'extract_failed',
				__( 'Failed to locate extracted plugin directory.', 'mcp-ai-wpoos' )
			);
		}
		$source_dir = $extracted_dirs[0];

		// Verify it looks like a plugin (has a main PHP file and addons/).
		$php_files = glob( $source_dir . '/*.php' );
		if ( empty( $php_files ) ) {
			self::rmdir_recursive( $temp_dir );
			return new WP_Error(
				'invalid_package',
				__( 'Downloaded package does not appear to be a valid plugin.', 'mcp-ai-wpoos' )
			);
		}

		$plugin_dir = self::get_plugin_dir();
		$backup_dir = $plugin_dir . '.backup-' . gmdate( 'YmdHis' );

		// Create a backup of the current plugin.
		if ( ! rename( $plugin_dir, $backup_dir ) ) {
			self::rmdir_recursive( $temp_dir );
			return new WP_Error(
				'backup_failed',
				__( 'Failed to create backup of existing plugin files.', 'mcp-ai-wpoos' )
			);
		}

		// Move the new files into place.
		if ( ! rename( $source_dir, $plugin_dir ) ) {
			// Restore from backup.
			rename( $backup_dir, $plugin_dir );
			self::rmdir_recursive( $temp_dir );
			return new WP_Error(
				'install_failed',
				__( 'Failed to install updated plugin files. The previous version has been restored.', 'mcp-ai-wpoos' )
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
	 * Download and install the Pro addon update from the GitHub release asset.
	 *
	 * Unlike the core update, this only replaces the addons/pro/ directory
	 * rather than the entire plugin.
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

		// Load WordPress file system if needed.
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

		$pro_dir    = untrailingslashit( WP_MCP_AI_PRO_PATH );
		$backup_dir = $pro_dir . '.backup-' . gmdate( 'YmdHis' );

		// Create a backup of the current Pro addon.
		if ( ! rename( $pro_dir, $backup_dir ) ) {
			self::rmdir_recursive( $temp_dir );
			return new WP_Error(
				'backup_failed',
				__( 'Failed to create backup of existing Pro addon files.', 'mcp-ai-wpoos' )
			);
		}

		// Move the new Pro files into place.
		if ( ! rename( $source_dir, $pro_dir ) ) {
			// Restore from backup.
			rename( $backup_dir, $pro_dir );
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
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rmdir_rmdir
	}
}
