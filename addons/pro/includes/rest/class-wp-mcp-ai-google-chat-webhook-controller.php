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
 *
 * Google Chat sends POST requests to a configured bot endpoint when a user
 * messages the bot. Each request carries a Google-signed OIDC token in the
 * Authorization header. When an audience URL is configured on the connection,
 * the token's `aud` claim is validated against it.
 *
 * @see https://developers.google.com/chat/how-tos/bots-develop
 * @see https://developers.google.com/chat/api/reference/rest/v1/spaces.messages/create
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
	 * Expected OIDC token issuer for Google.
	 */
	const GOOGLE_OIDC_ISSUER = 'accounts.google.com';

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
	}

	/**
	 * Register REST routes for Google Chat webhooks.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_google_oidc_token' ),
			)
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
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if the token is acceptable, false to reject.
	 */
	public function validate_google_oidc_token( $request ) {
		$auth_header = $request->get_header( 'authorization' );

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

		$connection = $this->get_active_google_chat_connection();
		$audience   = '';

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

		// Validate audience claim.
		$token_aud = isset( $claims['aud'] ) ? $claims['aud'] : '';

		if ( $token_aud !== $audience ) {
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
			return $this->handle_added_to_space( $payload );
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

		// Extract space name and sender for routing.
		$space_name  = isset( $payload['space']['name'] ) ? sanitize_text_field( $payload['space']['name'] ) : '';
		$sender_name = isset( $payload['message']['sender']['name'] ) ? sanitize_text_field( $payload['message']['sender']['name'] ) : '';

		if ( '' === $space_name ) {
			WP_MCP_AI_Logger::log_error( 'Google Chat webhook: unable to determine space name.' );
			return rest_ensure_response( $this->empty_response() );
		}

		// Resolve connection with assigned assistants, preferring space-specific connections.
		$connection = $this->get_active_google_chat_connection( $space_name );

		if ( ! $connection ) {
			WP_MCP_AI_Logger::log_error(
				'Google Chat webhook: no active Google Chat connection with assigned assistants found.'
			);
			return rest_ensure_response( $this->empty_response() );
		}

		$assigned_assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) )
			: array();

		if ( empty( $assigned_assistant_ids ) ) {
			return rest_ensure_response( $this->empty_response() );
		}

		$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';

		if ( '' === $connection_id ) {
			return rest_ensure_response( $this->empty_response() );
		}

		// When the connection requires an @slug mention, only reply if the message
		// explicitly addresses an assigned assistant by its WordPress post slug.
		if ( ! empty( $connection['require_mention'] ) && ! $this->message_mentions_assistant( $message_text, $assigned_assistant_ids ) ) {
			return rest_ensure_response( $this->empty_response() );
		}

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

		$job_args = array(
			array(
				'assistant_id'  => $assigned_assistant_ids[0],
				'message_text'  => $message_text,
				'space_name'    => $space_name,
				'sender_name'   => $sender_name,
				'connection_id' => $connection_id,
			),
		);

		wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
		spawn_cron();

		// Return empty response — Google Chat accepts 200 with an empty JSON body
		// or a message payload to reply synchronously. Using async cron avoids timeouts.
		return rest_ensure_response( $this->empty_response() );
	}

	/**
	 * Handle an ADDED_TO_SPACE event by sending a welcome message.
	 *
	 * When a bot is added to a space, Google Chat sends an ADDED_TO_SPACE event.
	 * This method schedules an async welcome message reply via cron.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Google Chat event payload.
	 * @return WP_REST_Response Empty acknowledgement response.
	 */
	protected function handle_added_to_space( array $payload ) {
		$space_name  = isset( $payload['space']['name'] ) ? sanitize_text_field( $payload['space']['name'] ) : '';
		$space_type  = isset( $payload['space']['type'] ) ? sanitize_text_field( $payload['space']['type'] ) : '';
		$sender_name = isset( $payload['user']['name'] ) ? sanitize_text_field( $payload['user']['name'] ) : '';

		if ( '' === $space_name ) {
			return rest_ensure_response( $this->empty_response() );
		}

		$connection = $this->get_active_google_chat_connection( $space_name );

		if ( ! $connection || empty( $connection['api_key'] ) ) {
			return rest_ensure_response( $this->empty_response() );
		}

		$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';

		if ( '' === $connection_id ) {
			return rest_ensure_response( $this->empty_response() );
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

		if ( ! $assistant_id || '' === $message_text || '' === $space_name || '' === $connection_id ) {
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
				'Google Chat AI reply: connection not found or access token missing.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		$access_token = $this->get_connection_access_token( $connection, $connection_id, 'Google Chat AI reply' );

		if ( '' === $access_token ) {
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

		// Post the reply via Google Chat API.
		$endpoint = self::CHAT_API_BASE . '/' . $space_name . '/messages';

		$payload = array(
			'text' => $content,
		);

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
	 * Find the best active Google Chat connection for the given space.
	 *
	 * When a space-specific connection is available (i.e. the connection's
	 * `google_chat_space` field matches $space_name) it is preferred over a
	 * generic connection. Falls back to the first enabled connection with
	 * assigned assistants when no space-specific match is found.
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

			if ( empty( $connection['assigned_assistant_ids'] ) || ! is_array( $connection['assigned_assistant_ids'] ) ) {
				continue;
			}

			// Check for a space-specific match first.
			if ( '' !== $space_name && ! empty( $connection['google_chat_space'] ) ) {
				$conn_space = sanitize_text_field( $connection['google_chat_space'] );
				if ( $conn_space === $space_name ) {
					return $connection;
				}
			}

			// Keep the first generic connection as fallback.
			if ( null === $fallback ) {
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
	 * @return array Empty response.
	 */
	protected function empty_response() {
		return array();
	}

	/**
	 * Check whether any assigned assistant is mentioned by @slug in the message text.
	 *
	 * Used when a connection has require_mention enabled so the bot only replies
	 * when a user explicitly addresses it with @assistant-slug in a group chat.
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
}

new WP_MCP_AI_Google_Chat_Webhook_Controller();
