<?php
/**
 * Auth REST controller — customer accounts for the NV commerce contract.
 *
 * Serves `/funiq/v1/auth/*` and (aliased) `/nvoos-commerce/v1/auth/*`
 * (Proposal 092 — bridge enhancement B4, owner decision Q5: accounts
 * included in v1).
 *
 * Accounts are WordPress users (subscriber role by default; filterable via
 * `funiq_bridge_customer_role`). Cookie auth flows through the storefront's
 * same-origin proxy, so no CORS configuration is needed and authenticated
 * reads work with the standard logged-in cookie.
 *
 * @package FuniqBridge\REST
 */

namespace FuniqBridge\REST;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Customer account surface: nonce, register, login, logout, me.
 */
class AuthController extends BaseController {

	/**
	 * Controller namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'funiq/v1';

	/**
	 * REST base path.
	 *
	 * @var string
	 */
	protected $rest_base = 'auth';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/nonce',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_nonce' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/register',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'register_user' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'email'    => array(
							'required'          => true,
							'type'              => 'string',
							'format'            => 'email',
							'sanitize_callback' => 'sanitize_email',
						),
						'password' => array(
							'required' => true,
							'type'     => 'string',
						),
						'name'     => array(
							'required'          => false,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'nonce'    => array(
							'description'       => 'REST nonce from GET /auth/nonce (CSRF guard).',
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/login',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'login_user' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						'email'    => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'password' => array(
							'required' => true,
							'type'     => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/logout',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'logout_user' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/me',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_me' ),
					'permission_callback' => 'is_user_logged_in',
				),
			)
		);
	}

	/**
	 * GET /auth/nonce — REST nonce for CSRF-protected register (and future
	 * state-changing calls).
	 *
	 * @return WP_REST_Response
	 */
	public function get_nonce() {
		return rest_ensure_response(
			array(
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * POST /auth/register — create a customer account and log it in.
	 *
	 * CSRF-guarded: requires a nonce obtained from GET /auth/nonce.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function register_user( $request ) {
		$nonce = (string) $request->get_param( 'nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'nvoos_invalid_nonce',
				'Invalid nonce. Refresh the page and try again.',
				array( 'status' => 403 )
			);
		}

		$email    = (string) $request->get_param( 'email' );
		$password = (string) $request->get_param( 'password' );
		$name     = (string) $request->get_param( 'name' );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'nvoos_invalid_email', 'A valid email address is required.', array( 'status' => 400 ) );
		}
		if ( strlen( $password ) < 8 ) {
			return new WP_Error( 'nvoos_weak_password', 'Password must be at least 8 characters.', array( 'status' => 400 ) );
		}
		if ( email_exists( $email ) ) {
			return new WP_Error( 'nvoos_email_exists', 'An account with this email already exists.', array( 'status' => 409 ) );
		}

		$login = sanitize_user( current( explode( '@', $email ) ), true );
		if ( username_exists( $login ) ) {
			$login .= wp_rand( 1000, 9999 );
		}

		$role = apply_filters( 'funiq_bridge_customer_role', 'subscriber' );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => '' !== $name ? $name : $login,
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error(
				'nvoos_registration_failed',
				'Account creation failed: ' . $user_id->get_error_message(),
				array( 'status' => 400 )
			);
		}

		wp_set_auth_cookie( $user_id, true );

		return rest_ensure_response( $this->me_payload( $user_id ) );
	}

	/**
	 * POST /auth/login — authenticate with email + password.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function login_user( $request ) {
		$email    = (string) $request->get_param( 'email' );
		$password = (string) $request->get_param( 'password' );

		$user = wp_signon(
			array(
				'user_login'    => $email,
				'user_password' => $password,
				'remember'      => true,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) ) {
			// Generic message — never reveal which field failed.
			return new WP_Error( 'nvoos_invalid_credentials', 'Invalid email or password.', array( 'status' => 401 ) );
		}

		wp_set_current_user( $user->ID );

		return rest_ensure_response( $this->me_payload( $user->ID ) );
	}

	/**
	 * POST /auth/logout — clear the session cookie.
	 *
	 * @return WP_REST_Response
	 */
	public function logout_user() {
		wp_logout();

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * GET /auth/me — current customer (cookie-authenticated).
	 *
	 * @return WP_REST_Response
	 */
	public function get_me() {
		return rest_ensure_response( $this->me_payload( get_current_user_id() ) );
	}

	/**
	 * Build the customer payload for a user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<string, mixed>
	 */
	private function me_payload( int $user_id ): array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array(
				'id'    => 0,
				'email' => '',
				'name'  => '',
			);
		}

		return array(
			'id'    => $user->ID,
			'email' => $user->user_email,
			'name'  => ( '' !== $user->display_name ) ? $user->display_name : $user->user_login,
		);
	}
}
