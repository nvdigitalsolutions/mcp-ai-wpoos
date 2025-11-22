<?php
/**
 * WordPress Core Function Polyfills
 *
 * Provides polyfills for WordPress admin functions that may not be available
 * in certain contexts, especially when accessed via REST API or other non-admin contexts.
 *
 * IMPORTANT: This file must be loaded BEFORE WordPress admin includes (like misc.php,
 * update.php, etc.) are loaded, because those files declare some of these functions
 * without checking if they exist first. Loading this file after those WordPress files
 * will cause "Cannot redeclare function" fatal errors.
 *
 * This file follows separation of concerns by centralizing all WordPress compatibility
 * polyfills in one place, making them available to tools that need to load WordPress
 * admin files in non-admin contexts.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent loading this file multiple times.
if ( defined( 'WP_MCP_AI_POLYFILLS_LOADED' ) ) {
	return;
}
define( 'WP_MCP_AI_POLYFILLS_LOADED', true );

/**
 * Helper function to safely load WordPress admin files.
 *
 * @param string $filename The filename relative to wp-admin/includes/.
 * @return bool True if file was loaded or already exists, false otherwise.
 */
function wp_mcp_ai_load_admin_file( $filename ) {
	$file_path = ABSPATH . 'wp-admin/includes/' . $filename;
	if ( file_exists( $file_path ) ) {
		require_once $file_path;
		return true;
	}
	return false;
}

