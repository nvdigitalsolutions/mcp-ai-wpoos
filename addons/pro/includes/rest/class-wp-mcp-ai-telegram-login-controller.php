<?php
/**
 * Telegram Web Login REST Controller
 *
 * Implements Telegram's Web Login feature (https://core.telegram.org/bots/features#web-login):
 * - REST endpoint that receives auth data from the Telegram Login Widget callback redirect.
 * - HMAC-SHA256 hash verification of the received user data using the bot token as the key.
 * - Fires action/filter hooks so site developers can handle the authenticated user.
 * - Provides the [mcp_ai_telegram_login] shortcode to embed the Telegram Login Widget.
 *
 * Verification algorithm (per Telegram docs):
 *   data_check_string = key1=value1\nkey2=value2 (all fields except `hash`, sorted alphabetically)
 *   secret_key        = SHA-256( bot_token )
 *   hash              = HMAC-SHA-256( data_check_string, secret_key )
 *
 * @see https://core.telegram.org/widgets/login
 * @see https://core.telegram.org/bots/features#web-login
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Telegram Web Login REST controller and shortcode handler.
 */
class WP_MCP_AI_Telegram_Login_Controller extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mcp-ai/v1';

	/**
	 * REST API endpoint base.
	 *
	 * @var string
	 */
	protected $rest_base = 'telegram-login';

	/**
	 * Maximum age (in seconds) of the auth_date field before auth data is
	 * considered expired. Telegram recommends 86400 seconds (24 hours).
	 */
	const AUTH_DATE_MAX_AGE = 86400;

	/**
	 * Constructor – registers routes and the [mcp_ai_telegram_login] shortcode.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_shortcode( 'mcp_ai_telegram_login', array( $this, 'render_login_widget' ) );
	}

	/**
	 * Register REST routes.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_login_callback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'         => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'first_name' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'auth_date'  => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'hash'       => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Handle the Telegram Login Widget callback redirect.
	 *
	 * Telegram sends the following query parameters when redirecting to the
	 * configured callback URL:
	 *   id, first_name, last_name (optional), username (optional),
	 *   photo_url (optional), auth_date, hash
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_login_callback( $request ) {
		// Validate that required Telegram auth parameters are present.
		// These are not enforced as REST API 'required' args so that missing-param
		// requests receive a descriptive error instead of the generic WordPress
		// rest_missing_callback_param response (which occurs when this login-callback
		// URL is mistakenly used as the Mini App URL in BotFather).
		$required_auth_params = array( 'id', 'first_name', 'auth_date', 'hash' );
		$missing_params       = array();
		foreach ( $required_auth_params as $param ) {
			if ( null === $request->get_param( $param ) ) {
				$missing_params[] = $param;
			}
		}
		if ( ! empty( $missing_params ) ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram Web Login: missing auth parameters.',
				array( 'missing' => $missing_params )
			);
			return new WP_Error(
				'wp_mcp_ai_telegram_login_missing_params',
				sprintf(
					/* translators: %s: comma-separated list of missing parameter names */
					__( 'Missing required Telegram auth parameter(s): %s. This endpoint handles Telegram Login Widget callbacks. If you are configuring a Telegram Mini App, please use the Mini App URL shown in your plugin settings instead.', 'mcp-ai-wpoos-pro' ),
					implode( ', ', $missing_params )
				),
				array( 'status' => 400 )
			);
		}

		$connection = $this->get_active_web_login_connection();

		if ( ! $connection ) {
			WP_MCP_AI_Logger::log_error( 'Telegram Web Login: no active connection with Web Login enabled.' );
			return new WP_Error(
				'wp_mcp_ai_telegram_login_not_configured',
				__( 'Telegram Web Login is not configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$bot_token = $this->get_bot_token( $connection );

		if ( '' === $bot_token ) {
			WP_MCP_AI_Logger::log_error( 'Telegram Web Login: bot token could not be decrypted.' );
			return new WP_Error(
				'wp_mcp_ai_telegram_login_token_error',
				__( 'Server configuration error.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		// Collect the raw auth data from query parameters.
		$auth_data = $this->extract_auth_data( $request );

		// Verify the hash.
		$verification = $this->verify_auth_data( $auth_data, $bot_token );

		if ( is_wp_error( $verification ) ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram Web Login: verification failed.',
				array( 'code' => $verification->get_error_code() )
			);

			/**
			 * Fires when Telegram Web Login verification fails.
			 *
			 * @since 1.0.0
			 *
			 * @param WP_Error $verification Verification error.
			 * @param array    $auth_data    Auth data received from Telegram (hash NOT verified).
			 */
			do_action( 'wp_mcp_ai_telegram_login_failed', $verification, $auth_data );

			return $verification;
		}

		WP_MCP_AI_Logger::log_event(
			'telegram_web_login_verified',
			'Telegram Web Login: user auth data verified.',
			array( 'telegram_id' => $auth_data['id'] )
		);

		/**
		 * Fires after a Telegram Web Login has been successfully verified.
		 *
		 * Use this hook to create/update a WordPress user, start a session,
		 * or perform any custom login logic.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $auth_data  Verified auth data from Telegram.
		 *                           Keys: id, first_name, last_name (may be ''), username (may be ''),
		 *                                 photo_url (may be ''), auth_date.
		 * @param array  $connection Active Telegram connection settings.
		 */
		do_action( 'wp_mcp_ai_telegram_login_verified', $auth_data, $connection );

		/**
		 * Filters the URL to redirect to after a successful Telegram Web Login.
		 *
		 * Return an empty string to skip the redirect and return a JSON response
		 * instead (useful for SPA / AJAX flows).
		 *
		 * @since 1.0.0
		 *
		 * @param string $redirect_url Redirect URL. Defaults to the connection's
		 *                             web_login_redirect_url or the site home URL.
		 * @param array  $auth_data    Verified Telegram auth data.
		 * @param array  $connection   Active Telegram connection settings.
		 */
		$redirect_url = apply_filters(
			'wp_mcp_ai_telegram_login_redirect_url',
			! empty( $connection['web_login_redirect_url'] ) ? $connection['web_login_redirect_url'] : home_url( '/' ),
			$auth_data,
			$connection
		);

		if ( '' !== $redirect_url ) {
			wp_safe_redirect( esc_url_raw( $redirect_url ) );
			exit;
		}

		return rest_ensure_response(
			array(
				'success'   => true,
				'user_data' => $auth_data,
			)
		);
	}

	/**
	 * Verify the Telegram auth data using the bot token.
	 *
	 * Algorithm defined at https://core.telegram.org/widgets/login:
	 *   1. Build data_check_string from all fields except `hash`, sorted alphabetically.
	 *   2. Compute secret_key = SHA-256( bot_token ).
	 *   3. Compute expected_hash = HMAC-SHA-256( data_check_string, secret_key ).
	 *   4. Compare expected_hash with the received hash (constant-time comparison).
	 *   5. Validate auth_date is not older than AUTH_DATE_MAX_AGE seconds.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $auth_data Auth data fields from Telegram (must contain 'hash').
	 * @param string $bot_token Plaintext Telegram bot token.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function verify_auth_data( array $auth_data, $bot_token ) {
		if ( empty( $auth_data['hash'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_login_missing_hash',
				__( 'Missing hash in Telegram auth data.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$received_hash = $auth_data['hash'];

		// Build the data-check string: all fields except hash, sorted alphabetically.
		$check_fields = array();
		foreach ( $auth_data as $key => $value ) {
			if ( 'hash' === $key || '' === (string) $value ) {
				continue;
			}
			$check_fields[] = $key . '=' . $value;
		}
		sort( $check_fields );
		$data_check_string = implode( "\n", $check_fields );

		// Compute the HMAC-SHA-256 hash.
		$secret_key    = hash( 'sha256', $bot_token, true );
		$expected_hash = hash_hmac( 'sha256', $data_check_string, $secret_key );

		if ( ! hash_equals( $expected_hash, $received_hash ) ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_login_invalid_hash',
				__( 'Telegram auth data verification failed: invalid hash.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// Validate auth_date freshness.
		if ( empty( $auth_data['auth_date'] ) || ( time() - (int) $auth_data['auth_date'] ) > self::AUTH_DATE_MAX_AGE ) {
			return new WP_Error(
				'wp_mcp_ai_telegram_login_expired',
				__( 'Telegram auth data has expired. Please log in again.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Render the Telegram Login Widget via the [mcp_ai_telegram_login] shortcode.
	 *
	 * Attributes:
	 *   bot_username   – Telegram bot username without '@' (required if not set on connection).
	 *   redirect_url   – URL Telegram will redirect to after login (defaults to the REST callback URL).
	 *   button_size    – Widget button size: 'large' (default), 'medium', or 'small'.
	 *   corner_radius  – Button corner radius in pixels (optional, 0–20).
	 *   request_access – 'write' to request permission to send messages (optional).
	 *   show_avatar    – '1' (default) or '0' – whether to show the user avatar.
	 *   lang           – ISO 639-1 language code for the widget (optional).
	 *
	 * @since 1.0.0
	 *
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Unused inner content.
	 * @return string HTML output.
	 */
	public function render_login_widget( $atts, $content = '' ) {
		$atts = shortcode_atts(
			array(
				'bot_username'   => '',
				'redirect_url'   => '',
				'button_size'    => 'large',
				'corner_radius'  => '',
				'request_access' => '',
				'show_avatar'    => '1',
				'lang'           => '',
			),
			$atts,
			'mcp_ai_telegram_login'
		);

		// Resolve bot username: shortcode attribute > connection setting.
		$bot_username = sanitize_text_field( $atts['bot_username'] );
		if ( '' === $bot_username ) {
			$connection   = $this->get_active_web_login_connection();
			$bot_username = $connection && ! empty( $connection['bot_username'] ) ? ltrim( $connection['bot_username'], '@' ) : '';
		} else {
			$bot_username = ltrim( $bot_username, '@' );
		}

		if ( '' === $bot_username ) {
			return '<!-- mcp_ai_telegram_login: bot_username not configured -->';
		}

		// Resolve redirect URL: shortcode attribute > REST callback URL.
		$redirect_url = esc_url_raw( $atts['redirect_url'] );
		if ( '' === $redirect_url ) {
			$redirect_url = rest_url( $this->namespace . '/' . $this->rest_base );
		}

		// Allowed button sizes.
		$allowed_sizes = array( 'large', 'medium', 'small' );
		$button_size   = in_array( $atts['button_size'], $allowed_sizes, true ) ? $atts['button_size'] : 'large';

		// Build script tag attributes (values are escaped when the attribute string is assembled below).
		$script_attrs = array(
			'src'                 => 'https://telegram.org/js/telegram-widget.js?22',
			'data-telegram-login' => $bot_username,
			'data-size'           => $button_size,
			'data-auth-url'       => $redirect_url,
			'async'               => 'async',
		);

		if ( '' !== $atts['corner_radius'] ) {
			$radius = absint( $atts['corner_radius'] );
			if ( $radius <= 20 ) {
				$script_attrs['data-radius'] = (string) $radius;
			}
		}

		if ( 'write' === $atts['request_access'] ) {
			$script_attrs['data-request-access'] = 'write';
		}

		if ( '0' === $atts['show_avatar'] ) {
			$script_attrs['data-userpic'] = 'false';
		}

		if ( '' !== $atts['lang'] ) {
			$script_attrs['data-lang'] = sanitize_text_field( $atts['lang'] );
		}

		// URL attributes that require esc_url() instead of esc_attr().
		$url_attrs = array( 'src', 'data-auth-url' );

		$attr_string = '';
		foreach ( $script_attrs as $attr => $value ) {
			if ( 'async' === $attr ) {
				$attr_string .= ' async';
			} elseif ( in_array( $attr, $url_attrs, true ) ) {
				$attr_string .= ' ' . esc_attr( $attr ) . '="' . esc_url( $value ) . '"';
			} else {
				$attr_string .= ' ' . esc_attr( $attr ) . '="' . esc_attr( $value ) . '"';
			}
		}

		return '<div class="wp-mcp-ai-telegram-login-widget"><script' . $attr_string . '></script></div>';
	}

	/**
	 * Extract and normalise auth data fields from the request.
	 *
	 * Only the fields defined in the Telegram Web Login spec are collected.
	 * Optional fields that are absent or empty are omitted from the data-check
	 * string as per Telegram's algorithm.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array Auth data array.
	 */
	protected function extract_auth_data( $request ) {
		$fields = array( 'id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date', 'hash' );
		$data   = array();

		foreach ( $fields as $field ) {
			$value = $request->get_param( $field );
			if ( null !== $value ) {
				$data[ $field ] = sanitize_text_field( (string) $value );
			}
		}

		return $data;
	}

	/**
	 * Find the first active Telegram connection that has Web Login enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return array|null Connection array or null if none found.
	 */
	protected function get_active_web_login_connection() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( ! is_array( $connections ) ) {
			return null;
		}

		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'telegram' !== $connection['connection_type'] ) {
				continue;
			}

			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			if ( empty( $connection['enable_web_login'] ) ) {
				continue;
			}

			return $connection;
		}

		return null;
	}

	/**
	 * Decrypt and return the bot token from a connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection array.
	 * @return string Plaintext bot token, or empty string on failure.
	 */
	protected function get_bot_token( array $connection ) {
		if ( empty( $connection['api_key'] ) ) {
			return '';
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
	}
}

new WP_MCP_AI_Telegram_Login_Controller();
