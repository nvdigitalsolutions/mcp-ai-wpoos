<?php
/**
 * Telegram Bot API Webhook Controller
 *
 * Handles incoming Telegram bot webhook events with industry-standard security
 * validation. Implements Telegram Bot API best practices:
 * - Optional secret-token header verification (X-Telegram-Bot-Api-Secret-Token)
 * - Per-user conversation history respecting max_history_messages
 * - AI auto-reply via WordPress cron (async, no timeout risk)
 * - Message deduplication via transient cache
 * - Group & supergroup support with @mention detection and reply threading
 * - Channel post handling (channel_post, edited_channel_post)
 * - Bot membership change events (my_chat_member)
 *
 * @see https://core.telegram.org/bots/api#setwebhook
 * @see https://core.telegram.org/bots/api#update
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

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
 * Telegram webhook REST controller.
 */
class WP_MCP_AI_Telegram_Webhook_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks/telegram';

	/**
	 * Tracks the connection_id resolved from the incoming request URL so every
	 * helper called during the request lifecycle targets the correct bot without
	 * requiring connection_id to be threaded through every method signature.
	 *
	 * @var string|null
	 */
	protected $current_connection_id = null;

	/**
	 * Cron hook for dispatching AI replies to incoming Telegram messages.
	 */
	const REPLY_CRON_HOOK = 'wp_mcp_ai_telegram_send_ai_reply';

	/**
	 * Cron hook for processing incoming Telegram media messages (photo, document,
	 * video, audio, voice, animation, video_note). The job downloads the file,
	 * sideloads it to the WordPress media library, sends an immediate metadata
	 * auto-reply, and then schedules the AI reply with full file context.
	 */
	const MEDIA_REPLY_CRON_HOOK = 'wp_mcp_ai_telegram_media_reply';

	/**
	 * TTL in seconds for the deduplication transient used to prevent
	 * double-processing the same update_id.
	 */
	const DEDUP_TRANSIENT_TTL = 60;

	/**
	 * TTL in seconds for per-user conversation history transients (24 hours).
	 */
	const CONVERSATION_HISTORY_TTL = 86400;

	/**
	 * Telegram message text length limit enforced before sending a reply.
	 */
	const MAX_MESSAGE_LENGTH = 4096;

	/**
	 * Default maximum agentic loop iterations for Telegram reply jobs.
	 *
	 * The /mcp-ai/v1/chat endpoint defaults to 1 iteration. Telegram reply jobs
	 * use a higher cap so multi-step tool workflows (e.g. search → analyse →
	 * respond) can complete before the reply is dispatched. This mirrors the
	 * pattern used by the browser chat-client endpoint.
	 */
	const DEFAULT_MAX_AGENTIC_ITERATIONS = 10;

	/**
	 * Maximum number of times a rate-limited (HTTP 429) Telegram reply job will
	 * be retried before giving up. Each retry respects the Retry-After header
	 * returned by the Telegram Bot API (typically 1–60 seconds).
	 */
	const MAX_RATE_LIMIT_RETRIES = 3;

	/**
	 * Minimum number of seconds to wait when rescheduling a rate-limited
	 * (HTTP 429) reply job, regardless of the Retry-After header value.
	 */
	const MIN_RETRY_DELAY = 30;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_telegram_reply_job' ) );
		add_action( self::MEDIA_REPLY_CRON_HOOK, array( $this, 'handle_telegram_media_job' ) );

		// WordPress Application Passwords (WP 5.6+) and JWT auth plugins intercept
		// unauthenticated REST requests and can set a WP_Error in
		// rest_authentication_errors before our permission_callback runs, causing a
		// 403 that Telegram immediately reports as "Wrong response from the webhook:
		// 403 Forbidden". Clear that error for requests to our webhook endpoints so
		// that our own validate_webhook_secret() callback handles authentication.
		// Priority 99999 ensures we run after third-party JWT plugins (commonly
		// 100–999) and WordPress Application Passwords (priority 100).
		add_filter( 'rest_authentication_errors', array( $this, 'allow_telegram_webhook_auth' ), 99999 );

		// Register an admin-ajax.php fallback endpoint for sites where Cloudflare
		// WAF, Bot Fight Mode, or other proxies block POST requests to /wp-json/.
		// Telegram can be configured with the admin-ajax URL instead.
		add_action( 'wp_ajax_nopriv_wp_mcp_ai_telegram_webhook', array( $this, 'handle_ajax_webhook' ) );
		add_action( 'wp_ajax_wp_mcp_ai_telegram_webhook', array( $this, 'handle_ajax_webhook' ) );
	}

	/**
	 * Register REST routes for Telegram webhooks.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Global webhook endpoint (backward-compatible, single-bot setups).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_webhook_secret' ),
			)
		);

		// Per-connection webhook endpoint so multiple Telegram bots can each
		// have a dedicated URL: /mcp-ai/v1/webhooks/telegram/{connection_id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<connection_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_webhook_secret' ),
				'args'                => array(
					'connection_id' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Validate incoming webhook request using the optional secret token.
	 *
	 * Applies two independent security checks (both are filterable):
	 *
	 * 1. **IP range validation** – verifies the request originates from one of
	 *    Telegram's published IP ranges (149.154.160.0/20, 91.108.4.0/22).
	 *    Can be disabled via the `wp_mcp_ai_telegram_ip_validation_enabled`
	 *    filter for sites behind a reverse proxy.
	 *
	 * 2. **Secret-token header verification** – when a secret_token is stored
	 *    on the connection, checks that Telegram's
	 *    X-Telegram-Bot-Api-Secret-Token header matches the stored value.
	 *    When no token is configured, the request is rejected with a 403 error.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if the request passes all configured security checks, WP_Error on failure.
	 */
	public function validate_webhook_secret( $request ) {
		// --- Layer 1: IP range validation -------------------------------------------
		if ( ! $this->is_request_from_telegram( $request ) ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram webhook rejected: request did not originate from a Telegram IP range.',
				array(
					'remote_addr' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
				)
			);
			return false;
		}

		// --- Layer 2: Secret-token header verification ------------------------------
		$connection_id = $request->get_param( 'connection_id' );

		// Verify the requested connection exists before checking the secret.
		// When a per-connection URL is used but the connection cannot be found,
		// log a specific error so the admin can diagnose the mismatch.
		if ( $connection_id ) {
			$connection = $this->get_active_telegram_connection( $connection_id );
			if ( ! $connection ) {
				WP_MCP_AI_Logger::log_error(
					'Telegram webhook rejected: the per-connection URL references a connection that does not exist or is disabled. Verify the connection ID in the webhook URL matches a saved, enabled Telegram connection.',
					array( 'connection_id' => $connection_id )
				);
				return new WP_Error(
					'rest_forbidden',
					__( 'Telegram webhook rejected: connection not found or disabled.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 403 )
				);
			}
		}

		$stored_secret = $this->get_secret_token( $connection_id );

		if ( empty( $stored_secret ) ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram webhook rejected: secret token is not configured. Configure a secret_token in the connection settings and click "Set Webhook" to re-register it with Telegram.',
				array( 'connection_id' => $connection_id ? $connection_id : 'default' )
			);
			return new WP_Error(
				'rest_forbidden',
				__( 'Telegram webhook authentication is not configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		$provided_token = $request->get_header( 'x-telegram-bot-api-secret-token' );

		if ( empty( $provided_token ) ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram webhook rejected: missing X-Telegram-Bot-Api-Secret-Token header. This usually means the webhook was registered without a secret_token. Click "Set Webhook" in the connection settings to re-register with the correct secret.',
				array( 'connection_id' => $connection_id ? $connection_id : 'default' )
			);
			return false;
		}

		if ( ! hash_equals( $stored_secret, $provided_token ) ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram webhook rejected: invalid secret token. Ensure the secret_token configured in your Telegram connection settings matches the token set in BotFather (setWebhook secret_token parameter). Click "Set Webhook" to re-sync.',
				array( 'connection_id' => $connection_id ? $connection_id : 'default' )
			);
			return false;
		}

		return true;
	}

	/**
	 * Allow Telegram webhook requests to reach our permission callback.
	 *
	 * WordPress Application Passwords (WP 5.6+) and third-party JWT auth plugins
	 * listen on the `determine_current_user` filter and set a WP_Error in
	 * `rest_authentication_errors` when they encounter an unauthenticated request.
	 * Because WordPress evaluates that filter before calling our permission_callback,
	 * any such error causes a 401/403 response that Telegram immediately surfaces
	 * as "Wrong response from the webhook: 403 Forbidden" — our
	 * validate_webhook_secret() never even runs.
	 *
	 * For requests targeting our webhook endpoints we clear the authentication error
	 * (return null) so WordPress proceeds to call validate_webhook_secret(), which
	 * is the correct authority on whether the secret token header is valid.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Error|null $error Existing authentication error or null.
	 * @return WP_Error|null Null for our webhook routes; unchanged value for all others.
	 */
	public function allow_telegram_webhook_auth( $error ) {
		// Only intervene when another plugin already set an error — if there is no
		// error we have nothing to clear.
		if ( ! is_wp_error( $error ) ) {
			return $error;
		}

		// Check whether this request targets one of our webhook routes.
		// Use wp_parse_url() to extract only the path component so query strings
		// and fragments cannot interfere with the route match.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		// Match the REST namespace + our rest_base to avoid clearing errors for
		// unrelated routes that happen to contain "webhooks/telegram" elsewhere.
		if ( false !== strpos( $path, '/mcp-ai/v1/' . $this->rest_base ) ) {
			// Clear the error so our permission_callback handles auth instead.
			return null;
		}

		return $error;
	}

	/**
	 * Handle a Telegram webhook event via the WordPress admin-ajax endpoint.
	 *
	 * Provides a Cloudflare-compatible alternative to the REST API webhook URL.
	 * When Cloudflare WAF, Bot Fight Mode, or other proxies block POST requests
	 * to /wp-json/ endpoints, configure Telegram's setWebhook to use the
	 * admin-ajax URL instead.
	 *
	 * Security is identical to the REST endpoint: the X-Telegram-Bot-Api-Secret-Token
	 * header and IP range validation are performed by validate_webhook_secret()
	 * before any event processing occurs.
	 *
	 * AJAX URL format:
	 *   /wp-admin/admin-ajax.php?action=wp_mcp_ai_telegram_webhook
	 *   /wp-admin/admin-ajax.php?action=wp_mcp_ai_telegram_webhook&connection_id={id}
	 *
	 * @since 1.0.0
	 */
	public function handle_ajax_webhook() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- secret token header is the auth mechanism.
		$connection_id = isset( $_GET['connection_id'] ) ? sanitize_key( wp_unslash( $_GET['connection_id'] ) ) : '';

		// Build a synthetic REST request so validate_webhook_secret() and
		// handle_webhook() can be reused without duplicating logic.
		$route        = '/mcp-ai/v1/' . $this->rest_base . ( '' !== $connection_id ? '/' . $connection_id : '' );
		$rest_request = new WP_REST_Request( 'POST', $route );

		// Forward the X-Telegram-Bot-Api-Secret-Token header.
		$secret_header = '';
		if ( ! empty( $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ) ) {
			$secret_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ) );
		}

		if ( '' !== $secret_header ) {
			$rest_request->set_header( 'x-telegram-bot-api-secret-token', $secret_header );
		}

		if ( '' !== $connection_id ) {
			$rest_request->set_param( 'connection_id', $connection_id );
		}

		// Validate the secret token before processing any payload.
		$auth_result = $this->validate_webhook_secret( $rest_request );
		if ( true !== $auth_result ) {
			$status = 403;
			$data   = array(
				'ok'    => false,
				'error' => 'Forbidden',
			);

			if ( is_wp_error( $auth_result ) ) {
				$error_data = $auth_result->get_error_data();
				if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
					$status = (int) $error_data['status'];
				}
				$data['error'] = $auth_result->get_error_message();
			}

			wp_send_json( $data, $status );
			return;
		}

		// Read the raw JSON body sent by Telegram and pass it to the handler.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw_body = file_get_contents( 'php://input' );
		$rest_request->set_header( 'Content-Type', 'application/json' );
		$rest_request->set_body( is_string( $raw_body ) ? $raw_body : '{}' );

		// Process the event via the existing REST handler.
		$response = $this->handle_webhook( $rest_request );
		$data     = $response instanceof WP_REST_Response ? $response->get_data() : array( 'ok' => true );
		$status   = $response instanceof WP_REST_Response ? $response->get_status() : 200;

		wp_send_json( $data, $status );
	}

	/**
	 * Handle incoming Telegram webhook update.
	 *
	 * Supports private messages, group messages, channel posts, and
	 * membership change events (my_chat_member) per Telegram Bot API
	 * best practices.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object (always 200 to prevent Telegram retries).
	 */
	public function handle_webhook( $request ) {
		// Resolve the per-connection ID from the URL so all helper methods in
		// this request lifecycle can target the correct Telegram bot.
		$connection_id_param         = $request->get_param( 'connection_id' );
		$this->current_connection_id = ! empty( $connection_id_param ) ? $connection_id_param : null;

		$payload = $request->get_json_params();

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram webhook received with empty or invalid payload.' );
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$update_id = isset( $payload['update_id'] ) ? absint( $payload['update_id'] ) : 0;

		WP_MCP_AI_Logger::log_event(
			'telegram_webhook_received',
			'Telegram webhook update received.',
			array( 'update_id' => $update_id )
		);

		// Deduplicate: skip updates we have already processed.
		if ( $update_id && $this->is_duplicate_update( $update_id ) ) {
			WP_MCP_AI_Logger::log_event(
				'telegram_webhook_duplicate',
				'Telegram update already processed; skipping.',
				array( 'update_id' => $update_id )
			);
			return rest_ensure_response( array( 'ok' => true ) );
		}

		// Mark as processed.
		if ( $update_id ) {
			set_transient( 'wp_mcp_ai_tg_dedup_' . $update_id, 1, self::DEDUP_TRANSIENT_TTL );
		}

		// Handle text message updates (private, group, supergroup).
		if ( isset( $payload['message'] ) && is_array( $payload['message'] ) ) {
			$this->process_message( $payload['message'] );
		}

		// Handle channel post updates.
		if ( isset( $payload['channel_post'] ) && is_array( $payload['channel_post'] ) ) {
			$this->process_channel_post( $payload['channel_post'] );
		}

		// Handle edited channel posts (re-process like a new post).
		if ( isset( $payload['edited_channel_post'] ) && is_array( $payload['edited_channel_post'] ) ) {
			$this->process_channel_post( $payload['edited_channel_post'], true );
		}

		// Handle bot membership changes (added/removed from groups/channels).
		if ( isset( $payload['my_chat_member'] ) && is_array( $payload['my_chat_member'] ) ) {
			$this->process_membership_update( $payload['my_chat_member'] );
		}

		// Handle inline query updates (user types @botname in any chat).
		if ( isset( $payload['inline_query'] ) && is_array( $payload['inline_query'] ) ) {
			$this->process_inline_query( $payload['inline_query'] );
		}

		// Handle pre-checkout query (Telegram Stars payment validation).
		if ( isset( $payload['pre_checkout_query'] ) && is_array( $payload['pre_checkout_query'] ) ) {
			$this->process_pre_checkout_query( $payload['pre_checkout_query'] );
		}

		// Handle successful payment notification (Telegram Stars).
		if ( isset( $payload['message']['successful_payment'] ) && is_array( $payload['message']['successful_payment'] ) ) {
			$this->process_successful_payment( $payload['message'] );
		}

		// Always return 200 so Telegram does not retry.
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Process a Telegram message object and dispatch an AI reply if applicable.
	 *
	 * Supports private chats, groups, and supergroups. In group contexts the
	 * bot replies to every message by default. When the connection has
	 * require_mention enabled, the bot only replies when explicitly addressed
	 * via @bot_username mention, when the message is a direct reply to one
	 * of the bot's messages, or when an assigned assistant @slug is mentioned.
	 *
	 * @since 1.0.0
	 *
	 * @param array $message Telegram message object.
	 */
	protected function process_message( array $message ) {
		// ── Media detection ─────────────────────────────────────────────────────
		// Inspect the message before the text guard so that photos, documents,
		// videos, audio, and voice messages are processed instead of silently
		// dropped. Industry standard: always extract the highest-resolution photo
		// (last PhotoSize element) and obtain a file_path via getFile.
		$media_info = $this->extract_media_info( $message );
		$has_media  = null !== $media_info;

		if ( empty( $message['text'] ) && ! $has_media ) {
			// Non-text, non-media messages (stickers, contacts, locations, etc.)
			// are not handled.
			return;
		}

		// For media messages without a text body, use the caption (if any) as the
		// message text so that all downstream checks – bot-mention detection,
		// automation keyword rules, require_mention gates, etc. – work correctly.
		$text      = ! empty( $message['text'] ) ? (string) $message['text'] : ( $has_media ? (string) $media_info['caption'] : '' );
		$chat_id   = isset( $message['chat']['id'] ) ? (string) $message['chat']['id'] : '';
		$from_id   = isset( $message['from']['id'] ) ? (string) $message['from']['id'] : '';
		$chat_type = isset( $message['chat']['type'] ) ? (string) $message['chat']['type'] : 'private';

		if ( '' === $chat_id ) {
			return;
		}

		// ── Bot command detection ──
		// Parse bot_command entities from the Telegram message. When the first
		// entity at offset 0 is a command, route it to the built-in handler
		// before the AI auto-reply pipeline. This follows Telegram best
		// practices: https://core.telegram.org/bots/features#commands
		$parsed_command = $this->parse_bot_command( $message );

		if ( null !== $parsed_command ) {
			$handled = $this->handle_bot_command( $parsed_command, $message, $chat_id, $from_id, $chat_type );
			if ( $handled ) {
				return; // Command was handled; no AI reply needed.
			}
			// Command not recognised – fall through to AI pipeline so the
			// assistant can handle it as a natural-language message.
		}

		// Resolve the Telegram connection early so group/channel settings are available.
		$connection = $this->get_active_telegram_connection();

		if ( ! $connection ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram webhook: no active Telegram connection found.'
			);
			return;
		}

		// Auto-populate the bot_username on the connection when it is missing.
		// This ensures the inbox can display the @bot_username badge without
		// requiring the admin to manually test or save the connection.
		$connection = $this->maybe_populate_bot_username( $connection );

		// ── Group / supergroup gate ──
		// When in a group context, respect the connection's enable_groups setting.
		$is_group     = in_array( $chat_type, array( 'group', 'supergroup' ), true );
		$tg_conv_type = $is_group ? 'group' : 'dm';
		if ( $is_group && empty( $connection['enable_groups'] ) ) {
			WP_MCP_AI_Logger::log_event(
				'telegram_group_message_ignored',
				'Group message ignored: group support not enabled on this connection.',
				array(
					'chat_type' => $chat_type,
					'chat_id'   => $chat_id,
				)
			);
			return;
		}

		if ( $is_group ) {
			WP_MCP_AI_Logger::log_event(
				'telegram_group_message_received',
				'Processing group message.',
				array(
					'chat_type' => $chat_type,
					'chat_id'   => $chat_id,
				)
			);
		}

		// Resolve automation rules and assistant IDs early so they are available
		// for both inbox persistence and the AI-reply pipeline below.
		$automation_rules       = get_option( 'wp_mcp_ai_chat_channels_automation_rules', array() );
		$assigned_assistant_ids = $this->resolve_assistant_ids( $connection, $automation_rules );

		$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';

		if ( '' === $connection_id ) {
			return;
		}

		// ── Persist inbound message to the inbox ──────────────────────────────
		// Group chats use the chat ID (not the sender's user ID) as the contact
		// identifier so all messages from the same group appear in a single
		// unified thread in the inbox. Private messages use from_id as before.
		$tg_contact_id = $is_group ? $chat_id : ( '' !== $from_id ? $from_id : $chat_id );

		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			if ( $is_group ) {
				$tg_contact_name  = isset( $message['chat']['title'] ) ? sanitize_text_field( $message['chat']['title'] ) : $chat_id;
				$tg_contact_extra = array(
					'display_name'      => $tg_contact_name,
					'metadata'          => array( 'contact_type' => $chat_type ),
					'connection_id'     => $connection_id,
					'conversation_type' => $tg_conv_type,
				);
			} else {
				$tg_contact_name = '';
				if ( isset( $message['from']['first_name'] ) ) {
					$tg_contact_name = trim( $message['from']['first_name'] . ' ' . ( isset( $message['from']['last_name'] ) ? $message['from']['last_name'] : '' ) );
				}
				$tg_contact_extra = array(
					'display_name'      => $tg_contact_name ? $tg_contact_name : $tg_contact_id,
					'metadata'          => array( 'contact_type' => 'private' ),
					'connection_id'     => $connection_id,
					'conversation_type' => $tg_conv_type,
				);
			}

			$contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
				'telegram',
				$tg_contact_id,
				$tg_contact_extra
			);
			if ( $contact_row_id ) {
				WP_MCP_AI_Channel_Contacts_CCT::touch( $contact_row_id );
			}
		}

		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			$tg_message_id = isset( $message['message_id'] ) ? (string) $message['message_id'] : '';
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'telegram',
					'channel_contact_id' => $tg_contact_id,
					'direction'          => 'inbound',
					'message_id'         => $tg_message_id,
					'message_type'       => $has_media ? $this->get_cct_message_type_for_media( $media_info['media_type'] ) : 'text',
					'content'            => '' !== $text ? $text : ( $has_media ? $media_info['media_type'] . ' received' : '' ),
					'raw_payload'        => $message,
					'status'             => 'received',
					'connection_id'      => $connection_id,
					'phone_number_id'    => $chat_id,
					'timestamp'          => isset( $message['date'] ) ? absint( $message['date'] ) : time(),
					'reply_sent'         => 0,
					'assigned_agent'     => ! empty( $assigned_assistant_ids ) ? (string) $assigned_assistant_ids[0] : '',
					'conversation_type'  => $tg_conv_type,
				)
			);
		}

		// In groups, only reply when the bot is mentioned or the message is a
		// reply to one of the bot's own messages – but only when require_mention
		// is explicitly enabled for this connection. Defaults to off so the bot
		// responds to every group message out of the box.
		// Note: the message is already stored in the inbox above regardless.
		$require_mention_in_group = $is_group && ! empty( $connection['require_mention'] );
		if ( $require_mention_in_group ) {
			$bot_mentioned = $this->message_mentions_bot( $text, $connection, $message );
			$reply_to_bot  = $this->is_reply_to_bot( $message, $connection );

			if ( ! $bot_mentioned && ! $reply_to_bot ) {
				// Also check for assistant @slug mentions.
				if ( ! $this->message_mentions_assistant( $text, $assigned_assistant_ids ) ) {
					return; // Not addressed to the bot; no reply will be sent.
				}
			}
		}

		// Strip the @bot_username mention from the text before processing so the
		// AI receives a clean prompt without the trigger prefix.
		$text = $this->strip_bot_mention( $text, $connection );

		// --- Automation keyword checks (mirrors WhatsApp maybe_auto_reply) ---
		$text_lower = strtolower( $text );

		if ( ! empty( $automation_rules['human_takeover_keywords'] ) && '' !== $text_lower ) {
			$takeover_kws = array_map( 'trim', explode( ',', strtolower( $automation_rules['human_takeover_keywords'] ) ) );
			foreach ( $takeover_kws as $kw ) {
				if ( '' !== $kw && false !== strpos( $text_lower, $kw ) ) {
					if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
						$contact_id = $this->get_channel_contact_id( 'telegram', $tg_contact_id );
						if ( $contact_id ) {
							WP_MCP_AI_Channel_Contacts_CCT::set_human_takeover( $contact_id, true );
						}
					}
					WP_MCP_AI_Logger::log_event(
						'telegram_human_takeover_triggered',
						'Human takeover triggered by keyword.',
						array(
							'from_id' => substr( $from_id, 0, 4 ) . '***',
							'keyword' => $kw,
						)
					);
					return; // Do not auto-reply; human agent will respond.
				}
			}
		}

		if ( ! empty( $automation_rules['ai_resume_keywords'] ) && '' !== $text_lower ) {
			$resume_kws = array_map( 'trim', explode( ',', strtolower( $automation_rules['ai_resume_keywords'] ) ) );
			foreach ( $resume_kws as $kw ) {
				if ( '' !== $kw && false !== strpos( $text_lower, $kw ) ) {
					if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
						$contact_id = $this->get_channel_contact_id( 'telegram', $tg_contact_id );
						if ( $contact_id ) {
							WP_MCP_AI_Channel_Contacts_CCT::set_human_takeover( $contact_id, false );
						}
					}
					WP_MCP_AI_Logger::log_event(
						'telegram_ai_resumed',
						'AI auto-reply resumed by keyword.',
						array(
							'from_id' => substr( $from_id, 0, 4 ) . '***',
							'keyword' => $kw,
						)
					);
					break; // Continue and allow AI to reply.
				}
			}
		}

		// --- Human takeover gate ---
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			if ( WP_MCP_AI_Channel_Contacts_CCT::is_human_takeover_active( 'telegram', $tg_contact_id, $connection_id ) ) {
				WP_MCP_AI_Logger::log_event(
					'telegram_auto_reply_skipped_human_takeover',
					'Auto-reply skipped: human takeover is active for this contact.',
					array( 'from_id' => substr( $from_id, 0, 4 ) . '***' )
				);
				return;
			}
		}

		/**
		 * Filter whether to auto-reply to Telegram messages.
		 *
		 * Defaults to true when the connection has one or more assigned AI assistants
		 * or a global default assistant is configured in the automation rules.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $auto_reply       Whether to auto-reply.
		 * @param array  $message          Telegram message object.
		 * @param array  $automation_rules Saved automation rule settings.
		 * @param string $chat_type        Chat type: private, group, supergroup, or channel.
		 */
		$should_reply = apply_filters( 'wp_mcp_ai_telegram_should_auto_reply', ! empty( $assigned_assistant_ids ), $message, $automation_rules, $chat_type );

		if ( ! $should_reply ) {
			return;
		}

		// Enforce per-contact rate limiting when the global setting is enabled.
		// Uses a transient-based sliding window; see wp_mcp_ai_chat_channel_is_rate_limited().
		if ( function_exists( 'wp_mcp_ai_chat_channel_is_rate_limited' ) &&
			wp_mcp_ai_chat_channel_is_rate_limited( 'telegram', '' !== $from_id ? $from_id : $chat_id ) ) {
			return;
		}

		do_action( 'wp_mcp_ai_telegram_auto_reply', $message, $automation_rules, $assigned_assistant_ids );

		if ( $has_media ) {
			// ── Media message: dispatch media processing job ──────────────────────
			// The job downloads the Telegram file, sideloads it to the WordPress
			// media library, sends the user an immediate metadata auto-reply
			// (attachment ID + URL + dimensions/duration), and then schedules the
			// AI reply job with the full file context included. This mirrors the
			// industry-standard pattern of acknowledging file receipt immediately
			// and processing asynchronously to avoid webhook timeout.
			$reply_to_message_id = isset( $message['message_id'] ) ? (string) $message['message_id'] : '';

			$media_job_args = array(
				array(
					'file_id'             => $media_info['file_id'],
					'media_type'          => $media_info['media_type'],
					'original_filename'   => $media_info['original_filename'],
					'mime_type'           => $media_info['mime_type'],
					'file_size'           => $media_info['file_size'],
					'width'               => $media_info['width'],
					'height'              => $media_info['height'],
					'duration'            => $media_info['duration'],
					'caption'             => $media_info['caption'],
					'message_text'        => $text, // Caption with @mention stripped.
					'assistant_id'        => ! empty( $assigned_assistant_ids ) ? $assigned_assistant_ids[0] : 0,
					'chat_id'             => $chat_id,
					'from_id'             => '' !== $from_id ? $from_id : $chat_id,
					'connection_id'       => $connection_id,
					'chat_type'           => $chat_type,
					'message_id'          => isset( $message['message_id'] ) ? (string) $message['message_id'] : '',
					'reply_to_message_id' => $is_group ? $reply_to_message_id : '',
				),
			);

			wp_schedule_single_event( time() + 1, self::MEDIA_REPLY_CRON_HOOK, $media_job_args );
			spawn_cron();

		} elseif ( ! empty( $assigned_assistant_ids ) ) {
			// ── Text message: dispatch AI reply job as before ─────────────────────
			$reply_to_message_id = isset( $message['message_id'] ) ? (string) $message['message_id'] : '';

			$job_args = array(
				array(
					'assistant_id'        => $assigned_assistant_ids[0],
					'message_text'        => $text,
					'chat_id'             => $chat_id,
					'from_id'             => '' !== $from_id ? $from_id : $chat_id,
					'connection_id'       => $connection_id,
					'chat_type'           => $chat_type,
					'reply_to_message_id' => $is_group ? $reply_to_message_id : '',
				),
			);

			wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
			spawn_cron();
		}
	}

	/**
	 * Cron callback: generate an AI reply and send it back via the Telegram Bot API.
	 *
	 * Implements per-user conversation history following the same pattern as the
	 * WhatsApp auto-reply handler (PR #3844), respecting the global
	 * max_history_messages setting and the wp_mcp_ai_telegram_max_history_messages
	 * filter.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Job arguments set by process_message().
	 */
	public function handle_telegram_reply_job( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		$assistant_id        = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;
		$message_text        = isset( $args['message_text'] ) ? (string) $args['message_text'] : '';
		$chat_id             = isset( $args['chat_id'] ) ? (string) $args['chat_id'] : '';
		$from_id             = isset( $args['from_id'] ) ? (string) $args['from_id'] : $chat_id;
		$connection_id       = isset( $args['connection_id'] ) ? sanitize_key( $args['connection_id'] ) : '';
		$chat_type           = isset( $args['chat_type'] ) ? (string) $args['chat_type'] : 'private';
		$reply_to_message_id = isset( $args['reply_to_message_id'] ) ? (string) $args['reply_to_message_id'] : '';
		// retry_count incremented each time the job is rescheduled due to a 429.
		$retry_count  = isset( $args['retry_count'] ) ? absint( $args['retry_count'] ) : 0;
		$tg_conv_type = in_array( $chat_type, array( 'group', 'supergroup' ), true ) ? 'group' : 'dm';
		// WordPress attachment sideloaded from a Telegram media message (photo,
		// document, video, etc.). When set, the message content array will include
		// the actual file so vision models can see images and the AI can reference
		// document context, rather than receiving only a plain-text description.
		$wp_attachment_id   = isset( $args['wp_attachment_id'] ) ? absint( $args['wp_attachment_id'] ) : 0;
		$wp_attachment_mime = isset( $args['wp_attachment_mime'] ) ? sanitize_text_field( $args['wp_attachment_mime'] ) : '';

		if ( ! $assistant_id || '' === $message_text || '' === $chat_id || '' === $connection_id ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection || empty( $connection['api_key'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram AI reply: connection not found or bot token missing.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );

		if ( '' === $bot_token ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram AI reply: bot token decryption returned empty string.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		// Send a typing indicator before starting AI inference so the user knows
		// their message is being processed. This is a widely-recommended industry
		// practice for chatbots: the typing action is visible for up to 5 seconds
		// and is automatically cleared when the reply arrives or after a timeout.
		$this->send_typing_action( $bot_token, $chat_id );

		// --- Per-user conversation history (mirrors PR #3844 for WhatsApp) ---
		$history_key      = $this->get_conversation_history_key( $from_id, $connection_id );
		$history          = get_transient( $history_key );
		$history          = is_array( $history ) ? $history : array();
		$history_for_chat = $this->normalize_conversation_history_for_chat( $history );

		$max_history = 8;
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings    = WP_MCP_AI_Admin_Settings::get_settings();
			$max_history = isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : $max_history;
		}

		/**
		 * Filters the maximum number of messages kept in a Telegram conversation history.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $max_history Maximum message count.
		 * @param array $args        Current job arguments.
		 */
		$max_history = (int) apply_filters( 'wp_mcp_ai_telegram_max_history_messages', $max_history, $args );
		$max_history = max( 1, $max_history );

		// When the transient cache is empty (e.g. after expiry or a cache flush),
		// hydrate the conversation context from the Channel Messages CCT so that
		// prior exchanges are never silently dropped. The CCT is the persistent
		// source of truth; the transient is a fast in-memory cache on top of it.
		// Only attempt when max_history > 1; a limit of 1 leaves no room for prior
		// turns (the current message occupies the sole slot).
		if ( empty( $history_for_chat ) && $max_history > 1 && class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			$history_for_chat = WP_MCP_AI_Channel_Messages_CCT::get_recent_messages(
				'telegram',
				$from_id,
				$connection_id,
				$max_history - 1
			);
		}

		$history_for_chat = WP_MCP_AI_Webhook_Context_Manager::trim_history( $history_for_chat, $max_history, 'telegram', 1 );

		// When a sideloaded WordPress attachment is available, build a multipart
		// message content array so the AI can see/process the actual file:
		// - images   → 'input_image' segment → vision models analyse the photo
		// - documents / audio / video → 'input_file' segment → file context
		// The text part carries the metadata summary + any user caption.
		// The history transient always stores the plain-text form so prior turns
		// remain compact and do not require a special segment normaliser.
		if ( $wp_attachment_id ) {
			$is_image           = 0 === strpos( $wp_attachment_mime, 'image/' );
			$attachment_segment = array(
				'type'          => $is_image ? 'input_image' : 'input_file',
				'attachment_id' => $wp_attachment_id,
			);

			if ( ! $is_image ) {
				// Provide a display_name for file-type segments so the AI receives
				// the original filename rather than a numeric attachment ID.
				$attached_file = get_attached_file( $wp_attachment_id );
				if ( $attached_file ) {
					$display_name = basename( $attached_file );
					if ( '' !== $display_name ) {
						$attachment_segment['display_name'] = $display_name;
					}
				}
			}

			$user_content = array( $attachment_segment );
			if ( '' !== $message_text ) {
				$user_content[] = array(
					'type' => 'text',
					'text' => $message_text,
				);
			}

			$messages = array_merge(
				$history_for_chat,
				array(
					array(
						'role'    => 'user',
						'content' => $user_content,
					),
				)
			);
		} else {
			$messages = array_merge(
				$history_for_chat,
				array(
					array(
						'role'    => 'user',
						'content' => $message_text,
					),
				)
			);
		}
		// --- End conversation history ---

		// Call the internal chat REST endpoint.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_body_params(
			array(
				'assistant_id' => $assistant_id,
				'messages'     => $messages,
				'stream'       => false,
			)
		);

		$original_user_id = get_current_user_id();

		// Resolve the WordPress user that the internal /mcp-ai/v1/chat request
		// should run as. Historically this impersonated the first administrator
		// returned by get_users(), which caused incorrect attribution: media
		// generated during a Telegram-driven turn would be owned by an
		// arbitrary site administrator instead of someone tied to the
		// assistant.  Prefer the assistant post's author (a deterministic
		// owner with capabilities the assistant was registered under) and only
		// fall back to the first administrator when the assistant has no
		// valid author.
		$impersonate_user_id = 0;
		$assistant_author    = (int) get_post_field( 'post_author', $assistant_id );

		if ( $assistant_author > 0 ) {
			$author_user = get_userdata( $assistant_author );
			if ( $author_user && ! empty( $author_user->ID ) ) {
				$impersonate_user_id = (int) $author_user->ID;
			}
		}

		if ( ! $impersonate_user_id ) {
			$admin_users = get_users(
				array(
					'role'   => 'administrator',
					'number' => 1,
					'fields' => 'ID',
				)
			);

			if ( ! empty( $admin_users ) ) {
				$impersonate_user_id = (int) $admin_users[0];
			}
		}

		if ( $impersonate_user_id ) {
			wp_set_current_user( $impersonate_user_id );
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		} else {
			WP_MCP_AI_Logger::log_error(
				'Telegram AI reply: no assistant author or administrator user found; internal chat request may fail.',
				array( 'assistant_id' => $assistant_id )
			);
		}

		// Raise the agentic-loop iteration cap for Telegram reply jobs so that
		// multi-step tool workflows (search → analyse → respond, etc.) can run
		// to completion. Without this, the /mcp-ai/v1/chat endpoint defaults to
		// a single iteration and the final content remains null when a second
		// tool round is needed.
		add_filter( 'wp_mcp_ai_max_agentic_iterations', array( $this, 'get_telegram_max_agentic_iterations' ), 10, 2 );
		$response = rest_do_request( $request );
		remove_filter( 'wp_mcp_ai_max_agentic_iterations', array( $this, 'get_telegram_max_agentic_iterations' ), 10 );

		wp_set_current_user( $original_user_id );

		if ( $response->is_error() ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram AI reply: chat request failed.',
				array( 'assistant_id' => $assistant_id )
			);
			return;
		}

		$response_data    = $response->get_data();
		$content          = $this->extract_content_from_chat_response( $response_data );
		$agentic_messages = $this->extract_agentic_tool_messages_from_chat_response( $response_data );

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram AI reply: empty content from assistant.',
				array(
					'assistant_id'           => $assistant_id,
					'has_data'               => isset( $response_data['data'] ),
					'has_choices'            => isset( $response_data['data']['choices'] ),
					'choices_count'          => isset( $response_data['data']['choices'] ) ? count( $response_data['data']['choices'] ) : 0,
					'finish_reason'          => isset( $response_data['data']['choices'][0]['finish_reason'] ) ? $response_data['data']['choices'][0]['finish_reason'] : '',
					'agentic_messages_count' => isset( $response_data['data']['agentic_tool_messages'] ) ? count( $response_data['data']['agentic_tool_messages'] ) : 0,
					'likely_tool_call_loop'  => isset( $response_data['data']['choices'][0]['finish_reason'] ) && 'tool_calls' === $response_data['data']['choices'][0]['finish_reason'],
				)
			);

			// Industry best practice: always reply to the user rather than silently
			// dropping the message. Send a graceful fallback so the user knows the
			// bot received their message even if AI generation failed.
			$this->send_telegram_fallback_reply( $bot_token, $chat_id, $chat_type, $reply_to_message_id );
			return;
		}

		// Keep the raw text for a plain-text fallback if HTML formatting fails.
		$raw_content = $content;

		// Convert Markdown from the AI response to Telegram-compatible HTML
		// so the reply renders with rich formatting (bold, italic, links, etc.).
		$content = $this->markdown_to_telegram_html( $content );

		// Enforce Telegram message length limit.
		if ( mb_strlen( $content ) > self::MAX_MESSAGE_LENGTH ) {
			$content = mb_substr( $content, 0, self::MAX_MESSAGE_LENGTH - 3 ) . '...';
		}

		// Send reply via Telegram Bot API.
		$endpoint = sprintf( 'https://api.telegram.org/bot%s/sendMessage', rawurlencode( $bot_token ) );

		$payload = array(
			'chat_id'    => $chat_id,
			'text'       => $content,
			'parse_mode' => ( isset( $connection['parse_mode'] ) && in_array( $connection['parse_mode'], array( 'HTML', 'Markdown', 'MarkdownV2' ), true ) )
				? $connection['parse_mode']
				: 'HTML',
		);

		// In group/supergroup chats, reply to the original message to keep the
		// conversation threaded and make it clear which message is being answered.
		// allow_sending_without_reply prevents failures when the original message
		// is unavailable (e.g. deleted, or migrated in supergroups).
		if ( '' !== $reply_to_message_id && in_array( $chat_type, array( 'group', 'supergroup' ), true ) ) {
			$payload['reply_to_message_id']         = (int) $reply_to_message_id;
			$payload['allow_sending_without_reply'] = true;
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			WP_MCP_AI_Logger::log_error( 'Telegram AI reply: failed to JSON-encode payload.' );
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'telegram_ai_reply_sending',
			'Sending Telegram AI reply.',
			array(
				'assistant_id' => $assistant_id,
				'chat_id'      => substr( $chat_id, 0, 4 ) . '***',
				'chat_type'    => $chat_type,
			)
		);

		$result = wp_remote_post(
			$endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 20,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram AI reply: HTTP request failed.',
				array( 'error' => $result->get_error_message() )
			);
			return;
		}

		$http_code     = (int) wp_remote_retrieve_response_code( $result );
		$response_body = json_decode( wp_remote_retrieve_body( $result ), true );

		// Industry standard: handle HTTP 429 Too Many Requests by respecting the
		// Retry-After header returned by the Telegram Bot API and rescheduling
		// the job. A retry counter prevents indefinite loops.
		if ( 429 === $http_code ) {
			if ( $retry_count >= self::MAX_RATE_LIMIT_RETRIES ) {
				WP_MCP_AI_Logger::log_error(
					'Telegram AI reply: rate limit retry limit reached; giving up.',
					array(
						'connection_id' => $connection_id,
						'retry_count'   => $retry_count,
					)
				);
				return;
			}

			$retry_after = (int) wp_remote_retrieve_header( $result, 'retry-after' );
			// Always wait at least MIN_RETRY_DELAY s; honour the Retry-After value when larger.
			$delay = max( self::MIN_RETRY_DELAY, $retry_after );

			WP_MCP_AI_Logger::log_error(
				sprintf(
					'Telegram AI reply: rate limited (429). Retrying in %d seconds (attempt %d/%d).',
					$delay,
					$retry_count + 1,
					self::MAX_RATE_LIMIT_RETRIES
				),
				array( 'connection_id' => $connection_id )
			);

			$retry_args                = $args;
			$retry_args['retry_count'] = $retry_count + 1;
			wp_schedule_single_event( time() + $delay, self::REPLY_CRON_HOOK, array( $retry_args ) );
			return;
		}

		if ( 200 !== $http_code ) {
			$api_description = isset( $response_body['description'] ) ? $response_body['description'] : '';

			WP_MCP_AI_Logger::log_error(
				'Telegram AI reply: API returned non-200 status.',
				array(
					'http_code'   => $http_code,
					'description' => $api_description,
					'chat_type'   => $chat_type,
				)
			);

			// If the send failed and reply_to_message_id was set, retry without
			// threading so the user still receives the reply even when the
			// original message reference is invalid.
			if ( isset( $payload['reply_to_message_id'] ) ) {
				unset( $payload['reply_to_message_id'], $payload['allow_sending_without_reply'] );

				$retry_body = wp_json_encode( $payload );
				if ( false !== $retry_body ) {
					$retry_result = wp_remote_post(
						$endpoint,
						array(
							'headers' => array( 'Content-Type' => 'application/json' ),
							'timeout' => 20,
							'body'    => $retry_body,
						)
					);

					$retry_code = is_wp_error( $retry_result )
						? 0
						: (int) wp_remote_retrieve_response_code( $retry_result );

					if ( 200 === $retry_code ) {
						WP_MCP_AI_Logger::log_event(
							'telegram_ai_reply_retry_success',
							'Telegram AI reply succeeded on retry without reply_to_message_id.',
							array( 'chat_id' => substr( $chat_id, 0, 4 ) . '***' )
						);
						// Fall through to conversation history update below.
					} else {
						$retry_body_decoded = is_wp_error( $retry_result )
							? array()
							: json_decode( wp_remote_retrieve_body( $retry_result ), true );
						$retry_desc         = isset( $retry_body_decoded['description'] ) ? $retry_body_decoded['description'] : '';

						WP_MCP_AI_Logger::log_error(
							'Telegram AI reply: retry without reply_to_message_id also failed.',
							array(
								'http_code'   => $retry_code,
								'description' => $retry_desc,
							)
						);
						return;
					}
				} else {
					return;
				}
			} elseif ( false !== strpos( $api_description, 'parse' ) || false !== strpos( $api_description, 'HTML' ) ) {
				// HTML formatting may have caused the error; retry with plain text.
				$payload['text'] = wp_strip_all_tags( $raw_content );
				if ( mb_strlen( $payload['text'] ) > self::MAX_MESSAGE_LENGTH ) {
					$payload['text'] = mb_substr( $payload['text'], 0, self::MAX_MESSAGE_LENGTH - 3 ) . '...';
				}
				unset( $payload['parse_mode'] );

				$retry_body = wp_json_encode( $payload );
				if ( false !== $retry_body ) {
					$retry_result = wp_remote_post(
						$endpoint,
						array(
							'headers' => array( 'Content-Type' => 'application/json' ),
							'timeout' => 20,
							'body'    => $retry_body,
						)
					);

					$retry_code = is_wp_error( $retry_result )
						? 0
						: (int) wp_remote_retrieve_response_code( $retry_result );

					if ( 200 === $retry_code ) {
						WP_MCP_AI_Logger::log_event(
							'telegram_ai_reply_retry_success',
							'Telegram AI reply succeeded on retry without HTML parse_mode.',
							array( 'chat_id' => substr( $chat_id, 0, 4 ) . '***' )
						);
					} else {
						return;
					}
				} else {
					return;
				}
			} else {
				return;
			}
		}

		// Persist updated conversation history.
		$history[]               = array(
			'role'    => 'user',
			'content' => $message_text,
		);
		$assistant_history_entry = array(
			'role'    => 'assistant',
			'content' => $raw_content,
		);
		if ( ! empty( $agentic_messages ) ) {
			$assistant_history_entry['agentic_tool_messages'] = $agentic_messages;
		}
		$history[] = $assistant_history_entry;

		$history = WP_MCP_AI_Webhook_Context_Manager::trim_history_after_response( $history, $max_history, 'telegram' );
		set_transient( $history_key, $history, self::CONVERSATION_HISTORY_TTL );

		WP_MCP_AI_Logger::log_event(
			'telegram_ai_reply_sent',
			'Telegram AI reply sent successfully.',
			array(
				'assistant_id' => $assistant_id,
				'chat_id'      => substr( $chat_id, 0, 4 ) . '***',
			)
		);

		// Persist the outbound AI reply to the Channel Messages CCT.
		// Store the raw (pre-Telegram-HTML) content so that when this CCT row is
		// later read back as conversation history the AI receives clean plain text
		// rather than HTML tags. The Telegram-formatted version is preserved in
		// raw_payload alongside the full API response.
		//
		// For group/supergroup chats the inbox contact is keyed to the chat ID
		// (not the individual sender) so that all messages – inbound and outbound
		// – appear in the same unified group thread. For private DMs the sender's
		// from_id is used, matching the inbound message storage logic.
		if ( 'group' === $tg_conv_type ) {
			// Group/supergroup: key the outbound message to the group chat ID so it
			// appears in the same inbox thread as the inbound group messages.
			$tg_outbound_contact_id = $chat_id;
		} elseif ( '' !== $from_id ) {
			// Private DM with a known sender ID.
			$tg_outbound_contact_id = $from_id;
		} else {
			// Fallback: no from_id available (e.g. channel posts), use chat_id.
			$tg_outbound_contact_id = $chat_id;
		}

		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'telegram',
					'channel_contact_id' => $tg_outbound_contact_id,
					'direction'          => 'outbound',
					'message_type'       => 'text',
					'content'            => $raw_content,
					'raw_payload'        => array(
						'chat_response'         => $response_data,
						'agentic_tool_messages' => $agentic_messages,
						'telegram_response'     => $response_body,
						'formatted_content'     => $content,
					),
					'status'             => 'sent',
					'connection_id'      => $connection_id,
					'phone_number_id'    => $chat_id,
					'timestamp'          => time(),
					'reply_sent'         => 1,
					'assigned_agent'     => (string) $assistant_id,
					'conversation_type'  => $tg_conv_type,
				)
			);
		}

		// Touch the contact record to update last_message_at.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			$tg_contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
				'telegram',
				$tg_outbound_contact_id,
				array(
					'connection_id'     => $connection_id,
					'conversation_type' => $tg_conv_type,
				)
			);
			if ( $tg_contact_row_id ) {
				WP_MCP_AI_Channel_Contacts_CCT::touch( $tg_contact_row_id );
			}
		}
	}

	/**
	 * Return the transient key used to store the conversation history for a
	 * specific Telegram sender on a specific connection.
	 *
	 * The key is hashed to avoid PII in option names and to stay within
	 * WordPress's 172-character transient key limit.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from_id       Sender's Telegram user/chat ID.
	 * @param string $connection_id Remote connection ID.
	 * @return string Transient key.
	 */
	protected function get_conversation_history_key( $from_id, $connection_id ) {
		return 'wp_mcp_ai_tg_conv_' . md5( $from_id . '_' . $connection_id );
	}

	/**
	 * Normalize stored conversation history entries into OpenAI-style chat messages.
	 *
	 * Stored history rows may include channel-specific metadata that should not be
	 * sent back to the /mcp-ai/v1/chat endpoint. This method keeps only the fields
	 * required for chat completion context.
	 *
	 * @since 1.0.0
	 *
	 * @param array $history Raw history entries loaded from transient storage.
	 * @return array[] Chat messages with only role/content pairs.
	 */
	protected function normalize_conversation_history_for_chat( array $history ) {
		$messages = array();

		foreach ( $history as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$role    = isset( $entry['role'] ) && is_string( $entry['role'] ) ? trim( $entry['role'] ) : '';
			$content = isset( $entry['content'] ) ? $this->resolve_content_to_string( $entry['content'] ) : '';

			if ( '' === $role || '' === $content ) {
				continue;
			}

			$messages[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		return $messages;
	}

	/**
	 * Return the maximum agentic loop iterations for Telegram reply jobs.
	 *
	 * Priority order (highest first):
	 * 1. Per-assistant `max_agentic_iterations` config value.
	 * 2. Admin setting (`filter_max_agentic_iterations`).
	 * 3. Telegram default (self::DEFAULT_MAX_AGENTIC_ITERATIONS).
	 *
	 * Attached to the `wp_mcp_ai_max_agentic_iterations` filter during internal
	 * REST requests made by `handle_telegram_reply_job()`.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $default_max      Current maximum (may include admin setting).
	 * @param array $assistant_config Assistant configuration array.
	 * @return int Maximum iterations to allow.
	 */
	public function get_telegram_max_agentic_iterations( $default_max, $assistant_config = array() ) {
		// Per-assistant override takes highest priority.
		if ( ! empty( $assistant_config['max_agentic_iterations'] ) ) {
			return absint( $assistant_config['max_agentic_iterations'] );
		}

		// If an admin setting or earlier filter has already raised the cap above
		// the hard-coded base of 1, honour that value.
		if ( $default_max > 1 ) {
			return $default_max;
		}

		// Fall back to the Telegram-specific default.
		return self::DEFAULT_MAX_AGENTIC_ITERATIONS;
	}

	/**
	 * Check whether an update_id has already been processed.
	 *
	 * @since 1.0.0
	 *
	 * @param int $update_id Telegram update ID.
	 * @return bool True if this update was seen before.
	 */
	protected function is_duplicate_update( $update_id ) {
		return (bool) get_transient( 'wp_mcp_ai_tg_dedup_' . $update_id );
	}

	/**
	 * Telegram IP CIDR ranges as published at https://core.telegram.org/bots/webhooks.
	 *
	 * These are the only IP addresses Telegram uses to deliver webhook updates.
	 * The list is filterable via `wp_mcp_ai_telegram_allowed_ip_ranges` so site
	 * operators can extend it if Telegram adds new ranges in future.
	 */
	const TELEGRAM_IP_RANGES = array(
		'149.154.160.0/20',
		'91.108.4.0/22',
	);

	/**
	 * Check whether the request originates from a known Telegram IP range.
	 *
	 * Returns true when IP validation is disabled via the filter, or when the
	 * remote address falls within one of Telegram's published CIDR blocks.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Incoming request object.
	 * @return bool True if the request IP is a Telegram IP (or validation is disabled).
	 */
	protected function is_request_from_telegram( $request ) {
		/**
		 * Filter: disable Telegram IP validation entirely.
		 *
		 * Set to false on sites behind a proxy that changes the apparent
		 * source IP. When false, only the secret-token header is checked.
		 *
		 * @since 1.0.0
		 *
		 * @param bool            $enabled Whether IP range validation is enabled. Default true.
		 * @param WP_REST_Request $request The incoming webhook request.
		 */
		$enabled = apply_filters( 'wp_mcp_ai_telegram_ip_validation_enabled', true, $request );

		if ( ! $enabled ) {
			return true;
		}

		// Retrieve remote address — fall back to empty string so the check
		// below will reject the request rather than silently pass it.
		$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filter: override the remote IP used for Telegram IP range validation.
		 *
		 * Useful on sites behind a reverse proxy that stores the real client IP
		 * in a header such as X-Forwarded-For.  The value MUST be sanitized and
		 * trusted before use to avoid spoofing.
		 *
		 * @since 1.0.0
		 *
		 * @param string          $remote_ip The IP address to validate.
		 * @param WP_REST_Request $request   The incoming webhook request.
		 */
		$remote_ip = apply_filters( 'wp_mcp_ai_telegram_webhook_remote_ip', $remote_ip, $request );
		$remote_ip = sanitize_text_field( (string) $remote_ip );

		if ( '' === $remote_ip ) {
			return false;
		}

		/**
		 * Filter: allowed Telegram CIDR ranges.
		 *
		 * Extends or replaces the default list of Telegram IP ranges.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $ranges Array of CIDR strings.
		 */
		$allowed_ranges = apply_filters( 'wp_mcp_ai_telegram_allowed_ip_ranges', self::TELEGRAM_IP_RANGES );

		foreach ( $allowed_ranges as $cidr ) {
			if ( $this->ip_in_cidr( $remote_ip, $cidr ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an IP address falls within a CIDR range.
	 *
	 * Supports IPv4 only; IPv6 addresses are treated as non-matching since
	 * Telegram currently uses IPv4 exclusively for webhook deliveries.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip   IP address to test (dotted-decimal notation).
	 * @param string $cidr CIDR range string, e.g. "149.154.160.0/20".
	 * @return bool True if the IP falls within the CIDR range.
	 */
	protected function ip_in_cidr( $ip, $cidr ) {
		if ( false === strpos( $cidr, '/' ) ) {
			return $ip === $cidr;
		}

		list( $subnet, $prefix ) = explode( '/', $cidr, 2 );

		$prefix = (int) $prefix;

		// Only handle IPv4.
		if ( false !== strpos( $ip, ':' ) || false !== strpos( $subnet, ':' ) ) {
			return false;
		}

		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );

		if ( false === $ip_long || false === $subnet_long || $prefix < 0 || $prefix > 32 ) {
			return false;
		}

		if ( 0 === $prefix ) {
			return true;
		}

		$mask = ~( ( 1 << ( 32 - $prefix ) ) - 1 );

		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}

	/**
	 * Retrieve the secret_token from the active Telegram connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $connection_id Optional connection ID to target a specific bot.
	 * @return string Secret token or empty string if not configured.
	 */
	protected function get_secret_token( $connection_id = null ) {
		$connection = $this->get_active_telegram_connection( $connection_id );

		if ( ! $connection || empty( $connection['secret_token'] ) ) {
			return '';
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['secret_token'] );
	}

	/**
	 * Find the active (enabled) Telegram connection.
	 *
	 * When a connection_id is provided (either via param or the instance
	 * property set at the top of handle_webhook()), the method uses the
	 * Remote Site Manager's direct array-key lookup to resolve that specific
	 * connection. Falls back to the first active Telegram connection for
	 * backward compatibility with single-bot setups.
	 *
	 * Unlike the previous implementation, this no longer requires
	 * assigned_assistant_ids to be set on the connection so that the global
	 * default_assistant_id from the automation rules can serve as a fallback
	 * (mirroring the WhatsApp auto-reply behaviour).
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $connection_id Optional connection ID. When null the instance
	 *                                   property $this->current_connection_id is used,
	 *                                   then falls back to the first active connection.
	 * @return array|null Connection array or null if none found.
	 */
	protected function get_active_telegram_connection( $connection_id = null ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		// Resolve target connection ID: explicit param > instance property.
		$target_id = $connection_id ?? $this->current_connection_id;

		// When a specific connection is requested, use the Remote Site Manager's
		// direct array-key lookup.  This is reliable regardless of whether the
		// connection data includes a redundant 'id' field – unlike the previous
		// foreach-based approach which compared $connection['id'] and silently
		// failed when that field was absent, producing 403 Forbidden errors on
		// multi-bot setups.  Mirrors the pattern used by the Twitter controller.
		if ( $target_id ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $target_id );

			if (
				$connection
				&& isset( $connection['connection_type'] )
				&& 'telegram' === $connection['connection_type']
				&& ! empty( $connection['enabled'] )
			) {
				return $connection;
			}

			// Specific connection requested but not found — do not fall back to
			// a different connection; return null so the caller can surface a
			// descriptive error instead of silently using wrong credentials.
			return null;
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( ! is_array( $connections ) ) {
			return null;
		}

		// Fallback: return the first active Telegram connection (single-bot / legacy behaviour).
		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'telegram' !== $connection['connection_type'] ) {
				continue;
			}

			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			return $connection;
		}

		return null;
	}

	/**
	 * Populate the bot_username on a Telegram connection when it is missing.
	 *
	 * Calls the Telegram getMe API once per connection (result cached via a
	 * transient for 24 hours) and persists the username on the connection so
	 * the inbox can display the @bot_username badge without requiring the
	 * admin to manually test or save the connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data array.
	 * @return array Connection data with bot_username populated (when possible).
	 */
	protected function maybe_populate_bot_username( array $connection ) {
		if ( ! empty( $connection['bot_username'] ) ) {
			return $connection;
		}

		$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';
		if ( '' === $connection_id ) {
			return $connection;
		}

		// Avoid repeated API calls: one attempt per connection per 24h.
		$transient_key = 'wp_mcp_ai_tg_botname_' . $connection_id;
		if ( false !== get_transient( $transient_key ) ) {
			return $connection;
		}

		$bot_token = isset( $connection['api_key'] )
			? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] )
			: '';

		if ( '' === $bot_token ) {
			set_transient( $transient_key, 'none', DAY_IN_SECONDS );
			return $connection;
		}

		$api_base    = 'https://api.telegram.org/bot' . rawurlencode( $bot_token );
		$get_me_resp = wp_remote_get( $api_base . '/getMe', array( 'timeout' => 10 ) );

		if ( is_wp_error( $get_me_resp ) ) {
			set_transient( $transient_key, 'error', DAY_IN_SECONDS );
			return $connection;
		}

		$body = json_decode( wp_remote_retrieve_body( $get_me_resp ), true );

		if ( empty( $body['ok'] ) || empty( $body['result']['username'] ) ) {
			set_transient( $transient_key, 'empty', DAY_IN_SECONDS );
			return $connection;
		}

		$bot_username = sanitize_text_field( $body['result']['username'] );

		// Persist the username on the connection.
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			WP_MCP_AI_Pro_Remote_Site_Manager::save_connection(
				array_merge( $connection, array( 'bot_username' => $bot_username ) )
			);
		}

		set_transient( $transient_key, $bot_username, DAY_IN_SECONDS );

		$connection['bot_username'] = $bot_username;
		return $connection;
	}

	/**
	 * Look up the channel contact record ID for the given Telegram user.
	 *
	 * Used to set/clear the human takeover flag on per-contact records stored
	 * in the WP_MCP_AI_Channel_Contacts_CCT table (mirrors the identical helper
	 * in the WhatsApp webhook controller).
	 *
	 * @since 1.0.0
	 *
	 * @param string $channel            Platform slug ('telegram').
	 * @param string $channel_contact_id Platform-level contact identifier (Telegram user ID).
	 * @return int|null Contact record ID or null if not found.
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
				"SELECT _ID FROM {$table} WHERE channel = %s AND channel_contact_id = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted CCT helper.
				sanitize_key( $channel ),
				sanitize_text_field( $channel_contact_id )
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * Resolve a message `content` field to a plain string.
	 *
	 * Providers normalise the content field differently:
	 *  - OpenAI / Anthropic / LM Studio: plain string.
	 *  - Gemini / Ollama: array of content segments, e.g.
	 *      [{ "type": "text", "text": "Hello!" }]
	 *
	 * This helper handles both formats so that channel auto-replies work
	 * regardless of which AI provider the assigned assistant uses.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $content Raw value of message['content'] from the chat response.
	 * @return string Plain-text string, or empty string when no text can be extracted.
	 */
	protected function resolve_content_to_string( $content ) {
		if ( is_string( $content ) ) {
			return trim( $content );
		}

		if ( ! is_array( $content ) ) {
			return '';
		}

		// Array of content segments (Gemini / Ollama normalised format).
		// Each segment is expected to be an associative array with at minimum a
		// 'type' key. Only segments of type 'text' carry displayable text.
		$parts = array();
		foreach ( $content as $segment ) {
			if ( ! is_array( $segment ) ) {
				continue;
			}

			$type = isset( $segment['type'] ) ? (string) $segment['type'] : '';

			if ( 'text' === $type && isset( $segment['text'] ) && is_string( $segment['text'] ) ) {
				$text = trim( $segment['text'] );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Extract the plain-text reply from the internal /mcp-ai/v1/chat response.
	 *
	 * The /mcp-ai/v1/chat endpoint wraps the LLM response under a `data` key:
	 *
	 *   { assistant_id, data: { choices: [{ message: { content, role }, finish_reason }] } }
	 *
	 * The `message.content` field can be a plain string (OpenAI/Anthropic) or an
	 * array of typed segments (Gemini/Ollama). Both formats are handled via
	 * {@see resolve_content_to_string()}.
	 *
	 * When an agentic tool-calling workflow runs, some providers set
	 * `message.content` to null on intermediate responses where
	 * `finish_reason = "tool_calls"`. This method handles that case by scanning
	 * all choices and falling back to `agentic_tool_messages` (intermediate
	 * assistant messages attached to the response by the chat service) so that
	 * the last assistant message with non-empty text is returned.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data Response data from the chat endpoint (WP_REST_Response::get_data()).
	 * @return string Plain-text assistant content, or empty string when none found.
	 */
	protected function extract_content_from_chat_response( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		// Normalise: the endpoint wraps the raw LLM response under 'data'.
		$llm_data = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
		$choices  = isset( $llm_data['choices'] ) && is_array( $llm_data['choices'] ) ? $llm_data['choices'] : array();

		// --- Pass 1: scan every choice for a non-empty content value.
		// The final response from a completed agentic workflow will normally be
		// found here with finish_reason = 'stop'. We prefer choices whose
		// finish_reason is 'stop' over 'tool_calls' so that a partial tool-call
		// message is not mistakenly returned as the final answer.
		$best_content = '';
		foreach ( $choices as $choice ) {
			$msg     = isset( $choice['message'] ) && is_array( $choice['message'] ) ? $choice['message'] : array();
			$content = isset( $msg['content'] ) ? $this->resolve_content_to_string( $msg['content'] ) : '';

			if ( '' === $content ) {
				continue;
			}

			$finish = isset( $choice['finish_reason'] ) ? (string) $choice['finish_reason'] : '';

			// A 'stop' finish is the definitive final answer — return immediately.
			if ( 'stop' === $finish ) {
				return $content;
			}

			// Keep as a candidate in case no 'stop' choice is found.
			if ( '' === $best_content ) {
				$best_content = $content;
			}
		}

		if ( '' !== $best_content ) {
			return $best_content;
		}

		// --- Pass 2: fall back to agentic_tool_messages.
		// When all choices have null/empty content (e.g. the loop exhausted its
		// iteration cap before the model produced a final text reply), the chat
		// service attaches intermediate assistant messages to the response under
		// `agentic_tool_messages`. Return the last one that contains text so the
		// user at least receives the most recent partial answer.
		$agentic_messages = isset( $llm_data['agentic_tool_messages'] ) && is_array( $llm_data['agentic_tool_messages'] )
			? $llm_data['agentic_tool_messages']
			: array();

		foreach ( array_reverse( $agentic_messages ) as $msg ) {
			if ( ! is_array( $msg ) ) {
				continue;
			}
			$content = isset( $msg['content'] ) ? $this->resolve_content_to_string( $msg['content'] ) : '';
			if ( '' !== $content ) {
				return $content;
			}
		}

		return '';
	}

	/**
	 * Extract normalized intermediate agentic tool messages from chat response data.
	 *
	 * Handles both plain string content (OpenAI/Anthropic) and array-segment content
	 * (Gemini/Ollama) in each message. Tool result messages (role: tool) always have
	 * JSON-encoded string content and are preserved as-is. Assistant messages that
	 * used tool calls may have array-format content; these are flattened to a string
	 * via {@see resolve_content_to_string()}.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data Response data from the chat endpoint.
	 * @return array[] Normalized agentic messages.
	 */
	protected function extract_agentic_tool_messages_from_chat_response( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}

		$llm_data = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;

		if ( ! isset( $llm_data['agentic_tool_messages'] ) || ! is_array( $llm_data['agentic_tool_messages'] ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $llm_data['agentic_tool_messages'] as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = isset( $message['role'] ) && is_string( $message['role'] ) ? trim( $message['role'] ) : '';
			$content = isset( $message['content'] ) ? $this->resolve_content_to_string( $message['content'] ) : '';

			if ( '' === $role || '' === $content ) {
				continue;
			}

			$entry = array(
				'role'    => $role,
				'content' => $content,
			);

			if ( isset( $message['name'] ) && is_string( $message['name'] ) && '' !== $message['name'] ) {
				$entry['name'] = $message['name'];
			}

			if ( isset( $message['tool_call_id'] ) && is_string( $message['tool_call_id'] ) && '' !== $message['tool_call_id'] ) {
				$entry['tool_call_id'] = $message['tool_call_id'];
			}

			$normalized[] = $entry;
		}

		return $normalized;
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

	// =========================================================================
	// Slash command parsing & routing
	// =========================================================================

	/**
	 * Parse the first bot_command entity from a Telegram message.
	 *
	 * Telegram sends `bot_command` entities in the `entities` array. This
	 * method extracts the command at offset 0 (the leading command) and
	 * returns a normalised structure with the command name (without `/`)
	 * and any arguments that follow.
	 *
	 * When no entities are present, falls back to checking if the text
	 * starts with `/` as a simple heuristic.
	 *
	 * @since 1.0.0
	 *
	 * @param array $message Telegram message object.
	 * @return array|null Array with 'command' and 'args' keys, or null.
	 */
	protected function parse_bot_command( array $message ) {
		$text     = isset( $message['text'] ) ? (string) $message['text'] : '';
		$entities = isset( $message['entities'] ) && is_array( $message['entities'] ) ? $message['entities'] : array();

		// Strategy 1: Use Telegram's entity metadata for precision.
		foreach ( $entities as $entity ) {
			if ( ! isset( $entity['type'] ) || 'bot_command' !== $entity['type'] ) {
				continue;
			}
			$offset = isset( $entity['offset'] ) ? (int) $entity['offset'] : -1;
			$length = isset( $entity['length'] ) ? (int) $entity['length'] : 0;

			// We only handle the first command at the very start of the message.
			if ( 0 !== $offset || $length < 2 ) {
				continue;
			}

			$raw_command = mb_substr( $text, 1, $length - 1 ); // strip leading '/'
			// Remove @bot_username suffix (e.g. "/help@my_bot" → "help").
			$parts   = explode( '@', $raw_command, 2 );
			$command = strtolower( trim( $parts[0] ) );
			$args    = trim( mb_substr( $text, $length ) );

			return array(
				'command' => $command,
				'args'    => $args,
			);
		}

		// Strategy 2: Fallback – simple prefix check when no entities are present.
		if ( isset( $text[0] ) && '/' === $text[0] ) {
			$space_pos = strpos( $text, ' ' );
			$raw       = false !== $space_pos ? substr( $text, 1, $space_pos - 1 ) : substr( $text, 1 );
			$parts     = explode( '@', $raw, 2 );
			$command   = strtolower( trim( $parts[0] ) );
			$args      = false !== $space_pos ? trim( substr( $text, $space_pos + 1 ) ) : '';

			if ( '' !== $command ) {
				return array(
					'command' => $command,
					'args'    => $args,
				);
			}
		}

		return null;
	}

	/**
	 * Handle a parsed bot command and optionally send a reply.
	 *
	 * Built-in commands (/start, /help, /settings, /status, /cancel) are
	 * handled directly. Unrecognised commands return false so the caller
	 * can fall through to the AI auto-reply pipeline.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $parsed    Parsed command with 'command' and 'args'.
	 * @param array  $message   Original Telegram message object.
	 * @param string $chat_id   Chat ID.
	 * @param string $from_id   Sender ID.
	 * @param string $chat_type Chat type (private, group, supergroup).
	 * @return bool True if the command was handled (reply sent or silenced).
	 */
	protected function handle_bot_command( array $parsed, array $message, $chat_id, $from_id, $chat_type ) {
		$command = $parsed['command'];
		$args    = $parsed['args'];

		// Retrieve the active connection so we can check the disabled-commands list.
		$connection        = $this->get_active_telegram_connection();
		$disabled_commands = ( $connection && isset( $connection['disabled_commands'] ) && is_array( $connection['disabled_commands'] ) )
			? $connection['disabled_commands']
			: array();

		/**
		 * Filters the built-in bot command response before the default handler.
		 *
		 * Return a non-null string to override the default reply for a command.
		 * Return the boolean `true` to silently consume the command (no reply).
		 * Return null to let the default handler or AI pipeline process it.
		 *
		 * @since 1.0.0
		 *
		 * @param string|bool|null $response  Response text, true to silence, or null.
		 * @param string           $command   Command name without leading '/'.
		 * @param string           $args      Arguments after the command.
		 * @param array            $message   Original Telegram message object.
		 * @param string           $chat_type Chat type.
		 */
		$custom_response = apply_filters( 'wp_mcp_ai_telegram_bot_command_response', null, $command, $args, $message, $chat_type );

		if ( true === $custom_response ) {
			return true; // Silently consumed.
		}

		if ( is_string( $custom_response ) && '' !== $custom_response ) {
			$this->send_command_reply( $chat_id, $custom_response, $message );
			return true;
		}

		// Built-in command handlers – skip disabled commands so the AI pipeline
		// can handle them instead (or they can just be ignored).
		// If the command is in the disabled list, fall through to the unhandled
		// command action below (returning false so the AI pipeline can take over).
		if ( ! in_array( $command, $disabled_commands, true ) ) {
			switch ( $command ) {
				case 'start':
					$this->cmd_start( $chat_id, $args, $message );
					return true;

				case 'help':
					$this->cmd_help( $chat_id, $chat_type, $message );
					return true;

				case 'settings':
					$this->cmd_settings( $chat_id, $message );
					return true;

				case 'status':
					$this->cmd_status( $chat_id, $message );
					return true;

				case 'cancel':
					$this->cmd_cancel( $chat_id, $from_id, $message );
					return true;

				case 'tools':
					$this->cmd_tools( $chat_id, $args, $message );
					return true;

				case 'balance':
					$this->cmd_balance( $chat_id, $message );
					return true;

				case 'app':
					$this->cmd_app( $chat_id, $message );
					return true;

				case 'vectorstore':
					$this->cmd_vectorstore( $chat_id, $message );
					return true;
			}
		}

		// Dispatch to the global slash command handler for dynamically registered commands.
		if ( ! in_array( $command, $disabled_commands, true ) ) {
			global $wp_mcp_ai_slash_command_handler;
			if ( $wp_mcp_ai_slash_command_handler instanceof WP_MCP_AI_Slash_Command_Handler
				&& $wp_mcp_ai_slash_command_handler->command_exists( $command )
			) {
				$from    = isset( $message['from'] ) ? $message['from'] : array();
				$tg_id   = isset( $from['id'] ) ? (string) $from['id'] : '';
				$user_id = $this->resolve_wp_user_from_telegram_id( $tg_id );

				$context = array(
					'user_id'        => $user_id ? $user_id : 0,
					'request_time'   => current_time( 'mysql' ),
					'ip_address'     => '',
					'correlation_id' => 'telegram_' . wp_generate_uuid4(),
					'channel'        => 'telegram',
					'chat_id'        => $chat_id,
				);

				$input  = '/' . $command . ( '' !== $args ? ' ' . $args : '' );
				$result = $wp_mcp_ai_slash_command_handler->execute( $input, $context );

				if ( is_wp_error( $result ) ) {
					if ( ! $user_id && 'insufficient_capability' === $result->get_error_code() ) {
						$reply = "🔐 This command requires a linked WordPress account.\n\nUse /settings to link your account.";
					} else {
						$reply = '⚠️ ' . wp_strip_all_tags( $result->get_error_message() );
					}
				} elseif ( is_string( $result ) && '' !== $result ) {
					$reply = wp_strip_all_tags( $result );
				} elseif ( is_array( $result ) && ! empty( $result['message'] ) ) {
					$reply = wp_strip_all_tags( (string) $result['message'] );
				} elseif ( is_array( $result ) && ! empty( $result['output'] ) ) {
					$reply = wp_strip_all_tags( (string) $result['output'] );
				} else {
					/* translators: %s: command name */
					$reply = '✅ ' . sprintf( __( 'Command /%s executed.', 'mcp-ai-wpoos-pro' ), sanitize_key( $command ) );
				}

				$this->send_command_reply( $chat_id, $reply, $message );
				return true;
			}
		}

		/**
		 * Fires when an unrecognised bot command is received.
		 *
		 * @since 1.0.0
		 *
		 * @param string $command   Command name.
		 * @param string $args      Arguments.
		 * @param array  $message   Telegram message.
		 * @param string $chat_type Chat type.
		 */
		do_action( 'wp_mcp_ai_telegram_unhandled_command', $command, $args, $message, $chat_type );

		// Return false so the AI pipeline can handle the message.
		return false;
	}

	/**
	 * Handle /start – greet the user and introduce the bot's capabilities.
	 *
	 * @param string $chat_id Chat ID.
	 * @param string $args    Deep-link parameter (if any).
	 * @param array  $message Telegram message.
	 */
	protected function cmd_start( $chat_id, $args, array $message ) {
		$site_name  = get_bloginfo( 'name' );
		$connection = $this->get_active_telegram_connection();

		// Use the custom welcome message saved on the connection when available.
		if ( $connection && ! empty( $connection['welcome_message'] ) ) {
			$text = $connection['welcome_message'];
		} else {
			$text = sprintf(
				"👋 Welcome to %s!\n\nI'm your AI assistant. You can ask me anything or use /help to see available commands.\n\nJust type your question to get started!",
				$site_name
			);
		}

		/**
		 * Fires when /start is received with a deep-link parameter.
		 *
		 * @since 1.0.0
		 *
		 * @param string $args    Deep-link parameter.
		 * @param string $chat_id Chat ID.
		 * @param array  $message Telegram message.
		 */
		if ( '' !== $args ) {
			do_action( 'wp_mcp_ai_telegram_start_deeplink', $args, $chat_id, $message );

			// Handle built-in deep links: tool_SLUG, content_TYPE, shop, balance.
			if ( 0 === strpos( $args, 'tool_' ) ) {
				$tool_slug = sanitize_text_field( substr( $args, 5 ) );
				$text      = "🔧 Opening tool: `$tool_slug`\n\nUse the Mini App to execute this tool with a full parameter form.\n\n/app – Open Mini App";
			} elseif ( 0 === strpos( $args, 'content_' ) ) {
				$content_type = sanitize_text_field( substr( $args, 8 ) );
				$text         = "📝 Content type: `$content_type`\n\nOpen the Mini App to browse and edit your content.\n\n/app – Open Mini App";
			} elseif ( 'shop' === $args ) {
				$text = "🛒 Open the Mini App to visit the Shop and purchase credits.\n\n/app – Open Mini App";
			} elseif ( 'balance' === $args ) {
				$this->cmd_balance( $chat_id, $message );
				return;
			}
		}

		$this->send_command_reply( $chat_id, $text, $message, 'Markdown' );
	}

	/**
	 * Handle /help – list available commands, context-aware for groups.
	 *
	 * @param string $chat_id   Chat ID.
	 * @param string $chat_type Chat type.
	 * @param array  $message   Telegram message.
	 */
	protected function cmd_help( $chat_id, $chat_type, array $message ) {
		$lines = array(
			"📖 *Available Commands*\n",
			'/start – Start the bot & see welcome message',
			'/help – Show this help message',
			'/tools – Browse and run AI tools',
			'/balance – Check your credits balance',
			'/app – Open the Mini App',
			'/settings – Open the Mini App settings',
			'/status – Check bot connection status',
			'/cancel – Reset your conversation history',
			'/vectorstore – Get vector store info for this assistant',
		);

		// Append dynamically registered slash commands.
		foreach ( $this->get_registered_slash_commands() as $cmd_name => $cmd_desc ) {
			$lines[] = '/' . $cmd_name . ' – ' . wp_strip_all_tags( $cmd_desc );
		}

		$is_group = in_array( $chat_type, array( 'group', 'supergroup' ), true );
		if ( $is_group ) {
			$connection = $this->get_active_telegram_connection();
			if ( $connection && ! empty( $connection['require_mention'] ) ) {
				$lines[] = "\n💡 *Tip:* In groups, mention me with @bot\\_username or reply to my messages to get a response.";
			} else {
				$lines[] = "\n💡 *Tip:* I respond to every message in this group. You can also mention me with @bot\\_username or reply to my messages.";
			}
		}

		$lines[] = "\nYou can also type any question and I'll respond using AI.";

		$this->send_command_reply( $chat_id, implode( "\n", $lines ), $message, 'Markdown' );
	}

	/**
	 * Handle /settings – provide a link to the Mini App settings page.
	 *
	 * @param string $chat_id Chat ID.
	 * @param array  $message Telegram message.
	 */
	protected function cmd_settings( $chat_id, array $message ) {
		$connection   = $this->get_active_telegram_connection();
		$bot_username = '';
		if ( $connection && ! empty( $connection['bot_username'] ) ) {
			$bot_username = ltrim( sanitize_text_field( $connection['bot_username'] ), '@' );
		}

		if ( '' !== $bot_username ) {
			$text = sprintf(
				"⚙️ Open the settings panel:\nhttps://t.me/%s?startapp=settings\n\nYou can manage your preferences, link your WordPress account, and configure notifications.",
				$bot_username
			);
		} else {
			$text = "⚙️ Settings are available through the bot's Mini App. Tap the menu button to open it.";
		}

		$this->send_command_reply( $chat_id, $text, $message );
	}

	/**
	 * Handle /status – report the connection and assistant status.
	 *
	 * @param string $chat_id Chat ID.
	 * @param array  $message Telegram message.
	 */
	protected function cmd_status( $chat_id, array $message ) {
		$connection = $this->get_active_telegram_connection();
		$lines      = array( '📊 *Bot Status*' );

		if ( $connection ) {
			$lines[]                = '✅ Connection: Active';
			$automation_rules       = get_option( 'wp_mcp_ai_chat_channels_automation_rules', array() );
			$assigned_assistant_ids = $this->resolve_assistant_ids( $connection, $automation_rules );
			$lines[]                = sprintf( '🤖 Assistants: %d configured', count( $assigned_assistant_ids ) );
			$lines[]                = sprintf( '👥 Groups: %s', ! empty( $connection['enable_groups'] ) ? 'Enabled' : 'Disabled' );
			$lines[]                = sprintf( '📢 Channels: %s', ! empty( $connection['enable_channels'] ) ? 'Enabled' : 'Disabled' );
		} else {
			$lines[] = '❌ Connection: Not configured';
		}

		$lines[] = sprintf( '🌐 Site: %s', get_bloginfo( 'name' ) );

		$this->send_command_reply( $chat_id, implode( "\n", $lines ), $message, 'Markdown' );
	}

	/**
	 * Handle /cancel – clear the user's conversation history.
	 *
	 * @param string $chat_id Chat ID.
	 * @param string $from_id Sender ID.
	 * @param array  $message Telegram message.
	 */
	protected function cmd_cancel( $chat_id, $from_id, array $message ) {
		$connection    = $this->get_active_telegram_connection();
		$connection_id = ( $connection && isset( $connection['id'] ) ) ? sanitize_key( $connection['id'] ) : '';

		$sender = '' !== $from_id ? $from_id : $chat_id;

		if ( '' !== $connection_id ) {
			$history_key = $this->get_conversation_history_key( $sender, $connection_id );
			delete_transient( $history_key );
		}

		$this->send_command_reply( $chat_id, '🔄 Conversation history cleared. Send a new message to start fresh!', $message );
	}

	/**
	 * Handle /tools – list the tools available to the assistant assigned to this
	 * Telegram connection.
	 *
	 * The list is scoped to the assistant configured for this connection so that
	 * users only see tools that are actually applicable (e.g. a customer-support
	 * assistant should not advertise developer or admin tools). When no
	 * assistant is configured, or the assistant has no tool restriction, the
	 * full registry is displayed as a fallback.
	 *
	 * @since 1.1.3
	 *
	 * @param string $chat_id Chat ID.
	 * @param string $args    Optional tool slug (reserved for future use).
	 * @param array  $message Telegram message.
	 */
	protected function cmd_tools( $chat_id, $args, array $message ) {
		if ( ! function_exists( 'wp_mcp_ai_get_tool_registry' ) ) {
			$this->send_command_reply( $chat_id, '🔧 Tool registry is not available.', $message );
			return;
		}

		$registry = wp_mcp_ai_get_tool_registry();
		if ( ! $registry ) {
			$this->send_command_reply( $chat_id, '🔧 Tool registry is not available.', $message );
			return;
		}

		// Scope the listing to the tools configured on the connection's assistant.
		// Falls back to the full registry when no assistant or tool restriction exists.
		$tool_slugs      = array(); // Empty = no restriction found yet.
		$assistant_label = ''; // Human-readable name shown in the header.
		$assistant_id    = 0;

		$connection = $this->get_active_telegram_connection();
		if ( $connection ) {
			$automation_rules = get_option( 'wp_mcp_ai_chat_channels_automation_rules', array() );
			$assistant_ids    = $this->resolve_assistant_ids( $connection, $automation_rules );
			$assistant_id     = ! empty( $assistant_ids ) ? (int) $assistant_ids[0] : 0;

			if ( $assistant_id && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
				$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
				if ( ! empty( $config['tools'] ) && is_array( $config['tools'] ) ) {
					$tool_slugs = $config['tools'];
				}
				// Build a friendly label from the assistant's post title.
				$post = get_post( $assistant_id );
				if ( $post ) {
					$assistant_label = $post->post_title;
				}
			}
		}

		// Build the display list.
		if ( ! empty( $tool_slugs ) ) {
			// Assistant has an explicit tool list — show only those tools.
			// Slugs in the configuration are already stored as sanitize_key() values,
			// so we only cast here to avoid double-sanitization altering them.
			$display_tools = array();
			foreach ( $tool_slugs as $slug ) {
				$slug = (string) $slug;
				$tool = $registry->get_tool( $slug );
				if ( $tool ) {
					$display_tools[ $slug ] = $tool;
				} else {
					WP_MCP_AI_Logger::log_event(
						'debug',
						'Telegram /tools: configured tool slug not found in registry — may be disabled or removed. Verify the assistant tool configuration.',
						array(
							'slug'         => $slug,
							'assistant_id' => $assistant_id,
						)
					);
				}
			}
		} else {
			// No restriction configured — fall back to the full registry.
			$display_tools = $registry->get_all_tools();
		}

		$count = count( $display_tools );

		// Build header — sanitize the label so Markdown special chars don't break formatting.
		$safe_label = $this->sanitize_for_telegram_markdown( $assistant_label );
		if ( '' !== $safe_label ) {
			/* translators: 1: assistant name, 2: number of tools */
			$header = sprintf( '🔧 *%s – Available Tools* (%d)', $safe_label, $count );
		} else {
			/* translators: %d: number of tools */
			$header = sprintf( '🔧 *Available Tools* (%d)', $count );
		}

		$text  = $header . "\n\n";
		$text .= "Use the Mini App to browse and execute tools with full parameter forms.\n\n";

		$i = 0;
		foreach ( $display_tools as $slug => $tool ) {
			if ( $i >= 20 ) {
				$text .= "\n_… and " . ( $count - 20 ) . ' more. Open the Mini App to see all._';
				break;
			}
			$raw_name = method_exists( $tool, 'get_name' ) ? $tool->get_name() : $slug;
			$name     = $this->sanitize_for_telegram_markdown( $raw_name );
			$text    .= "• `$slug` – $name\n";
			++$i;
		}

		$this->send_command_reply( $chat_id, $text, $message, 'Markdown' );
	}

	/**
	 * Handle /balance – show user's credits balance.
	 *
	 * @since 1.1.3
	 *
	 * @param string $chat_id Chat ID.
	 * @param array  $message Telegram message.
	 */
	protected function cmd_balance( $chat_id, array $message ) {
		$from    = isset( $message['from'] ) ? $message['from'] : array();
		$tg_id   = isset( $from['id'] ) ? (string) $from['id'] : '';
		$user_id = $this->resolve_wp_user_from_telegram_id( $tg_id );

		if ( ! $user_id ) {
			$this->send_command_reply( $chat_id, '💰 Please link your WordPress account first using /settings.', $message );
			return;
		}

		$balance = (int) get_user_meta( $user_id, '_wp_mcp_ai_tma_stars_balance', true );

		$text  = "💰 *Your Balance*\n\n";
		$text .= "⭐ Stars: $balance\n\n";
		$text .= '_Use the Mini App Shop tab to purchase more credits._';

		$this->send_command_reply( $chat_id, $text, $message, 'Markdown' );
	}

	/**
	 * Handle /app – send a button to open the Mini App.
	 *
	 * @since 1.1.3
	 *
	 * @param string $chat_id Chat ID.
	 * @param array  $message Telegram message.
	 */
	protected function cmd_app( $chat_id, array $message ) {
		$connection = $this->get_active_telegram_connection();
		if ( ! $connection || empty( $connection['api_key'] ) ) {
			return;
		}

		// Respect the "Enable Mini App" connection setting (default: enabled for
		// backwards compatibility with connections that pre-date this setting).
		$mini_app_enabled = ! array_key_exists( 'enable_mini_app', $connection ) || ! empty( $connection['enable_mini_app'] );
		if ( ! $mini_app_enabled ) {
			$this->send_command_reply( $chat_id, '📱 The Mini App is not available for this bot.', $message );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
		if ( '' === $bot_token ) {
			return;
		}

		$mini_app_url = rest_url( 'mcp-ai/v1/telegram-mini-app' );
		$endpoint     = sprintf( 'https://api.telegram.org/bot%s/sendMessage', rawurlencode( $bot_token ) );

		$payload = array(
			'chat_id'      => $chat_id,
			'text'         => '📱 Tap the button below to open the Mini App:',
			'reply_markup' => array(
				'inline_keyboard' => array(
					array(
						array(
							'text'    => '📱 Open Mini App',
							'web_app' => array( 'url' => $mini_app_url ),
						),
					),
				),
			),
		);

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return;
		}

		wp_remote_post(
			$endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 15,
				'body'    => $body,
			)
		);
	}

	/**
	 * Handle /vectorstore – retrieve vector store information for the assistant.
	 *
	 * @since 1.1.5
	 *
	 * @param string $chat_id Chat ID.
	 * @param array  $message Telegram message.
	 */
	protected function cmd_vectorstore( $chat_id, array $message ) {
		// Resolve the connected assistant to obtain its vector store configuration.
		$vector_store_id = '';
		$assistant_name  = '';

		$connection = $this->get_active_telegram_connection();
		if ( $connection ) {
			$automation_rules = get_option( 'wp_mcp_ai_chat_channels_automation_rules', array() );
			$assistant_ids    = $this->resolve_assistant_ids( $connection, $automation_rules );
			$assistant_id     = ! empty( $assistant_ids ) ? (int) $assistant_ids[0] : 0;

			if ( $assistant_id && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
				$config = WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
				if ( ! empty( $config['vector_store_id'] ) ) {
					$vector_store_id = sanitize_text_field( $config['vector_store_id'] );
				}
				$post = get_post( $assistant_id );
				if ( $post ) {
					$assistant_name = $post->post_title;
				}
			}
		}

		if ( empty( $vector_store_id ) ) {
			$this->send_command_reply(
				$chat_id,
				'🗄️ No vector store is configured for this assistant.',
				$message
			);
			return;
		}

		// Execute the get_vector_store tool via the registry when available,
		// falling back to a direct instantiation.
		$tool   = null;
		$result = null;

		if ( function_exists( 'wp_mcp_ai_get_tool_registry' ) ) {
			$registry = wp_mcp_ai_get_tool_registry();
			if ( $registry ) {
				$tool = $registry->get_tool( 'get_vector_store' );
			}
		}

		if ( ! $tool ) {
			$tool_file = WP_MCP_AI_PATH . 'includes/tools/class-wp-mcp-ai-tool-get-vector-store.php';
			if ( file_exists( $tool_file ) ) {
				require_once $tool_file;
			}
			if ( class_exists( 'WP_MCP_AI_Tool_Get_Vector_Store' ) ) {
				$tool = new WP_MCP_AI_Tool_Get_Vector_Store();
			}
		}

		if ( ! $tool ) {
			$this->send_command_reply(
				$chat_id,
				'🗄️ Vector store tool is not available.',
				$message
			);
			return;
		}

		$result = $tool->execute(
			array( 'vector_store_id' => $vector_store_id ),
			array()
		);

		if ( empty( $result['success'] ) ) {
			$error = ! empty( $result['error'] ) ? $result['error'] : __( 'Unknown error.', 'mcp-ai-wpoos' );
			$this->send_command_reply(
				$chat_id,
				'🗄️ ' . wp_strip_all_tags( $error ),
				$message
			);
			return;
		}

		$data   = ! empty( $result['data'] ) ? $result['data'] : array();
		$name   = ! empty( $data['name'] ) ? $this->sanitize_for_telegram_markdown( $data['name'] ) : __( 'Unknown', 'mcp-ai-wpoos' );
		$id     = ! empty( $data['id'] ) ? $this->sanitize_for_telegram_markdown( $data['id'] ) : $this->sanitize_for_telegram_markdown( $vector_store_id );
		$status = ! empty( $data['status'] ) ? $this->sanitize_for_telegram_markdown( $data['status'] ) : __( 'unknown', 'mcp-ai-wpoos' );

		$header = '🗄️ *Vector Store*';
		if ( '' !== $assistant_name ) {
			$safe_assistant = $this->sanitize_for_telegram_markdown( $assistant_name );
			$header         = "🗄️ *Vector Store – $safe_assistant*";
		}

		$text  = $header . "\n\n";
		$text .= "*Name:* $name\n";
		$text .= "*ID:* `$id`\n";
		$text .= "*Status:* $status\n";

		if ( ! empty( $data['file_counts'] ) && is_array( $data['file_counts'] ) ) {
			$counts = $data['file_counts'];
			$text  .= "\n*Files:*\n";
			if ( isset( $counts['completed'] ) ) {
				$text .= '  ✅ Completed: ' . (int) $counts['completed'] . "\n";
			}
			if ( isset( $counts['in_progress'] ) ) {
				$text .= '  🔄 In progress: ' . (int) $counts['in_progress'] . "\n";
			}
			if ( isset( $counts['failed'] ) ) {
				$text .= '  ❌ Failed: ' . (int) $counts['failed'] . "\n";
			}
			if ( isset( $counts['cancelled'] ) ) {
				$text .= '  ⏹ Cancelled: ' . (int) $counts['cancelled'] . "\n";
			}
		}

		if ( ! empty( $data['expires_at'] ) ) {
			$expires = date_i18n( get_option( 'date_format' ), (int) $data['expires_at'] );
			$text   .= "\n*Expires:* " . $this->sanitize_for_telegram_markdown( $expires ) . "\n";
		}

		$this->send_command_reply( $chat_id, $text, $message, 'Markdown' );
	}

	/**
	 * Process an incoming inline query from Telegram.
	 *
	 * When a user types @botname in any chat, this returns matching tools
	 * and recent content as inline results.
	 *
	 * @since 1.1.3
	 *
	 * @param array $inline_query The inline_query object from Telegram.
	 */
	protected function process_inline_query( array $inline_query ) {
		$query_id = isset( $inline_query['id'] ) ? (string) $inline_query['id'] : '';
		$query    = isset( $inline_query['query'] ) ? sanitize_text_field( $inline_query['query'] ) : '';

		if ( empty( $query_id ) ) {
			return;
		}

		$connection = $this->get_active_telegram_connection();
		if ( ! $connection || empty( $connection['api_key'] ) ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
		if ( '' === $bot_token ) {
			return;
		}

		$results = array();

		// Search published posts matching the query.
		if ( strlen( $query ) >= 2 ) {
			$posts = get_posts(
				array(
					'post_type'      => 'any',
					'post_status'    => 'publish',
					's'              => $query,
					'posts_per_page' => 10,
					'orderby'        => 'relevance',
				)
			);

			foreach ( $posts as $post ) {
				$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '…' );
				$url     = get_permalink( $post->ID );

				$results[] = array(
					'type'                  => 'article',
					'id'                    => 'post_' . $post->ID,
					'title'                 => get_the_title( $post ),
					'description'           => $excerpt,
					'url'                   => $url,
					'input_message_content' => array(
						'message_text' => get_the_title( $post ) . "\n" . $url,
					),
				);
			}
		}

		// Answer the inline query.
		$endpoint = sprintf( 'https://api.telegram.org/bot%s/answerInlineQuery', rawurlencode( $bot_token ) );

		$payload = array(
			'inline_query_id' => $query_id,
			'results'         => $results,
			'cache_time'      => 60,
			'is_personal'     => true,
		);

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return;
		}

		wp_remote_post(
			$endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 15,
				'body'    => $body,
			)
		);
	}

	/**
	 * Process a pre-checkout query for Telegram Stars payments.
	 *
	 * Validates the payment request and responds with approval. Telegram requires
	 * a response within 10 seconds or the payment will be cancelled.
	 *
	 * @since 1.1.3
	 *
	 * @param array $pre_checkout_query The pre_checkout_query object from Telegram.
	 */
	protected function process_pre_checkout_query( array $pre_checkout_query ) {
		$query_id = isset( $pre_checkout_query['id'] ) ? (string) $pre_checkout_query['id'] : '';

		if ( empty( $query_id ) ) {
			return;
		}

		$connection = $this->get_active_telegram_connection();
		if ( ! $connection || empty( $connection['api_key'] ) ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
		if ( '' === $bot_token ) {
			return;
		}

		/**
		 * Filters whether to approve a Telegram Stars pre-checkout query.
		 *
		 * Return a non-empty string to reject the payment with that error message.
		 * Return empty string or false to approve.
		 *
		 * @since 1.1.3
		 *
		 * @param string $error_message    Error message (empty = approve).
		 * @param array  $pre_checkout_query The pre_checkout_query data.
		 */
		$error = apply_filters( 'wp_mcp_ai_telegram_pre_checkout_validation', '', $pre_checkout_query );

		$endpoint = sprintf( 'https://api.telegram.org/bot%s/answerPreCheckoutQuery', rawurlencode( $bot_token ) );

		$payload = array( 'pre_checkout_query_id' => $query_id );

		if ( ! empty( $error ) ) {
			$payload['ok']            = false;
			$payload['error_message'] = sanitize_text_field( (string) $error );
		} else {
			$payload['ok'] = true;
		}

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return;
		}

		wp_remote_post(
			$endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 10,
				'body'    => $body,
			)
		);

		WP_MCP_AI_Logger::log_event(
			'telegram_pre_checkout',
			'Pre-checkout query processed.',
			array(
				'query_id' => $query_id,
				'approved' => empty( $error ),
			)
		);
	}

	/**
	 * Process a successful Telegram Stars payment.
	 *
	 * Credits the user's balance and logs the transaction.
	 *
	 * @since 1.1.3
	 *
	 * @param array $message The message containing successful_payment data.
	 */
	protected function process_successful_payment( array $message ) {
		$payment = isset( $message['successful_payment'] ) ? $message['successful_payment'] : array();
		$from    = isset( $message['from'] ) ? $message['from'] : array();
		$chat_id = isset( $message['chat']['id'] ) ? (string) $message['chat']['id'] : '';
		$tg_id   = isset( $from['id'] ) ? (string) $from['id'] : '';

		$currency        = isset( $payment['currency'] ) ? sanitize_text_field( $payment['currency'] ) : '';
		$total_amount    = isset( $payment['total_amount'] ) ? absint( $payment['total_amount'] ) : 0;
		$invoice_payload = isset( $payment['invoice_payload'] ) ? sanitize_text_field( $payment['invoice_payload'] ) : '';
		$charge_id       = isset( $payment['telegram_payment_charge_id'] ) ? sanitize_text_field( $payment['telegram_payment_charge_id'] ) : '';
		$provider_id     = isset( $payment['provider_payment_charge_id'] ) ? sanitize_text_field( $payment['provider_payment_charge_id'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'telegram_payment_received',
			'Telegram Stars payment received.',
			array(
				'telegram_id' => $tg_id,
				'currency'    => $currency,
				'amount'      => $total_amount,
				'payload'     => $invoice_payload,
				'charge_id'   => $charge_id,
				'provider_id' => $provider_id,
			)
		);

		// Resolve the WordPress user from Telegram ID.
		$user_id = $this->resolve_wp_user_from_telegram_id( $tg_id );

		if ( $user_id ) {
			// Credit the user's stars balance.
			$current_balance = (int) get_user_meta( $user_id, '_wp_mcp_ai_tma_stars_balance', true );
			update_user_meta( $user_id, '_wp_mcp_ai_tma_stars_balance', $current_balance + $total_amount );

			// Append to payment history.
			$history = get_user_meta( $user_id, '_wp_mcp_ai_tma_payment_history', true );
			if ( ! is_array( $history ) ) {
				$history = array();
			}
			$history[] = array(
				'date'      => gmdate( 'Y-m-d H:i:s' ),
				'currency'  => $currency,
				'amount'    => $total_amount,
				'payload'   => $invoice_payload,
				'charge_id' => $charge_id,
			);
			// Keep last 100 entries.
			if ( count( $history ) > 100 ) {
				$history = array_slice( $history, -100 );
			}
			update_user_meta( $user_id, '_wp_mcp_ai_tma_payment_history', $history );
		}

		/**
		 * Fires after a successful Telegram Stars payment is processed.
		 *
		 * @since 1.1.3
		 *
		 * @param array    $payment Payment data from Telegram.
		 * @param int|null $user_id WordPress user ID or null.
		 * @param array    $message Full Telegram message.
		 */
		do_action( 'wp_mcp_ai_telegram_payment_received', $payment, $user_id, $message );

		// Send confirmation to the user.
		if ( ! empty( $chat_id ) ) {
			$text  = "✅ *Payment Received!*\n\n";
			$text .= "⭐ Amount: $total_amount $currency\n";
			if ( $user_id ) {
				$new_balance = (int) get_user_meta( $user_id, '_wp_mcp_ai_tma_stars_balance', true );
				$text       .= "💰 New Balance: $new_balance Stars\n";
			}
			$text .= "\nThank you for your purchase!";

			$this->send_command_reply( $chat_id, $text, $message, 'Markdown' );
		}
	}

	/**
	 * Resolve a WordPress user ID from a Telegram user ID.
	 *
	 * @since 1.1.3
	 *
	 * @param string $telegram_id Telegram user ID.
	 * @return int|null WordPress user ID or null.
	 */
	protected function resolve_wp_user_from_telegram_id( $telegram_id ) {
		if ( empty( $telegram_id ) ) {
			return null;
		}

		$users = get_users(
			array(
				'meta_key'   => '_wp_mcp_ai_telegram_id',
				'meta_value' => $telegram_id,
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		return ! empty( $users ) ? (int) $users[0] : null;
	}

	/**
	 * Send a reply to a bot command via the Telegram Bot API.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $chat_id    Chat ID to reply in.
	 * @param string      $text       Reply text.
	 * @param array       $message    Original Telegram message (used for reply_to_message_id in groups).
	 * @param string|null $parse_mode Optional parse_mode (Markdown, MarkdownV2, HTML).
	 */
	protected function send_command_reply( $chat_id, $text, array $message = array(), $parse_mode = null ) {
		$connection = $this->get_active_telegram_connection();

		if ( ! $connection || empty( $connection['api_key'] ) ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );

		if ( '' === $bot_token ) {
			return;
		}

		if ( mb_strlen( $text ) > self::MAX_MESSAGE_LENGTH ) {
			$text = mb_substr( $text, 0, self::MAX_MESSAGE_LENGTH - 3 ) . '...';
		}

		$endpoint = sprintf( 'https://api.telegram.org/bot%s/sendMessage', rawurlencode( $bot_token ) );

		$payload = array(
			'chat_id' => $chat_id,
			'text'    => $text,
		);

		if ( null !== $parse_mode ) {
			$payload['parse_mode'] = $parse_mode;
		}

		// In group chats, reply to the original command message.
		$chat_type = isset( $message['chat']['type'] ) ? (string) $message['chat']['type'] : 'private';
		if ( in_array( $chat_type, array( 'group', 'supergroup' ), true ) && isset( $message['message_id'] ) ) {
			$payload['reply_to_message_id'] = (int) $message['message_id'];
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return;
		}

		wp_remote_post(
			$endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 15,
				'body'    => $body,
			)
		);
	}

	/**
	 * Get slash commands registered with the global slash command handler that
	 * are not already handled as built-in Telegram bot commands.
	 *
	 * @since 1.0.0
	 *
	 * @return array Associative array of command_name => description.
	 */
	protected function get_registered_slash_commands() {
		global $wp_mcp_ai_slash_command_handler;

		if ( ! ( $wp_mcp_ai_slash_command_handler instanceof WP_MCP_AI_Slash_Command_Handler ) ) {
			return array();
		}

		// Commands handled natively by this controller – skip to avoid duplication.
		$builtin = array( 'start', 'help', 'settings', 'status', 'cancel', 'tools', 'balance', 'app', 'vectorstore' );

		$registered = array();
		foreach ( $wp_mcp_ai_slash_command_handler->get_commands() as $name => $config ) {
			if ( ! in_array( $name, $builtin, true ) ) {
				$registered[ $name ] = ! empty( $config['description'] ) ? (string) $config['description'] : $name;
			}
		}

		return $registered;
	}

	/**
	 * Get the default set of bot commands to register with Telegram.
	 *
	 * These are the built-in commands the webhook controller handles.
	 * Developers can filter this list to add or remove commands.
	 *
	 * @since 1.0.0
	 *
	 * @param string $scope_type The BotCommandScope type for context-aware commands.
	 * @return array Array of command arrays with 'command' and 'description'.
	 */
	public static function get_default_commands( $scope_type = 'default' ) {
		$commands = array(
			array(
				'command'     => 'start',
				'description' => 'Start the bot and see welcome message',
			),
			array(
				'command'     => 'help',
				'description' => 'List available commands',
			),
			array(
				'command'     => 'settings',
				'description' => 'Open settings panel',
			),
			array(
				'command'     => 'status',
				'description' => 'Check bot connection status',
			),
			array(
				'command'     => 'cancel',
				'description' => 'Clear conversation history',
			),
			array(
				'command'     => 'tools',
				'description' => 'Browse and run AI tools',
			),
			array(
				'command'     => 'balance',
				'description' => 'Check your credits balance',
			),
			array(
				'command'     => 'app',
				'description' => 'Open the Mini App',
			),
			array(
				'command'     => 'vectorstore',
				'description' => 'Get vector store info for this assistant',
			),
		);

		// Merge dynamically registered slash commands that are not already listed.
		global $wp_mcp_ai_slash_command_handler;
		if ( $wp_mcp_ai_slash_command_handler instanceof WP_MCP_AI_Slash_Command_Handler ) {
			$builtin_names = wp_list_pluck( $commands, 'command' );
			foreach ( $wp_mcp_ai_slash_command_handler->get_commands() as $cmd_name => $config ) {
				if ( ! in_array( $cmd_name, $builtin_names, true ) ) {
					$desc       = ! empty( $config['description'] ) ? wp_strip_all_tags( (string) $config['description'] ) : $cmd_name;
					$commands[] = array(
						'command'     => $cmd_name,
						'description' => substr( $desc, 0, 256 ),
					);
				}
			}
		}

		/**
		 * Filters the default bot commands before registration with Telegram.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $commands   Array of command definitions.
		 * @param string $scope_type BotCommandScope type for context.
		 */
		return apply_filters( 'wp_mcp_ai_telegram_default_commands', $commands, $scope_type );
	}

	// =========================================================================
	// Group & channel support helpers
	// =========================================================================

	/**
	 * Resolve the list of assistant IDs for the given connection, falling back
	 * to automation rules and then any published assistant.
	 *
	 * Extracted from process_message() so the same resolution logic can be
	 * reused by channel-post and membership handlers.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection       Active Telegram connection.
	 * @param array $automation_rules Saved automation rule settings.
	 * @return int[] Array of assistant post IDs (may be empty).
	 */
	protected function resolve_assistant_ids( array $connection, array $automation_rules = array() ) {
		$assigned = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_values( array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) ) )
			: array();

		if ( empty( $assigned ) && ! empty( $automation_rules['default_assistant_id'] ) ) {
			$assigned = array( absint( $automation_rules['default_assistant_id'] ) );
		}

		if ( empty( $assigned ) ) {
			$any_id = $this->get_any_assistant_id();
			if ( $any_id ) {
				$assigned = array( $any_id );
			}
		}

		return $assigned;
	}

	/**
	 * Check whether the message text contains an @bot_username mention.
	 *
	 * Used in group chats to determine if the bot is being addressed directly.
	 * First checks message entities (reliable, provided by Telegram), then
	 * falls back to a case-insensitive regex match against bot_username.
	 *
	 * When the optional $message array is provided and bot_username is not
	 * configured on the connection, the method inspects Telegram `mention`
	 * entities and accepts any bot mention (entity whose extracted text ends
	 * with "bot", which is a Telegram naming requirement for bots).
	 *
	 * @since 1.0.0
	 *
	 * @param string     $text       Message text.
	 * @param array|null $connection Active Telegram connection.
	 * @param array      $message    Optional full Telegram message object for entity-based detection.
	 * @return bool True if the bot's username is mentioned.
	 */
	protected function message_mentions_bot( $text, $connection, array $message = array() ) {
		$bot_username = '';
		if ( $connection && ! empty( $connection['bot_username'] ) ) {
			$bot_username = ltrim( sanitize_text_field( $connection['bot_username'] ), '@' );
		}

		// Strategy 1: regex match when bot_username is known.
		if ( '' !== $bot_username ) {
			if ( preg_match( '/@' . preg_quote( $bot_username, '/' ) . '(?:[^a-zA-Z0-9_]|$)/i', $text ) ) {
				return true;
			}
		}

		// Strategy 2: inspect Telegram mention entities from the message.
		// This helps when bot_username is not stored in the connection but
		// the user did @mention the bot in the chat.
		if ( ! empty( $message['entities'] ) && is_array( $message['entities'] ) ) {
			foreach ( $message['entities'] as $entity ) {
				if ( ! isset( $entity['type'] ) || 'mention' !== $entity['type'] ) {
					continue;
				}

				$offset         = isset( $entity['offset'] ) ? (int) $entity['offset'] : 0;
				$length         = isset( $entity['length'] ) ? (int) $entity['length'] : 0;
				$mention_text   = mb_substr( $text, $offset, $length );
				$mentioned_name = strtolower( ltrim( $mention_text, '@' ) );

				// If we know the bot_username, match exactly.
				if ( '' !== $bot_username && strtolower( $bot_username ) === $mentioned_name ) {
					return true;
				}

				// If bot_username is unknown, accept any mention whose name
				// ends with "bot" – Telegram requires all bot usernames to
				// end with "bot".
				if ( '' === $bot_username && 'bot' === mb_substr( $mentioned_name, -3 ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Check whether the incoming message is a reply to one of the bot's own messages.
	 *
	 * Telegram includes a `reply_to_message.from.is_bot` flag and the user ID
	 * of the original sender. When the original sender is a bot with the same
	 * username as our connection, this message is considered a reply to the bot.
	 *
	 * @since 1.0.0
	 *
	 * @param array      $message    Telegram message object.
	 * @param array|null $connection Active Telegram connection.
	 * @return bool True if the message is a reply to the bot.
	 */
	protected function is_reply_to_bot( array $message, $connection ) {
		if ( ! isset( $message['reply_to_message']['from']['is_bot'] ) ) {
			return false;
		}

		if ( true !== $message['reply_to_message']['from']['is_bot'] ) {
			return false;
		}

		// If we know the bot username, verify it matches. Otherwise accept any bot reply.
		if ( $connection && ! empty( $connection['bot_username'] ) ) {
			$expected = strtolower( ltrim( sanitize_text_field( $connection['bot_username'] ), '@' ) );
			$actual   = isset( $message['reply_to_message']['from']['username'] )
				? strtolower( (string) $message['reply_to_message']['from']['username'] )
				: '';

			return '' !== $expected && $expected === $actual;
		}

		return true;
	}

	/**
	 * Strip the @bot_username mention from the message text.
	 *
	 * Removing the trigger mention produces a cleaner prompt for the AI model.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $text       Message text.
	 * @param array|null $connection Active Telegram connection.
	 * @return string Text with the bot mention removed.
	 */
	protected function strip_bot_mention( $text, $connection ) {
		if ( ! $connection || empty( $connection['bot_username'] ) ) {
			return $text;
		}

		$bot_username = ltrim( sanitize_text_field( $connection['bot_username'] ), '@' );
		if ( '' === $bot_username ) {
			return $text;
		}

		return trim( preg_replace( '/@' . preg_quote( $bot_username, '/' ) . '(?=[^a-zA-Z0-9_]|$)/i', '', $text ) );
	}

	/**
	 * Process a Telegram channel_post or edited_channel_post update.
	 *
	 * Channels behave differently from groups: messages come from the channel
	 * itself (no `from` field for forwarded posts) and the bot must be an
	 * admin of the channel. This handler logs the post and optionally dispatches
	 * an AI reply when the connection has enable_channels turned on.
	 *
	 * @since 1.0.0
	 *
	 * @param array $post   Telegram channel post/message object.
	 * @param bool  $edited Whether this is an edited channel post.
	 */
	protected function process_channel_post( array $post, $edited = false ) {
		$chat_id    = isset( $post['chat']['id'] ) ? (string) $post['chat']['id'] : '';
		$chat_title = isset( $post['chat']['title'] ) ? sanitize_text_field( $post['chat']['title'] ) : '';

		if ( '' === $chat_id ) {
			return;
		}

		$connection = $this->get_active_telegram_connection();

		if ( ! $connection ) {
			return;
		}

		// Auto-populate the bot_username on the connection when it is missing.
		$connection = $this->maybe_populate_bot_username( $connection );

		// Only process channel posts when channel support is enabled.
		if ( empty( $connection['enable_channels'] ) ) {
			WP_MCP_AI_Logger::log_event(
				'telegram_channel_post_ignored',
				'Channel post ignored: channel support not enabled on this connection.',
				array( 'chat_id' => substr( $chat_id, 0, 4 ) . '***' )
			);
			return;
		}

		$text = isset( $post['text'] ) ? (string) $post['text'] : '';

		// Log the channel post.
		WP_MCP_AI_Logger::log_event(
			$edited ? 'telegram_channel_post_edited' : 'telegram_channel_post_received',
			$edited ? 'Edited channel post received.' : 'Channel post received.',
			array(
				'chat_id'    => substr( $chat_id, 0, 4 ) . '***',
				'chat_title' => $chat_title,
				'has_text'   => '' !== $text,
			)
		);

		// Persist to Channel Messages CCT and upsert the channel contact so that
		// the conversation appears in the inbox (which queries the contacts table).
		if ( '' !== $text && class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';
			$message_id    = isset( $post['message_id'] ) ? (string) $post['message_id'] : '';
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'telegram',
					'channel_contact_id' => $chat_id,
					'direction'          => 'inbound',
					'message_id'         => $message_id,
					'message_type'       => 'channel_post',
					'content'            => $text,
					'raw_payload'        => $post,
					'status'             => 'received',
					'connection_id'      => $connection_id,
					'phone_number_id'    => $chat_id,
					'timestamp'          => isset( $post['date'] ) ? absint( $post['date'] ) : time(),
					'reply_sent'         => 0,
					'conversation_type'  => 'channel',
				)
			);

			// Upsert the channel contact. Without a matching contact record the
			// inbox REST endpoint (which queries the contacts table) would not
			// return this channel's conversation.
			if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
				$channel_contact_name = $chat_title ? $chat_title : $chat_id;
				$channel_contact_row  = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
					'telegram',
					$chat_id,
					array(
						'display_name'      => $channel_contact_name,
						'metadata'          => array( 'contact_type' => 'channel' ),
						'connection_id'     => $connection_id,
						'conversation_type' => 'channel',
					)
				);
				if ( $channel_contact_row ) {
					WP_MCP_AI_Channel_Contacts_CCT::touch( $channel_contact_row );
				}
			}
		}

		/**
		 * Fires when a channel post is received from Telegram.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $post       Telegram channel post object.
		 * @param bool   $edited     Whether this is an edited post.
		 * @param array  $connection Active Telegram connection.
		 */
		do_action( 'wp_mcp_ai_telegram_channel_post', $post, $edited, $connection );
	}

	/**
	 * Process a my_chat_member update (bot added/removed from group or channel).
	 *
	 * This is called when the bot's membership status changes in a chat – for
	 * example when a user adds or removes the bot from a group or channel.
	 * The handler logs the event and fires an action hook so other code can
	 * react (e.g. send a welcome message, clean up data).
	 *
	 * @since 1.0.0
	 *
	 * @param array $update The my_chat_member update object from Telegram.
	 */
	protected function process_membership_update( array $update ) {
		$chat       = isset( $update['chat'] ) ? $update['chat'] : array();
		$chat_id    = isset( $chat['id'] ) ? (string) $chat['id'] : '';
		$chat_type  = isset( $chat['type'] ) ? (string) $chat['type'] : '';
		$chat_title = isset( $chat['title'] ) ? sanitize_text_field( $chat['title'] ) : '';

		$old_status = isset( $update['old_chat_member']['status'] ) ? (string) $update['old_chat_member']['status'] : '';
		$new_status = isset( $update['new_chat_member']['status'] ) ? (string) $update['new_chat_member']['status'] : '';

		WP_MCP_AI_Logger::log_event(
			'telegram_membership_change',
			'Bot membership status changed.',
			array(
				'chat_id'    => substr( $chat_id, 0, 4 ) . '***',
				'chat_type'  => $chat_type,
				'chat_title' => $chat_title,
				'old_status' => $old_status,
				'new_status' => $new_status,
			)
		);

		// Determine if the bot was added or removed.
		$left_statuses   = array( 'left', 'kicked' );
		$joined_statuses = array( 'member', 'administrator', 'creator' );

		$was_added   = in_array( $old_status, $left_statuses, true ) && in_array( $new_status, $joined_statuses, true );
		$was_removed = in_array( $old_status, $joined_statuses, true ) && in_array( $new_status, $left_statuses, true );

		/**
		 * Fires when the bot's membership status changes in a group or channel.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $update     Full my_chat_member update object.
		 * @param string $chat_type  Chat type: group, supergroup, or channel.
		 * @param bool   $was_added  True if the bot was just added.
		 * @param bool   $was_removed True if the bot was just removed.
		 */
		do_action( 'wp_mcp_ai_telegram_membership_change', $update, $chat_type, $was_added, $was_removed );
	}

	// =========================================================================
	// Markdown → Telegram HTML conversion
	// =========================================================================

	/**
	 * Send a user-friendly fallback message when AI content generation fails.
	 *
	 * Industry best practice for Telegram bots: always reply rather than leaving
	 * users hanging. When the assistant returns empty content (e.g. due to an
	 * agentic workflow error or an unexpected API response), this method sends a
	 * brief apology so the user knows their message was received.
	 *
	 * The fallback message text is filterable via
	 * `wp_mcp_ai_telegram_fallback_reply_text` so site operators can customise it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bot_token          Decrypted Telegram bot token.
	 * @param string $chat_id            Telegram chat ID.
	 * @param string $chat_type          Chat type ('private', 'group', 'supergroup', etc.).
	 * @param string $reply_to_message_id Original message ID for threaded replies in groups.
	 * @return void
	 */
	protected function send_telegram_fallback_reply( $bot_token, $chat_id, $chat_type, $reply_to_message_id ) {
		/**
		 * Filter the fallback message sent to Telegram when the AI returns empty content.
		 *
		 * @since 1.0.0
		 *
		 * @param string $text Default fallback message text.
		 */
		$fallback_text = (string) apply_filters(
			'wp_mcp_ai_telegram_fallback_reply_text',
			__( 'I was not able to generate a response right now. Please try again in a moment.', 'mcp-ai-wpoos' )
		);

		if ( '' === $fallback_text ) {
			return;
		}

		$endpoint = sprintf( 'https://api.telegram.org/bot%s/sendMessage', rawurlencode( $bot_token ) );

		$payload = array(
			'chat_id' => $chat_id,
			'text'    => $fallback_text,
		);

		if ( '' !== $reply_to_message_id && in_array( $chat_type, array( 'group', 'supergroup' ), true ) ) {
			$payload['reply_to_message_id']         = (int) $reply_to_message_id;
			$payload['allow_sending_without_reply'] = true;
		}

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return;
		}

		wp_remote_post(
			$endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 10,
				'body'    => $body,
			)
		);
	}

	/**
	 * Send a typing indicator to a Telegram chat.
	 *
	 * Sends the `typing` chat action via the Telegram Bot API so the user sees
	 * a "Bot is typing…" status while the AI assistant generates its reply.
	 * This is an industry-standard UX pattern for conversational bots:
	 *
	 * - Telegram's typing action auto-expires after ~5 seconds and is
	 *   automatically cleared when the actual message arrives.
	 * - The call is fire-and-forget: failures are logged but do not block
	 *   the AI reply pipeline.
	 *
	 * @see https://core.telegram.org/bots/api#sendchataction
	 *
	 * @since 1.0.0
	 *
	 * @param string $bot_token Decrypted Telegram bot token.
	 * @param string $chat_id   Telegram chat ID to send the typing action to.
	 */
	protected function send_typing_action( $bot_token, $chat_id ) {
		$endpoint = sprintf( 'https://api.telegram.org/bot%s/sendChatAction', rawurlencode( $bot_token ) );

		$body = wp_json_encode(
			array(
				'chat_id' => $chat_id,
				'action'  => 'typing',
			)
		);

		if ( false === $body ) {
			return;
		}

		$result = wp_remote_post(
			$endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 5,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_event(
				'telegram_typing_action_failed',
				'Telegram typing action could not be sent (non-blocking).',
				array( 'error' => $result->get_error_message() )
			);
		}
	}

	/**
	 * Sanitize a plain-text string for safe embedding in a Telegram basic-Markdown
	 * (parse_mode: Markdown) message.
	 *
	 * Telegram's legacy Markdown format does not support backslash-escaping of special
	 * characters the way MarkdownV2 does. The only safe approach is to remove the four
	 * characters that trigger unintended Markdown formatting when they appear in
	 * user-supplied values (assistant names, tool names, etc.):
	 *   * – bold delimiter
	 *   ` – inline-code delimiter
	 *   _ – italic delimiter
	 *   [ – inline-link start
	 *
	 * @since 1.1.3
	 *
	 * @param string $text Plain text to sanitize.
	 * @return string Sanitized text safe for Telegram basic Markdown.
	 */
	protected function sanitize_for_telegram_markdown( $text ) {
		return str_replace( array( '*', '`', '_', '[' ), '', (string) $text );
	}

	/**
	 * Convert Markdown produced by AI assistants to Telegram-compatible HTML.
	 *
	 * Telegram's Bot API supports a limited subset of HTML tags when
	 * `parse_mode` is set to `HTML`. This method converts the most common
	 * Markdown constructs emitted by GPT / Gemini / Ollama into that subset:
	 *
	 *   - Fenced code blocks (```lang … ```) → <pre><code class="language-X">…</code></pre>
	 *   - Inline code (`…`)                  → <code>…</code>
	 *   - Bold (**…** / __…__)               → <b>…</b>
	 *   - Italic (*…* / _…_)                 → <i>…</i>
	 *   - Strikethrough (~~…~~)              → <s>…</s>
	 *   - Links ([text](url))                → <a href="url">text</a>
	 *   - Headings (# … / ## … / etc.)       → <b>…</b> (Telegram has no heading tag)
	 *   - Blockquotes (> …)                  → <blockquote>…</blockquote>
	 *
	 * Special characters (`<`, `>`, `&`) in non-tag text are escaped so they
	 * do not break the HTML parse.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Markdown text from the AI assistant.
	 * @return string Telegram-compatible HTML.
	 */
	protected function markdown_to_telegram_html( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}

		// 1. Extract fenced code blocks and replace with placeholders so that
		// content inside them is not processed by other regex rules.
		$code_blocks = array();
		$placeholder = "\x07TGCB:";  // BEL-based placeholder safe from Markdown pattern matching.
		$block_index = 0;

		$text = preg_replace_callback(
			'/```([a-zA-Z0-9_+-]*)\n([\s\S]*?)```/',
			function ( $m ) use ( &$code_blocks, &$block_index, $placeholder ) {
				$lang = trim( $m[1] );
				$code = $m[2];
				// Remove one trailing newline if present (aesthetic).
				$code = rtrim( $code, "\n" );
				// Escape HTML entities inside the code block.
				$code                = htmlspecialchars( $code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
				$tag                 = '' !== $lang
					? '<pre><code class="language-' . htmlspecialchars( $lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '">' . $code . '</code></pre>'
					: '<pre>' . $code . '</pre>';
				$key                 = $placeholder . $block_index . "\x07";
				$code_blocks[ $key ] = $tag;
				++$block_index;
				return $key;
			},
			$text
		);

		// 2. Extract inline code spans and replace with placeholders.
		$inline_codes = array();
		$ic_index     = 0;
		$ic_ph        = "\x07TGIC:";

		$text = preg_replace_callback(
			'/`([^`\n]+?)`/',
			function ( $m ) use ( &$inline_codes, &$ic_index, $ic_ph ) {
				$code                 = htmlspecialchars( $m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
				$key                  = $ic_ph . $ic_index . "\x07";
				$inline_codes[ $key ] = '<code>' . $code . '</code>';
				++$ic_index;
				return $key;
			},
			$text
		);

		// 2b. Extract existing HTML anchor tags so they survive the HTML-escaping
		// pass in step 3. AI responses sometimes emit raw <a href="…">…</a>
		// links (e.g. from tool output) instead of Markdown [text](url) syntax.
		$html_links = array();
		$hl_index   = 0;
		$hl_ph      = "\x07TGHL:";

		$text = preg_replace_callback(
			'/<a\b[^>]*\bhref=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/si',
			function ( $m ) use ( &$html_links, &$hl_index, $hl_ph ) {
				$url       = esc_url( $m[1] );
				$link_text = htmlspecialchars( wp_strip_all_tags( $m[2] ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
				if ( '' === $url ) {
					return $link_text;
				}
				$tag                = '<a href="' . htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '">' . $link_text . '</a>';
				$key                = $hl_ph . $hl_index . "\x07";
				$html_links[ $key ] = $tag;
				++$hl_index;
				return $key;
			},
			$text
		);

		// 3. Escape HTML special characters in the remaining text so that raw
		// `<`, `>`, and `&` do not break Telegram's HTML parser.
		$text = htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		// 4. Headings (# … through ######) → bold text on its own line.
		$text = preg_replace( '/^#{1,6}\s+(.+)$/m', '<b>$1</b>', $text );

		// 5. Bold: **text** or __text__ → <b>text</b>.
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<b>$1</b>', $text );
		$text = preg_replace( '/__(.+?)__/', '<b>$1</b>', $text );

		// 6. Italic: *text* or _text_ → <i>text</i>.
		// Use a negative lookbehind / lookahead to avoid matching mid-word underscores.
		$text = preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<i>$1</i>', $text );
		$text = preg_replace( '/(?<![a-zA-Z0-9])_(?!_)(.+?)(?<!_)_(?![a-zA-Z0-9])/', '<i>$1</i>', $text );

		// 7. Strikethrough: ~~text~~ → <s>text</s>.
		$text = preg_replace( '/~~(.+?)~~/', '<s>$1</s>', $text );

		// 8. Links: [text](url) → <a href="url">text</a>.
		// The URL was HTML-escaped in step 3; restore `&amp;` → `&` inside href
		// and apply esc_url for security.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)]+)\)/',
			function ( $m ) {
				$link_text = $m[1];
				$url       = html_entity_decode( $m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
				$url       = esc_url( $url );
				if ( '' === $url ) {
					return $link_text;
				}
				return '<a href="' . htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '">' . $link_text . '</a>';
			},
			$text
		);

		// 9. Blockquotes: lines starting with > → <blockquote>…</blockquote>.
		// Collapse consecutive blockquote lines into a single element.
		$text = preg_replace_callback(
			'/(?:^&gt;\s?(.*)$\n?)+/m',
			function ( $m ) {
				// Remove the leading "&gt; " (escaped "> ") from each line.
				$inner = preg_replace( '/^&gt;\s?/m', '', $m[0] );
				return '<blockquote>' . trim( $inner ) . '</blockquote>';
			},
			$text
		);

		// 10. Restore HTML anchor tag placeholders.
		if ( ! empty( $html_links ) ) {
			$text = str_replace( array_keys( $html_links ), array_values( $html_links ), $text );
		}

		// 11. Restore inline code placeholders.
		if ( ! empty( $inline_codes ) ) {
			$text = str_replace( array_keys( $inline_codes ), array_values( $inline_codes ), $text );
		}

		// 12. Restore fenced code block placeholders.
		if ( ! empty( $code_blocks ) ) {
			$text = str_replace( array_keys( $code_blocks ), array_values( $code_blocks ), $text );
		}

		return trim( $text );
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
				'post_type'              => 'mcp_ai_assistant',
				'post_status'            => 'publish',
				'numberposts'            => 1,
				'fields'                 => 'ids',
				'orderby'                => 'date',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : 0;
	}

	// =========================================================================
	// MEDIA HANDLING: extract → getFile → sideload → metadata reply → AI reply
	// =========================================================================

	/**
	 * Extract media metadata from a Telegram message object.
	 *
	 * Supports all media types accepted by the Telegram Bot API:
	 * photo, document, video, audio, voice, animation (GIF), and video_note
	 * (circular video). Returns null for non-media messages so the caller can
	 * distinguish text-only messages from media messages at a glance.
	 *
	 * Industry-standard note: for photos Telegram delivers an array of PhotoSize
	 * objects at different resolutions. The last element is always the highest
	 * quality and should be used per Telegram Bot API documentation.
	 *
	 * @since 1.0.0
	 *
	 * @param array $message Telegram message object from the webhook payload.
	 * @return array|null Associative media-info array or null if no media found.
	 *   Keys: file_id, media_type, original_filename, mime_type, file_size,
	 *         width, height, duration, caption, caption_entities.
	 */
	protected function extract_media_info( array $message ) {
		// Photo: array of PhotoSize objects; use the last (highest resolution).
		if ( ! empty( $message['photo'] ) && is_array( $message['photo'] ) ) {
			$photo = end( $message['photo'] );
			return array(
				'media_type'        => 'photo',
				'file_id'           => isset( $photo['file_id'] ) ? (string) $photo['file_id'] : '',
				'original_filename' => 'photo.jpg',
				'mime_type'         => 'image/jpeg',
				'file_size'         => isset( $photo['file_size'] ) ? absint( $photo['file_size'] ) : 0,
				'width'             => isset( $photo['width'] ) ? absint( $photo['width'] ) : 0,
				'height'            => isset( $photo['height'] ) ? absint( $photo['height'] ) : 0,
				'duration'          => 0,
				'caption'           => isset( $message['caption'] ) ? (string) $message['caption'] : '',
				'caption_entities'  => isset( $message['caption_entities'] ) ? (array) $message['caption_entities'] : array(),
			);
		}

		// Document: single Document object with optional file_name and mime_type.
		if ( ! empty( $message['document'] ) && is_array( $message['document'] ) ) {
			$doc = $message['document'];
			return array(
				'media_type'        => 'document',
				'file_id'           => isset( $doc['file_id'] ) ? (string) $doc['file_id'] : '',
				'original_filename' => isset( $doc['file_name'] ) ? sanitize_file_name( $doc['file_name'] ) : '',
				'mime_type'         => isset( $doc['mime_type'] ) ? sanitize_text_field( $doc['mime_type'] ) : '',
				'file_size'         => isset( $doc['file_size'] ) ? absint( $doc['file_size'] ) : 0,
				'width'             => 0,
				'height'            => 0,
				'duration'          => 0,
				'caption'           => isset( $message['caption'] ) ? (string) $message['caption'] : '',
				'caption_entities'  => isset( $message['caption_entities'] ) ? (array) $message['caption_entities'] : array(),
			);
		}

		// Video.
		if ( ! empty( $message['video'] ) && is_array( $message['video'] ) ) {
			$video = $message['video'];
			return array(
				'media_type'        => 'video',
				'file_id'           => isset( $video['file_id'] ) ? (string) $video['file_id'] : '',
				'original_filename' => isset( $video['file_name'] ) ? sanitize_file_name( $video['file_name'] ) : 'video.mp4',
				'mime_type'         => isset( $video['mime_type'] ) ? sanitize_text_field( $video['mime_type'] ) : 'video/mp4',
				'file_size'         => isset( $video['file_size'] ) ? absint( $video['file_size'] ) : 0,
				'width'             => isset( $video['width'] ) ? absint( $video['width'] ) : 0,
				'height'            => isset( $video['height'] ) ? absint( $video['height'] ) : 0,
				'duration'          => isset( $video['duration'] ) ? absint( $video['duration'] ) : 0,
				'caption'           => isset( $message['caption'] ) ? (string) $message['caption'] : '',
				'caption_entities'  => isset( $message['caption_entities'] ) ? (array) $message['caption_entities'] : array(),
			);
		}

		// Audio (music files).
		if ( ! empty( $message['audio'] ) && is_array( $message['audio'] ) ) {
			$audio = $message['audio'];
			return array(
				'media_type'        => 'audio',
				'file_id'           => isset( $audio['file_id'] ) ? (string) $audio['file_id'] : '',
				'original_filename' => isset( $audio['file_name'] ) ? sanitize_file_name( $audio['file_name'] ) : 'audio.mp3',
				'mime_type'         => isset( $audio['mime_type'] ) ? sanitize_text_field( $audio['mime_type'] ) : 'audio/mpeg',
				'file_size'         => isset( $audio['file_size'] ) ? absint( $audio['file_size'] ) : 0,
				'width'             => 0,
				'height'            => 0,
				'duration'          => isset( $audio['duration'] ) ? absint( $audio['duration'] ) : 0,
				'caption'           => isset( $message['caption'] ) ? (string) $message['caption'] : '',
				'caption_entities'  => isset( $message['caption_entities'] ) ? (array) $message['caption_entities'] : array(),
			);
		}

		// Voice message (OGG audio recorded in-app).
		if ( ! empty( $message['voice'] ) && is_array( $message['voice'] ) ) {
			$voice = $message['voice'];
			return array(
				'media_type'        => 'voice',
				'file_id'           => isset( $voice['file_id'] ) ? (string) $voice['file_id'] : '',
				'original_filename' => 'voice.ogg',
				'mime_type'         => isset( $voice['mime_type'] ) ? sanitize_text_field( $voice['mime_type'] ) : 'audio/ogg',
				'file_size'         => isset( $voice['file_size'] ) ? absint( $voice['file_size'] ) : 0,
				'width'             => 0,
				'height'            => 0,
				'duration'          => isset( $voice['duration'] ) ? absint( $voice['duration'] ) : 0,
				'caption'           => '',
				'caption_entities'  => array(),
			);
		}

		// Animation (GIF or MP4 without audio).
		if ( ! empty( $message['animation'] ) && is_array( $message['animation'] ) ) {
			$animation = $message['animation'];
			return array(
				'media_type'        => 'animation',
				'file_id'           => isset( $animation['file_id'] ) ? (string) $animation['file_id'] : '',
				'original_filename' => isset( $animation['file_name'] ) ? sanitize_file_name( $animation['file_name'] ) : 'animation.gif',
				'mime_type'         => isset( $animation['mime_type'] ) ? sanitize_text_field( $animation['mime_type'] ) : 'video/mp4',
				'file_size'         => isset( $animation['file_size'] ) ? absint( $animation['file_size'] ) : 0,
				'width'             => isset( $animation['width'] ) ? absint( $animation['width'] ) : 0,
				'height'            => isset( $animation['height'] ) ? absint( $animation['height'] ) : 0,
				'duration'          => isset( $animation['duration'] ) ? absint( $animation['duration'] ) : 0,
				'caption'           => isset( $message['caption'] ) ? (string) $message['caption'] : '',
				'caption_entities'  => isset( $message['caption_entities'] ) ? (array) $message['caption_entities'] : array(),
			);
		}

		// Video note (circular video message).
		if ( ! empty( $message['video_note'] ) && is_array( $message['video_note'] ) ) {
			$video_note = $message['video_note'];
			return array(
				'media_type'        => 'video_note',
				'file_id'           => isset( $video_note['file_id'] ) ? (string) $video_note['file_id'] : '',
				'original_filename' => 'video_note.mp4',
				'mime_type'         => 'video/mp4',
				'file_size'         => isset( $video_note['file_size'] ) ? absint( $video_note['file_size'] ) : 0,
				'width'             => isset( $video_note['length'] ) ? absint( $video_note['length'] ) : 0,
				'height'            => isset( $video_note['length'] ) ? absint( $video_note['length'] ) : 0,
				'duration'          => isset( $video_note['duration'] ) ? absint( $video_note['duration'] ) : 0,
				'caption'           => '',
				'caption_entities'  => array(),
			);
		}

		return null; // No supported media found in this message.
	}

	/**
	 * Map a Telegram media type to the Channel Messages CCT message_type value.
	 *
	 * Supported CCT message types: text, image, video, audio, document, other.
	 *
	 * @since 1.0.0
	 *
	 * @param string $media_type Telegram media type (photo, document, video, etc.).
	 * @return string CCT message type.
	 */
	protected function get_cct_message_type_for_media( $media_type ) {
		$map = array(
			'photo'      => 'image',
			'animation'  => 'image',
			'video'      => 'video',
			'video_note' => 'video',
			'audio'      => 'audio',
			'voice'      => 'audio',
			'document'   => 'document',
		);

		return isset( $map[ $media_type ] ) ? $map[ $media_type ] : 'other';
	}

	/**
	 * Cron callback: process an incoming Telegram media message.
	 *
	 * Orchestrates the full media-handling pipeline per Telegram Bot API
	 * industry standards (2024):
	 *
	 * 1. Retrieve the Telegram `file_path` via `getFile`.
	 * 2. Download the file using `download_url()` and sideload it to the
	 *    WordPress media library with `media_handle_sideload()`.
	 * 3. Send an immediate auto-reply to the user containing the WordPress
	 *    attachment ID and public URL so the file can be referenced in chat.
	 * 4. If an AI assistant is assigned, schedule the AI reply job with the
	 *    full file context (type, attachment ID, URL, dimensions, duration,
	 *    filename) prepended to the message text.
	 *
	 * Security note: the Telegram file download URL embeds the bot token and
	 * is never surfaced to end-users. Only the public WordPress attachment URL
	 * is shared in the reply.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Job arguments set by process_message(). Keys:
	 *   file_id, media_type, original_filename, mime_type, file_size, width,
	 *   height, duration, caption, message_text, assistant_id, chat_id, from_id,
	 *   connection_id, chat_type, message_id, reply_to_message_id.
	 */
	public function handle_telegram_media_job( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		$file_id             = isset( $args['file_id'] ) ? sanitize_text_field( $args['file_id'] ) : '';
		$media_type          = isset( $args['media_type'] ) ? sanitize_key( $args['media_type'] ) : '';
		$original_filename   = isset( $args['original_filename'] ) ? sanitize_file_name( $args['original_filename'] ) : '';
		$mime_type           = isset( $args['mime_type'] ) ? sanitize_text_field( $args['mime_type'] ) : '';
		$file_size           = isset( $args['file_size'] ) ? absint( $args['file_size'] ) : 0;
		$width               = isset( $args['width'] ) ? absint( $args['width'] ) : 0;
		$height              = isset( $args['height'] ) ? absint( $args['height'] ) : 0;
		$duration            = isset( $args['duration'] ) ? absint( $args['duration'] ) : 0;
		$caption             = isset( $args['caption'] ) ? sanitize_textarea_field( $args['caption'] ) : '';
		$message_text        = isset( $args['message_text'] ) ? (string) $args['message_text'] : '';
		$assistant_id        = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;
		$chat_id             = isset( $args['chat_id'] ) ? (string) $args['chat_id'] : '';
		$from_id             = isset( $args['from_id'] ) ? (string) $args['from_id'] : $chat_id;
		$connection_id       = isset( $args['connection_id'] ) ? sanitize_key( $args['connection_id'] ) : '';
		$chat_type           = isset( $args['chat_type'] ) ? sanitize_text_field( $args['chat_type'] ) : 'private';
		$message_id          = isset( $args['message_id'] ) ? (string) $args['message_id'] : '';
		$reply_to_message_id = isset( $args['reply_to_message_id'] ) ? (string) $args['reply_to_message_id'] : '';

		if ( '' === $file_id || '' === $chat_id || '' === $connection_id ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection || empty( $connection['api_key'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram media job: connection not found or bot token missing.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );

		if ( '' === $bot_token ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram media job: bot token decryption returned empty string.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'telegram_media_processing_start',
			'Starting Telegram media file processing.',
			array(
				'media_type' => $media_type,
				'mime_type'  => $mime_type,
				'chat_id'    => substr( $chat_id, 0, 4 ) . '***',
			)
		);

		// ── Step 1: Retrieve the Telegram file_path via getFile. ──────────────
		// file_path is a server-side path relative to the Telegram file server.
		// Per Telegram docs, file paths expire after about an hour so we must
		// download the file immediately.
		$file_path = $this->get_telegram_file_path( $bot_token, $file_id );

		if ( null === $file_path ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram media job: failed to retrieve file_path from Telegram.',
				array(
					'media_type' => $media_type,
					'chat_id'    => substr( $chat_id, 0, 4 ) . '***',
				)
			);
			$this->send_raw_telegram_message(
				$bot_token,
				$chat_id,
				__( '⚠️ File received but could not be retrieved from Telegram. Please try again.', 'mcp-ai-wpoos-pro' ),
				$chat_type,
				$reply_to_message_id
			);
			return;
		}

		// ── Step 2: Sideload the file to the WordPress media library. ─────────
		// Build the download URL from the bot token + file_path. This URL is
		// intentionally kept server-side and never exposed to end-users.
		$file_url = sprintf(
			'https://api.telegram.org/file/bot%s/%s',
			rawurlencode( $bot_token ),
			$file_path
		);

		$attachment_id = $this->sideload_telegram_file( $file_url, $original_filename, $mime_type, $file_size );

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			$err = is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : 'unknown sideload error';
			WP_MCP_AI_Logger::log_error(
				'Telegram media job: file sideload to WordPress media library failed.',
				array(
					'media_type' => $media_type,
					'error'      => $err,
				)
			);
			$this->send_raw_telegram_message(
				$bot_token,
				$chat_id,
				__( '⚠️ File received but could not be saved to the media library. Please try again.', 'mcp-ai-wpoos-pro' ),
				$chat_type,
				$reply_to_message_id
			);
			return;
		}

		// ── Step 3: Get the public WordPress attachment URL. ──────────────────
		$attachment_url = (string) wp_get_attachment_url( $attachment_id );

		WP_MCP_AI_Logger::log_event(
			'telegram_media_sideloaded',
			'Telegram media file sideloaded to WordPress media library.',
			array(
				'attachment_id' => $attachment_id,
				'media_type'    => $media_type,
				'chat_id'       => substr( $chat_id, 0, 4 ) . '***',
			)
		);

		// ── Step 4: Send the metadata auto-reply. ─────────────────────────────
		// Industry practice: acknowledge receipt immediately with structured
		// metadata so the user can reference the file in follow-up messages.
		$type_labels = array(
			'photo'      => '🖼️ Image',
			'document'   => '📄 Document',
			'video'      => '🎬 Video',
			'audio'      => '🎵 Audio',
			'voice'      => '🎤 Voice message',
			'animation'  => '🎞️ Animation',
			'video_note' => '📹 Video note',
		);
		$type_label  = isset( $type_labels[ $media_type ] ) ? $type_labels[ $media_type ] : '📎 File';

		/**
		 * Filter the metadata auto-reply lines sent when a Telegram user uploads a file.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $lines         Lines of the reply message.
		 * @param int      $attachment_id WordPress attachment post ID.
		 * @param string   $attachment_url Public attachment URL.
		 * @param array    $args          Original media job args.
		 */
		$reply_lines = apply_filters(
			'wp_mcp_ai_telegram_media_metadata_reply_lines',
			$this->build_media_metadata_reply_lines(
				$type_label,
				$attachment_id,
				$attachment_url,
				$original_filename,
				$mime_type,
				$width,
				$height,
				$duration,
				$file_size,
				$caption
			),
			$attachment_id,
			$attachment_url,
			$args
		);

		$reply_text = implode( "\n", (array) $reply_lines );
		$this->send_raw_telegram_message( $bot_token, $chat_id, $reply_text, $chat_type, $reply_to_message_id );

		// ── Step 5: Schedule the AI reply job with full file context. ─────────
		// Only triggered when an AI assistant is assigned to this connection.
		if ( $assistant_id ) {
			// Build the AI-facing context block: file metadata + cleaned caption.
			$ai_context_lines = array(
				sprintf( '[%s uploaded]', ucfirst( str_replace( '_', ' ', $media_type ) ) ),
				sprintf( '- Attachment ID: %d', $attachment_id ),
				sprintf( '- URL: %s', $attachment_url ),
			);

			if ( '' !== $original_filename ) {
				$ai_context_lines[] = sprintf( '- Filename: %s', $original_filename );
			}
			if ( '' !== $mime_type ) {
				$ai_context_lines[] = sprintf( '- MIME type: %s', $mime_type );
			}
			if ( $width && $height ) {
				$ai_context_lines[] = sprintf( '- Dimensions: %dx%d px', $width, $height );
			}
			if ( $duration ) {
				$ai_context_lines[] = sprintf( '- Duration: %d seconds', $duration );
			}

			$ai_context  = implode( "\n", $ai_context_lines );
			$ai_msg_text = '' !== $message_text
				? $ai_context . "\n\nUser caption: " . $message_text
				: $ai_context;

			$is_group = in_array( $chat_type, array( 'group', 'supergroup' ), true );

			$ai_job_args = array(
				array(
					'assistant_id'        => $assistant_id,
					'message_text'        => $ai_msg_text,
					// Pass the sideloaded attachment so the AI reply job can build
					// a multipart message content array (input_image / input_file)
					// and the assistant can actually see/process the uploaded file.
					'wp_attachment_id'    => $attachment_id,
					'wp_attachment_mime'  => $mime_type,
					'chat_id'             => $chat_id,
					'from_id'             => $from_id,
					'connection_id'       => $connection_id,
					'chat_type'           => $chat_type,
					'reply_to_message_id' => $is_group ? $message_id : '',
				),
			);

			wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $ai_job_args );
			spawn_cron();
		}
	}

	/**
	 * Build the metadata reply lines for a Telegram media auto-reply.
	 *
	 * Extracted as a standalone method so it can be unit-tested independently
	 * and overridden by themes/plugins via the
	 * `wp_mcp_ai_telegram_media_metadata_reply_lines` filter.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type_label        Human-readable media type label with emoji.
	 * @param int    $attachment_id     WordPress attachment post ID.
	 * @param string $attachment_url    Public WordPress attachment URL.
	 * @param string $original_filename Original filename from Telegram.
	 * @param string $mime_type         File MIME type.
	 * @param int    $width             Image/video width in pixels (0 if N/A).
	 * @param int    $height            Image/video height in pixels (0 if N/A).
	 * @param int    $duration          Audio/video duration in seconds (0 if N/A).
	 * @param int    $file_size         File size in bytes (0 if unknown).
	 * @param string $caption           User-supplied caption (may be empty).
	 * @return string[] Ordered array of reply message lines.
	 */
	protected function build_media_metadata_reply_lines(
		$type_label,
		$attachment_id,
		$attachment_url,
		$original_filename,
		$mime_type,
		$width,
		$height,
		$duration,
		$file_size,
		$caption
	) {
		/* translators: %s: media type label, e.g. "🖼️ Image" */
		$lines = array( sprintf( __( '%s received and saved!', 'mcp-ai-wpoos-pro' ), $type_label ), '' );

		/* translators: %d: WordPress attachment post ID */
		$lines[] = sprintf( __( '📌 Attachment ID: %d', 'mcp-ai-wpoos-pro' ), $attachment_id );
		/* translators: %s: public attachment URL */
		$lines[] = sprintf( __( '🔗 URL: %s', 'mcp-ai-wpoos-pro' ), esc_url( $attachment_url ) );

		if ( '' !== $original_filename ) {
			/* translators: %s: original filename */
			$lines[] = sprintf( __( '📁 Filename: %s', 'mcp-ai-wpoos-pro' ), $original_filename );
		}
		if ( '' !== $mime_type ) {
			/* translators: %s: MIME type string */
			$lines[] = sprintf( __( '🗂️ Type: %s', 'mcp-ai-wpoos-pro' ), $mime_type );
		}
		if ( $width && $height ) {
			/* translators: 1: width in pixels, 2: height in pixels */
			$lines[] = sprintf( __( '📐 Dimensions: %1$dx%2$d px', 'mcp-ai-wpoos-pro' ), $width, $height );
		}
		if ( $duration ) {
			/* translators: %d: duration in seconds */
			$lines[] = sprintf( __( '⏱️ Duration: %d sec', 'mcp-ai-wpoos-pro' ), $duration );
		}
		if ( $file_size ) {
			/* translators: %s: human-readable file size */
			$lines[] = sprintf( __( '💾 Size: %s', 'mcp-ai-wpoos-pro' ), size_format( $file_size ) );
		}
		if ( '' !== $caption ) {
			$lines[] = '';
			/* translators: %s: user-supplied file caption */
			$lines[] = sprintf( __( '💬 Caption: %s', 'mcp-ai-wpoos-pro' ), $caption );
		}

		$lines[] = '';
		$lines[] = __( 'You can reference this file in your next message using the Attachment ID or URL above.', 'mcp-ai-wpoos-pro' );

		return $lines;
	}

	/**
	 * Retrieve the Telegram `file_path` for a given `file_id` via the `getFile` API.
	 *
	 * The returned `file_path` is a server-side relative path used to construct
	 * the private download URL. Per Telegram's documentation, file paths are
	 * valid for approximately one hour after retrieval, so the file must be
	 * downloaded promptly.
	 *
	 * @see https://core.telegram.org/bots/api#getfile
	 *
	 * @since 1.0.0
	 *
	 * @param string $bot_token Decrypted Telegram bot token.
	 * @param string $file_id   Telegram file identifier from the message object.
	 * @return string|null The `file_path` string, or null on failure.
	 */
	protected function get_telegram_file_path( $bot_token, $file_id ) {
		$endpoint = sprintf(
			'https://api.telegram.org/bot%s/getFile',
			rawurlencode( $bot_token )
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 15,
				'body'    => wp_json_encode( array( 'file_id' => $file_id ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_event(
				'telegram_get_file_error',
				'Telegram getFile API call failed.',
				array( 'error' => $response->get_error_message() )
			);
			return null;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $http_code || empty( $body['ok'] ) || empty( $body['result']['file_path'] ) ) {
			WP_MCP_AI_Logger::log_event(
				'telegram_get_file_error',
				'Telegram getFile returned an unexpected response.',
				array(
					'http_code'   => $http_code,
					'description' => isset( $body['description'] ) ? $body['description'] : '',
				)
			);
			return null;
		}

		return (string) $body['result']['file_path'];
	}

	/**
	 * Download a Telegram file and sideload it to the WordPress media library.
	 *
	 * Uses WordPress core's `download_url()` + `media_handle_sideload()` pattern,
	 * which is the recommended way to programmatically import remote files. The
	 * temporary file is cleaned up automatically on success, and explicitly
	 * deleted on failure to avoid orphaned /tmp entries.
	 *
	 * File size is validated against Telegram's 20 MB bot download limit before
	 * attempting the download. This avoids unnecessary network traffic for
	 * oversized files.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file_url          Telegram private download URL (contains bot token).
	 * @param string $original_filename Suggested filename for the attachment.
	 * @param string $mime_type         MIME type hint for proper extension resolution.
	 * @param int    $file_size         Known file size in bytes (0 if unknown).
	 * @return int|WP_Error WordPress attachment post ID on success, WP_Error on failure.
	 */
	protected function sideload_telegram_file( $file_url, $original_filename, $mime_type, $file_size ) {
		// Enforce Telegram Bot API's 20 MB download limit for bots.
		if ( $file_size && $file_size > 20 * MB_IN_BYTES ) {
			return new WP_Error(
				'telegram_file_too_large',
				sprintf(
					/* translators: %s: human-readable file size */
					__( 'Telegram file (%s) exceeds the 20 MB bot download limit.', 'mcp-ai-wpoos-pro' ),
					size_format( $file_size )
				)
			);
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// When no filename was supplied, derive one from the URL path and the
		// MIME type so the attachment gets a recognisable name in the library.
		if ( '' === $original_filename ) {
			$url_basename      = wp_basename( wp_parse_url( $file_url, PHP_URL_PATH ) );
			$original_filename = '' !== $url_basename ? $url_basename : 'telegram-file';

			if ( '' !== $mime_type && false === strpos( $original_filename, '.' ) ) {
				$mime_map    = wp_get_mime_types();
				$ext_map     = array_flip( $mime_map );
				$type_string = isset( $ext_map[ $mime_type ] ) ? $ext_map[ $mime_type ] : '';
				if ( '' !== $type_string ) {
					$ext_parts          = explode( '|', $type_string );
					$original_filename .= '.' . $ext_parts[0];
				}
			}
		}

		// download_url() saves the remote file to a WordPress-managed temp path.
		$tmp_file = download_url( $file_url, 60 );

		if ( is_wp_error( $tmp_file ) ) {
			return $tmp_file;
		}

		$file_array = array(
			'name'     => sanitize_file_name( $original_filename ),
			'tmp_name' => $tmp_file,
		);

		// Setting the 'type' key passes a MIME hint to wp_handle_sideload() so
		// that WordPress file-type validation does not reject files whose
		// extensions are absent or ambiguous (common with Telegram's opaque
		// file paths like photos/file_0 or documents/file_10).
		if ( '' !== $mime_type ) {
			$file_array['type'] = $mime_type;
		}

		// Sideload: move the temp file into the uploads directory, create an
		// attachment post, and generate image sub-sizes. Post ID 0 means the
		// attachment is not associated with any specific post.
		$attachment_id = media_handle_sideload( $file_array, 0 );

		// media_handle_sideload() cleans up $tmp_file on success. On failure
		// we must remove it to avoid leftover files in /tmp.
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp_file );
		}

		return $attachment_id;
	}

	/**
	 * Send a plain-text message to a Telegram chat using the Bot API.
	 *
	 * A lightweight fire-and-forget wrapper used for immediate (non-AI) replies
	 * such as the media metadata confirmation and error notices. Unlike
	 * `send_command_reply()`, this method accepts an explicit bot_token so it
	 * can be called from async cron jobs that already hold a decrypted token.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bot_token           Decrypted Telegram bot token.
	 * @param string $chat_id             Target Telegram chat ID.
	 * @param string $text                Message text (plain text; no parse_mode).
	 * @param string $chat_type           Chat type: private, group, or supergroup.
	 * @param string $reply_to_message_id Optional message ID to thread in groups.
	 */
	protected function send_raw_telegram_message( $bot_token, $chat_id, $text, $chat_type = 'private', $reply_to_message_id = '' ) {
		if ( mb_strlen( $text ) > self::MAX_MESSAGE_LENGTH ) {
			$text = mb_substr( $text, 0, self::MAX_MESSAGE_LENGTH - 3 ) . '...';
		}

		$endpoint = sprintf( 'https://api.telegram.org/bot%s/sendMessage', rawurlencode( $bot_token ) );

		$payload = array(
			'chat_id' => $chat_id,
			'text'    => $text,
		);

		if ( '' !== $reply_to_message_id && in_array( $chat_type, array( 'group', 'supergroup' ), true ) ) {
			$payload['reply_to_message_id']         = (int) $reply_to_message_id;
			$payload['allow_sending_without_reply'] = true;
		}

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			return;
		}

		wp_remote_post(
			$endpoint,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 15,
				'body'    => $body,
			)
		);
	}
}

new WP_MCP_AI_Telegram_Webhook_Controller();
