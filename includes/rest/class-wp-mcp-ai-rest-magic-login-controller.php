<?php
/**
 * Magic-link (passwordless) sign-in REST controller.
 *
 * Two public routes under /wp-json/mcp-ai/v1/magic-login/*:
 *   POST /request — accepts a username/email, emails a one-shot sign-in link.
 *   POST /confirm — consumes the link token and starts a WordPress session.
 *
 * Deliberately unauthenticated by design (the visitor has no session yet —
 * same documented exception as the /session/nonce route in the chat
 * controller). Both routes run a named permission callback that still
 * enforces the feature flag and the security manager's IP/HTTPS posture
 * checks, plus transient-based rate limiting.
 *
 * Industry-standard magic-link hygiene (OWASP Authentication & Forgot
 * Password cheat sheets, and the 2025-era magic-link guidance they inform):
 *   - Token: wp_generate_password(43, false, false) — 43 alphanumeric chars,
 *     ~256 bits of entropy from WordPress' CSPRNG.
 *   - Stored hashed (SHA-256) in a transient — the raw token never touches
 *     the database.
 *   - Expires in 15 minutes, single-use, and at most one outstanding link
 *     per user (requesting a new one revokes the previous).
 *   - Uniform responses on /request whether or not the account exists
 *     (no account enumeration — OWASP A07:2025), and on /confirm whether
 *     the token is unknown, expired, or already used.
 *   - Rate limits: 3 requests per identifier per 15 min and 10 per IP per
 *     hour; 10 confirm attempts per IP per 15 min.
 *   - The emailed link always points at the configured app origin
 *     (wp_mcp_ai_magic_login_app_origin, default home_url) — the client
 *     payload can never retarget the link.
 *   - Confirm fires do_action( 'wp_login', … ) so security plugins that hook
 *     login events still see the sign-in.
 *
 * Opt-in: enabled via the wp_mcp_ai_magic_login_enabled option (or the
 * wp_mcp_ai_magic_login_enabled filter). Disabled by default — while
 * disabled both routes 404 (rest_no_route) so the surface is invisible.
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
 * REST API controller for passwordless magic-link sign-in.
 */
class WP_MCP_AI_REST_Magic_Login_Controller extends WP_MCP_AI_REST_Controller_Base {

	/**
	 * Token lifetime in seconds (15 minutes — industry standard window).
	 */
	const TOKEN_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Transient key prefixes. Hashes keep raw tokens out of the key space.
	 */
	const TRANSIENT_TOKEN = 'wp_mcp_ai_magic_login_token_';
	const TRANSIENT_USER  = 'wp_mcp_ai_magic_login_user_';
	const RATE_KEY_IP     = 'wp_mcp_ai_magic_login_rl_ip_';
	const RATE_KEY_ID     = 'wp_mcp_ai_magic_login_rl_id_';
	const RATE_KEY_CONFIRM = 'wp_mcp_ai_magic_login_rl_confirm_';

	/**
	 * Constructor — hook route registration.
	 */
	public function __construct() {
		parent::__construct();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Whether magic-link sign-in is enabled on this site.
	 *
	 * Defaults to off. The option is the operator-facing switch; the filter
	 * lets deployments (e.g. the NV oOS Complete Console) force it.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters(
			'wp_mcp_ai_magic_login_enabled',
			(bool) get_option( 'wp_mcp_ai_magic_login_enabled', false )
		);
	}

