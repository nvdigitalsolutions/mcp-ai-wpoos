<?php
/**
 * Breeze cache integration — excludes session-bearing pages from the cache.
 *
 * Breeze (Cloudways' cache plugin) can serve cached front-end pages that
 * were rendered for a logged-in visitor. Those copies embed the admin-bar
 * markup with a session-bound `wp_rest` nonce and the log-out nonce. When a
 * different session receives that HTML (the Complete Console scrapes
 * /wp-admin/ → /dashboard/ for a nonce as a fallback), the scraped nonce
 * fails core's rest_cookie_check_errors and every authenticated REST call
 * answers 403 rest_cookie_invalid_nonce ("Cookie check failed").
 *
 * This integration appends the known session-bearing paths to Breeze's
 * `breeze_exclude_urls` filter so those pages are never served from cache.
 * /wp-admin/ is already excluded by Breeze internally; it is listed for
 * completeness. The list is filterable via
 * `wp_mcp_ai_breeze_cache_excluded_urls`.
 *
 * Self-gated: hooks are only installed when Breeze is active
 * (`BREEZE_VERSION` is defined).
 *
 * @package NV_oOS
 * @since   1.2.13
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Breeze_Cache_Integration
 *
 * @since 1.2.13
 */
class WP_MCP_AI_Breeze_Cache_Integration {

	/**
	 * Install the Breeze exclusion filter when Breeze is active.
	 *
	 * @since 1.2.13
	 * @return void
	 */
	public static function init() {
		if ( ! defined( 'BREEZE_VERSION' ) ) {
			return;
		}

		add_filter( 'breeze_exclude_urls', array( __CLASS__, 'exclude_session_pages' ) );
	}

	/**
	 * Append session-bearing pages to Breeze's cache exclusion list.
	 *
	 * @since 1.2.13
	 * @param array $urls Current excluded URL patterns.
	 * @return array
	 */
	public static function exclude_session_pages( $urls ) {
		if ( ! is_array( $urls ) ) {
			$urls = array();
		}

		/**
		 * Filter the URL patterns excluded from Breeze's cache by NV oOS.
		 *
		 * @since 1.2.13
		 * @param array $excluded Default session-bearing paths.
		 */
		$excluded = apply_filters(
			'wp_mcp_ai_breeze_cache_excluded_urls',
			array( '/dashboard/', '/wp-admin/' )
		);

		foreach ( (array) $excluded as $path ) {
			$path = trim( (string) $path );
			if ( '' !== $path && ! in_array( $path, $urls, true ) ) {
				$urls[] = $path;
			}
		}

		return $urls;
	}
}
