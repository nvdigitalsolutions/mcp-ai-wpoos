<?php
/**
 * Google Chat Bot Webhook Controller
 *
 * Handles incoming Google Chat bot events with industry-standard security
 * validation. Implements Google Chat API best practices:
 * - OIDC Bearer token verification (Authorization header, optional audience check)
 * - Per-user conversation history respecting max_history_messages
 * - AI auto-reply via WordPress cron (async, no timeout risk)
 * - Message deduplication via transient cache
 * - Support for MESSAGE, ADDED_TO_SPACE, and CARD_CLICKED event types
 * - Support for both direct Chat app events and Google Workspace Add-ons event format
 *
 * Google Chat sends POST requests to a configured bot endpoint when a user
 * messages the bot. Each request carries a Google-signed OIDC token in the
 * Authorization header. When an audience URL is configured on the connection,
 * the token's `aud` claim is validated against it.
 *
 * Two event payload formats are accepted:
 *  1. Direct Chat app (HTTP endpoint via Google Cloud Console):
 *       {"type":"MESSAGE","message":{...},"space":{...},"user":{...}}
 *  2. Google Workspace Add-ons framework:
 *       {"type":"GOOGLE_CHAT","google":{"chat":{"type":"MESSAGE","message":{...},...}}}
 * Both are normalised to the standard format before processing.
 *
 * @see https://developers.google.com/workspace/chat/api/reference/rest/v1/spaces.messages/create
 * @see https://developers.google.com/workspace/add-ons/chat/build#event-objects
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PRO_PATH . 'includes/src/Tools/ChatChannels/class-wp-mcp-ai-pro-google-service-account.php';

// Load channel CCT helpers when available.
$_cc_messages_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-channel-messages-cct.php';
$_cc_contacts_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-channel-contacts-cct.php';
if ( file_exists( $_cc_messages_file ) && ! class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
	require_once $_cc_messages_file;
}
if ( file_exists( $_cc_contacts_file ) && ! class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
	require_once $_cc_contacts_file;
}
unset( $_cc_messages_file, $_cc_contacts_file );

/**
 * Google Chat webhook REST controller.
 */
