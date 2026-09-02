<?php
/**
 * Guest token flow for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's guest-access implementation
 * (`WP_MCP_AI_Shortcode::generate_guest_token()` /
 * `validate_guest_token()` + the REST authenticator's header extraction)
 * (behaviour-preserving; base copies retained permanently — ecosystem
 * port plan D-NOBASE). Token format, transient key prefix, TTL caps,
 * assistant scoping, origin binding, and filter names keep their base
 * names and semantics so a token issued by either install mode validates
 * identically.
 *
 * Decoupling (documented, additive):
 * - The TTL setting seam reads the base `WP_MCP_AI_Settings_Registry`
 *   (`guest_token_lifetime`) in monolith installs and the CG settings
 *   store standalone.
 * - REST permission callbacks in `ChatCompatController` +
 *   `ChatController` consult this class (additive — logged-in users are
 *   unaffected; guests gain access only with a valid, assistant-scoped
 *   token).
 *
 * @package NvoosContentGraphAi\Chat
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Chat;

use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues and validates guest access tokens for public chat surfaces.
 *
 * @since 1.1.0
 */
class GuestToken {

	/**
	 * Default lifetime for guest access tokens (in seconds).
	 * Used when no admin setting is configured.
	 */
	const GUEST_TOKEN_TTL = DAY_IN_SECONDS;

	/**
	 * Maximum allowed lifetime for guest tokens (7 days).
	 * Provides a hard cap regardless of configuration.
	 */
	const GUEST_TOKEN_MAX_TTL = 604800;

	/**
	 * Minimum allowed lifetime for guest tokens (60 seconds).
	 */
	const GUEST_TOKEN_MIN_TTL = 60;

	/**
	 * Prefix used for guest access transients.
	 */
	const GUEST_TOKEN_TRANSIENT_PREFIX = 'wp_mcp_ai_guest_access_';

	/**
	 * Extract a guest token from request headers or parameters.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return string Guest token or empty string.
	 */
	public static function extract_guest_token( \WP_REST_Request $request ): string {
		$token = $request->get_header( 'X-WP-MCP-AI-Guest' );

		if ( ! $token ) {
			$token = $request->get_param( 'guest_token' );
		}

		if ( is_string( $token ) ) {
			return trim( $token );
		}

		return '';
	}

	/**
	 * Generate a guest access token for the given assistant.
	 *
	 * Optionally binds the token to the page origin so a token leaked from
	 * one origin cannot be replayed from another. Pass `$origin` to
	 * override the default (the host portion of `home_url()`), or filter
	 * `wp_mcp_ai_guest_token_issuance_origin` to provide a different
	 * binding host. An empty string disables the binding for this token.
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $origin       Optional. Origin host to bind the token to.
	 * @return string Guest access token or empty string on failure.
	 */
	public static function generate_guest_token( $assistant_id, $origin = null ) {
		$assistant_id = absint( $assistant_id );

		if ( ! $assistant_id ) {
			return '';
		}

		$token = wp_generate_password( 32, false, false );

		if ( ! $token ) {
			return '';
		}

		$ttl = self::get_guest_token_ttl();

		if ( null === $origin ) {
			$origin = self::default_guest_token_origin();
		}

		/**
		 * Filter the origin a guest token is bound to at issuance.
		 *
		 * Return an empty string to disable origin binding for this token.
		 *
		 * @param string $origin       Bound origin host (lower-case, no scheme).
		 * @param int    $assistant_id Assistant post ID.
		 */
		$origin = apply_filters( 'wp_mcp_ai_guest_token_issuance_origin', $origin, $assistant_id );

		$record = array(
			'assistant_id' => $assistant_id,
			'created'      => time(),
			'origin'       => is_string( $origin ) ? strtolower( trim( $origin ) ) : '',
		);

		$saved = set_transient( self::build_guest_token_key( $token ), $record, $ttl );

		if ( ! $saved ) {
			return '';
		}

		return $token;
	}