// Polyfill for wp_check_php_version() - introduced in WordPress 5.1.0.
if ( ! function_exists( 'wp_check_php_version' ) ) {
	/**
	 * Polyfill for wp_check_php_version() function.
	 *
	 * Checks the PHP version and returns version data from WordPress.org API.
	 * This is a simplified version that returns cached or basic data.
	 *
	 * @since 5.1.0 (WordPress Core)
	 * @return array|false Array of PHP version data on success, false on failure.
	 */
	function wp_check_php_version() {
		$response = get_site_transient( 'php_check_result' );

		if ( false !== $response ) {
			return $response;
		}

		$url      = 'https://api.wordpress.org/core/serve-happy/1.0/';
		$response = wp_remote_get( $url );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Return a basic response structure if the API call fails.
			return array(
				'recommended_version' => '7.4',
				'minimum_version'     => '7.4',
				'is_supported'        => version_compare( PHP_VERSION, '7.4', '>=' ),
				'is_secure'           => version_compare( PHP_VERSION, '7.4', '>=' ),
				'is_acceptable'       => version_compare( PHP_VERSION, '7.4', '>=' ),
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$body = json_decode( $body, true );

		if ( ! is_array( $body ) || empty( $body ) ) {
			return false;
		}

		$response = array(
			'recommended_version' => isset( $body['recommended_version'] ) ? $body['recommended_version'] : '7.4',
			'minimum_version'     => isset( $body['minimum_version'] ) ? $body['minimum_version'] : '7.4',
			'is_supported'        => isset( $body['is_supported'] ) ? (bool) $body['is_supported'] : false,
			'is_secure'           => isset( $body['is_secure'] ) ? (bool) $body['is_secure'] : false,
			'is_acceptable'       => isset( $body['is_acceptable'] ) ? (bool) $body['is_acceptable'] : false,
		);

		if ( isset( $body['is_lower_than_future_minimum'] ) ) {
			$response['is_lower_than_future_minimum'] = (bool) $body['is_lower_than_future_minimum'];
		}

		set_site_transient( 'php_check_result', $response, DAY_IN_SECONDS );

		return $response;
	}
}

// Polyfill for wp_is_auto_update_forced_for_item() - checks if auto-updates are forced.
if ( ! function_exists( 'wp_is_auto_update_forced_for_item' ) ) {
	/**
	 * Polyfill for wp_is_auto_update_forced_for_item() function.
	 *
	 * Checks if auto-updates are forced for a specific item type.
	 *
	 * @since 5.6.0 (WordPress Core)
	 * @param string      $type   The type of update being checked: 'theme' or 'plugin'. Unused in this polyfill.
	 * @param bool        $update Whether the update is enabled. Unused in this polyfill.
	 * @param object|null $item   Optional. The update offer. Unused in this polyfill.
	 * @return bool True if auto-updates are forced, false otherwise.
	 */
	function wp_is_auto_update_forced_for_item( $type, $update, $item ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Parameters kept for WordPress core API compatibility.
		// In non-admin contexts, we can't reliably determine if auto-updates are forced.
		// Return false to indicate auto-updates are not forced.
		return false;
	}
}

// Polyfill for get_core_updates() - gets available WordPress core updates.
if ( ! function_exists( 'get_core_updates' ) ) {
	/**
	 * Polyfill for get_core_updates() function.
	 *
	 * Gets available WordPress core updates from the transient.
	 *
	 * @since 2.7.0 (WordPress Core)
	 * @param array $options Optional. Options to pass to the transient check. Unused in this polyfill.
	 * @return array|false Array of update objects on success, false on failure.
	 */
	function get_core_updates( $options = array() ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Parameter kept for WordPress core API compatibility.
		$updates = get_site_transient( 'update_core' );

		if ( ! isset( $updates->updates ) || ! is_array( $updates->updates ) ) {
			return false;
		}

		return $updates->updates;
	}
}

// Polyfill for get_plugin_updates() - gets available plugin updates.
if ( ! function_exists( 'get_plugin_updates' ) ) {
	/**
	 * Polyfill for get_plugin_updates() function.
	 *
	 * Gets available plugin updates from the transient.
	 *
	 * @since 2.9.0 (WordPress Core)
	 * @return array Array of plugin update data.
	 */
	function get_plugin_updates() {
		// Ensure get_plugins() is available by loading plugin.php.
		if ( ! function_exists( 'get_plugins' ) ) {
			wp_mcp_ai_load_admin_file( 'plugin.php' );
		}

		// If get_plugins() is still not available, return empty array.
		if ( ! function_exists( 'get_plugins' ) ) {
			return array();
		}

		$all_plugins     = get_plugins();
		$upgrade_plugins = array();
		$current         = get_site_transient( 'update_plugins' );

		if ( ! isset( $current->response ) ) {
			return $upgrade_plugins;
		}

		foreach ( (array) $all_plugins as $plugin_file => $plugin_data ) {
			if ( isset( $current->response[ $plugin_file ] ) ) {
				$upgrade_plugins[ $plugin_file ]         = (object) $plugin_data;
				$upgrade_plugins[ $plugin_file ]->update = $current->response[ $plugin_file ];
			}
		}

		return $upgrade_plugins;
	}
}

// Polyfill for get_theme_updates() - gets available theme updates.
if ( ! function_exists( 'get_theme_updates' ) ) {
	/**
	 * Polyfill for get_theme_updates() function.
	 *
	 * Gets available theme updates from the transient.
	 *
	 * @since 2.8.0 (WordPress Core)
	 * @return array Array of theme update data.
	 */
	function get_theme_updates() {
		// Ensure wp_get_themes() is available by loading theme.php.
		if ( ! function_exists( 'wp_get_themes' ) ) {
			wp_mcp_ai_load_admin_file( 'theme.php' );
		}

		// If wp_get_themes() is still not available, return empty array.
		if ( ! function_exists( 'wp_get_themes' ) ) {
			return array();
		}

		$themes        = wp_get_themes();
		$current       = get_site_transient( 'update_themes' );
		$update_themes = array();

		if ( ! isset( $current->response ) ) {
			return $update_themes;
		}

		foreach ( $themes as $stylesheet => $theme ) {
			if ( isset( $current->response[ $stylesheet ] ) ) {
				$update_themes[ $stylesheet ] = wp_get_theme( $stylesheet );
			}
		}

		return $update_themes;
	}
}