class WP_MCP_AI_Google_Chat_Webhook_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks/google-chat';

	/**
	 * Cron hook for dispatching AI replies to incoming Google Chat messages.
	 */
	const REPLY_CRON_HOOK = 'wp_mcp_ai_google_chat_send_ai_reply';

	/**
	 * TTL in seconds for the deduplication transient.
	 */
	const DEDUP_TRANSIENT_TTL = 60;

	/**
	 * TTL in seconds for per-user conversation history transients (24 hours).
	 */
	const CONVERSATION_HISTORY_TTL = 86400;

	/**
	 * Google Chat API base URL.
	 */
	const CHAT_API_BASE = 'https://chat.googleapis.com/v1';

	/**
	 * Pattern for validating Google Chat incoming webhook URLs.
	 *
	 * Incoming webhooks are created in Google Chat space settings and embed the
	 * authentication key and token directly in the URL, allowing messages to be
	 * posted to a specific space without OAuth 2.0 credentials.
	 *
	 * @see https://developers.google.com/workspace/chat/quickstart/webhooks
	 */
	const WEBHOOK_URL_PATTERN = '#^https://chat\.googleapis\.com/v1/spaces/[a-zA-Z0-9_-]+/messages\?#';

	/**
	 * Expected OIDC token issuer for Google Chat HTTP-endpoint apps.
	 *
	 * Google Chat signs webhook OIDC tokens with the service account
	 * chat@system.gserviceaccount.com. Workspace Add-ons may additionally
	 * use accounts.google.com — both are accepted in validate_google_oidc_token().
	 *
	 * @see https://developers.google.com/workspace/chat/authenticate-authorize-chat-app
	 */
	const GOOGLE_OIDC_ISSUER = 'chat@system.gserviceaccount.com';

	/**
	 * Google Chat API scope for bot operations.
	 */
	const CHAT_BOT_SCOPE = 'https://www.googleapis.com/auth/chat.bot';

	/**
	 * Google tokeninfo endpoint for OIDC token validation.
	 */
	const GOOGLE_TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_google_chat_reply_job' ) );
		add_action( 'wp_mcp_ai_google_chat_send_welcome_message', array( $this, 'handle_welcome_message_job' ) );
		add_filter( 'wp_mcp_ai_chat_channels_send_reply', array( $this, 'handle_channel_send_reply' ), 10, 6 );

		// WordPress Application Passwords (WP 5.6+) and JWT auth plugins intercept
		// the Authorization: Bearer header and can set a WP_Error in
		// rest_authentication_errors before our permission_callback runs, causing a
		// 401/403 that Google Chat immediately reports as "not responding".
		// Clear that error for requests to our webhook endpoints so that our own
		// validate_google_oidc_token() callback handles authentication.
		// Priority 99999 ensures we run after third-party JWT plugins (commonly 100–999)
		// and WordPress Application Passwords (priority 100) that may re-set the error
		// after a lower-priority filter has already cleared it.
		add_filter( 'rest_authentication_errors', array( $this, 'allow_google_oidc_auth' ), 99999 );

		// Register an admin-ajax.php fallback endpoint for sites where Cloudflare
		// WAF, Bot Fight Mode, or other proxies block POST requests to /wp-json/.
		// Google Chat can be configured with the admin-ajax URL instead.
		add_action( 'wp_ajax_nopriv_wp_mcp_ai_google_chat_webhook', array( $this, 'handle_ajax_webhook' ) );
		add_action( 'wp_ajax_wp_mcp_ai_google_chat_webhook', array( $this, 'handle_ajax_webhook' ) );
	}

	/**
	 * Allow Google OIDC-authenticated webhook requests to reach our permission callback.
	 *
	 * WordPress Application Passwords (WP 5.6+) and third-party JWT auth plugins
	 * listen on the `determine_current_user` filter and set a WP_Error in
	 * `rest_authentication_errors` when they cannot parse the Authorization header.
	 * Because WordPress evaluates that filter before calling our permission_callback,
	 * any such error causes a 401/403 response that Google Chat immediately surfaces
	 * as "not responding" — our validate_google_oidc_token() never even runs.
	 *
	 * For requests targeting our webhook endpoints we clear the authentication error
	 * (return null) so WordPress proceeds to call validate_google_oidc_token(), which
	 * is the correct authority on whether the Google OIDC Bearer token is valid.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Error|null $error Existing authentication error or null.
	 * @return WP_Error|null Null for our webhook routes; unchanged value for all others.
	 */
	public function allow_google_oidc_auth( $error ) {
		// Only intervene when another plugin already set an error — if there is no
		// error we have nothing to clear.
		if ( ! is_wp_error( $error ) ) {
			return $error;
		}

		// Check whether this request targets one of our webhook routes.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		if ( false !== strpos( $request_uri, '/' . $this->rest_base ) ) {
			// Clear the error so our permission_callback handles auth instead.
			return null;
		}

		return $error;
	}

	/**
	 * Handle a Google Chat webhook event via the WordPress admin-ajax endpoint.
	 *
	 * Provides a Cloudflare-compatible alternative to the REST API webhook URL.
	 * When Cloudflare WAF, Bot Fight Mode, or other proxies block POST requests
	 * to /wp-json/ endpoints, configure Google Chat to use the admin-ajax URL
	 * instead (shown in the plugin's Google Chat connection settings).
	 *
	 * Security is identical to the REST endpoint: the Google OIDC Bearer token
	 * sent by Google Chat is validated by validate_google_oidc_token() before
	 * any event processing occurs. No WordPress nonce is required here because
	 * the OIDC token is the authentication mechanism.
	 *
	 * AJAX URL format:
	 *   /wp-admin/admin-ajax.php?action=wp_mcp_ai_google_chat_webhook
	 *   /wp-admin/admin-ajax.php?action=wp_mcp_ai_google_chat_webhook&connection_id={id}
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_webhook() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OIDC token is the auth mechanism.
		$connection_id = isset( $_GET['connection_id'] ) ? sanitize_key( wp_unslash( $_GET['connection_id'] ) ) : '';

		// Build a synthetic REST request so validate_google_oidc_token() and
		// handle_webhook() can be reused without duplicating logic.
		$route        = '/mcp-ai/v1/webhooks/google-chat' . ( '' !== $connection_id ? '/' . $connection_id : '' );
		$rest_request = new WP_REST_Request( 'POST', $route );

		// Forward the Authorization header. Some server stacks (Apache + FastCGI)
		// expose it only via REDIRECT_HTTP_AUTHORIZATION.
		$auth = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( '' !== $auth ) {
			$rest_request->set_header( 'authorization', $auth );
		}

		if ( '' !== $connection_id ) {
			$rest_request->set_param( 'connection_id', $connection_id );
		}

		// Validate the Google OIDC Bearer token before processing any payload.
		if ( ! $this->validate_google_oidc_token( $rest_request ) ) {
			wp_send_json( array( 'error' => 'Invalid or missing Authorization Bearer token.' ), 401 );
			return;
		}

		// Read the raw JSON body sent by Google Chat and pass it to the handler.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw_body = file_get_contents( 'php://input' );
		$rest_request->set_header( 'Content-Type', 'application/json' );
		$rest_request->set_body( is_string( $raw_body ) ? $raw_body : '{}' );

		// Process the event via the existing REST handler.
		$response      = $this->handle_webhook( $rest_request );
		$data          = $response instanceof WP_REST_Response ? $response->get_data() : new stdClass();
		$status        = $response instanceof WP_REST_Response ? $response->get_status() : 200;

		wp_send_json( $data, $status );
	}

	/**
	 * Register REST routes for Google Chat webhooks.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		$route_args = array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_webhook' ),
			'permission_callback' => array( $this, 'validate_google_oidc_token' ),
		);

		// Generic webhook URL — handles all Google Chat connections.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			$route_args
		);

		// Connection-specific webhook URL (e.g. /webhooks/google-chat/{connection_id}).
		// The admin UI exposes this URL so each Google Cloud project can route events
		// to its own dedicated endpoint. Without this registration those URLs return 404
		// and Google Chat events are silently dropped.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<connection_id>[a-zA-Z0-9_-]+)',
			$route_args
		);
	}

	/**
	 * Decode a base64url-encoded string (RFC 4648 §5).
	 *
	 * JWT segments use base64url encoding (URL-safe alphabet, no padding).
	 *
	 * @since 1.0.0
	 *
	 * @param string $input Base64url-encoded string.
	 * @return string|false Decoded bytes or false on failure.
	 */
	protected function base64url_decode( $input ) {
		$padded = str_pad(
			strtr( $input, '-_', '+/' ),
			strlen( $input ) % 4 === 0 ? strlen( $input ) : strlen( $input ) + 4 - ( strlen( $input ) % 4 ),
			'=',
			STR_PAD_RIGHT
		);
		return base64_decode( $padded ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * Validate the Google OIDC Bearer token sent by Google Chat.
	 *
	 * Google Chat signs each request with a Google OIDC token in the
	 * Authorization header (Bearer scheme). When an audience URL is stored on
	 * the connection the `aud` claim of the decoded JWT payload is checked
	 * against it. If no audience is configured the token presence check is
	 * still enforced, but audience matching is skipped with a security notice.
	 *
	 * When `disable_oidc_verification` is enabled on the connection, all OIDC
	 * token checks are bypassed and any POST request is accepted. This mirrors
	 * Telegram's behavior when no secret token is configured, and is useful for
	 * environments where the Authorization header is stripped by a proxy or WAF.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if the token is acceptable, false to reject.
	 */
	public function validate_google_oidc_token( $request ) {
		// Load the connection first so we can check disable_oidc_verification
		// before doing any token validation.
		$url_connection_id = $request->get_param( 'connection_id' );
		if ( ! empty( $url_connection_id ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
			}
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( sanitize_key( $url_connection_id ) );
		} else {
			$connection = $this->get_active_google_chat_connection();
		}

		// When OIDC verification is disabled for this connection, accept any POST
		// request without token validation. This mirrors Telegram's no-secret-token
		// mode and is useful for environments where the Authorization header is
		// stripped by a server, proxy, or WAF.
		if ( $connection && ! empty( $connection['disable_oidc_verification'] ) ) {
			WP_MCP_AI_Logger::log_event(
				'google_chat_webhook_oidc_skipped',
				'Google Chat webhook: OIDC verification is disabled for this connection. Accepting request without token validation. Enable OIDC verification for production environments.',
				array()
			);
			return true;
		}

		// Accept WordPress nonce authentication for administrator users.
		// This allows logged-in admins to trigger or test the webhook endpoint
		// from wp-admin or other WordPress code without a Google OIDC Bearer token.
		// The standard WordPress REST API nonce (X-WP-Nonce header with action
		// 'wp_rest') is required, and the caller must have the manage_options
		// capability so that ordinary subscribers cannot authenticate this way.
		$wp_nonce = $request->get_header( 'X-WP-Nonce' );
		if (
			! empty( $wp_nonce ) &&
			is_user_logged_in() &&
			current_user_can( 'manage_options' ) &&
			wp_verify_nonce( $wp_nonce, 'wp_rest' )
		) {
			WP_MCP_AI_Logger::log_event(
				'google_chat_webhook_nonce_auth',
				'Google Chat webhook: request authenticated via WordPress nonce.',
				array()
			);
			return true;
		}

		$auth_header = $request->get_header( 'authorization' );

		// Fallback: some server configurations (Apache + FastCGI / PHP-FPM) do not
		// populate $_SERVER['HTTP_AUTHORIZATION'], so WordPress's get_header() returns
		// empty. Check the two common alternative server variables before giving up.
		if ( empty( $auth_header ) && ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		if ( empty( $auth_header ) && ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth_header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( empty( $auth_header ) || 0 !== strncasecmp( $auth_header, 'Bearer ', 7 ) ) {
			WP_MCP_AI_Logger::log_error(
				'Google Chat webhook rejected: missing or malformed Authorization Bearer header.'
			);
			return false;
		}

		$token = substr( $auth_header, 7 );

		if ( empty( $token ) ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat webhook rejected: empty Bearer token.' );
			return false;
		}

		$audience = '';

		if ( $connection && ! empty( $connection['verify_token'] ) ) {
			$audience = $connection['verify_token'];
		}

		if ( empty( $audience ) ) {
			WP_MCP_AI_Logger::log_event(
				'google_chat_webhook_no_audience',
				'Google Chat webhook received without audience URL configured. OIDC audience check skipped. Configure the audience URL on the connection for enhanced security.',
				array()
			);
			// Token presence has been verified above; allow through without audience check.
			return true;
		}

		// Decode the JWT payload (base64url) to inspect claims without full crypto verification.
		$jwt_parts = explode( '.', $token );

		if ( count( $jwt_parts ) < 2 ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat webhook rejected: token is not a valid JWT.' );
			return false;
		}

		// Decode the payload (second segment).
		$payload_json = $this->base64url_decode( $jwt_parts[1] );

		if ( false === $payload_json ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat webhook rejected: failed to base64-decode JWT payload.' );
			return false;
		}

		$claims = json_decode( $payload_json, true );

		if ( ! is_array( $claims ) ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat webhook rejected: JWT payload is not valid JSON.' );
			return false;
		}

		// Validate token expiry.
		if ( isset( $claims['exp'] ) && (int) $claims['exp'] < time() ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat webhook rejected: OIDC token has expired.' );
			return false;
		}

		// Validate issuer — must be a recognised Google issuer.
		// Google Chat HTTP-endpoint apps use chat@system.gserviceaccount.com;
		// Workspace Add-ons may additionally use accounts.google.com or its HTTPS variant.
		$token_iss     = isset( $claims['iss'] ) ? (string) $claims['iss'] : '';
		$valid_issuers = array(
			self::GOOGLE_OIDC_ISSUER,          // chat@system.gserviceaccount.com.
			'accounts.google.com',             // Workspace Add-ons / OAuth-based tokens.
			'https://accounts.google.com',     // Alternative HTTPS form sometimes seen.
		);

		if ( ! in_array( $token_iss, $valid_issuers, true ) ) {
			WP_MCP_AI_Logger::log_error(
				'Google Chat webhook rejected: OIDC token issuer is not a recognised Google issuer.',
				array( 'iss' => '' !== $token_iss ? substr( $token_iss, 0, 40 ) : '(empty)' )
			);
			return false;
		}

		// Validate audience claim.
		// The JWT spec (RFC 7519 §4.1.3) allows 'aud' to be either a single string
		// or an array of strings.  Handle both forms to avoid incorrectly rejecting
		// legitimate Google Chat OIDC tokens.
		$token_aud = isset( $claims['aud'] ) ? $claims['aud'] : '';

		if ( is_array( $token_aud ) ) {
			// aud is an array — verify the configured audience is one of the values.
			if ( ! in_array( $audience, $token_aud, true ) ) {
				WP_MCP_AI_Logger::log_error(
					'Google Chat webhook rejected: OIDC token audience array does not contain the expected audience.',
					array( 'expected' => $audience )
				);
				return false;
			}
		} elseif ( $token_aud !== $audience ) {
			WP_MCP_AI_Logger::log_error(
				'Google Chat webhook rejected: OIDC token audience mismatch.',
				array(
					'expected' => $audience,
					'received' => is_string( $token_aud ) ? substr( $token_aud, 0, 20 ) . '***' : gettype( $token_aud ),
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Handle an incoming Google Chat bot event.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response acknowledged to Google Chat.
	 */
	public function handle_webhook( $request ) {
		$payload = $request->get_json_params();

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat webhook: empty or invalid JSON payload.' );
			return rest_ensure_response( $this->empty_response() );
		}

		// Normalise Workspace Add-ons wrapper format to standard Chat event format.
		$payload = $this->normalize_payload( $payload );

		$event_type = isset( $payload['type'] ) ? sanitize_text_field( $payload['type'] ) : '';
		$message_id = isset( $payload['message']['name'] ) ? sanitize_text_field( $payload['message']['name'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'google_chat_webhook_received',
			'Google Chat webhook event received.',
			array(
				'event_type' => $event_type,
				'message_id' => $message_id,
			)
		);

		// Handle ADDED_TO_SPACE: send a welcome message via cron.
		if ( 'ADDED_TO_SPACE' === $event_type ) {
			return $this->handle_added_to_space( $payload, $request );
		}

		// Handle APP_COMMAND events (slash commands configured in Google Cloud Console).
		// Google Chat sends APP_COMMAND instead of MESSAGE when a user invokes a
		// configured slash command. Without this branch the event is silently dropped
		// and Google Chat immediately shows "not responding".
		if ( 'APP_COMMAND' === $event_type ) {
			return $this->handle_app_command( $payload, $request );
		}

		// Only process MESSAGE events (not REMOVED_FROM_SPACE, CARD_CLICKED, etc.).
		if ( 'MESSAGE' !== $event_type ) {
			return rest_ensure_response( $this->empty_response() );
		}

		// Deduplicate by message name.
		if ( $message_id && $this->is_duplicate_message( $message_id ) ) {
			WP_MCP_AI_Logger::log_event(
				'google_chat_webhook_duplicate',
				'Google Chat message already processed; skipping.',
				array( 'message_id' => $message_id )
			);
			return rest_ensure_response( $this->empty_response() );
		}

		if ( $message_id ) {
			set_transient( 'wp_mcp_ai_gc_dedup_' . md5( $message_id ), 1, self::DEDUP_TRANSIENT_TTL );
		}

		// Extract message text (plain text from the message body).
		$message_text = $this->extract_message_text( $payload );

		if ( '' === $message_text ) {
			return rest_ensure_response( $this->empty_response() );
		}

		// Extract space name, space type, thread name, and sender for routing.
		$space_name  = isset( $payload['space']['name'] ) ? sanitize_text_field( $payload['space']['name'] ) : '';
		$space_type  = $this->get_space_type( $payload );
		$sender_name = isset( $payload['message']['sender']['name'] ) ? sanitize_text_field( $payload['message']['sender']['name'] ) : '';
		$thread_name = isset( $payload['message']['thread']['name'] ) ? sanitize_text_field( $payload['message']['thread']['name'] ) : '';

		if ( '' === $space_name ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat webhook: unable to determine space name.' );
			return rest_ensure_response( $this->empty_response() );
		}

		// Resolve connection: prefer the connection_id from the URL route (connection-specific
		// webhook endpoint), then fall back to space-name-based matching.
		$url_connection_id = $request->get_param( 'connection_id' );
		if ( ! empty( $url_connection_id ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
			}
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( sanitize_key( $url_connection_id ) );
			if ( ! $connection || empty( $connection['enabled'] ) ) {
				$connection = null;
			}
		} else {
			$connection = $this->get_active_google_chat_connection( $space_name );
		}

		if ( ! $connection ) {
			WP_MCP_AI_Logger::log_error(
				'Google Chat webhook: no active Google Chat connection found.'
			);
			return rest_ensure_response( $this->empty_response() );
		}

		$assigned_assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_values( array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) ) )
			: array();

		// --- Automation rules: fall back to global default assistant ---
		$automation_rules = get_option( 'wp_mcp_ai_chat_channels_automation_rules', array() );
		if ( empty( $assigned_assistant_ids ) && ! empty( $automation_rules['default_assistant_id'] ) ) {
			$assigned_assistant_ids = array( absint( $automation_rules['default_assistant_id'] ) );
		}

		// --- Final fallback: use any published assistant so all messages get a reply ---
		if ( empty( $assigned_assistant_ids ) ) {
			$any_id = $this->get_any_assistant_id();
			if ( $any_id ) {
				$assigned_assistant_ids = array( $any_id );
			}
		}

		/**
		 * Filter whether to auto-reply to Google Chat messages.
		 *
		 * Defaults to true when the connection has one or more assigned AI assistants
		 * or a global default assistant is configured in the automation rules.
		 *
		 * Note: Google Chat already enforces mention/routing rules at the platform
		 * level — MESSAGE events in spaces are only delivered to the bot when it is
		 *
		 * @mentioned, and every message in a DIRECT_MESSAGE space is directed at the
		 * bot. No additional require_mention filtering is needed here.
		 *
		 * @since 1.0.0
		 *
		 * @param bool  $auto_reply       Whether to auto-reply.
		 * @param array $payload          Google Chat event payload.
		 * @param array $automation_rules Saved automation rule settings.
		 */
		$should_reply = apply_filters( 'wp_mcp_ai_google_chat_should_auto_reply', ! empty( $assigned_assistant_ids ), $payload, $automation_rules );

		if ( ! $should_reply ) {
			return rest_ensure_response( $this->empty_response() );
		}

		$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';

		if ( '' === $connection_id ) {
			return rest_ensure_response( $this->empty_response() );
		}

		do_action( 'wp_mcp_ai_google_chat_auto_reply', $payload, $automation_rules, $assigned_assistant_ids );

		// Find or create the contact in the Channel Contacts CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			$contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
				'google_chat',
				$sender_name,
				array( 'display_name' => $sender_name )
			);
			if ( $contact_row_id ) {
				WP_MCP_AI_Channel_Contacts_CCT::touch( $contact_row_id );
			}
		}

		// Persist inbound message to Channel Messages CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'google_chat',
					'channel_contact_id' => $sender_name,
					'direction'          => 'inbound',
					'message_id'         => $message_id,
					'message_type'       => 'text',
					'content'            => $message_text,
					'status'             => 'received',
					'connection_id'      => $connection_id,
					'phone_number_id'    => $space_name,
					'timestamp'          => time(),
					'reply_sent'         => 0,
					'assigned_agent'     => (string) $assigned_assistant_ids[0],
				)
			);
		}

		// Trigger auto-reply with human takeover / automation keyword checks,
		// mirroring the WhatsApp auto-reply dispatch pattern.
		$this->maybe_auto_reply(
			$message_text,
			$sender_name,
			$space_name,
			$connection_id,
			$thread_name,
			$assigned_assistant_ids,
			$automation_rules
		);

		// Return empty response — Google Chat accepts 200 with an empty JSON body
		// or a message payload to reply synchronously. Using async cron avoids timeouts.
		return rest_ensure_response( $this->empty_response() );
	}

	/**
	 * Decide whether to auto-reply to an incoming Google Chat message.
	 *
	 * Mirrors the WhatsApp maybe_auto_reply() pattern: applies automation keyword
	 * checks (human takeover / AI resume) and the human takeover gate before
	 * dispatching an async AI reply via WordPress cron.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message_text           Plain-text message from the sender.
	 * @param string $sender_name            Google Chat sender resource name (e.g. users/12345).
	 * @param string $space_name             Google Chat space resource name (e.g. spaces/AAAA).
	 * @param string $connection_id          Remote connection ID.
	 * @param string $thread_name            Thread resource name (may be empty for new threads).
	 * @param int[]  $assigned_assistant_ids Assistant post IDs assigned to this connection.
	 * @param array  $automation_rules       Global chat channels automation rule settings.
	 */
	protected function maybe_auto_reply( $message_text, $sender_name, $space_name, $connection_id, $thread_name, array $assigned_assistant_ids, array $automation_rules ) {
		// Nothing to do for empty messages — dispatch_google_chat_ai_reply() would
		// reject them too, but checking early avoids unnecessary keyword iterations.
		if ( '' === $message_text ) {
			return;
		}

		$message_text_lower = strtolower( $message_text );

		// --- Human takeover keyword check ---
		// When a message contains a configured human-takeover keyword, flag the
		// contact for human takeover and skip the AI auto-reply so a human agent
		// can respond instead.
		if ( ! empty( $automation_rules['human_takeover_keywords'] ) ) {
			$takeover_kws = array_map( 'trim', explode( ',', strtolower( $automation_rules['human_takeover_keywords'] ) ) );
			foreach ( $takeover_kws as $kw ) {
				if ( '' !== $kw && false !== strpos( $message_text_lower, $kw ) ) {
					if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
						$contact_id = $this->get_channel_contact_id( 'google_chat', $sender_name );
						if ( $contact_id ) {
							WP_MCP_AI_Channel_Contacts_CCT::set_human_takeover( $contact_id, true );
						}
					}
					WP_MCP_AI_Logger::log_event(
						'google_chat_human_takeover_triggered',
						'Human takeover triggered by keyword.',
						array( 'sender_name' => $sender_name, 'keyword' => $kw )
					);
					return; // Do not auto-reply; human agent will respond.
				}
			}
		}

		// --- AI resume keyword check ---
		// When a message contains a configured AI-resume keyword, clear the human
		// takeover flag so AI auto-replies resume for this contact.
		if ( ! empty( $automation_rules['ai_resume_keywords'] ) ) {
			$resume_kws = array_map( 'trim', explode( ',', strtolower( $automation_rules['ai_resume_keywords'] ) ) );
			foreach ( $resume_kws as $kw ) {
				if ( '' !== $kw && false !== strpos( $message_text_lower, $kw ) ) {
					if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
						$contact_id = $this->get_channel_contact_id( 'google_chat', $sender_name );
						if ( $contact_id ) {
							WP_MCP_AI_Channel_Contacts_CCT::set_human_takeover( $contact_id, false );
						}
					}
					WP_MCP_AI_Logger::log_event(
						'google_chat_ai_resumed',
						'AI auto-reply resumed by keyword.',
						array( 'sender_name' => $sender_name, 'keyword' => $kw )
					);
					break; // Continue and allow AI to reply.
				}
			}
		}

		// --- Human takeover gate ---
		// Skip AI auto-reply when a human agent is actively handling this contact.
		if ( ! empty( $sender_name ) && class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			if ( WP_MCP_AI_Channel_Contacts_CCT::is_human_takeover_active( 'google_chat', $sender_name ) ) {
				WP_MCP_AI_Logger::log_event(
					'google_chat_auto_reply_skipped_human_takeover',
					'Auto-reply skipped: human takeover is active for this contact.',
					array( 'sender_name' => $sender_name )
				);
				return;
			}
		}

		// Dispatch an AI-generated reply asynchronously via WordPress cron.
		$this->dispatch_google_chat_ai_reply(
			$message_text,
			$sender_name,
			$space_name,
			$connection_id,
			$thread_name,
			$assigned_assistant_ids
		);
	}

	/**
	 * Schedule an asynchronous cron job to generate and send a Google Chat AI reply.
	 *
	 * Mirrors the WhatsApp dispatch_whatsapp_ai_reply() pattern. Scheduling
	 * slightly in the future (time() + 1) ensures the webhook response is
	 * returned to Google Chat before the cron job begins, preventing timeouts.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message_text           Plain-text message from the sender.
	 * @param string $sender_name            Google Chat sender resource name.
	 * @param string $space_name             Google Chat space resource name.
	 * @param string $connection_id          Remote connection ID.
	 * @param string $thread_name            Thread resource name (may be empty).
	 * @param int[]  $assigned_assistant_ids Assistant post IDs for this connection.
	 */
	protected function dispatch_google_chat_ai_reply( $message_text, $sender_name, $space_name, $connection_id, $thread_name, array $assigned_assistant_ids ) {
		if ( '' === $message_text || '' === $space_name || '' === $connection_id || empty( $assigned_assistant_ids ) ) {
			return;
		}

		$job_args = array(
			array(
				'assistant_id'  => $assigned_assistant_ids[0],
				'message_text'  => $message_text,
				'space_name'    => $space_name,
				'sender_name'   => $sender_name,
				'connection_id' => $connection_id,
				'thread_name'   => $thread_name,
			),
		);

		// Schedule slightly in the future so the current request can complete first.
		wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
		spawn_cron();
	}

	/**
	 * Retrieve the Channel Contacts CCT row ID for a Google Chat sender.
	 *
	 * Used by maybe_auto_reply() to set or clear human takeover flags, mirroring
	 * the equivalent helper in WP_MCP_AI_WhatsApp_Webhook_Controller.
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel            Channel slug (e.g. 'google_chat').
	 * @param string $channel_contact_id Platform-side contact identifier (sender resource name).
	 * @return int|null CCT row ID, or null if not found or CCT is unavailable.
	 */
	protected function get_channel_contact_id( $channel, $channel_contact_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) || ! WP_MCP_AI_Channel_Contacts_CCT::table_exists() ) {
			return null;
		}

		global $wpdb;
		$table = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT _ID FROM {$table} WHERE channel = %s AND channel_contact_id = %s LIMIT 1",
				sanitize_key( $channel ),
				sanitize_text_field( $channel_contact_id )
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * Handle an ADDED_TO_SPACE event by sending a welcome message.
	 *
	 * When a bot is added to a space, Google Chat sends an ADDED_TO_SPACE event.
	 * This method schedules an async welcome message reply via cron.
	 *
	 * @since 1.0.0
	 *
	 * @param array           $payload Google Chat event payload.
	 * @param WP_REST_Request $request Original REST request (used to read connection_id param).
	 * @return WP_REST_Response Empty acknowledgement response.
	 */
	protected function handle_added_to_space( array $payload, WP_REST_Request $request = null ) {
		$space_name  = isset( $payload['space']['name'] ) ? sanitize_text_field( $payload['space']['name'] ) : '';
		$space_type  = $this->get_space_type( $payload );
		$sender_name = isset( $payload['user']['name'] ) ? sanitize_text_field( $payload['user']['name'] ) : '';

		if ( '' === $space_name ) {
			return rest_ensure_response( $this->empty_response() );
		}

		// Resolve connection: prefer the connection_id from the URL route so that
		// per-connection webhook URLs work correctly for ADDED_TO_SPACE events too.
		$url_connection_id = $request ? $request->get_param( 'connection_id' ) : '';
		if ( ! empty( $url_connection_id ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
			}
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( sanitize_key( $url_connection_id ) );
			if ( ! $connection || empty( $connection['enabled'] ) ) {
				$connection = null;
			}
		}

		if ( empty( $connection ) ) {
			$connection = $this->get_active_google_chat_connection( $space_name );
		}

		$has_credentials = $connection && (
			! empty( $connection['api_key'] ) ||
			( ! empty( $connection['client_id'] ) && ! empty( $connection['client_secret'] ) && ! empty( $connection['refresh_token'] ) )
		);

		if ( ! $has_credentials ) {
			return rest_ensure_response( $this->empty_response() );
		}

		$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';

		if ( '' === $connection_id ) {
			return rest_ensure_response( $this->empty_response() );
		}

		// When the bot is added to a DIRECT_MESSAGE space, Google Chat includes the
		// user's first message in the ADDED_TO_SPACE event payload. Process it as an
		// AI reply so the user's question is answered rather than ignored.
		if ( 'DIRECT_MESSAGE' === $space_type ) {
			$initial_message_text = $this->extract_message_text( $payload );

			if ( '' !== $initial_message_text ) {
				$assigned_assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
					? array_values( array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) ) )
					: array();

				$automation_rules = get_option( 'wp_mcp_ai_chat_channels_automation_rules', array() );
				if ( empty( $assigned_assistant_ids ) && ! empty( $automation_rules['default_assistant_id'] ) ) {
					$assigned_assistant_ids = array( absint( $automation_rules['default_assistant_id'] ) );
				}

				// Final fallback: use any published assistant.
				if ( empty( $assigned_assistant_ids ) ) {
					$any_id = $this->get_any_assistant_id();
					if ( $any_id ) {
						$assigned_assistant_ids = array( $any_id );
					}
				}

				if ( ! empty( $assigned_assistant_ids ) ) {
					$thread_name = isset( $payload['message']['thread']['name'] ) ? sanitize_text_field( $payload['message']['thread']['name'] ) : '';

					$job_args = array(
						array(
							'assistant_id'  => $assigned_assistant_ids[0],
							'message_text'  => $initial_message_text,
							'space_name'    => $space_name,
							'sender_name'   => $sender_name,
							'connection_id' => $connection_id,
							'thread_name'   => $thread_name,
						),
					);

					wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
					spawn_cron();

					WP_MCP_AI_Logger::log_event(
						'google_chat_added_to_space',
						'Bot added to Google Chat DM; AI reply scheduled for initial message.',
						array(
							'space_name' => $space_name,
							'space_type' => $space_type,
						)
					);

					return rest_ensure_response( $this->empty_response() );
				}
			}
		}

		/**
		 * Filters the welcome message sent when the bot is added to a Google Chat space.
		 *
		 * @since 1.0.0
		 *
		 * @param string $message     Default welcome message.
		 * @param string $space_name  Space resource name.
		 * @param string $space_type  Space type (SPACE, GROUP_CHAT, DIRECT_MESSAGE).
		 * @param string $sender_name Resource name of the user who added the bot.
		 */
		$welcome_message = apply_filters(
			'wp_mcp_ai_google_chat_welcome_message',
			__( 'Hello! I\'m your AI assistant. How can I help you today?', 'mcp-ai-wpoos-pro' ),
			$space_name,
			$space_type,
			$sender_name
		);

		if ( '' === $welcome_message ) {
			return rest_ensure_response( $this->empty_response() );
		}

		$job_args = array(
			array(
				'space_name'    => $space_name,
				'message_text'  => $welcome_message,
				'connection_id' => $connection_id,
			),
		);

		wp_schedule_single_event( time() + 1, 'wp_mcp_ai_google_chat_send_welcome_message', $job_args );
		spawn_cron();

		WP_MCP_AI_Logger::log_event(
			'google_chat_added_to_space',
			'Bot added to Google Chat space; welcome message scheduled.',
			array(
				'space_name' => $space_name,
				'space_type' => $space_type,
			)
		);

		return rest_ensure_response( $this->empty_response() );
	}

	/**
	 * Handle an APP_COMMAND event (slash command configured in Google Cloud Console).
	 *
	 * Google Chat sends APP_COMMAND instead of MESSAGE when a user invokes a
	 * configured slash command. The text argument entered after the command name
	 * is available in appCommandPayload.commandText. When no argument text is
	 * provided (e.g. the user typed just "/help"), a generic prompt is used so
	 * the AI still returns a useful reply.
	 *
	 * @since 1.0.0
	 *
	 * @param array           $payload Normalised Google Chat event payload.
	 * @param WP_REST_Request $request Original REST request (used to read connection_id param).
	 * @return WP_REST_Response Empty acknowledgement response.
	 */
	protected function handle_app_command( array $payload, WP_REST_Request $request ) {
		$space_name  = isset( $payload['space']['name'] ) ? sanitize_text_field( $payload['space']['name'] ) : '';
		$space_type  = $this->get_space_type( $payload );
		$sender_name = isset( $payload['user']['name'] ) ? sanitize_text_field( $payload['user']['name'] ) : '';

		if ( '' === $space_name ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat webhook: APP_COMMAND missing space name.' );
			return rest_ensure_response( $this->empty_response() );
		}

		$message_text = $this->extract_app_command_text( $payload );

		// Resolve connection: prefer the connection_id from the URL route.
		$url_connection_id = $request->get_param( 'connection_id' );
		if ( ! empty( $url_connection_id ) ) {
			if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
			}
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( sanitize_key( $url_connection_id ) );
			if ( ! $connection || empty( $connection['enabled'] ) ) {
				$connection = null;
			}
		} else {
			$connection = $this->get_active_google_chat_connection( $space_name );
		}

		if ( ! $connection ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat webhook: APP_COMMAND — no active connection found.' );
			return rest_ensure_response( $this->empty_response() );
		}

		$assigned_assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_values( array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) ) )
			: array();

		$automation_rules = get_option( 'wp_mcp_ai_chat_channels_automation_rules', array() );
		if ( empty( $assigned_assistant_ids ) && ! empty( $automation_rules['default_assistant_id'] ) ) {
			$assigned_assistant_ids = array( absint( $automation_rules['default_assistant_id'] ) );
		}

		// Final fallback: use any published assistant so slash commands always get a reply.
		if ( empty( $assigned_assistant_ids ) ) {
			$any_id = $this->get_any_assistant_id();
			if ( $any_id ) {
				$assigned_assistant_ids = array( $any_id );
			}
		}

		if ( empty( $assigned_assistant_ids ) ) {
			return rest_ensure_response( $this->empty_response() );
		}

		$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';

		if ( '' === $connection_id ) {
			return rest_ensure_response( $this->empty_response() );
		}

		$job_args = array(
			array(
				'assistant_id'  => $assigned_assistant_ids[0],
				'message_text'  => $message_text,
				'space_name'    => $space_name,
				'sender_name'   => $sender_name,
				'connection_id' => $connection_id,
				'thread_name'   => '',
			),
		);

		wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
		spawn_cron();

		WP_MCP_AI_Logger::log_event(
			'google_chat_app_command',
			'Google Chat APP_COMMAND received; AI reply scheduled.',
			array(
				'space_name'  => $space_name,
				'space_type'  => $space_type,
				'sender_name' => $sender_name,
			)
		);

		return rest_ensure_response( $this->empty_response() );
	}

	/**
	 * Cron callback: generate an AI reply and post it to the Google Chat space.
	 *
	 * Implements per-user conversation history following the same pattern as the
	 * WhatsApp auto-reply handler, respecting the global max_history_messages
	 * setting and the wp_mcp_ai_google_chat_max_history_messages filter.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Job arguments set by handle_webhook().
	 */
	public function handle_google_chat_reply_job( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		$assistant_id  = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;
		$message_text  = isset( $args['message_text'] ) ? (string) $args['message_text'] : '';
		$space_name    = isset( $args['space_name'] ) ? sanitize_text_field( (string) $args['space_name'] ) : '';
		$sender_name   = isset( $args['sender_name'] ) ? sanitize_text_field( (string) $args['sender_name'] ) : '';
		$connection_id = isset( $args['connection_id'] ) ? sanitize_key( $args['connection_id'] ) : '';
		$thread_name   = isset( $args['thread_name'] ) ? sanitize_text_field( (string) $args['thread_name'] ) : '';

		if ( ! $assistant_id || '' === $message_text || '' === $space_name || '' === $connection_id ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$has_reply_webhook = $connection && ! empty( $connection['reply_webhook_url'] )
			&& preg_match( self::WEBHOOK_URL_PATTERN, $connection['reply_webhook_url'] );

		$has_credentials = $connection && (
			! empty( $connection['api_key'] ) ||
			( ! empty( $connection['client_id'] ) && ! empty( $connection['client_secret'] ) && ! empty( $connection['refresh_token'] ) )
		);

		if ( ! $has_credentials && ! $has_reply_webhook ) {
			WP_MCP_AI_Logger::log_error(
				'Google Chat AI reply: connection not found or access token missing.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		// Obtain access token only when OAuth/Service Account credentials are available.
		$access_token = '';
		if ( $has_credentials ) {
			$access_token = $this->get_connection_access_token( $connection, $connection_id, 'Google Chat AI reply' );
		}

		if ( '' === $access_token && ! $has_reply_webhook ) {
			return;
		}

		// --- Per-user conversation history (mirrors WhatsApp auto-reply pattern) ---
		$history_key = $this->get_conversation_history_key( $sender_name, $space_name, $connection_id );
		$history     = get_transient( $history_key );
		$history     = is_array( $history ) ? $history : array();

		$max_history = 8;
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings    = WP_MCP_AI_Admin_Settings::get_settings();
			$max_history = isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : $max_history;
		}

		/**
		 * Filters the maximum number of messages kept in a Google Chat conversation history.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $max_history Maximum message count.
		 * @param array $args        Current job arguments.
		 */
		$max_history = (int) apply_filters( 'wp_mcp_ai_google_chat_max_history_messages', $max_history, $args );
		$max_history = max( 1, $max_history );

		if ( count( $history ) >= $max_history ) {
			$history = array_slice( $history, -( $max_history - 1 ) );
		}

		$messages = array_merge(
			$history,
			array(
				array(
					'role'    => 'user',
					'content' => $message_text,
				),
			)
		);
		// --- End conversation history ---

		// Run the get_vector_store tool when the assistant uses OpenAI with a vector store.
		// Prepend a system message so the AI is aware of the available knowledge store.
		if ( function_exists( 'wp_mcp_ai_run_get_vector_store_for_channel' ) ) {
			$vs_result = wp_mcp_ai_run_get_vector_store_for_channel( $assistant_id );
			if ( is_array( $vs_result ) && ! empty( $vs_result['data'] ) ) {
				$vs_data    = $vs_result['data'];
				$file_count = isset( $vs_data['file_counts']['completed'] ) ? (int) $vs_data['file_counts']['completed'] : 0;
				$vs_name    = ( isset( $vs_data['name'] ) && '' !== $vs_data['name'] ) ? $vs_data['name'] : 'Knowledge Base';
				$vs_status  = isset( $vs_data['status'] ) ? $vs_data['status'] : 'unknown';
				$messages   = array_merge(
					array(
						array(
							'role'    => 'system',
							/* translators: 1: vector store name, 2: status, 3: indexed file count */
							'content' => sprintf(
								__( 'Vector store available: "%1$s" (Status: %2$s, Indexed files: %3$d). You may reference this knowledge base when answering questions.', 'mcp-ai-wpoos' ),
								$vs_name,
								$vs_status,
								$file_count
							),
						),
					),
					$messages
				);
			}
		}

		// Call the internal chat REST endpoint.
		$rest_request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$rest_request->set_body_params(
			array(
				'assistant_id' => $assistant_id,
				'messages'     => $messages,
				'stream'       => false,
			)
		);

		$original_user_id = get_current_user_id();
		$admin_users      = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		if ( ! empty( $admin_users ) ) {
			wp_set_current_user( $admin_users[0] );
			$rest_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		} else {
			WP_MCP_AI_Logger::log_error(
				'Google Chat AI reply: no administrator user found; internal chat request may fail.',
				array( 'assistant_id' => $assistant_id )
			);
		}

		$response = rest_do_request( $rest_request );
		wp_set_current_user( $original_user_id );

		if ( $response->is_error() ) {
			WP_MCP_AI_Logger::log_error(
				'Google Chat AI reply: internal chat request failed.',
				array( 'assistant_id' => $assistant_id )
			);
			return;
		}

		$content = $this->extract_content_from_chat_response( $response->get_data() );

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat AI reply: empty content from assistant.' );
			return;
		}

		// Post the reply via Google Chat API or incoming webhook URL.
		// When the original message belongs to a thread, reply in that thread so the
		// response appears inline rather than as a new top-level message in the space.
		// The messageReplyOption=REPLY_MESSAGE_FALLBACK_TO_NEW_THREAD query parameter
		// instructs the API to create a new thread when the provided thread no longer
		// exists (e.g. race conditions or deleted threads).
		//
		// Priority: OAuth/Service Account API → incoming webhook URL (fallback).
		// Incoming webhooks (https://developers.google.com/workspace/chat/quickstart/webhooks)
		// do not support threading, so thread_name is ignored on that path.

		if ( '' !== $access_token ) {
			// --- OAuth / Service Account path ---
			$endpoint = self::CHAT_API_BASE . '/' . $space_name . '/messages';

			if ( '' !== $thread_name ) {
				$endpoint = add_query_arg( 'messageReplyOption', 'REPLY_MESSAGE_FALLBACK_TO_NEW_THREAD', $endpoint );
			}

			$payload = array(
				'text' => $content,
			);

			if ( '' !== $thread_name ) {
				$payload['thread'] = array( 'name' => $thread_name );
			}

			$body = wp_json_encode( $payload );

			if ( false === $body ) {
				WP_MCP_AI_Logger::log_error( 'Google Chat AI reply: failed to JSON-encode payload.' );
				return;
			}

			WP_MCP_AI_Logger::log_event(
				'google_chat_ai_reply_sending',
				'Sending Google Chat AI reply.',
				array(
					'assistant_id' => $assistant_id,
					'space_name'   => $space_name,
					'thread_name'  => $thread_name,
				)
			);

			$result = wp_remote_post(
				$endpoint,
				array(
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $access_token,
					),
					'timeout' => 20,
					'body'    => $body,
				)
			);
		} else {
			// --- Incoming webhook URL path (no OAuth needed) ---
			$webhook_url = $connection['reply_webhook_url'];

			$body = wp_json_encode( array( 'text' => $content ) );

			if ( false === $body ) {
				WP_MCP_AI_Logger::log_error( 'Google Chat AI reply: failed to JSON-encode webhook payload.' );
				return;
			}

			WP_MCP_AI_Logger::log_event(
				'google_chat_ai_reply_sending',
				'Sending Google Chat AI reply via incoming webhook URL.',
				array(
					'assistant_id' => $assistant_id,
					'space_name'   => $space_name,
				)
			);

			$result = wp_remote_post(
				$webhook_url,
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'timeout' => 20,
					'body'    => $body,
				)
			);
		}

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Google Chat AI reply: HTTP request to Chat API failed.',
				array( 'error' => $result->get_error_message() )
			);
			return;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $result );

		if ( 200 !== $http_code ) {
			WP_MCP_AI_Logger::log_error(
				'Google Chat AI reply: Chat API returned non-200 status.',
				array(
					'http_code'  => $http_code,
					'space_name' => $space_name,
				)
			);
			return;
		}

		// Persist updated conversation history.
		$history[] = array(
			'role'    => 'user',
			'content' => $message_text,
		);
		$history[] = array(
			'role'    => 'assistant',
			'content' => $content,
		);
		if ( count( $history ) > $max_history ) {
			$history = array_slice( $history, -$max_history );
		}
		set_transient( $history_key, $history, self::CONVERSATION_HISTORY_TTL );

		WP_MCP_AI_Logger::log_event(
			'google_chat_ai_reply_sent',
			'Google Chat AI reply sent successfully.',
			array(
				'assistant_id' => $assistant_id,
				'space_name'   => $space_name,
			)
		);

		// Persist the outbound AI reply to the Channel Messages CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'google_chat',
					'channel_contact_id' => $sender_name,
					'direction'          => 'outbound',
					'message_type'       => 'text',
					'content'            => $content,
					'status'             => 'sent',
					'connection_id'      => $connection_id,
					'phone_number_id'    => $space_name,
					'timestamp'          => time(),
					'reply_sent'         => 1,
					'assigned_agent'     => (string) $assistant_id,
				)
			);
		}

		// Touch the contact record to update last_message_at.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			$gc_contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create( 'google_chat', $sender_name );
			if ( $gc_contact_row_id ) {
				WP_MCP_AI_Channel_Contacts_CCT::touch( $gc_contact_row_id );
			}
		}
	}

	/**
	 * Cron callback: send a welcome message when the bot is added to a space.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Job arguments set by handle_added_to_space().
	 */
	public function handle_welcome_message_job( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		$space_name    = isset( $args['space_name'] ) ? sanitize_text_field( (string) $args['space_name'] ) : '';
		$message_text  = isset( $args['message_text'] ) ? (string) $args['message_text'] : '';
		$connection_id = isset( $args['connection_id'] ) ? sanitize_key( $args['connection_id'] ) : '';

		if ( '' === $space_name || '' === $message_text || '' === $connection_id ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		$has_credentials = $connection && (
			! empty( $connection['api_key'] ) ||
			( ! empty( $connection['client_id'] ) && ! empty( $connection['client_secret'] ) && ! empty( $connection['refresh_token'] ) )
		);

		if ( ! $has_credentials ) {
			WP_MCP_AI_Logger::log_error(
				'Google Chat welcome message: connection not found or access token missing.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		$access_token = $this->get_connection_access_token( $connection, $connection_id, 'Google Chat welcome message' );

		if ( '' === $access_token ) {
			return;
		}

		$endpoint = self::CHAT_API_BASE . '/' . $space_name . '/messages';

		$payload = array(
			'text' => $message_text,
		);

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return;
		}

		$result = wp_remote_post(
			$endpoint,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $access_token,
				),
				'timeout' => 20,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Google Chat welcome message: HTTP request to Chat API failed.',
				array( 'error' => $result->get_error_message() )
			);
			return;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $result );

		if ( 200 !== $http_code ) {
			WP_MCP_AI_Logger::log_error(
				'Google Chat welcome message: Chat API returned non-200 status.',
				array(
					'http_code'  => $http_code,
					'space_name' => $space_name,
				)
			);
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'google_chat_welcome_message_sent',
			'Google Chat welcome message sent successfully.',
			array( 'space_name' => $space_name )
		);
	}

	/**
	 * Return the transient key for a Google Chat sender/space conversation history.
	 *
	 * The key is hashed to avoid PII in option names and to remain within
	 * WordPress's 172-character transient key limit.
	 *
	 * @since 1.0.0
	 *
	 * @param string $sender_name   Google Chat sender resource name (e.g. users/12345).
	 * @param string $space_name    Google Chat space resource name (e.g. spaces/AAAA).
	 * @param string $connection_id Remote connection ID.
	 * @return string Transient key.
	 */
	protected function get_conversation_history_key( $sender_name, $space_name, $connection_id ) {
		return 'wp_mcp_ai_gc_conv_' . md5( $sender_name . '_' . $space_name . '_' . $connection_id );
	}

	/**
	 * Check whether a Google Chat message has already been processed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message_id Google Chat message resource name.
	 * @return bool True if already processed.
	 */
	protected function is_duplicate_message( $message_id ) {
		return (bool) get_transient( 'wp_mcp_ai_gc_dedup_' . md5( $message_id ) );
	}

	/**
	 * Normalize a Google Chat event payload to the standard format.
	 *
	 * Google Chat delivers events in two formats depending on how the app
	 * is registered:
	 *
	 *  1. Direct Chat app (HTTP endpoint via Google Cloud Console):
	 *       {"type":"MESSAGE","message":{...},"space":{...},"user":{...}}
	 *
	 *  2. Google Workspace Add-ons framework (registered via Workspace Add-ons):
	 *       {"type":"GOOGLE_CHAT","google":{"chat":{"type":"MESSAGE","message":{...},...}}}
	 *
	 * When the Workspace Add-ons wrapper is detected the inner `google.chat`
	 * object is returned so that all downstream logic handles both formats
	 * identically.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Raw event payload from Google Chat.
	 * @return array Normalised event payload.
	 */
	protected function normalize_payload( array $payload ) {
		if (
			isset( $payload['type'] ) && 'GOOGLE_CHAT' === $payload['type'] &&
			isset( $payload['google']['chat'] ) && is_array( $payload['google']['chat'] )
		) {
			return $payload['google']['chat'];
		}

		return $payload;
	}

	/**
	 * Extract and normalise the space type from a Google Chat event payload.
	 *
	 * Google Chat is migrating from the deprecated `space.type` field (values:
	 * DM, ROOM) to the newer `space.spaceType` field (values: DIRECT_MESSAGE,
	 * SPACE, GROUP_CHAT). This helper reads `spaceType` first and falls back
	 * to the legacy `type` field, mapping old values to their canonical
	 * equivalents so downstream logic only needs to handle the modern names.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Google Chat event payload (already normalised).
	 * @return string Normalised space type (e.g. DIRECT_MESSAGE, SPACE, GROUP_CHAT).
	 */
	protected function get_space_type( array $payload ) {
		// Prefer the current spaceType field (introduced alongside Chat API v1 deprecations).
		if ( ! empty( $payload['space']['spaceType'] ) ) {
			return sanitize_text_field( $payload['space']['spaceType'] );
		}

		// Map deprecated type field values to their modern equivalents.
		$legacy_type = isset( $payload['space']['type'] ) ? sanitize_text_field( $payload['space']['type'] ) : '';

		$type_map = array(
			'DM'   => 'DIRECT_MESSAGE',
			'ROOM' => 'SPACE',
		);

		return isset( $type_map[ $legacy_type ] ) ? $type_map[ $legacy_type ] : $legacy_type;
	}

	/**
	 * Extract the plain-text message from a Google Chat webhook payload.
	 *
	 * Google Chat provides the text in `message.text` (plain text) or
	 * `message.argumentText` (text with the bot mention stripped). This
	 * helper prefers `argumentText` when present to avoid echoing the
	 * bot @-mention back to the assistant.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Google Chat event payload.
	 * @return string Plain-text message or empty string.
	 */
	protected function extract_message_text( array $payload ) {
		// argumentText strips the bot @-mention (populated when bot is mentioned in a space).
		if ( isset( $payload['message']['argumentText'] ) && '' !== trim( $payload['message']['argumentText'] ) ) {
			return sanitize_textarea_field( trim( $payload['message']['argumentText'] ) );
		}

		if ( isset( $payload['message']['text'] ) && '' !== trim( $payload['message']['text'] ) ) {
			return sanitize_textarea_field( trim( $payload['message']['text'] ) );
		}

		return '';
	}

	/**
	 * Extract the plain-text content from a Google Chat APP_COMMAND event payload.
	 *
	 * For slash commands, Google Chat populates appCommandPayload.commandText with
	 * the text the user typed after the command name. When the command is invoked
	 * with no argument (e.g. "/help" with nothing after it) commandText is empty;
	 * in that case a generic prompt is returned so the AI still provides a reply
	 * instead of treating it as a no-op.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Normalised Google Chat APP_COMMAND event payload.
	 * @return string Non-empty plain-text string to send to the AI.
	 */
	protected function extract_app_command_text( array $payload ) {
		$command_text = isset( $payload['appCommandPayload']['commandText'] )
			? trim( $payload['appCommandPayload']['commandText'] )
			: '';

		if ( '' !== $command_text ) {
			return sanitize_textarea_field( $command_text );
		}

		// No argument text was supplied — use a generic prompt so the AI responds.
		/* translators: Fallback prompt sent to the AI when a slash command is invoked with no argument text. */
		return __( 'What can you do?', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Find the best active Google Chat connection for the given space.
	 *
	 * When a space-specific connection is available (i.e. the connection's
	 * `google_chat_space` field matches $space_name) it is preferred over a
	 * generic connection. Falls back to the first enabled connection that has
	 * NO specific space configured (a "generic" connection). Connections that
	 * are space-specific for a DIFFERENT space are never used as fallback — using
	 * the wrong credentials/assistant for an unrelated space would route messages
	 * incorrectly and could expose one space's data to another.
	 *
	 * @since 1.0.0
	 *
	 * @param string $space_name Optional Google Chat space resource name for per-space routing.
	 * @return array|null Connection array or null if none found.
	 */
	protected function get_active_google_chat_connection( $space_name = '' ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( ! is_array( $connections ) ) {
			return null;
		}

		$fallback = null;

		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'google_chat' !== $connection['connection_type'] ) {
				continue;
			}

			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			// Check for a space-specific match first.
			if ( '' !== $space_name && ! empty( $connection['google_chat_space'] ) ) {
				$conn_space = sanitize_text_field( $connection['google_chat_space'] );
				if ( $conn_space === $space_name ) {
					return $connection;
				}
				// This connection is space-specific for a different space — skip it
				// entirely; it must not become the fallback for an unrelated space.
				continue;
			}

			// Keep the first generic (no specific space) connection as fallback.
			if ( null === $fallback && empty( $connection['google_chat_space'] ) ) {
				$fallback = $connection;
			}
		}

		return $fallback;
	}

	/**
	 * Extract the plain-text reply from the internal /mcp-ai/v1/chat response.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data Response data from the internal chat endpoint.
	 * @return string Plain-text content or empty string.
	 */
	protected function extract_content_from_chat_response( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		$choices = isset( $data['data']['choices'] ) ? $data['data']['choices']
			: ( isset( $data['choices'] ) ? $data['choices'] : array() );

		if ( ! is_array( $choices ) || empty( $choices ) ) {
			return '';
		}

		$first = reset( $choices );

		if ( isset( $first['message']['content'] ) && is_string( $first['message']['content'] ) ) {
			return trim( $first['message']['content'] );
		}

		return '';
	}

	/**
	 * Return an empty Google Chat-compatible response body.
	 *
	 * Google Chat accepts an empty JSON object as a valid acknowledgement
	 * when the bot opts to reply asynchronously.
	 *
	 * @since 1.0.0
	 *
	 * @return stdClass Empty JSON object response.
	 */
	protected function empty_response() {
		return new stdClass();
	}

	/**
	 * Check whether any assigned assistant is mentioned by @slug in the message text.
	 *
	 * Available as a hook target for integrations that want custom mention routing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message_text  The incoming message text.
	 * @param int[]  $assistant_ids Array of assigned assistant post IDs.
	 * @return bool True if any assistant slug is found as @slug in the text.
	 */
	protected function message_mentions_assistant( $message_text, array $assistant_ids ) {
		if ( '' === $message_text ) {
			return false;
		}
		foreach ( $assistant_ids as $assistant_id ) {
			$slug = get_post_field( 'post_name', absint( $assistant_id ) );
			if ( is_string( $slug ) && '' !== $slug && preg_match( '/@' . preg_quote( $slug, '/' ) . '(?:[^a-zA-Z0-9-]|$)/i', $message_text ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Retrieve an OAuth 2.0 access token from a connection's stored credentials.
	 *
	 * Supports Service Account JSON keys (automatically exchanges for a fresh access
	 * token), OAuth 2.0 refresh tokens (exchanges for a new access token using the
	 * stored client_id and client_secret), and legacy raw access tokens in api_key.
	 *
	 * @param array  $connection    Connection configuration array.
	 * @param string $connection_id Connection ID (used for log context).
	 * @param string $log_context   Human-readable context string for log messages.
	 * @return string Access token, or empty string on failure.
	 */
	protected function get_connection_access_token( array $connection, $connection_id, $log_context ) {
		// Try OAuth refresh token flow first if client_id, client_secret, and refresh_token are all present.
		$client_id         = isset( $connection['client_id'] ) ? (string) $connection['client_id'] : '';
		$raw_client_secret = isset( $connection['client_secret'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] ) : '';
		$raw_refresh_token = isset( $connection['refresh_token'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['refresh_token'] ) : '';

		if ( '' !== $client_id && '' !== $raw_client_secret && '' !== $raw_refresh_token ) {
			$token = $this->get_access_token_from_refresh_token( $client_id, $raw_client_secret, $raw_refresh_token, $connection_id, $log_context );
			if ( '' !== $token ) {
				return $token;
			}
		}

		$raw_key = isset( $connection['api_key'] ) ? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] ) : '';

		if ( '' === $raw_key ) {
			WP_MCP_AI_Logger::log_error(
				$log_context . ': no valid credentials found (no OAuth refresh token or service account key).',
				array( 'connection_id' => $connection_id )
			);
			return '';
		}

		// Detect Service Account JSON key (starts with '{').
		if ( strlen( $raw_key ) > 0 && '{' === $raw_key[0] ) {
			$token = WP_MCP_AI_Pro_Google_Service_Account::get_access_token_from_key( $raw_key, self::CHAT_BOT_SCOPE );

			if ( is_wp_error( $token ) ) {
				WP_MCP_AI_Logger::log_error(
					$log_context . ': failed to obtain access token from service account key.',
					array(
						'connection_id' => $connection_id,
						'error'         => $token->get_error_message(),
					)
				);
				return '';
			}

			return (string) $token;
		}

		// Legacy: raw access token stored directly.
		return $raw_key;
	}

	/**
	 * Exchange an OAuth 2.0 refresh token for a fresh access token.
	 *
	 * @since 1.0.0
	 *
	 * @param string $client_id     OAuth client ID.
	 * @param string $client_secret OAuth client secret (decrypted).
	 * @param string $refresh_token OAuth refresh token (decrypted).
	 * @param string $connection_id Connection ID (used for log context).
	 * @param string $log_context   Human-readable context string for log messages.
	 * @return string Fresh access token, or empty string on failure.
	 */
	protected function get_access_token_from_refresh_token( $client_id, $client_secret, $refresh_token, $connection_id, $log_context ) {
		$cache_key    = 'wp_mcp_ai_gc_oauth_token_' . md5( $client_id . '|' . $refresh_token );
		$cached_token = get_transient( $cache_key );

		if ( is_string( $cached_token ) && '' !== $cached_token ) {
			return $cached_token;
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				$log_context . ': failed to exchange refresh token for access token.',
				array(
					'connection_id' => $connection_id,
					'error'         => $response->get_error_message(),
				)
			);
			return '';
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				$log_context . ': refresh token exchange returned non-200 status.',
				array(
					'connection_id' => $connection_id,
					'status'        => wp_remote_retrieve_response_code( $response ),
				)
			);
			return '';
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) || empty( $decoded['access_token'] ) ) {
			WP_MCP_AI_Logger::log_error(
				$log_context . ': invalid response from refresh token exchange.',
				array( 'connection_id' => $connection_id )
			);
			return '';
		}

		$access_token = (string) $decoded['access_token'];
		$expires_in   = isset( $decoded['expires_in'] ) ? max( 60, (int) $decoded['expires_in'] - 60 ) : 3540;

		// Cache access token for slightly less than its expiry time.
		set_transient( $cache_key, $access_token, $expires_in );

		return $access_token;
	}

	/**
	 * Handle the wp_mcp_ai_chat_channels_send_reply filter for Google Chat.
	 *
	 * Sends a manual reply from the admin inbox to the originating Google Chat
	 * space. Called via the `wp_mcp_ai_chat_channels_send_reply` filter fired
	 * by WP_MCP_AI_Chat_Channels_REST_Controller::send_reply().
	 *
	 * @since 1.0.0
	 *
	 * @param bool|WP_Error $result             Current filter result.
	 * @param string        $channel            Channel slug.
	 * @param string        $channel_contact_id Platform-side contact ID (sender resource name).
	 * @param string        $message_text       Message text to send.
	 * @param string        $connection_id      Connection ID.
	 * @param array         $contact            Full contact row from the contacts CCT.
	 * @return bool|WP_Error True on success, WP_Error on failure, or $result unchanged for other channels.
	 */
	public function handle_channel_send_reply( $result, $channel, $channel_contact_id, $message_text, $connection_id, $contact ) {
		if ( 'google_chat' !== $channel ) {
			return $result;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		// Try to resolve the explicit connection first; fall back to any active Google Chat connection.
		$connection = '' !== $connection_id ? WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id ) : null;

		if ( ! $connection ) {
			$connection = $this->get_active_google_chat_connection();
		}

		if ( ! $connection ) {
			return new WP_Error(
				'google_chat_no_connection',
				__( 'No active Google Chat connection found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$resolved_connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : $connection_id;

		// Determine the target space for this contact.
		$space_name = $this->resolve_google_chat_space_for_contact( $channel_contact_id, $connection );

		if ( '' === $space_name ) {
			return new WP_Error(
				'google_chat_no_space',
				__( 'Unable to determine the Google Chat space for this contact. Ensure messages have been received and a space is configured on the connection.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 422 )
			);
		}

		$access_token = $this->get_connection_access_token( $connection, $resolved_connection_id, 'Google Chat inbox reply' );

		// Fall back to the incoming webhook URL when no OAuth/service-account credentials
		// are available. This mirrors the AI auto-reply path in handle_google_chat_reply_job()
		// and makes manual inbox replies work even without full OAuth setup.
		$has_reply_webhook = ! empty( $connection['reply_webhook_url'] )
			&& preg_match( self::WEBHOOK_URL_PATTERN, $connection['reply_webhook_url'] );

		if ( '' === $access_token && ! $has_reply_webhook ) {
			return new WP_Error(
				'google_chat_token_error',
				__( 'Failed to obtain a Google Chat access token. Check the connection credentials or configure an Incoming Webhook URL.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$body = wp_json_encode( array( 'text' => $message_text ) );

		if ( false === $body ) {
			return new WP_Error(
				'google_chat_encode_error',
				__( 'Failed to encode the Google Chat message payload.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		if ( '' !== $access_token ) {
			// --- OAuth / Service Account path ---
			$endpoint = self::CHAT_API_BASE . '/' . $space_name . '/messages';
			$response = wp_remote_post(
				$endpoint,
				array(
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $access_token,
					),
					'timeout' => 20,
					'body'    => $body,
				)
			);
		} else {
			// --- Incoming webhook URL path (no OAuth needed) ---
			$response = wp_remote_post(
				$connection['reply_webhook_url'],
				array(
					'headers' => array( 'Content-Type' => 'application/json' ),
					'timeout' => 20,
					'body'    => $body,
				)
			);
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'google_chat_send_failed',
				$response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $http_code ) {
			$body_data = json_decode( wp_remote_retrieve_body( $response ), true );
			$error_msg = isset( $body_data['error']['message'] )
				? $body_data['error']['message']
				: __( 'Google Chat API error.', 'mcp-ai-wpoos-pro' );
			return new WP_Error( 'google_chat_api_error', $error_msg, array( 'status' => 502 ) );
		}

		WP_MCP_AI_Logger::log_event(
			'google_chat_inbox_reply_sent',
			'Google Chat inbox reply sent successfully.',
			array(
				'space_name'         => $space_name,
				'channel_contact_id' => $channel_contact_id,
			)
		);

		return true;
	}

	/**
	 * Resolve the Google Chat space resource name for a given contact.
	 *
	 * Queries the Channel Messages CCT for the most recent message from the
	 * contact (phone_number_id stores the space name for Google Chat messages).
	 * Falls back to the connection's configured google_chat_space.
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel_contact_id Sender resource name (e.g. users/12345).
	 * @param array  $connection         Google Chat connection array.
	 * @return string Space resource name (e.g. spaces/AAAA) or empty string.
	 */
	protected function resolve_google_chat_space_for_contact( $channel_contact_id, array $connection ) {
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) && WP_MCP_AI_Channel_Messages_CCT::table_exists() ) {
			global $wpdb;
			$messages_table = WP_MCP_AI_Channel_Messages_CCT::get_table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$space_name = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT phone_number_id FROM {$messages_table} WHERE channel = %s AND channel_contact_id = %s AND phone_number_id != '' ORDER BY message_timestamp DESC LIMIT 1",
					'google_chat',
					$channel_contact_id
				)
			);

			if ( ! empty( $space_name ) ) {
				return sanitize_text_field( $space_name );
			}
		}

		// Fall back to the connection's configured space.
		if ( ! empty( $connection['google_chat_space'] ) ) {
			return sanitize_text_field( $connection['google_chat_space'] );
		}

		return '';
	}

	/**
	 * Return the ID of any published AI assistant as a last-resort fallback.
	 *
	 * When no assistant is explicitly assigned to a connection and no global
	 * default_assistant_id is configured in the automation rules, this helper
	 * queries for the first published mcp_ai_assistant post so that incoming
	 * messages always receive a reply rather than being silently dropped.
	 *
	 * @since 1.0.0
	 *
	 * @return int Assistant post ID, or 0 if none exist.
	 */
	protected function get_any_assistant_id() {
		$posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'numberposts'    => 1,
				'fields'         => 'ids',
				'orderby'        => 'date',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}
}

new WP_MCP_AI_Google_Chat_Webhook_Controller();