	/**
	 * Validate a guest token and ensure it is scoped to the requested assistant.
	 *
	 * Enforces both the transient-based sliding TTL and an absolute maximum
	 * lifetime from the token's creation timestamp. When a REST request is
	 * provided and the token record is origin-bound, the request's Origin
	 * header (or Referer host) must match the bound origin or be present in
	 * the `wp_mcp_ai_guest_token_allowed_origins` allowlist.
	 *
	 * @param string               $token        Guest access token supplied by the client.
	 * @param int                  $assistant_id Assistant post ID provided in the request.
	 * @param WP_REST_Request|null $request      Optional. REST request for origin checks.
	 * @return int|false Assistant ID associated with the token when valid, false otherwise.
	 */
	public static function validate_guest_token( $token, $assistant_id = 0, $request = null ) {
		$token = is_string( $token ) ? trim( $token ) : '';

		if ( '' === $token ) {
			return false;
		}

		$data = get_transient( self::build_guest_token_key( $token ) );

		if ( empty( $data ) || ! is_array( $data ) ) {
			return false;
		}

		// Enforce absolute maximum lifetime.
		$created = isset( $data['created'] ) ? absint( $data['created'] ) : 0;
		if ( $created > 0 && ( time() - $created ) > self::GUEST_TOKEN_MAX_TTL ) {
			delete_transient( self::build_guest_token_key( $token ) );
			return false;
		}

		$stored_assistant = isset( $data['assistant_id'] ) ? absint( $data['assistant_id'] ) : 0;

		if ( $assistant_id && $stored_assistant && $assistant_id !== $stored_assistant ) {
			return false;
		}

		// Origin binding. Skip when the record has no stored origin (legacy
		// tokens) or when the caller did not pass a request — internal call
		// paths such as CLI / cron must remain unaffected.
		$bound_origin = isset( $data['origin'] ) ? (string) $data['origin'] : '';
		if ( '' !== $bound_origin && $request instanceof \WP_REST_Request ) {
			$request_origin = self::extract_request_origin_host( $request );

			/**
			 * Filter the list of origin hosts allowed to use a guest token.
			 *
			 * The bound origin is always included; this filter lets
			 * integrators add additional origins.
			 *
			 * @param string[]        $allowed_origins  Lower-case host names.
			 * @param int             $stored_assistant Assistant post ID the token is scoped to.
			 * @param WP_REST_Request $request          Incoming REST request.
			 */
			$allowed_origins = apply_filters(
				'wp_mcp_ai_guest_token_allowed_origins',
				array( $bound_origin ),
				$stored_assistant,
				$request
			);

			if ( ! is_array( $allowed_origins ) ) {
				$allowed_origins = array( $bound_origin );
			}
			$allowed_origins = array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'trim', $allowed_origins ) ) ) ) );

			if ( '' === $request_origin || ! in_array( $request_origin, $allowed_origins, true ) ) {
				return false;
			}
		}

		// Refresh the sliding TTL for active sessions.
		set_transient( self::build_guest_token_key( $token ), $data, self::get_guest_token_ttl() );

		return $stored_assistant;
	}

	/**
	 * Convenience wrapper: extract + validate a guest token from a request.
	 *
	 * Returns the scoped assistant ID on success, false otherwise.
	 *
	 * @param WP_REST_Request $request      REST request.
	 * @param int             $assistant_id Assistant ID to scope against (0 = any).
	 * @return int|false
	 */
	public static function validate_request_guest_access( \WP_REST_Request $request, $assistant_id = 0 ) {
		$token = self::extract_guest_token( $request );

		if ( '' === $token ) {
			return false;
		}

		return self::validate_guest_token( $token, absint( $assistant_id ), $request );
	}

	/**
	 * Default origin host to bind a freshly issued guest token to.
	 *
	 * @return string Host or empty string.
	 */
	protected static function default_guest_token_origin() {
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $home_host ) ? strtolower( $home_host ) : '';
	}

	/**
	 * Derive the calling origin host from a REST request.
	 *
	 * Prefers the `Origin` header; falls back to the host portion of
	 * `Referer` when `Origin` is absent.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return string Lower-case host or empty string when neither header is present.
	 */
	protected static function extract_request_origin_host( \WP_REST_Request $request ) {
		$origin = $request->get_header( 'origin' );
		if ( is_string( $origin ) && '' !== $origin && 'null' !== strtolower( $origin ) ) {
			$host = wp_parse_url( $origin, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				return strtolower( $host );
			}
		}

		$referer = $request->get_header( 'referer' );
		if ( is_string( $referer ) && '' !== $referer ) {
			$host = wp_parse_url( $referer, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				return strtolower( $host );
			}
		}

		return '';
	}

	/**
	 * Build the transient key used to persist guest access tokens.
	 *
	 * @param string $token Guest access token.
	 * @return string
	 */
	protected static function build_guest_token_key( $token ) {
		return self::GUEST_TOKEN_TRANSIENT_PREFIX . md5( $token );
	}

	/**
	 * Get the configured guest token TTL from settings.
	 *
	 * Falls back to the default constant when the setting is not configured
	 * and enforces the minimum/maximum lifetime caps (byte-identical to the
	 * base).
	 *
	 * @return int TTL in seconds.
	 */
	protected static function get_guest_token_ttl() {
		$ttl = self::GUEST_TOKEN_TTL;

		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
			// Monolith: the base settings registry owns the setting.
			$configured = \WP_MCP_AI_Settings_Registry::get_setting( 'guest_token_lifetime', self::GUEST_TOKEN_TTL );
			$ttl        = absint( $configured );
		} else {
			// Standalone: CG settings store (nvoos_content_graph_settings).
			$ttl = absint( CoreBridge::instance()->settings->get( 'guest_token_lifetime', self::GUEST_TOKEN_TTL ) );
		}

		// Enforce minimum and maximum caps.
		$ttl = max( self::GUEST_TOKEN_MIN_TTL, min( $ttl, self::GUEST_TOKEN_MAX_TTL ) );

		return $ttl;
	}
}
