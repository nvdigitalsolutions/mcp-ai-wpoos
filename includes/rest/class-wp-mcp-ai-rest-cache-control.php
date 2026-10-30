<?php
/**
 * REST response cache-control preservation.
 *
 * WordPress 6.9 sends no-cache headers on EVERY logged-in REST response —
 * `WP_REST_Server::serve_request()` applies `rest_send_nocache_headers`
 * (default `is_user_logged_in()`) and overwrites any `Cache-Control` a
 * controller set on its response object. That clobbers a controller's
 * explicit shared-cacheability declaration (`Cache-Control: public, … /
 * s-maxage`), which headless BFFs (the Complete Console's proxy
 * micro-cache) and CDN edge caches key on to offload WordPress.
 *
 * This class opts a request out of the core injection when the response
 * explicitly declares itself shared-cacheable. Default-deny is preserved:
 * unmarked responses (and `private`-only ones) keep the core no-cache
 * headers unchanged.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST cache-control preservation class.
 *
 * @since 1.2.10
 */
class WP_MCP_AI_REST_Cache_Control {

	/**
	 * Register the rest_post_dispatch hook.
	 *
	 * Any priority works — the core no-cache block runs in
	 * `WP_REST_Server::serve_request()` after all `rest_post_dispatch`
	 * filters have returned, so the opt-out filter is registered in time.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'preserve_explicit_cacheability' ), 15, 3 );
	}

	/**
	 * Opt the current request out of the WP 6.9 authenticated no-cache
	 * injection when the response explicitly declares shared cacheability.
	 *
	 * @param WP_HTTP_Response $result  Response object.
	 * @param WP_REST_Server   $server  Server instance.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @return WP_HTTP_Response
	 */
	public static function preserve_explicit_cacheability( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress filter signature.
		if ( ! $result instanceof WP_HTTP_Response ) {
			return $result;
		}

		$cache_control = self::get_cache_control_header( $result );
		if ( '' === $cache_control ) {
			return $result;
		}

		$lower = strtolower( $cache_control );
		// Only explicit shared-cacheability opts out — `private`/`max-age`
		// alone still gets the core no-cache treatment (that is a browser
		// concern; this gate exists for shared caches only).
		if ( false === strpos( $lower, 'public' ) && false === strpos( $lower, 's-maxage' ) ) {
			return $result;
		}

		// Priority 20: above the core default so the opt-out wins the
		// apply_filters() in serve_request().
		add_filter( 'rest_send_nocache_headers', '__return_false', 20 );

		return $result;
	}

	/**
	 * Read the Cache-Control header the controller declared.
	 *
	 * Most controllers set it on the response object; a few legacy ones
	 * (the status controller) emit it via header() during the callback —
	 * headers_list() covers those.
	 *
	 * @param WP_HTTP_Response $result Response object.
	 * @return string
	 */
	private static function get_cache_control_header( $result ) {
		$headers = $result->get_headers();
		if ( isset( $headers['Cache-Control'] ) ) {
			return implode( ', ', (array) $headers['Cache-Control'] );
		}

		foreach ( headers_list() as $header_line ) {
			if ( 0 === stripos( $header_line, 'Cache-Control:' ) ) {
				return trim( substr( $header_line, strlen( 'Cache-Control:' ) ) );
			}
		}

		return '';
	}
}