	/**
	 * Origin the emailed link points at.
	 *
	 * Never taken from the request payload: a link to an attacker-chosen
	 * origin is a phishing vector. Defaults to home_url('/').
	 *
	 * @return string Origin URL with trailing slash.
	 */
	public static function app_origin() {
		$origin = (string) get_option( 'wp_mcp_ai_magic_login_app_origin', '' );
		$origin = (string) apply_filters( 'wp_mcp_ai_magic_login_app_origin', $origin );
		if ( '' === $origin ) {
			$origin = home_url( '/' );
		}
		return trailingslashit( $origin );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/magic-login/request',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'permission_magic_login_public' ),
				'callback'            => array( $this, 'handle_magic_login_request' ),
				'args'                => array(
					'identifier' => array(
						'description'       => __( 'Username or email address of the account to sign in to.', 'mcp-ai-wpoos' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_identifier' ),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/magic-login/confirm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( $this, 'permission_magic_login_public' ),
				'callback'            => array( $this, 'handle_magic_login_confirm' ),
				'args'                => array(
					'token' => array(
						'description'       => __( 'One-shot token from the emailed sign-in link.', 'mcp-ai-wpoos' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => array( $this, 'validate_token' ),
					),
				),
			)
		);
	}

	/**
	 * Shared permission callback for the two public magic-login routes.
	 *
	 * Reuses the security manager's non-user checks (IP allow/deny, HTTPS
	 * requirement) — the same posture every authenticated route enforces —
	 * and gates the surface behind the feature flag. Intentionally skips the
	 * authenticator: these routes exist for visitors who have no session.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return bool|WP_Error True when the route may run, WP_Error otherwise.
	 */
	public function permission_magic_login_public( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by the WordPress REST permission_callback signature.
		if ( ! self::is_enabled() ) {
			return $this->error(
				'rest_no_route',
				__( 'No route was found matching the URL and request method.', 'mcp-ai-wpoos' ),
				404
			);
		}

		$security_check = $this->security_manager->check_ip_access( $this->get_client_ip() );
		if ( is_wp_error( $security_check ) ) {
			return $security_check;
		}

		$https_check = $this->security_manager->check_https_requirement();
		if ( is_wp_error( $https_check ) ) {
			return $https_check;
		}

		return true;
	}

	/**
	 * Validate the request identifier (length bound before lookup).
	 *
	 * @param mixed $value Raw identifier value.
	 * @return bool Whether the identifier is a sane, bounded string.
	 */
	public function validate_identifier( $value ) {
		return is_string( $value ) && '' !== trim( $value ) && strlen( $value ) <= 254;
	}

	/**
	 * Validate the confirm token shape before hashing (expected: 43 alnum).
	 *
	 * @param mixed $value Raw token value.
	 * @return bool Whether the token looks like one we mint.
	 */
	public function validate_token( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9]{20,100}$/', $value );
	}

	/**
	 * Handle POST /magic-login/request.
	 *
	 * Anti-enumeration: the response is identical whether the account exists,
	 * whether mail delivery failed, or whether the identifier is unknown.
	 * Only a rate-limit hit returns a different (429) answer.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_magic_login_request( WP_REST_Request $request ) {
		$identifier = $request->get_param( 'identifier' );
		$identifier = is_string( $identifier ) ? strtolower( trim( $identifier ) ) : '';

		$rate_limit = $this->check_request_rate_limits( $identifier );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$user = $this->find_user_by_identifier( $identifier );

		// Unknown account: same response as success — nothing to send, and
		// no hint that the identifier was rejected.
		if ( ! $user ) {
			return $this->request_response();
		}

		// One outstanding link per user: revoke any previous token first.
		$previous_hash = get_transient( self::TRANSIENT_USER . $user->ID );
		if ( is_string( $previous_hash ) && '' !== $previous_hash ) {
			delete_transient( self::TRANSIENT_TOKEN . $previous_hash );
		}

		$token    = wp_generate_password( 43, false, false );
		$hash     = hash( 'sha256', $token );

		set_transient( self::TRANSIENT_USER . $user->ID, $hash, self::TOKEN_TTL );
		set_transient(
			self::TRANSIENT_TOKEN . $hash,
			array(
				'user_id' => $user->ID,
			),
			self::TOKEN_TTL
		);

		$sent = $this->send_magic_login_email( $user, $token );

		if ( ! $sent ) {
			// A dead mail transport must not leave a live token behind, and
			// the caller still gets the uniform response.
			delete_transient( self::TRANSIENT_TOKEN . $hash );
			delete_transient( self::TRANSIENT_USER . $user->ID );
			$this->log( 'magic-login email failed for user ' . $user->ID, 'magic-login' );
		}

		return $this->request_response();
	}

	/**
	 * Handle POST /magic-login/confirm.
	 *
	 * Consumes the token (single-use), starts a fresh WordPress session, and
	 * fires wp_login so login-auditing plugins observe the sign-in.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_magic_login_confirm( WP_REST_Request $request ) {
		$rate_limit = $this->check_confirm_rate_limits();
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$token = $request->get_param( 'token' );
		$token = is_string( $token ) ? trim( $token ) : '';

		$data = get_transient( self::TRANSIENT_TOKEN . hash( 'sha256', $token ) );

		if ( ! is_array( $data ) || empty( $data['user_id'] ) ) {
			return $this->confirm_error();
		}

		$user = get_user_by( 'id', absint( $data['user_id'] ) );
		if ( ! $user instanceof WP_User ) {
			delete_transient( self::TRANSIENT_TOKEN . hash( 'sha256', $token ) );
			return $this->confirm_error();
		}

		// Consume before acting — a link may only ever complete once.
		delete_transient( self::TRANSIENT_TOKEN . hash( 'sha256', $token ) );
		delete_transient( self::TRANSIENT_USER . $user->ID );

		wp_clear_auth_cookie();
		wp_set_auth_cookie( $user->ID, true, is_ssl() );

		/**
		 * Magic-link sign-in completed.
		 *
		 * Mirrors the hook WordPress itself fires on the native login form so
		 * security plugins (login notifications, 2FA audits) see this event.
		 *
		 * @param string  $user_login User login name.
		 * @param WP_User $user       User object.
		 */
		do_action( 'wp_login', $user->user_login, $user );

		return $this->success(
			array(
				'ok'   => true,
				'user' => array(
					'id'           => $user->ID,
					'login'        => $user->user_login,
					'display_name' => $user->display_name,
				),
			)
		);
	}

