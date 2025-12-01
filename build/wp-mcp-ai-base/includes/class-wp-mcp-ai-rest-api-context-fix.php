<?php
/**
 * REST API Context Parameter Fix
 *
 * Ensures WordPress REST API context parameter (e.g., ?context=edit) is properly
 * processed and not stripped by caching layers or security plugins.
 *
 * This addresses issues where:
 * - Block editor breaks due to missing context=edit parameter
 * - WAF/Cloudflare strips query strings from /wp-json/ requests
 * - Caching layers cache responses that should vary by context parameter
 * - Server configurations don't preserve query parameters
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API Context Parameter Fix class
 *
 * Handles proper cache headers and query string preservation for WordPress REST API.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_REST_API_Context_Fix {

	/**
	 * Initialize the fix
	 *
	 * @return void
	 */
	public static function init() {
		// Add cache-control headers to all REST API responses.
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_no_cache_headers' ), 10, 3 );

		// Ensure query strings are preserved for REST API requests.
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'ensure_query_string_preservation' ), 5, 4 );

		// Add diagnostic information when REST API errors occur.
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'add_diagnostic_info_on_error' ), 999, 3 );

		// Add Vary header to ensure caches differentiate by context parameter.
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_vary_header' ), 5, 3 );
	}

	/**
	 * Add no-cache headers to REST API responses
	 *
	 * This prevents caching layers (Cloudflare, Nginx, Apache, etc.) from caching
	 * REST API responses that should vary based on query parameters like ?context=edit.
	 *
	 * @param WP_HTTP_Response $result  Result to send to the client.
	 * @param WP_REST_Server   $server  Server instance.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @return WP_HTTP_Response
	 */
	public static function add_no_cache_headers( $result, $server, $request ) {
		if ( ! $result instanceof WP_HTTP_Response ) {
			return $result;
		}

		// Only apply to WordPress core REST API endpoints, not our custom namespace.
		$route = $request->get_route();
		if ( empty( $route ) ) {
			return $result;
		}

		// Skip our own endpoints - they have their own cache control.
		if ( 0 === strpos( $route, '/mcp-ai/' ) ) {
			return $result;
		}

		// Check if context parameter was explicitly provided by the client.
		// We check both query params and body params, but NOT route defaults.
		// This ensures we only apply no-cache headers when the client actually requested
		// a specific context, not when the endpoint just has a default context value.
		$query_params = $request->get_query_params();
		$body_params  = $request->get_body_params();
		$has_explicit_context = isset( $query_params['context'] ) || isset( $body_params['context'] );

		// For requests with explicit context parameter or edit-related endpoints, ensure no caching.
		if ( $has_explicit_context || self::is_edit_endpoint( $route ) ) {
			// Set aggressive no-cache headers to prevent any caching.
			$result->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$result->header( 'Pragma', 'no-cache' );
			$result->header( 'Expires', '0' );

			// Remove any existing cache headers that might allow caching.
			$headers = $result->get_headers();
			unset( $headers['ETag'] );
			unset( $headers['Last-Modified'] );
			$result->set_headers( $headers );
		}

		return $result;
	}

	/**
	 * Add Vary header to ensure caches differentiate by context parameter
	 *
	 * This tells caching layers to store separate cache entries for different
	 * values of the context parameter.
	 *
	 * @param WP_HTTP_Response $result  Result to send to the client.
	 * @param WP_REST_Server   $server  Server instance.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @return WP_HTTP_Response
	 */
	public static function add_vary_header( $result, $server, $request ) {
		if ( ! $result instanceof WP_HTTP_Response ) {
			return $result;
		}

		$route = $request->get_route();
		if ( empty( $route ) ) {
			return $result;
		}

		// Skip our own endpoints.
		if ( 0 === strpos( $route, '/mcp-ai/' ) ) {
			return $result;
		}

		// Add Vary header for context parameter.
		$existing_vary = $result->get_headers()['Vary'] ?? '';
		$vary_values   = array_filter( array_map( 'trim', explode( ',', $existing_vary ) ) );

		// Add context to Vary header if not already present.
		if ( ! in_array( 'context', $vary_values, true ) ) {
			$vary_values[] = 'context';
		}

		$result->header( 'Vary', implode( ', ', $vary_values ) );

		return $result;
	}

	/**
	 * Ensure query string preservation for REST API requests
	 *
	 * This filter runs early to detect and warn about missing query parameters.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_HTTP_Response $result  Result to send to the client.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool
	 */
	public static function ensure_query_string_preservation( $served, $result, $request, $server ) {
		// This is primarily a diagnostic hook - we can't actually restore stripped parameters,
		// but we can log when they appear to be missing.

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return $served;
		}

		// Check if we're in a REST request.
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return $served;
		}

		// Get the raw request URI.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( empty( $request_uri ) ) {
			return $served;
		}

		// Check if URI contains query string but request object doesn't have those params.
		if ( strpos( $request_uri, '?' ) !== false ) {
			$parsed = wp_parse_url( $request_uri );
			if ( ! empty( $parsed['query'] ) ) {
				parse_str( $parsed['query'], $uri_params );

				// Check for context parameter in URI but not in request query params.
				// We check query params specifically because we're detecting if query strings are stripped.
				$query_params = $request->get_query_params();
				if ( isset( $uri_params['context'] ) && ! isset( $query_params['context'] ) ) {
					// Query string is being stripped - log for debugging.
					if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
						WP_MCP_AI_Logger::log_event(
							'warning',
							'REST API context parameter stripped from request',
							array(
								'request_uri'     => $request_uri,
								'expected_params' => $uri_params,
								'actual_params'   => $request->get_query_params(),
								'route'           => $request->get_route(),
							)
						);
					}
				}
			}
		}

		return $served;
	}

	/**
	 * Add diagnostic information to REST API errors
	 *
	 * When REST API requests fail, add information about whether query parameters
	 * might have been stripped.
	 *
	 * @param WP_HTTP_Response $response Result from endpoint callback.
	 * @param array            $handler  Route handler array.
	 * @param WP_REST_Request  $request  Request used to generate the response.
	 * @return WP_HTTP_Response
	 */
	public static function add_diagnostic_info_on_error( $response, $handler, $request ) {
		// Only add diagnostics for errors.
		if ( ! is_wp_error( $response ) ) {
			return $response;
		}

		// Only in debug mode.
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return $response;
		}

		$error_code = $response->get_error_code();

		// Check for common errors that might be related to missing context parameter.
		$context_related_errors = array(
			'rest_forbidden',
			'rest_forbidden_context',
			'rest_invalid_param',
		);

		if ( ! in_array( $error_code, $context_related_errors, true ) ) {
			return $response;
		}

		// Check if context parameter might have been stripped.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( empty( $request_uri ) ) {
			return $response;
		}

		// Check if context parameter in URI might have been stripped from query params.
		// We check query params specifically to detect query string stripping.
		$query_params = $request->get_query_params();
		if ( strpos( $request_uri, '?context=' ) !== false && ! isset( $query_params['context'] ) ) {
			// Add diagnostic information to the error.
			$error_data = $response->get_error_data();
			if ( ! is_array( $error_data ) ) {
				$error_data = array();
			}

			$error_data['diagnostic'] = array(
				'issue'         => 'Query parameter appears to have been stripped',
				'parameter'     => 'context',
				'request_uri'   => $request_uri,
				'likely_cause'  => 'Caching layer, WAF, or server configuration is stripping query strings',
				'documentation' => 'See deployment-troubleshooting.md for server configuration instructions',
			);

			$response->add_data( $error_data );
		}

		return $response;
	}

	/**
	 * Check if a route is an edit-related endpoint
	 *
	 * These endpoints should never be cached as they contain edit-specific data.
	 *
	 * @param string $route REST API route.
	 * @return bool
	 */
	private static function is_edit_endpoint( $route ) {
		// Block editor endpoints.
		$edit_patterns = array(
			'/wp/v2/types',
			'/wp/v2/statuses',
			'/wp/v2/taxonomies',
			'/wp/v2/posts',
			'/wp/v2/pages',
			'/wp/v2/media',
			'/wp/v2/blocks',
			'/wp/v2/templates',
			'/wp/v2/template-parts',
			'/wp/v2/navigation',
			'/wp/v2/block-patterns',
			'/wp/v2/block-directory',
		);

		foreach ( $edit_patterns as $pattern ) {
			if ( 0 === strpos( $route, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get diagnostic information about REST API configuration
	 *
	 * This can be called from admin screens or CLI to diagnose issues.
	 *
	 * @return array Diagnostic information.
	 */
	public static function get_diagnostics() {
		$diagnostics = array(
			'rest_url_rewrite_enabled' => true,
			'query_string_preserved'   => true,
			'cache_headers_applied'    => true,
			'server_software'          => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown',
			'recommendations'          => array(),
		);

		// Check if pretty permalinks are enabled.
		$permalink_structure = get_option( 'permalink_structure' );
		if ( empty( $permalink_structure ) ) {
			$diagnostics['rest_url_rewrite_enabled'] = false;
			$diagnostics['recommendations'][]        = 'Enable pretty permalinks for better REST API compatibility';
		}

		// Check for common caching plugins.
		$caching_plugins = array(
			'w3-total-cache/w3-total-cache.php'   => 'W3 Total Cache',
			'wp-super-cache/wp-cache.php'         => 'WP Super Cache',
			'wp-rocket/wp-rocket.php'             => 'WP Rocket',
			'litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache',
			'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache',
		);

		// Ensure is_plugin_active() is available.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_caching_plugins = array();
		foreach ( $caching_plugins as $plugin_file => $plugin_name ) {
			if ( is_plugin_active( $plugin_file ) ) {
				$active_caching_plugins[] = $plugin_name;
			}
		}

		if ( ! empty( $active_caching_plugins ) ) {
			$diagnostics['caching_plugins']   = $active_caching_plugins;
			$diagnostics['recommendations'][] = sprintf(
				'Exclude /wp-json/* from caching in: %s',
				implode( ', ', $active_caching_plugins )
			);
		}

		// Check server software.
		$server_software = $diagnostics['server_software'];
		if ( stripos( $server_software, 'nginx' ) !== false ) {
			$diagnostics['recommendations'][] = 'For Nginx, ensure location ~* /wp-json/ has no-cache headers';
		} elseif ( stripos( $server_software, 'apache' ) !== false ) {
			$diagnostics['recommendations'][] = 'For Apache, ensure .htaccess preserves query strings for /wp-json/';
		}

		return $diagnostics;
	}
}
