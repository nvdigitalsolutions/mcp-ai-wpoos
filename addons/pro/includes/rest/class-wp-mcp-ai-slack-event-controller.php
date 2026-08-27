<?php
/**
 * Slack Events API Controller
 *
 * Handles incoming Slack Events API webhook payloads with industry-standard
 * security validation. Implements Slack platform best practices:
 * - HMAC-SHA256 signature verification (X-Slack-Signature header)
 * - URL verification challenge response
 * - Per-user conversation history respecting max_history_messages
 * - AI auto-reply via WordPress cron (async, within 3-second acknowledgement window)
 * - Message deduplication via transient cache
 * - Per-connection webhook endpoints for multiple Slack workspace support
 *
 * @see https://api.slack.com/events-api
 * @see https://api.slack.com/authentication/verifying-requests-from-slack
 * @see https://sean-rennie.medium.com/building-a-slack-bot-c39cce21e106
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
 * Slack Events API REST controller.
 */
class WP_MCP_AI_Slack_Event_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks/slack';

	/**
	 * Tracks the connection_id resolved from the incoming request URL so every
	 * helper called during the request lifecycle targets the correct Slack workspace
	 * without requiring connection_id to be threaded through every method signature.
	 *
	 * @var string|null
	 */
	protected $current_connection_id = null;

	/**
	 * Cron hook for dispatching AI replies to incoming Slack messages.
	 */
	const REPLY_CRON_HOOK = 'wp_mcp_ai_slack_send_ai_reply';

	/**
	 * TTL in seconds for the deduplication transient.
	 */
	const DEDUP_TRANSIENT_TTL = 60;

	/**
	 * TTL in seconds for per-user conversation history transients (24 hours).
	 */
	const CONVERSATION_HISTORY_TTL = 86400;

	/**
	 * Maximum age in seconds for an incoming Slack request timestamp (5 minutes).
	 * Requests older than this are rejected to prevent replay attacks.
	 */
	const MAX_REQUEST_AGE = 300;

	/**
	 * Maximum number of times a rate-limited (HTTP 429) reply job will be
	 * retried before giving up.  Each retry respects the Retry-After header
	 * returned by Slack.
	 */
	const MAX_RATE_LIMIT_RETRIES = 3;

	/**
	 * Minimum number of seconds to wait when rescheduling a rate-limited
	 * (HTTP 429) reply job, regardless of the Retry-After header value.
	 */
	const MIN_RETRY_DELAY = 30;

	/**
	 * Default maximum agentic loop iterations for Slack reply jobs.
	 *
	 * The /mcp-ai/v1/chat endpoint defaults to 1 iteration. Slack reply jobs
	 * use a higher cap so multi-step tool workflows (e.g. search → analyse →
	 * respond) can complete before the reply is dispatched. This mirrors the
	 * pattern used by the Telegram and browser chat-client endpoints.
	 */
	const DEFAULT_MAX_AGENTIC_ITERATIONS = 10;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_slack_reply_job' ) );
	}

	/**
	 * Register REST routes for Slack webhooks.
	 *
	 * Registers two routes:
	 * - Generic:        POST /mcp-ai/v1/webhooks/slack
	 * - Per-connection: POST /mcp-ai/v1/webhooks/slack/{connection_id}
	 *
	 * The per-connection route lets multiple Slack workspaces each have a
	 * dedicated webhook URL, matching the pattern used by the Telegram controller.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Global webhook endpoint (backward-compatible, single-workspace setups).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_event' ),
				'permission_callback' => array( $this, 'validate_slack_signature' ),
			)
		);

		// Per-connection webhook endpoint so multiple Slack workspaces can each
		// have a dedicated URL: /mcp-ai/v1/webhooks/slack/{connection_id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<connection_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_event' ),
				'permission_callback' => array( $this, 'validate_slack_signature' ),
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
	 * Validate the Slack request signature using HMAC-SHA256.
	 *
	 * Slack signs every request with the signing secret from the App dashboard.
	 * The signature is computed as:
	 *   "v0=" . HMAC-SHA256( "v0:{timestamp}:{raw_body}", signing_secret )
	 * and sent in the X-Slack-Signature header.
	 *
	 * When a `connection_id` is present in the URL the signing secret is looked up
	 * from that specific connection. When absent, the first active Slack connection
	 * with a signing secret configured is used (backward-compatible).
	 *
	 * When the signing secret is not configured the webhook request is rejected
	 * with a 403 error so that unconfigured endpoints cannot be exploited.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if signature is valid, WP_Error on failure.
	 */
	public function validate_slack_signature( $request ) {
		$connection_id  = $request->get_param( 'connection_id' );
		$signing_secret = $this->get_signing_secret( $connection_id );

		if ( empty( $signing_secret ) ) {
			WP_MCP_AI_Logger::log_error(
				'Slack webhook rejected: signing secret is not configured. Configure signing_secret in the connection settings to enable this webhook.',
				array( 'connection_id' => $connection_id ? $connection_id : 'default' )
			);
			return new WP_Error(
				'rest_forbidden',
				__( 'Slack webhook authentication is not configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		$timestamp = $request->get_header( 'x-slack-request-timestamp' );
		$signature = $request->get_header( 'x-slack-signature' );

		if ( empty( $timestamp ) || empty( $signature ) ) {
			WP_MCP_AI_Logger::log_error( 'Slack webhook rejected: missing timestamp or signature header.' );
			return false;
		}

		// Reject requests older than MAX_REQUEST_AGE seconds (replay-attack prevention).
		if ( abs( time() - (int) $timestamp ) > self::MAX_REQUEST_AGE ) {
			WP_MCP_AI_Logger::log_error( 'Slack webhook rejected: request timestamp is stale.' );
			return false;
		}

		$raw_body = $request->get_body();
		$sig_base = 'v0:' . $timestamp . ':' . $raw_body;
		$computed = 'v0=' . hash_hmac( 'sha256', $sig_base, $signing_secret );

		if ( ! hash_equals( $computed, $signature ) ) {
			WP_MCP_AI_Logger::log_error( 'Slack webhook rejected: invalid signature.' );
			return false;
		}

		return true;
	}

	/**
	 * Handle incoming Slack event or URL-verification challenge.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function handle_event( $request ) {
		// Resolve the per-connection ID from the URL so all helper methods in
		// this request lifecycle can target the correct Slack workspace.
		$raw_conn_id                 = $request->get_param( 'connection_id' );
		$this->current_connection_id = $raw_conn_id ? $raw_conn_id : null;

		$payload = $request->get_json_params();

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			WP_MCP_AI_Logger::log_error( 'Slack webhook: empty or invalid JSON payload.' );
			return rest_ensure_response( array() );
		}

		$type = isset( $payload['type'] ) ? sanitize_text_field( $payload['type'] ) : '';

		// Respond to Slack URL-verification challenge immediately.
		if ( 'url_verification' === $type ) {
			$challenge = isset( $payload['challenge'] ) ? sanitize_text_field( $payload['challenge'] ) : '';
			WP_MCP_AI_Logger::log_event( 'slack_webhook_url_verification', 'Slack URL verification challenge answered.' );
			return rest_ensure_response( array( 'challenge' => $challenge ) );
		}

		if ( 'event_callback' !== $type ) {
			return rest_ensure_response( array() );
		}

		$event_id = isset( $payload['event_id'] ) ? sanitize_text_field( $payload['event_id'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'slack_webhook_event_received',
			'Slack event callback received.',
			array( 'event_id' => $event_id )
		);

		// Deduplicate Slack event delivery.
		if ( $event_id && $this->is_duplicate_event( $event_id ) ) {
			return rest_ensure_response( array() );
		}

		if ( $event_id ) {
			set_transient( 'wp_mcp_ai_sl_dedup_' . md5( $event_id ), 1, self::DEDUP_TRANSIENT_TTL );
		}

		$event = isset( $payload['event'] ) && is_array( $payload['event'] ) ? $payload['event'] : array();
		$this->process_event( $event );

		// Acknowledge within 3 seconds; actual reply is sent asynchronously.
		return rest_ensure_response( array() );
	}

	/**
	 * Process a Slack event object.
	 *
	 * Handles non-bot text messages (`message` type without a subtype) and
	 * direct @mentions of the app (`app_mention` type). The `app_mention` event
	 * is fired by Slack when a user @mentions the bot in a channel where the app
	 * is installed and requires the `app_mentions:read` scope plus the
	 * `app_mention` event subscription.
	 *
	 * Channel-type awareness (industry standard):
	 * - `channel` / `group`: public and private channels — mentions optional.
	 * - `im`: 1-on-1 direct message — the bot is the direct recipient, so
	 *   `require_mention` is never enforced (user is already talking to the bot).
	 * - `mpim`: multi-person DM — treated the same as `im` (bot is a direct
	 *   participant, no @mention noise needed).
	 *
	 * Thread-aware replies (industry standard):
	 * The `thread_ts` field is forwarded to the async reply job so that messages
	 * sent inside a Slack thread receive their AI reply inside the same thread
	 * (via `chat.postMessage` `thread_ts` parameter).
	 *
	 * @since 1.0.0
	 *
	 * @param array $event Slack event object.
	 */
	protected function process_event( array $event ) {
		$event_type = isset( $event['type'] ) ? $event['type'] : '';

		// Handle both plain channel messages and direct @mentions (app_mention).
		// app_mention is fired when a user @mentions the bot in any channel.
		// message.channels / message.groups / message.im cover all messages in
		// channels where the bot is a member.
		$is_app_mention = ( 'app_mention' === $event_type );

		if ( 'message' !== $event_type && ! $is_app_mention ) {
			return;
		}

		// Skip bot messages and subtypes (edits, deletions, etc.) for message events.
		// app_mention events originate from real users, so the subtype check is
		// applied only to the generic message type to avoid double-replies.
		if ( ! $is_app_mention && ( isset( $event['bot_id'] ) || isset( $event['subtype'] ) ) ) {
			return;
		}

		$text       = isset( $event['text'] ) ? (string) $event['text'] : '';
		$user_id    = isset( $event['user'] ) ? (string) $event['user'] : '';
		$channel_id = isset( $event['channel'] ) ? (string) $event['channel'] : '';
		$message_ts = isset( $event['ts'] ) ? (string) $event['ts'] : '';
		// channel_type is set by Slack: 'channel' (public), 'group' (private),
		// 'im' (1-on-1 DM), or 'mpim' (multi-person DM).
		$channel_type = isset( $event['channel_type'] ) ? sanitize_key( $event['channel_type'] ) : '';
		// thread_ts is present when the message is posted inside an existing thread.
		// It identifies the root (parent) message of the thread. Passing it through
		// to the reply job allows the bot to respond in the same thread.
		$thread_ts = isset( $event['thread_ts'] ) ? (string) $event['thread_ts'] : '';

		if ( '' === $text || '' === $user_id || '' === $channel_id ) {
			return;
		}

		$connection = $this->get_active_slack_connection( $this->current_connection_id );

		if ( ! $connection ) {
			WP_MCP_AI_Logger::log_error( 'Slack webhook: no active Slack connection with assigned assistants found.' );
			return;
		}

		$assigned_assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_values( array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) ) )
			: array();

		if ( empty( $assigned_assistant_ids ) ) {
			return;
		}

		$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';

		if ( '' === $connection_id ) {
			return;
		}

		// Retrieve the bot's Slack user ID (stored when the admin last ran the
		// connection test). Used to detect native Slack @mentions (<@USER_ID>)
		// in message events and to strip the mention prefix before sending to AI.
		$bot_user_id = isset( $connection['slack_bot_user_id'] )
			? sanitize_text_field( $connection['slack_bot_user_id'] )
			: '';

		// When the connection requires a mention, app_mention events ALWAYS satisfy
		// it (the user explicitly @mentioned the bot). For plain message events,
		// accept EITHER a Slack native bot mention (<@BOT_USER_ID>) OR an
		// @assistant-slug mention so the bot responds even when only the
		// message.channels event is subscribed (not app_mention).
		//
		// Industry standard: in 1-on-1 DMs (channel_type 'im') and multi-person
		// DMs (channel_type 'mpim') the bot is already the direct recipient —
		// requiring an @mention in a private DM conversation is not user-friendly
		// and goes against Slack platform conventions. The require_mention flag
		// is therefore only enforced for channel/group messages.
		$is_dm = ( 'im' === $channel_type || 'mpim' === $channel_type );

		// For channel/group messages use the channel_id as the inbox conversation thread.
		// For DMs keep the user_id so each DM conversation is per-user.
		$inbox_contact_id = $is_dm ? $user_id : $channel_id;
		$inbox_conv_type  = $is_dm ? 'dm' : 'channel';

		if ( ! empty( $connection['require_mention'] ) && ! $is_app_mention && ! $is_dm ) {
			$has_slack_bot_mention = '' !== $bot_user_id && false !== strpos( $text, '<@' . $bot_user_id . '>' );
			if ( ! $has_slack_bot_mention && ! $this->message_mentions_assistant( $text, $assigned_assistant_ids ) ) {
				return;
			}
		}

		// Prevent duplicate AI replies when Slack delivers both an app_mention
		// event AND a message.channels event for the same user message (Slack
		// sends both when the bot is @mentioned in a public channel). Guard with
		// a short transient keyed on the message timestamp + channel + connection.
		if ( '' !== $message_ts ) {
			$msg_dedup_key = 'wp_mcp_ai_sl_msg_' . md5( $message_ts . '_' . $channel_id . '_' . $connection_id );
			if ( get_transient( $msg_dedup_key ) ) {
				return;
			}
			set_transient( $msg_dedup_key, 1, self::DEDUP_TRANSIENT_TTL );
		}

		// Strip the leading "<@BOT_USER_ID> " Slack mention syntax from the
		// message text before storing and sending to the AI so the assistant
		// receives a clean question without the mention noise.
		if ( '' !== $bot_user_id ) {
			$stripped = trim( preg_replace( '/^<@' . preg_quote( $bot_user_id, '/' ) . '>\s*/u', '', $text ) );
			if ( '' !== $stripped ) {
				$text = $stripped;
			}
		}

		// Enforce per-contact rate limiting when the global setting is enabled.
		// Uses a transient-based sliding window; see wp_mcp_ai_chat_channel_is_rate_limited().
		if ( function_exists( 'wp_mcp_ai_chat_channel_is_rate_limited' ) &&
			wp_mcp_ai_chat_channel_is_rate_limited( 'slack', $user_id ) ) {
			return;
		}

		// Find or create the contact in the Channel Contacts CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			$contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
				'slack',
				$inbox_contact_id,
				array(
					'display_name'      => $is_dm ? $user_id : ( '#' . $channel_id ),
					'connection_id'     => $connection_id,
					'conversation_type' => $inbox_conv_type,
				)
			);
			if ( $contact_row_id ) {
				WP_MCP_AI_Channel_Contacts_CCT::touch( $contact_row_id );
			}
		}

		// Persist inbound message to Channel Messages CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'slack',
					'channel_contact_id' => $inbox_contact_id,
					'direction'          => 'inbound',
					'message_id'         => isset( $event['event_ts'] ) ? sanitize_text_field( $event['event_ts'] ) : '',
					'message_type'       => 'text',
					'content'            => $text,
					'status'             => 'received',
					'connection_id'      => $connection_id,
					'phone_number_id'    => $channel_id,
					'timestamp'          => '' !== $message_ts ? (int) (float) $message_ts : time(),
					'reply_sent'         => 0,
					'assigned_agent'     => (string) $assigned_assistant_ids[0],
					'conversation_type'  => $inbox_conv_type,
				)
			);
		}

		$job_args = array(
			array(
				'assistant_id'  => $assigned_assistant_ids[0],
				'message_text'  => $text,
				'user_id'       => $user_id,
				'channel_id'    => $channel_id,
				'connection_id' => $connection_id,
				// thread_ts: forward so the reply is posted inside the same Slack thread.
				// Empty string when the message is at channel-level (not in a thread).
				'thread_ts'     => $thread_ts,
				// channel_type: 'channel', 'group', 'im', or 'mpim'. Used by the
				// reply job to determine whether to include thread_ts in the payload.
				'channel_type'  => $channel_type,
			),
		);

		wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
		spawn_cron();
	}

	/**
	 * Cron callback: generate an AI reply and post it to Slack via chat.postMessage.
	 *
	 * Implements per-user conversation history following the same pattern as the
	 * WhatsApp auto-reply handler (PR #3844), respecting the global
	 * max_history_messages setting and the wp_mcp_ai_slack_max_history_messages filter.
	 *
	 * Thread-aware replies (industry standard):
	 * When the original message was posted inside a Slack thread (thread_ts present),
	 * the AI reply is sent back into the same thread via the thread_ts parameter of
	 * chat.postMessage, keeping conversations tidy and contextual.
	 *
	 * Rate limiting (industry standard):
	 * When Slack returns HTTP 429 Too Many Requests the Retry-After header value is
	 * respected and the job is rescheduled.  A retry counter (max MAX_RATE_LIMIT_RETRIES)
	 * prevents indefinite loops.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Job arguments set by process_event().
	 */
	public function handle_slack_reply_job( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		$assistant_id  = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;
		$message_text  = isset( $args['message_text'] ) ? (string) $args['message_text'] : '';
		$user_id       = isset( $args['user_id'] ) ? (string) $args['user_id'] : '';
		$channel_id    = isset( $args['channel_id'] ) ? (string) $args['channel_id'] : '';
		$connection_id = isset( $args['connection_id'] ) ? sanitize_key( $args['connection_id'] ) : '';
		// thread_ts is set when the message was posted inside a Slack thread.
		// Empty string for top-level (non-threaded) channel messages and DMs.
		$thread_ts = isset( $args['thread_ts'] ) ? (string) $args['thread_ts'] : '';
		// channel_type distinguishes DMs ('im', 'mpim') from channel messages.
		$channel_type = isset( $args['channel_type'] ) ? sanitize_key( $args['channel_type'] ) : '';
		// Retry counter incremented each time the job is rescheduled due to a 429.
		$retry_count = isset( $args['retry_count'] ) ? absint( $args['retry_count'] ) : 0;

		if ( ! $assistant_id || '' === $message_text || '' === $channel_id || '' === $connection_id ) {
			WP_MCP_AI_Logger::log_error(
				'Slack AI reply: missing required job argument.',
				array(
					'assistant_id'  => $assistant_id,
					'has_message'   => '' !== $message_text,
					'has_channel'   => '' !== $channel_id,
					'connection_id' => $connection_id,
				)
			);
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection || empty( $connection['api_key'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Slack AI reply: connection not found or bot token missing.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		$bot_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );

		if ( '' === $bot_token ) {
			WP_MCP_AI_Logger::log_error(
				'Slack AI reply: bot token decryption returned empty string.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		// --- Per-user conversation history (mirrors PR #3844 for WhatsApp) ---
		// Thread-aware history: when the message is inside a Slack thread, scope
		// the history to that thread so each thread maintains independent context.
		// DMs and top-level channel messages use the channel-level key (no thread_ts).
		$is_dm            = ( 'im' === $channel_type || 'mpim' === $channel_type );
		$inbox_contact_id = $is_dm ? $user_id : $channel_id;
		$inbox_conv_type  = $is_dm ? 'dm' : 'channel';
		$history_key      = $this->get_conversation_history_key(
			$user_id,
			$channel_id,
			$connection_id,
			( ! $is_dm && '' !== $thread_ts ) ? $thread_ts : ''
		);
		$history          = get_transient( $history_key );
		$history          = is_array( $history ) ? $history : array();

		$max_history = 8;
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings    = WP_MCP_AI_Admin_Settings::get_settings();
			$max_history = isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : $max_history;
		}

		/**
		 * Filters the maximum number of messages kept in a Slack conversation history.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $max_history Maximum message count.
		 * @param array $args        Current job arguments.
		 */
		$max_history = (int) apply_filters( 'wp_mcp_ai_slack_max_history_messages', $max_history, $args );
		$max_history = max( 1, $max_history );

		// When the transient cache is empty (e.g. after expiry or a cache flush),
		// hydrate the conversation context from the Channel Messages CCT so that
		// prior exchanges are never silently dropped. The CCT is the persistent
		// source of truth; the transient is a fast in-memory cache on top of it.
		if ( empty( $history ) && $max_history > 1 && class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			$history = WP_MCP_AI_Channel_Messages_CCT::get_recent_messages(
				'slack',
				$user_id,
				$connection_id,
				$max_history - 1
			);
		}

		$history = WP_MCP_AI_Webhook_Context_Manager::trim_history( $history, $max_history, 'slack', 1 );

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
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_body_params(
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
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		} else {
			WP_MCP_AI_Logger::log_error(
				'Slack AI reply: no administrator user found; internal chat request may fail.',
				array( 'assistant_id' => $assistant_id )
			);
		}

		// Raise the agentic-loop iteration cap for Slack reply jobs so that
		// multi-step tool workflows (search → analyse → respond, etc.) can run
		// to completion. Without this, the /mcp-ai/v1/chat endpoint defaults to
		// a single iteration and the final content remains null when a second
		// tool round is needed.
		add_filter( 'wp_mcp_ai_max_agentic_iterations', array( $this, 'get_slack_max_agentic_iterations' ), 10, 2 );
		$response = rest_do_request( $request );
		remove_filter( 'wp_mcp_ai_max_agentic_iterations', array( $this, 'get_slack_max_agentic_iterations' ), 10 );

		wp_set_current_user( $original_user_id );

		if ( $response->is_error() ) {
			WP_MCP_AI_Logger::log_error(
				'Slack AI reply: chat request failed.',
				array( 'assistant_id' => $assistant_id )
			);
			return;
		}

		$content = $this->extract_content_from_chat_response( $response->get_data() );

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error( 'Slack AI reply: empty content from assistant.' );
			return;
		}

		// Convert AI markdown response to Slack mrkdwn so the reply renders
		// with proper bold, italic, code, and link formatting instead of showing
		// raw markdown syntax in the Slack channel.
		$mrkdwn_content = self::convert_markdown_to_mrkdwn( $content );

		// Post reply to Slack via chat.postMessage.
		// Industry standard: if the incoming message was inside a Slack thread,
		// reply inside the same thread by passing thread_ts. This keeps channel
		// conversations tidy and is the expected behaviour for Slack bots.
		// thread_ts is not included for DMs (already 1-on-1) or top-level
		// channel messages (no active thread).
		// Use Slack Block Kit with mrkdwn text type for rich formatting.
		// The plain-text 'text' field is required as a notification fallback.
		$blocks = self::build_slack_blocks( $mrkdwn_content );

		$payload = array(
			'channel' => $channel_id,
			'text'    => wp_strip_all_tags( $content ),
			'blocks'  => $blocks,
		);

		if ( '' !== $thread_ts && ! $is_dm ) {
			$payload['thread_ts'] = $thread_ts;
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			WP_MCP_AI_Logger::log_error( 'Slack AI reply: failed to JSON-encode payload.' );
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'slack_ai_reply_sending',
			'Sending Slack AI reply.',
			array(
				'assistant_id' => $assistant_id,
				'channel_id'   => $channel_id,
			)
		);

		$result = wp_remote_post(
			'https://slack.com/api/chat.postMessage',
			array(
				'headers' => array(
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Bearer ' . $bot_token,
				),
				'timeout' => 20,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Slack AI reply: HTTP request failed.',
				array( 'error' => $result->get_error_message() )
			);
			return;
		}

		$http_code    = (int) wp_remote_retrieve_response_code( $result );
		$decoded_body = json_decode( wp_remote_retrieve_body( $result ), true );
		$api_ok       = is_array( $decoded_body ) && ! empty( $decoded_body['ok'] );

		// Industry standard: handle HTTP 429 Too Many Requests by respecting the
		// Retry-After header returned by Slack and rescheduling the job.
		// A retry counter prevents indefinite loops.
		if ( 429 === $http_code ) {
			if ( $retry_count >= self::MAX_RATE_LIMIT_RETRIES ) {
				WP_MCP_AI_Logger::log_error(
					'Slack AI reply: rate limit retry limit reached; giving up.',
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
					'Slack AI reply: rate limited (429). Retrying in %d seconds (attempt %d/%d).',
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

		if ( 200 !== $http_code || ! $api_ok ) {
			$api_error = is_array( $decoded_body ) && isset( $decoded_body['error'] ) ? $decoded_body['error'] : '';

			if ( 'account_inactive' === $api_error ) {
				WP_MCP_AI_Logger::log_error(
					'Slack AI reply: bot account is inactive (account_inactive). The Slack app may have been removed from the workspace or the bot user deactivated. Update the Bot Token in the connection settings.',
					array(
						'connection_id' => $connection_id,
						'api_error'     => $api_error,
					)
				);
			} else {
				WP_MCP_AI_Logger::log_error(
					'Slack AI reply: API returned an error.',
					array(
						'http_code' => $http_code,
						'api_error' => $api_error,
					)
				);
			}
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
		$history   = WP_MCP_AI_Webhook_Context_Manager::trim_history_after_response( $history, $max_history, 'slack' );
		set_transient( $history_key, $history, self::CONVERSATION_HISTORY_TTL );

		WP_MCP_AI_Logger::log_event(
			'slack_ai_reply_sent',
			'Slack AI reply sent successfully.',
			array(
				'assistant_id' => $assistant_id,
				'channel_id'   => $channel_id,
			)
		);

		// Persist the outbound AI reply to the Channel Messages CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'slack',
					'channel_contact_id' => $inbox_contact_id,
					'direction'          => 'outbound',
					'message_type'       => 'text',
					'content'            => $content,
					'status'             => 'sent',
					'connection_id'      => $connection_id,
					'phone_number_id'    => $channel_id,
					'timestamp'          => time(),
					'reply_sent'         => 1,
					'assigned_agent'     => (string) $assistant_id,
					'conversation_type'  => $inbox_conv_type,
				)
			);
		}

		// Touch the contact record to update last_message_at.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			$sl_contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
				'slack',
				$inbox_contact_id,
				array(
					'connection_id'     => $connection_id,
					'conversation_type' => $inbox_conv_type,
				)
			);
			if ( $sl_contact_row_id ) {
				WP_MCP_AI_Channel_Contacts_CCT::touch( $sl_contact_row_id );
			}
		}
	}

	/**
	 * Return the transient key for a Slack user/channel conversation history.
	 *
	 * The key is hashed to avoid PII in option names and stay within WordPress's
	 * 172-character transient key limit.
	 *
	 * Thread-scoped history: when $thread_ts is supplied (non-empty string) the
	 * key is scoped to that specific thread so that multiple concurrent thread
	 * conversations in the same channel each maintain independent context.
	 * Pass an empty string (or omit) for DMs and top-level channel messages.
	 *
	 * @since 1.0.0
	 *
	 * @param string $user_id       Slack user ID.
	 * @param string $channel_id    Slack channel ID.
	 * @param string $connection_id Remote connection ID.
	 * @param string $thread_ts     Optional thread root timestamp for thread-scoped history.
	 * @return string Transient key.
	 */
	protected function get_conversation_history_key( $user_id, $channel_id, $connection_id, $thread_ts = '' ) {
		$key_parts = $user_id . '_' . $channel_id . '_' . $connection_id;
		if ( '' !== $thread_ts ) {
			$key_parts .= '_' . $thread_ts;
		}
		return 'wp_mcp_ai_sl_conv_' . md5( $key_parts );
	}

	/**
	 * Check whether an event_id has already been processed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_id Slack event ID.
	 * @return bool True if this event was seen before.
	 */
	protected function is_duplicate_event( $event_id ) {
		return (bool) get_transient( 'wp_mcp_ai_sl_dedup_' . md5( $event_id ) );
	}

	/**
	 * Find a specific Slack connection by its ID.
	 *
	 * Only returns the connection when it is enabled; does not require
	 * assigned assistants so it can be used for both signing-secret lookup
	 * (URL verification) and event routing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID to look up.
	 * @param array  $connections   All stored connections (must already be loaded).
	 * @return array|null Connection array or null if not found.
	 */
	protected function find_slack_connection_by_id( $connection_id, array $connections ) {
		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'slack' !== $connection['connection_type'] ) {
				continue;
			}

			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			if ( isset( $connection['id'] ) && $connection['id'] === $connection_id ) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * Retrieve the HMAC signing secret for a given Slack connection.
	 *
	 * When a `$connection_id` is supplied the signing secret is read from that
	 * specific connection regardless of whether assistants have been assigned.
	 * This allows the URL-verification challenge to succeed during initial setup
	 * before assistants are configured.
	 *
	 * Falls back to the first active Slack connection that has a signing secret
	 * configured when no connection_id is provided (backward-compatible behaviour).
	 *
	 * Checks `signing_secret` first (populated by the updated admin form).
	 * Falls back to `api_secret` for legacy connections saved before the admin
	 * form was updated to write the correct field.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $connection_id Optional connection ID from the request URL.
	 * @return string Signing secret or empty string if not configured.
	 */
	protected function get_signing_secret( $connection_id = null ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( ! is_array( $connections ) ) {
			return '';
		}

		// When a specific connection is requested, look it up directly (no
		// assistant requirement — signing secret is needed for URL verification
		// even before assistants are assigned).
		if ( $connection_id ) {
			$connection = $this->find_slack_connection_by_id( $connection_id, $connections );

			if ( ! $connection ) {
				return '';
			}

			$secret = ! empty( $connection['signing_secret'] ) ? $connection['signing_secret']
				: ( ! empty( $connection['api_secret'] ) ? $connection['api_secret'] : '' );

			if ( '' === $secret ) {
				return '';
			}

			return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $secret );
		}

		// Fallback: return the signing secret from the first active Slack
		// connection that has one configured (backward-compatible).
		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'slack' !== $connection['connection_type'] ) {
				continue;
			}

			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			$secret = ! empty( $connection['signing_secret'] ) ? $connection['signing_secret']
				: ( ! empty( $connection['api_secret'] ) ? $connection['api_secret'] : '' );

			if ( '' === $secret ) {
				continue;
			}

			return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $secret );
		}

		return '';
	}

	/**
	 * Find the active Slack connection for processing an incoming event.
	 *
	 * When a `$connection_id` is supplied the matching connection is returned
	 * directly, enabling per-workspace routing for multi-workspace setups.
	 *
	 * Without a connection_id the instance property `$this->current_connection_id`
	 * is checked first (set by `handle_event()` from the URL), then the first
	 * active Slack connection with assigned assistants is returned for
	 * backward-compatible single-workspace setups.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $connection_id Optional connection ID to target directly.
	 * @return array|null Connection array or null if none found.
	 */
	protected function get_active_slack_connection( $connection_id = null ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( ! is_array( $connections ) ) {
			return null;
		}

		// Resolve target connection ID: explicit param > instance property.
		$target_id = $connection_id ?? $this->current_connection_id;

		// When a specific connection is requested, look it up directly.
		if ( $target_id ) {
			// find_slack_connection_by_id() does not require assigned_assistant_ids.
			// The caller (process_event) enforces that check after this returns.
			return $this->find_slack_connection_by_id( $target_id, $connections );
		}

		// Fallback: return the first active Slack connection with assigned assistants.
		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'slack' !== $connection['connection_type'] ) {
				continue;
			}

			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			if ( empty( $connection['assigned_assistant_ids'] ) || ! is_array( $connection['assigned_assistant_ids'] ) ) {
				continue;
			}

			return $connection;
		}

		return null;
	}

	/**
	 * Convert standard Markdown to Slack mrkdwn format.
	 *
	 * AI models return responses in standard Markdown. Slack uses its own
	 * "mrkdwn" dialect that differs in key ways (e.g. *bold* not **bold**,
	 * _italic_, ~strikethrough~, <url|text> links). This method bridges the
	 * gap so that AI replies render with proper formatting in Slack channels.
	 *
	 * Code spans and code blocks are preserved verbatim because their syntax
	 * is identical in both dialects. Headings are converted to bold lines.
	 * HTML anchor tags that AI assistants sometimes emit are converted to
	 * Slack link syntax. Unrecognised HTML tags are stripped.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Markdown-formatted AI response text.
	 * @return string mrkdwn-formatted text suitable for Slack chat.postMessage.
	 */
	public static function convert_markdown_to_mrkdwn( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}

		// 1. Extract fenced code blocks and replace with placeholders so that
		// content inside them is not processed by subsequent regex rules.
		$code_blocks            = array();
		$code_block_placeholder = "\x07SLKCB:";
		$cb_index               = 0;

		$text = preg_replace_callback(
			'/```([a-zA-Z0-9_+-]*)\n?([\s\S]*?)```/s',
			function ( $m ) use ( &$code_blocks, &$cb_index, $code_block_placeholder ) {
				$key                 = $code_block_placeholder . $cb_index . "\x07";
				$code_blocks[ $key ] = '```' . rtrim( $m[2], "\n" ) . '```';
				++$cb_index;
				return $key;
			},
			$text
		);

		// 2. Extract inline code spans and replace with placeholders.
		$inline_codes            = array();
		$inline_code_placeholder = "\x07SLKIC:";
		$ic_index                = 0;

		$text = preg_replace_callback(
			'/`([^`\n]+?)`/',
			function ( $m ) use ( &$inline_codes, &$ic_index, $inline_code_placeholder ) {
				$key                  = $inline_code_placeholder . $ic_index . "\x07";
				$inline_codes[ $key ] = '`' . $m[1] . '`';
				++$ic_index;
				return $key;
			},
			$text
		);

		// 3. Convert HTML anchor tags to Slack link syntax <url|link_text>.
		// AI responses sometimes emit raw <a href="…">…</a> instead of
		// Markdown [text](url) syntax. The result is stashed behind a
		// placeholder: wp_strip_all_tags() below would otherwise eat the
		// Slack link syntax, which looks like an HTML tag.
		$anchors            = array();
		$anchor_placeholder = "\x07SLKA:";
		$an_index           = 0;

		$text = preg_replace_callback(
			'/<a\b[^>]*\bhref=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/si',
			function ( $m ) use ( &$anchors, &$an_index, $anchor_placeholder ) {
				$url       = esc_url( $m[1] );
				$link_text = wp_strip_all_tags( $m[2] );
				$key       = $anchor_placeholder . $an_index . "\x07";
				if ( '' === $url ) {
					$anchors[ $key ] = $link_text;
				} else {
					$anchors[ $key ] = '' !== $link_text ? '<' . $url . '|' . $link_text . '>' : '<' . $url . '>';
				}
				++$an_index;
				return $key;
			},
			$text
		);

		// 4. Strip any remaining HTML tags (Slack does not render HTML).
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 4b. Restore the converted anchors.
		if ( ! empty( $anchors ) ) {
			$text = str_replace( array_keys( $anchors ), array_values( $anchors ), $text );
		}

		// 5. Italic: *text* → _text_ (Slack underscore italic).
		// Runs BEFORE the bold and heading conversions: those steps produce
		// Slack's own single-asterisk *bold* tokens, and the lookarounds here
		// only guard against adjacent asterisks (**pairs**), so running this
		// afterwards would silently convert fresh *bold* output into _italic_.
		$text = preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '_$1_', $text );

		// 6. Headings (# … through ######) → *bold* text on its own line.
		$text = preg_replace( '/^#{1,6}\s+(.+)$/m', '*$1*', $text );

		// 7. Bold: **text** or __text__ → *text* (Slack single-asterisk bold).
		$text = preg_replace( '/\*\*(.+?)\*\*/s', '*$1*', $text );
		$text = preg_replace( '/__(.+?)__/s', '*$1*', $text );

		// 8. Underscored italic: _text_ stays as _text_ (already mrkdwn).
		// No change needed.

		// 9. Strikethrough: ~~text~~ → ~text~ (Slack single-tilde).
		$text = preg_replace( '/~~(.+?)~~/s', '~$1~', $text );

		// 10. Markdown links: [text](url) → <url|text>.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)]+)\)/',
			function ( $m ) {
				$url = esc_url( trim( $m[2] ) );
				if ( '' === $url ) {
					return $m[1];
				}
				return '<' . $url . '|' . $m[1] . '>';
			},
			$text
		);

		// 11. Bullet lists: lines starting with "- " or "* " → "• " (Unicode bullet).
		$text = preg_replace( '/^[ \t]*[-*]\s+/m', '• ', $text );

		// 12. Restore inline code placeholders.
		if ( ! empty( $inline_codes ) ) {
			$text = str_replace( array_keys( $inline_codes ), array_values( $inline_codes ), $text );
		}

		// 13. Restore fenced code block placeholders.
		if ( ! empty( $code_blocks ) ) {
			$text = str_replace( array_keys( $code_blocks ), array_values( $code_blocks ), $text );
		}

		return trim( $text );
	}

	/**
	 * Build a Slack Block Kit payload from mrkdwn-formatted text.
	 *
	 * Slack Block Kit section blocks allow mrkdwn rendering and have a 3 000-
	 * character limit per block. This method splits long replies into multiple
	 * section blocks while keeping paragraph breaks intact. Each block renders
	 * as rich text with proper bold, italic, code, and link formatting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $mrkdwn mrkdwn-formatted text.
	 * @return array Array of Slack Block Kit block objects.
	 */
	public static function build_slack_blocks( $mrkdwn ) {
		// Slack section block text limit.
		$max_block_len = 3000;

		if ( strlen( $mrkdwn ) <= $max_block_len ) {
			return array(
				array(
					'type' => 'section',
					'text' => array(
						'type' => 'mrkdwn',
						'text' => $mrkdwn,
					),
				),
			);
		}

		// Split on two or more consecutive newlines to identify paragraph boundaries.
		// Each paragraph is accumulated into a block until the 3000-char limit is reached.
		$paragraphs = preg_split( '/\n{2,}/', $mrkdwn );
		$blocks     = array();
		$current    = '';

		foreach ( $paragraphs as $para ) {
			$para = trim( $para );
			if ( '' === $para ) {
				continue;
			}

			$candidate = '' === $current ? $para : $current . "\n\n" . $para;

			if ( strlen( $candidate ) <= $max_block_len ) {
				$current = $candidate;
			} else {
				if ( '' !== $current ) {
					$blocks[] = array(
						'type' => 'section',
						'text' => array(
							'type' => 'mrkdwn',
							'text' => $current,
						),
					);
				}
				// If a single paragraph exceeds the limit, truncate it.
				$current = strlen( $para ) > $max_block_len ? substr( $para, 0, $max_block_len ) : $para;
			}
		}

		if ( '' !== $current ) {
			$blocks[] = array(
				'type' => 'section',
				'text' => array(
					'type' => 'mrkdwn',
					'text' => $current,
				),
			);
		}

		return $blocks;
	}

	/**
	 * Return the maximum number of agentic iterations for Slack reply jobs.
	 *
	 * Hooked to the `wp_mcp_ai_max_agentic_iterations` filter during cron
	 * execution so that multi-step tool workflows complete before the Slack
	 * reply is dispatched.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $default_max     Current maximum from upstream filter chain.
	 * @param array $assistant_config Assistant configuration (unused).
	 * @return int The higher of the incoming default and DEFAULT_MAX_AGENTIC_ITERATIONS.
	 */
	public function get_slack_max_agentic_iterations( $default_max, $assistant_config = array() ) {
		return max( (int) $default_max, self::DEFAULT_MAX_AGENTIC_ITERATIONS );
	}

	/**
	 * Resolve a message content value to a plain-text string.
	 *
	 * The /mcp-ai/v1/chat endpoint can return content as either:
	 * - A plain string (OpenAI, Anthropic).
	 * - An array of typed segments (Gemini / Ollama normalised format), where
	 *   each segment has at minimum a `type` key. Only `text`-type segments
	 *   carry displayable text.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $content Raw content value from message.content.
	 * @return string Plain-text content or empty string.
	 */
	protected function resolve_content_to_string( $content ) {
		if ( is_string( $content ) ) {
			return trim( $content );
		}

		if ( ! is_array( $content ) ) {
			return '';
		}

		// Array of content segments (Gemini / Ollama normalised format).
		$parts = array();
		foreach ( $content as $segment ) {
			if ( ! is_array( $segment ) ) {
				if ( is_string( $segment ) ) {
					$parts[] = $segment;
				}
				continue;
			}

			$type = isset( $segment['type'] ) ? (string) $segment['type'] : '';

			if ( 'text' === $type && isset( $segment['text'] ) && is_string( $segment['text'] ) ) {
				$text = trim( $segment['text'] );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			} elseif ( isset( $segment['text'] ) && is_string( $segment['text'] ) ) {
				$text = trim( $segment['text'] );
				if ( '' !== $text ) {
					$parts[] = $text;
				}
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Extract plain-text content from the internal /mcp-ai/v1/chat response.
	 *
	 * Handles both string and array content segments (multi-provider normalisation
	 * for Gemini and Ollama responses that return content as an array).
	 *
	 * When an agentic tool-calling workflow runs, some providers set
	 * `message.content` to null on intermediate responses where
	 * `finish_reason = "tool_calls"`. This method handles that case by scanning
	 * all choices and falling back to `agentic_tool_messages` so that the last
	 * assistant message with non-empty text is returned.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data Response data from the chat endpoint.
	 * @return string Plain-text content or empty string.
	 */
	protected function extract_content_from_chat_response( $data ) {
		if ( ! is_array( $data ) ) {
			return '';
		}

		// Normalise: the endpoint wraps the raw LLM response under 'data'.
		$llm_data = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
		$choices  = isset( $llm_data['choices'] ) && is_array( $llm_data['choices'] ) ? $llm_data['choices'] : array();

		// --- Pass 1: scan every choice for a non-empty content value.
		// Prefer choices whose finish_reason is 'stop' over 'tool_calls'.
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
		// `agentic_tool_messages`. Return the last one that contains text.
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
}

new WP_MCP_AI_Slack_Event_Controller();