	/**
	 * The uniform /request success body.
	 *
	 * Worded so it is truthful for both real and non-existent accounts.
	 *
	 * @return WP_REST_Response
	 */
	private function request_response() {
		return $this->success(
			array(
				'ok'      => true,
				'message' => __( "If an account matches, you'll receive an email with a sign-in link. It expires in 15 minutes and can be used once.", 'mcp-ai-wpoos' ),
			)
		);
	}

	/**
	 * The uniform /confirm failure body (unknown, expired, or used token).
	 *
	 * @return WP_Error
	 */
	private function confirm_error() {
		return $this->error(
			'invalid_token',
			__( 'This sign-in link is invalid or has expired. Request a new one.', 'mcp-ai-wpoos' ),
			400
		);
	}

	/**
	 * Resolve a username-or-email identifier to a user.
	 *
	 * @param string $identifier Normalized (lowercased, trimmed) identifier.
	 * @return WP_User|null
	 */
	private function find_user_by_identifier( $identifier ) {
		$user = get_user_by( 'login', $identifier );
		if ( ! $user && is_email( $identifier ) ) {
			$user = get_user_by( 'email', $identifier );
		}
		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Build the sign-in link for the email.
	 *
	 * @param string $token Raw one-shot token.
	 * @return string
	 */
	private function build_magic_link( $token ) {
		return untrailingslashit( self::app_origin() ) . '/#/magic-login?token=' . rawurlencode( $token );
	}

	/**
	 * Email the sign-in link.
	 *
	 * @param WP_User $user  Account the link signs in to.
	 * @param string  $token Raw one-shot token.
	 * @return bool Whether wp_mail accepted the send.
	 */
	private function send_magic_login_email( WP_User $user, $token ) {
		$link = $this->build_magic_link( $token );

		/* translators: %s: site name. */
		$subject = sprintf( __( 'Your sign-in link for %s', 'mcp-ai-wpoos' ), wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) );

		$message  = sprintf(
			/* translators: 1: username, 2: site name. */
			__( 'Hi %1$s, someone requested a sign-in link for your account on %2$s.', 'mcp-ai-wpoos' ),
			$user->user_login,
			wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES )
		) . "\n\n";
		$message .= __( 'Click the link below to sign in. It expires in 15 minutes and can only be used once:', 'mcp-ai-wpoos' ) . "\n\n";
		$message .= $link . "\n\n";
		$message .= __( "If you didn't request this, you can safely ignore this email — your password has not been changed.", 'mcp-ai-wpoos' ) . "\n";

