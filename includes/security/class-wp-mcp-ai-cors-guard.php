<?php
/**
 * CORS origin enforcement for the NV oOS REST API.
 *
 * WordPress core's rest_send_cors_headers() (hooked on
 * 'rest_pre_serve_request' at priority 10) reflects ANY Origin header back
 * with 'Access-Control-Allow-Credentials: true' on every REST response.
 * That core behavior silently overrides the plugin's Security → Network
 * "Same Origin" setting, so a hardened site still echoes arbitrary origins.
 *
 * This guard hooks the same filter at a later priority (20) and, when the
 * setting is 'site', replaces the reflected header with the configured
 * policy: exact origins in the allowlist are echoed back, everything else
 * gets the site's own origin with credentials disabled.
 *
 * @package WP_MCP_AI
 * @subpackage Security
 * @since 1.2.2
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enforces the cors_allow_origin setting against WordPress core's
 * unconditional origin reflection.
 *
 * @since 1.2.2
 */
class WP_MCP_AI_CORS_Guard {

	/**
	 * Hook priority. Core's rest_send_cors_headers uses the default 10;
	 * this runs afterwards so its header() call replaces the reflected one.
	 *
	 * @since 1.2.2
	 * @var int
	 */
	const HOOK_PRIORITY = 20;

	/**
	 * Register the enforcement filter.
	 *
	 * @since 1.2.2
	 * @return void
	 */
	public static function init() {
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'enforce_on_pre_serve' ), self::HOOK_PRIORITY, 4 );
	}

	/**
	 * Get the sanitized Origin header value for the current request.
	 *
	 * @since 1.2.2
	 *
	 * @return string Origin value ('' when absent). Browsers send the literal
	 *                string 'null' for sandboxed/file origins — callers must
	 *                treat that as disallowed.
	 */
	public static function get_request_origin() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_text_field below handles slashes and encoding.
		if ( empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			return '';
		}
		return sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized above.
	}

	/**
	 * Compute the allowed-origin list for 'site' mode.
	 *
	 * Always contains the site's own origin. Extras come from the
	 * cors_allowed_origins setting (one origin per line) and the
	 * 'wp_mcp_ai_cors_allowed_origins' filter (array of exact origins).
	 *
	 * @since 1.2.2
	 *
	 * @return string[] Exact origins, no trailing slashes.
	 */
	public static function get_allowed_origins() {
		$allowed = array( untrailingslashit( get_site_url() ) );

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		if ( ! empty( $settings['cors_allowed_origins'] ) && is_string( $settings['cors_allowed_origins'] ) ) {
			foreach ( preg_split( '/[\r\n]+/', $settings['cors_allowed_origins'] ) as $line ) {
				$line = untrailingslashit( trim( $line ) );
				if ( '' !== $line ) {
					$allowed[] = $line;
				}
			}
		}

		/**
		 * Filter additional exact origins allowed to call the plugin REST
		 * API cross-origin when CORS is set to Same Origin.
		 *
		 * @since 1.2.2
		 *
		 * @param string[] $allowed Exact origins (site origin already included).
		 */
		$extras = apply_filters( 'wp_mcp_ai_cors_allowed_origins', array() );
		foreach ( (array) $extras as $extra ) {
			if ( is_string( $extra ) && '' !== trim( $extra ) ) {
				$allowed[] = untrailingslashit( trim( $extra ) );
			}
		}

		return array_values( array_unique( $allowed ) );
	}

	/**
	 * Whether the given origin is in the 'site' mode allowlist.
	 *
	 * @since 1.2.2
	 *
	 * @param string $origin Origin value from get_request_origin().
	 * @return bool True when the origin may access the API cross-origin.
	 */
	public static function is_origin_allowed( $origin ) {
		if ( '' === $origin || 'null' === $origin ) {
			return false;
		}
		return in_array( untrailingslashit( $origin ), self::get_allowed_origins(), true );
	}

	/**
	 * Resolve the Access-Control-Allow-Origin value for the current request.
	 *
	 * Shared by the response-header emitters (MCP trait, OPTIONS handler,
	 * SSE handler/stream) so every surface agrees with the pre-serve
	 * enforcement. The legacy 'wp_mcp_ai_cors_allow_origin' filter remains
	 * the final override for backward compatibility.
	 *
	 * @since 1.2.2
	 *
	 * @return string Origin value to emit.
	 */
	public static function resolve_allow_origin() {
		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$mode     = isset( $settings['cors_allow_origin'] ) ? $settings['cors_allow_origin'] : 'site';

		if ( 'star' === $mode ) {
			return apply_filters( 'wp_mcp_ai_cors_allow_origin', '*' );
		}

		$origin = self::get_request_origin();
		if ( self::is_origin_allowed( $origin ) ) {
			$resolved = $origin;
		} else {
			$resolved = untrailingslashit( get_site_url() );
		}

		return apply_filters( 'wp_mcp_ai_cors_allow_origin', $resolved );
	}

	/**
	 * Replace core's reflected Origin header with the configured policy.
	 *
	 * Runs after core's rest_send_cors_headers (priority 10) so the header()
	 * calls below win. In 'star' mode core's reflection already allows every
	 * origin, so nothing is changed.
	 *
	 * @since 1.2.2
	 *
	 * @param bool             $served  Whether the request was already served.
	 * @param WP_HTTP_Response $result  Result to send to the client.
	 * @param WP_REST_Request  $request Request that generated the response.
	 * @param WP_REST_Server   $server  Server instance. Unused, required by the filter signature.
	 * @return bool Unchanged $served value.
	 */
	public static function enforce_on_pre_serve( $served, $result, $request, $server ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress filter signature.
		if ( $served ) {
			return $served;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		$mode     = isset( $settings['cors_allow_origin'] ) ? $settings['cors_allow_origin'] : 'site';
		if ( 'star' === $mode ) {
			// Allow All: core's reflection already permits any origin.
			return $served;
		}

		$route = $request->get_route();

		/**
		 * Filter the REST route prefixes the guard enforces. Defaults to the
		 * plugin's own namespace; widen to '' to enforce on every REST route.
		 *
		 * @since 1.2.2
		 *
		 * @param string[] $prefixes Route prefixes (e.g. '/mcp-ai/v1').
		 */
		$prefixes = apply_filters( 'wp_mcp_ai_cors_guard_route_prefixes', array( '/mcp-ai/v1' ) );
		$enforce  = false;
		foreach ( (array) $prefixes as $prefix ) {
			if ( is_string( $prefix ) && 0 === strpos( $route, $prefix ) ) {
				$enforce = true;
				break;
			}
		}
		if ( ! $enforce ) {
			return $served;
		}

		$origin = self::get_request_origin();
		if ( '' === $origin ) {
			// No Origin header: not a browser cross-origin request.
			return $served;
		}

		if ( self::is_origin_allowed( $origin ) ) {
			header( 'Access-Control-Allow-Origin: ' . $origin, true );
			header( 'Access-Control-Allow-Credentials: true', true );
		} else {
			header( 'Access-Control-Allow-Origin: ' . untrailingslashit( get_site_url() ), true );
			header( 'Access-Control-Allow-Credentials: false', true );
		}

		return $served;
	}
}
