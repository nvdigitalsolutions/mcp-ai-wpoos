<?php
/**
 * Woo SSO handoff route (Proposal 108 Phase 3, owner toggle Q1).
 *
 * When `funiq_bridge_woo_sso_enabled` is `yes`, the auth surface mints a
 * short-lived, single-use HMAC token on login (`wooLoginToken`) that the
 * storefront exchanges HERE: the route validates it, signs the shopper in
 * with a real Woo auth cookie, and redirects to the hosted checkout — so a
 * shopper who logged in on the storefront arrives at checkout already
 * authenticated, across the registrable-domain boundary.
 *
 * Off by default (404). Tokens are 5-minute TTL, single-use, per-user
 * (one active token per user), constant-time verified, and the redirect
 * target is the same-site-validated checkout URL.
 *
 * @package NvoosAstroBridge\REST
 */

namespace NvoosAstroBridge\REST;

use NvoosAstroBridge\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles GET /nvoos-commerce/v1/woo-login/{token}.
 */
class WooLoginController extends BaseController {

	/**
	 * Token TTL (seconds).
	 */
	private const TOKEN_TTL = 300;

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'funiq/v1';

	/**
	 * REST base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'woo-login/(?P<token>[A-Za-z0-9._-]+)';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'exchange_token' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * GET /woo-login/{token} — validate, sign in, redirect to checkout.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function exchange_token( $request ) {
		if ( 'yes' !== get_option( Schema::OPTION_WOO_SSO_ENABLED, 'no' ) ) {
			return new WP_Error(
				'astro_woo_sso_disabled',
				'The Woo SSO handoff is disabled.',
				array( 'status' => 404 )
			);
		}

		$user_id = $this->verify_token( (string) $request->get_param( 'token' ) );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'astro_woo_sso_invalid_token', 'Invalid handoff token.', array( 'status' => 401 ) );
		}

		// The auth cookie is set on THIS (backend) domain — the hosted
		// checkout that follows reads it natively.
		wp_set_auth_cookie( $user_id, true );

		$stored = get_option( Schema::OPTION_SHOP, Schema::shop_defaults() );
		$stored = is_array( $stored ) ? $stored : Schema::shop_defaults();
		$target = isset( $stored['checkoutUrl'] ) ? esc_url_raw( (string) $stored['checkoutUrl'] ) : '';

		if ( '' === $target ) {
			return new WP_Error( 'astro_woo_sso_no_checkout', 'No checkout URL is configured.', array( 'status' => 500 ) );
		}

		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $target );
		return $response;
	}

	/**
	 * Mint a single-use handoff token for a signed-in shopper.
	 *
	 * Stores the expected HMAC in a per-user transient (one active token per
	 * user — minting revokes the previous one). Returns the URL-ready token.
	 *
	 * @param int $user_id Shopper user id.
	 * @return string
	 */
	public function mint_token( int $user_id ): string {
		$exp  = time() + self::TOKEN_TTL;
		$hmac = $this->hmac_for( $user_id, $exp );

		set_transient( $this->token_transient( $user_id ), $hmac, self::TOKEN_TTL );

		return sprintf( '%d.%d.%s', $user_id, $exp, $hmac );
	}

	/**
	 * Whether the SSO handoff is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return 'yes' === get_option( Schema::OPTION_WOO_SSO_ENABLED, 'no' );
	}

	/**
	 * Verify a token: shape, expiry, constant-time HMAC, single-use.
	 *
	 * @param string $token Raw token.
	 * @return int|WP_Error
	 */
	private function verify_token( string $token ) {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'astro_woo_sso_invalid_token', 'Invalid handoff token.', array( 'status' => 401 ) );
		}

		list( $user_id_raw, $exp_raw, $hmac ) = $parts;
		$user_id = absint( $user_id_raw );
		$exp     = absint( $exp_raw );

		if ( $user_id < 1 || $exp < time() || $exp > time() + self::TOKEN_TTL + 60 ) {
			return new WP_Error( 'astro_woo_sso_expired_token', 'The handoff token has expired.', array( 'status' => 401 ) );
		}

		$expected = get_transient( $this->token_transient( $user_id ) );
		if ( ! is_string( $expected ) || '' === $expected ) {
			return new WP_Error( 'astro_woo_sso_invalid_token', 'Invalid handoff token.', array( 'status' => 401 ) );
		}

		if ( ! hash_equals( $expected, $this->hmac_for( $user_id, $exp ) ) ) {
			return new WP_Error( 'astro_woo_sso_invalid_token', 'Invalid handoff token.', array( 'status' => 401 ) );
		}

		// Single use — the exchange consumes the token.
		delete_transient( $this->token_transient( $user_id ) );

		return $user_id;
	}

	/**
	 * The HMAC for a user/expiry pair (wp_salt-scoped).
	 *
	 * @param int $user_id User id.
	 * @param int $exp     Expiry timestamp.
	 * @return string
	 */
	private function hmac_for( int $user_id, int $exp ): string {
		return hash_hmac( 'sha256', $user_id . '.' . $exp, wp_salt( 'auth' ) . '|nvoos-woo-login' );
	}

	/**
	 * Per-user single-use transient key.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private function token_transient( int $user_id ): string {
		return 'funiq_bridge_woo_sso_' . $user_id;
	}
}