		/**
		 * Filter the magic-login email body.
		 *
		 * @param string  $message Plain-text message.
		 * @param WP_User $user    Account the link signs in to.
		 * @param string  $link    Sign-in link.
		 */
		$message = apply_filters( 'wp_mcp_ai_magic_login_email_message', $message, $user, $link );

		return (bool) wp_mail( $user->user_email, $subject, $message );
	}

	/**
	 * Request-endpoint rate limits: 3 per identifier per 15 min, 10 per IP
	 * per hour.
	 *
	 * @param string $identifier Normalized identifier.
	 * @return true|WP_Error True when allowed, 429 WP_Error otherwise.
	 */
	private function check_request_rate_limits( $identifier ) {
		$id_key = self::RATE_KEY_ID . md5( $identifier );
		$ip_key = self::RATE_KEY_IP . md5( $this->get_client_ip() );

		if ( $this->increment_counter( $id_key, self::TOKEN_TTL ) > 3 ) {
			return $this->rate_limit_error();
		}
		if ( $this->increment_counter( $ip_key, HOUR_IN_SECONDS ) > 10 ) {
			return $this->rate_limit_error();
		}
		return true;
	}

	/**
	 * Confirm-endpoint rate limit: 10 attempts per IP per 15 min. (Tokens are
	 * ~256-bit, so this only dampens abusive traffic, never user retries.)
	 *
	 * @return true|WP_Error True when allowed, 429 WP_Error otherwise.
	 */
	private function check_confirm_rate_limits() {
		$ip_key = self::RATE_KEY_CONFIRM . md5( $this->get_client_ip() );
		if ( $this->increment_counter( $ip_key, self::TOKEN_TTL ) > 10 ) {
			return $this->rate_limit_error();
		}
		return true;
	}

	/**
	 * Increment a transient-backed counter, preserving its original window.
	 *
	 * @param string $key Transient key.
	 * @param int    $ttl Initial window in seconds.
	 * @return int New counter value.
	 */
	private function increment_counter( $key, $ttl ) {
		$count = get_transient( $key );
		$count = is_numeric( $count ) ? (int) $count + 1 : 1;

		$remaining = get_option( '_transient_timeout_' . $key );
		$remaining = is_numeric( $remaining ) ? max( 1, (int) $remaining - time() ) : $ttl;

		set_transient( $key, $count, $remaining );
		return $count;
	}

	/**
	 * Uniform 429 for rate-limited callers.
	 *
	 * @return WP_Error
	 */
	private function rate_limit_error() {
		return $this->error(
			'too_many_requests',
			__( 'Too many sign-in link requests. Please wait a few minutes and try again.', 'mcp-ai-wpoos' ),
			429
		);
	}

	/**
	 * Resolve the client IP (mirrors the controller-base logic, which is
	 * private there).
	 *
	 * @return string IP address, '0.0.0.0' when unresolvable.
	 */
	private function get_client_ip() {
		$ip_keys = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',  // Proxy/load balancer.
			'HTTP_X_REAL_IP',        // Nginx proxy.
			'REMOTE_ADDR',           // Direct connection.
		);

		foreach ( $ip_keys as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				if ( false !== strpos( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}
}

new WP_MCP_AI_REST_Magic_Login_Controller();
