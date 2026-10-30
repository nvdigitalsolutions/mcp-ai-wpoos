<?php
/**
 * Auth support helpers for the bridge REST surface.
 *
 * The contract's cookie-auth surface has to survive WP's REST auth pipeline
 * on single-site HTTPS installs, where no cookie authenticates /wp-json
 * requests before rest_cookie_check_errors() runs: the secure auth cookie is
 * path-scoped to /wp-admin, and whenever the logged-in cookie DOES validate
 * (via determine_current_user), the auth_cookie_valid action flips
 * $wp_rest_auth_cookie to true — which makes rest_cookie_check_errors() skip
 * its early return and force nonce-less requests back to user 0.
 *
 * The console's proven pattern: re-resolve the session in the permission
 * callback itself, right before the capability check. Nonces minted via
 * /auth/nonce remain valid regardless — wp_create_nonce/wp_verify_nonce
 * derive the session token from the logged-in cookie directly, so a
 * cookie-carrying client passes the nonce gate even when the user is
 * restored here (uid 0 on both sides of the nonce round-trip).
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

/**
 * Cookie-session restore helpers.
 */
final class AuthSupport {

	/**
	 * Restore the current user from the logged-in auth cookie when the REST
	 * pipeline has forced the session to user 0.
	 *
	 * @return int The resolved user ID (0 when no session is present).
	 */
	public static function maybe_restore_user_from_logged_in_cookie(): int {
		$user_id = get_current_user_id();
		if ( $user_id ) {
			return $user_id;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Auth material compared via wp_validate_auth_cookie(), never echoed or stored.
		if ( empty( $_COOKIE[ LOGGED_IN_COOKIE ] ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$cookie_user = wp_validate_auth_cookie( wp_unslash( $_COOKIE[ LOGGED_IN_COOKIE ] ), 'logged_in' );
		if ( $cookie_user ) {
			wp_set_current_user( $cookie_user );
			return (int) $cookie_user;
		}
		return 0;
	}

	/** Private — not instantiable. */
	private function __construct() {}
}
