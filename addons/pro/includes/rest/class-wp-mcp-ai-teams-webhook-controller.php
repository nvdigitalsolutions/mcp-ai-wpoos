<?php
/**
 * Microsoft Teams Outgoing Webhook Controller
 *
 * Handles incoming Microsoft Teams outgoing webhook requests with
 * industry-standard security validation and rich messaging features.
 * Implements Teams platform best practices and mirrors the Slack and Telegram
 * channel controller feature set:
 *
 * - HMAC-SHA256 signature verification (Authorization header)
 * - Per-connection webhook endpoints for multi-tenant Teams support
 * - Payload timestamp validation (replay-attack prevention)
 * - Conversation type detection (channel / groupChat / personal DM)
 * - DM bypass: require_mention is not enforced for personal/groupChat chats
 * - Thread-aware replies: channel messages are replied to in-thread via
 *   the Graph API replies endpoint (keeping conversations tidy)
 * - Personal/groupChat support via the /chats/{id}/messages endpoint
 * - Rich markdown→HTML formatting (bold, italic, code, links, lists)
 * - Per-contact rate limiting (transient-based sliding window)
 * - AI auto-reply via WordPress cron (async)
 * - HTTP 429 retry-after handling with configurable retry cap
 * - Message deduplication (activity-level + message-level double dedup)
 * - Thread-scoped per-user conversation history
 * - Multi-provider content normalisation (string + array content segments)
 *
 * Teams outgoing webhooks are configured in the Teams Admin Center and send
 * requests signed with a shared HMAC-SHA256 secret (the "Security token"
 * shown when creating the outgoing webhook). Store this as signing_secret on
 * the connection.
 *
 * @see https://learn.microsoft.com/en-us/microsoftteams/platform/webhooks-and-connectors/how-to/add-outgoing-webhook
 * @see https://learn.microsoft.com/en-us/graph/api/channel-post-messages
 * @see https://learn.microsoft.com/en-us/graph/api/chatmessage-post-replies
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
 * Microsoft Teams outgoing webhook REST controller.
 */
class WP_MCP_AI_Teams_Webhook_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks/teams';

	/**
	 * Tracks the connection_id resolved from the incoming request URL so every
	 * helper called during the request lifecycle targets the correct Teams tenant
	 * without requiring connection_id to be threaded through every method signature.
	 *
	 * @var string|null
	 */
	protected $current_connection_id = null;

	/**
	 * Cron hook for dispatching AI replies to incoming Teams messages.
	 */
	const REPLY_CRON_HOOK = 'wp_mcp_ai_teams_send_ai_reply';

	/**
	 * TTL in seconds for the deduplication transient.
	 */
	const DEDUP_TRANSIENT_TTL = 60;

	/**
	 * TTL in seconds for per-user conversation history transients (24 hours).
	 */
	const CONVERSATION_HISTORY_TTL = 86400;

	/**
	 * Microsoft Graph API base URL.
	 */
	const GRAPH_API_BASE = 'https://graph.microsoft.com/v1.0';

	/**
	 * Maximum age in seconds for an incoming Teams request timestamp (5 minutes).
	 * Payloads with a timestamp older than this are rejected as potential replays.
	 */
	const MAX_REQUEST_AGE = 300;

	/**
	 * Maximum number of times a rate-limited (HTTP 429) reply job will be
	 * retried before giving up. Each retry respects the Retry-After header
	 * returned by the Graph API.
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
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_teams_reply_job' ) );
	}

	/**
	 * Register REST routes for Teams outgoing webhooks.
	 *
	 * Registers two routes:
	 * - Generic:        POST /mcp-ai/v1/webhooks/teams
	 * - Per-connection: POST /mcp-ai/v1/webhooks/teams/{connection_id}
	 *
	 * The per-connection route lets multiple Teams tenants each have a
	 * dedicated webhook URL, matching the pattern used by the Slack and
	 * Telegram controllers.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Global webhook endpoint (backward-compatible, single-tenant setups).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_teams_signature' ),
			)
		);

		// Per-connection webhook endpoint so multiple Teams tenants can each
		// have a dedicated URL: /mcp-ai/v1/webhooks/teams/{connection_id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<connection_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_teams_signature' ),
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
	 * Validate the Teams outgoing webhook HMAC-SHA256 signature.
	 *
	 * Teams signs the request body using the HMAC-SHA256 algorithm with the
	 * shared security token (base64-encoded) and sends the resulting signature
	 * (also base64-encoded) in the Authorization header.
	 *
	 * When a `connection_id` is present in the URL the signing secret is looked
	 * up from that specific connection. When absent, the first active Teams
	 * connection with a signing secret configured is used (backward-compatible).
	 *
	 * When the signing secret is not configured the webhook request is rejected
	 * with a 403 error so that unconfigured endpoints cannot be exploited.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if signature is valid, WP_Error on failure.
	 */
	public function validate_teams_signature( $request ) {
		$connection_id  = $request->get_param( 'connection_id' );
		$signing_secret = $this->get_signing_secret( $connection_id );

		if ( empty( $signing_secret ) ) {
			WP_MCP_AI_Logger::log_error(
				'Teams webhook rejected: no signing secret configured. Set signing_secret in the connection settings to enable HMAC validation.',
				array( 'connection_id' => $connection_id ? $connection_id : 'default' )
			);
			return new WP_Error(
				'rest_forbidden',
				__( 'Webhook authentication is not configured. Please set a signing secret in the connection settings.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		$auth_header = $request->get_header( 'authorization' );

		if ( empty( $auth_header ) ) {
			WP_MCP_AI_Logger::log_error( 'Teams webhook rejected: missing Authorization header.' );
			return false;
		}

		// Teams sends "HMAC {base64-signature}".
		if ( 0 !== strncmp( $auth_header, 'HMAC ', 5 ) ) {
			WP_MCP_AI_Logger::log_error( 'Teams webhook rejected: Authorization header does not start with "HMAC ".' );
			return false;
		}

		$provided_signature = substr( $auth_header, 5 );

		$raw_body      = $request->get_body();
		$key_bytes     = base64_decode( $signing_secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$computed_hmac = hash_hmac( 'sha256', $raw_body, $key_bytes, true );
		$computed_b64  = base64_encode( $computed_hmac ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		if ( ! hash_equals( $computed_b64, $provided_signature ) ) {
			WP_MCP_AI_Logger::log_error( 'Teams webhook rejected: invalid HMAC-SHA256 signature.' );
			return false;
		}

		return true;
	}

	/**
	 * Handle an incoming Teams outgoing webhook request.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function handle_webhook( $request ) {
		// Resolve the per-connection ID from the URL so all helper methods in
		// this request lifecycle can target the correct Teams tenant.
		$raw_conn_id                 = $request->get_param( 'connection_id' );
		$this->current_connection_id = $raw_conn_id ? $raw_conn_id : null;

		$payload = $request->get_json_params();

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			WP_MCP_AI_Logger::log_error( 'Teams webhook: empty or invalid JSON payload.' );
			return rest_ensure_response( $this->empty_response() );
		}

		// Extract Teams-specific identifiers for deduplication and routing.
		$activity_id = isset( $payload['id'] ) ? sanitize_text_field( $payload['id'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'teams_webhook_received',
			'Teams outgoing webhook activity received.',
			array( 'activity_id' => $activity_id )
		);

		// Reject payloads with a stale timestamp (replay-attack prevention).
		// Teams includes an ISO 8601 timestamp in the payload body.
		if ( ! $this->is_request_timestamp_valid( $payload ) ) {
			WP_MCP_AI_Logger::log_error( 'Teams webhook rejected: payload timestamp is stale (possible replay attack).' );
			return rest_ensure_response( $this->empty_response() );
		}

		if ( $activity_id && $this->is_duplicate_activity( $activity_id ) ) {
			return rest_ensure_response( $this->empty_response() );
		}

		if ( $activity_id ) {
			set_transient( 'wp_mcp_ai_ms_dedup_' . md5( $activity_id ), 1, self::DEDUP_TRANSIENT_TTL );
		}

		// Extract the message text.
		$message_text = $this->extract_message_text( $payload );

		if ( '' === $message_text ) {
			return rest_ensure_response( $this->empty_response() );
		}

		// Extract user and channel identifiers for routing and history.
		$user_id      = $this->extract_user_id( $payload );
		$display_name = $this->extract_display_name( $payload );
		$channel_id   = isset( $payload['channelData']['channel']['id'] ) ? sanitize_text_field( $payload['channelData']['channel']['id'] ) : '';
		$team_id      = isset( $payload['channelData']['team']['id'] ) ? sanitize_text_field( $payload['channelData']['team']['id'] ) : '';
		$tenant_id    = isset( $payload['channelData']['tenant']['id'] ) ? sanitize_text_field( $payload['channelData']['tenant']['id'] ) : '';
		$service_url  = isset( $payload['serviceUrl'] ) ? esc_url_raw( $payload['serviceUrl'] ) : '';
		$conversation = isset( $payload['conversation'] ) && is_array( $payload['conversation'] ) ? $payload['conversation'] : array();

		// Detect conversation type: 'channel', 'groupChat', or 'personal'.
		// Used to determine whether require_mention should be enforced and
		// which Graph API endpoint to use when posting the reply.
		$conversation_type = $this->extract_conversation_type( $payload );

		// Extract the parent thread ID (replyToId). When a user posts inside an
		// existing Teams channel thread, this identifies the root message so the
		// AI reply is posted into the same thread.
		$reply_to_id = $this->extract_reply_to_id( $payload );

		$connection = $this->get_active_teams_connection( $tenant_id );

		if ( ! $connection ) {
			WP_MCP_AI_Logger::log_error( 'Teams webhook: no active Teams connection with assigned assistants found.' );
			return rest_ensure_response( $this->empty_response() );
		}

		$assigned_assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_values( array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) ) )
			: array();

		if ( empty( $assigned_assistant_ids ) ) {
			return rest_ensure_response( $this->empty_response() );
		}

		$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';

		if ( '' === $connection_id ) {
			return rest_ensure_response( $this->empty_response() );
		}

		// Industry standard: in personal chats (1-on-1 DM with the bot) and
		// group chats the bot is already a direct participant — requiring an
		// @mention in a private conversation is not user-friendly and goes
		// against Teams platform conventions. The require_mention flag is
		// therefore only enforced for channel messages.
		$is_dm = ( 'personal' === $conversation_type || 'groupChat' === $conversation_type );

		$inbox_contact_id = ( 'channel' === $conversation_type ) ? $channel_id : $user_id;
		$inbox_conv_type  = ( 'channel' === $conversation_type ) ? 'channel' : 'dm';

		if ( ! empty( $connection['require_mention'] ) && ! $is_dm ) {
			if ( ! $this->message_mentions_assistant( $message_text, $assigned_assistant_ids ) ) {
				return rest_ensure_response( $this->empty_response() );
			}
		}

		// Prevent duplicate AI replies when Teams delivers the same event more
		// than once. Guard with a short transient keyed on the activity ID +
		// channel + connection (mirrors the Slack message-level dedup pattern).
		if ( '' !== $activity_id ) {
			$msg_dedup_key = 'wp_mcp_ai_ms_msg_' . md5( $activity_id . '_' . $channel_id . '_' . $connection_id );
			if ( get_transient( $msg_dedup_key ) ) {
				return rest_ensure_response( $this->empty_response() );
			}
			set_transient( $msg_dedup_key, 1, self::DEDUP_TRANSIENT_TTL );
		}

		// Enforce per-contact rate limiting when the global setting is enabled.
		// Uses a transient-based sliding window; see wp_mcp_ai_chat_channel_is_rate_limited().
		if ( function_exists( 'wp_mcp_ai_chat_channel_is_rate_limited' ) &&
			wp_mcp_ai_chat_channel_is_rate_limited( 'teams', $user_id ) ) {
			return rest_ensure_response( $this->empty_response() );
		}

		// Find or create the contact in the Channel Contacts CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			$contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
				'teams',
				$inbox_contact_id,
				array(
					'display_name'      => '' !== $display_name ? $display_name : $inbox_contact_id,
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
					'channel'            => 'teams',
					'channel_contact_id' => $inbox_contact_id,
					'direction'          => 'inbound',
					'message_id'         => $activity_id,
					'message_type'       => 'text',
					'content'            => $message_text,
					'status'             => 'received',
					'connection_id'      => $connection_id,
					'phone_number_id'    => $channel_id,
					'timestamp'          => time(),
					'reply_sent'         => 0,
					'assigned_agent'     => (string) $assigned_assistant_ids[0],
					'conversation_type'  => $inbox_conv_type,
				)
			);
		}

		$job_args = array(
			array(
				'assistant_id'      => $assigned_assistant_ids[0],
				'message_text'      => $message_text,
				'user_id'           => $user_id,
				'channel_id'        => $channel_id,
				'team_id'           => $team_id,
				'service_url'       => $service_url,
				'conversation'      => $conversation,
				'connection_id'     => $connection_id,
				// conversation_type: 'channel', 'groupChat', or 'personal'.
				// Used by the reply job to pick the correct Graph API endpoint.
				'conversation_type' => $conversation_type,
				// activity_id / reply_to_id: forwarded so the reply is posted
				// inside the same thread (channel messages only).
				'activity_id'       => $activity_id,
				'reply_to_id'       => $reply_to_id,
			),
		);

		wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
		spawn_cron();

		// Return empty acknowledgement — Teams shows this in the channel.
		// An empty text means the outgoing webhook is silent until the cron reply arrives.
		return rest_ensure_response( $this->empty_response() );
	}

	/**
	 * Cron callback: generate an AI reply and post it to the Teams channel/chat.
	 *
	 * Implements per-user conversation history respecting the global
	 * max_history_messages setting and the wp_mcp_ai_teams_max_history_messages filter.
	 *
	 * Thread-aware replies (industry standard):
	 * When the original message was posted in a Teams channel thread, the AI
	 * reply is sent back into the same thread via the Graph API replies endpoint,
	 * keeping conversations tidy and contextual within the Teams channel.
	 *
	 * Personal/groupChat support:
	 * For 1-on-1 DMs and group chats the /chats/{id}/messages endpoint is used,
	 * matching the Teams Graph API design for non-channel conversations.
	 *
	 * Rate limiting (industry standard):
	 * When the Graph API returns HTTP 429 Too Many Requests the Retry-After
	 * header value is respected and the job is rescheduled. A retry counter
	 * (max MAX_RATE_LIMIT_RETRIES) prevents indefinite loops.
	 *
	 * Rich formatting:
	 * AI replies are converted from Markdown to Teams-compatible HTML before
	 * sending, so bold, italic, code blocks, and links render properly in Teams.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Job arguments set by handle_webhook().
	 */
	public function handle_teams_reply_job( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		$assistant_id  = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;
		$message_text  = isset( $args['message_text'] ) ? (string) $args['message_text'] : '';
		$user_id       = isset( $args['user_id'] ) ? (string) $args['user_id'] : '';
		$channel_id    = isset( $args['channel_id'] ) ? (string) $args['channel_id'] : '';
		$team_id       = isset( $args['team_id'] ) ? (string) $args['team_id'] : '';
		$service_url   = isset( $args['service_url'] ) ? esc_url_raw( (string) $args['service_url'] ) : '';
		$conversation  = isset( $args['conversation'] ) && is_array( $args['conversation'] ) ? $args['conversation'] : array();
		$connection_id = isset( $args['connection_id'] ) ? sanitize_key( $args['connection_id'] ) : '';
		// conversation_type: 'channel', 'groupChat', or 'personal'.
		$conversation_type = isset( $args['conversation_type'] ) ? sanitize_key( $args['conversation_type'] ) : 'channel';
		// activity_id / reply_to_id: used for thread-aware replies in channels.
		$activity_id = isset( $args['activity_id'] ) ? sanitize_text_field( $args['activity_id'] ) : '';
		$reply_to_id = isset( $args['reply_to_id'] ) ? sanitize_text_field( $args['reply_to_id'] ) : '';
		// retry_count incremented each time the job is rescheduled due to a 429.
		$retry_count = isset( $args['retry_count'] ) ? absint( $args['retry_count'] ) : 0;

		if ( ! $assistant_id || '' === $message_text || '' === $connection_id ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection || empty( $connection['token'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Teams AI reply: connection not found or access token missing.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		$access_token = $this->get_or_refresh_graph_token( $connection );

		if ( '' === $access_token ) {
			WP_MCP_AI_Logger::log_error(
				'Teams AI reply: access token is empty after refresh attempt.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		// --- Per-user conversation history ---
		// Thread-aware history: when the message is inside a Teams channel thread,
		// scope the history to that thread so each thread maintains independent
		// context. Personal/groupChat and top-level channel messages use the
		// channel/conversation-level key (no thread scope).
		$is_dm            = ( 'personal' === $conversation_type || 'groupChat' === $conversation_type );
		$inbox_contact_id = ( 'channel' === $conversation_type ) ? $channel_id : $user_id;
		$inbox_conv_type  = ( 'channel' === $conversation_type ) ? 'channel' : 'dm';
		$thread_id        = '' !== $reply_to_id ? $reply_to_id : $activity_id;

		$history_key = $this->get_conversation_history_key(
			$user_id,
			$channel_id,
			$connection_id,
			( ! $is_dm && '' !== $thread_id ) ? $thread_id : ''
		);
		$history     = get_transient( $history_key );
		$history     = is_array( $history ) ? $history : array();

		$max_history = 8;
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings    = WP_MCP_AI_Admin_Settings::get_settings();
			$max_history = isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : $max_history;
		}

		/**
		 * Filters the maximum number of messages kept in a Teams conversation history.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $max_history Maximum message count.
		 * @param array $args        Current job arguments.
		 */
		$max_history = (int) apply_filters( 'wp_mcp_ai_teams_max_history_messages', $max_history, $args );
		$max_history = max( 1, $max_history );

		// When the transient cache is empty (e.g. after expiry or a cache flush),
		// hydrate the conversation context from the Channel Messages CCT so that
		// prior exchanges are never silently dropped. The CCT is the persistent
		// source of truth; the transient is a fast in-memory cache on top of it.
		if ( empty( $history ) && $max_history > 1 && class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			$history = WP_MCP_AI_Channel_Messages_CCT::get_recent_messages(
				'teams',
				$user_id,
				$connection_id,
				$max_history - 1
			);
		}

		$history = WP_MCP_AI_Webhook_Context_Manager::trim_history( $history, $max_history, 'teams', 1 );

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
				'Teams AI reply: no administrator user found; internal chat request may fail.',
				array( 'assistant_id' => $assistant_id )
			);
		}

		$response = rest_do_request( $request );
		wp_set_current_user( $original_user_id );

		if ( $response->is_error() ) {
			WP_MCP_AI_Logger::log_error(
				'Teams AI reply: chat request failed.',
				array( 'assistant_id' => $assistant_id )
			);
			return;
		}

		$content = $this->extract_content_from_chat_response( $response->get_data() );

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error( 'Teams AI reply: empty content from assistant.' );
			return;
		}

		// Convert AI Markdown response to Teams-compatible HTML so bold, italic,
		// code blocks, and links render with proper formatting in Teams clients.
		$html_content = self::convert_markdown_to_teams_html( $content );

		// Determine the correct Graph API endpoint based on conversation type
		// and thread context.
		//
		// Channel messages: reply in-thread when we have a parent message ID.
		// Industry standard: posting to the thread replies endpoint keeps the
		// conversation tidy and contextual within the Teams channel.
		//
		// Personal/groupChat: use the /chats/{id}/messages endpoint, which is
		// the Graph API design for non-channel (DM and group chat) conversations.
		$graph_endpoint = '';
		$graph_payload  = array(
			'body' => array(
				'contentType' => 'html',
				'content'     => $html_content,
			),
		);

		if ( 'channel' === $conversation_type && '' !== $team_id && '' !== $channel_id ) {
			if ( '' !== $thread_id ) {
				// Reply in the existing channel thread (thread-aware, industry standard).
				$graph_endpoint = sprintf(
					'%s/teams/%s/channels/%s/messages/%s/replies',
					self::GRAPH_API_BASE,
					rawurlencode( $team_id ),
					rawurlencode( $channel_id ),
					rawurlencode( $thread_id )
				);
			} else {
				// No thread context: post as a new top-level channel message.
				$graph_endpoint = sprintf(
					'%s/teams/%s/channels/%s/messages',
					self::GRAPH_API_BASE,
					rawurlencode( $team_id ),
					rawurlencode( $channel_id )
				);
			}
		} elseif ( $is_dm && ! empty( $conversation['id'] ) ) {
			// Personal chat (DM) or group chat: use the chats messages endpoint.
			$chat_id        = sanitize_text_field( $conversation['id'] );
			$graph_endpoint = sprintf(
				'%s/chats/%s/messages',
				self::GRAPH_API_BASE,
				rawurlencode( $chat_id )
			);
		} elseif ( '' !== $team_id && '' !== $channel_id ) {
			// Fallback: post to the channel as a new top-level message.
			$graph_endpoint = sprintf(
				'%s/teams/%s/channels/%s/messages',
				self::GRAPH_API_BASE,
				rawurlencode( $team_id ),
				rawurlencode( $channel_id )
			);
		}

		if ( '' === $graph_endpoint ) {
			WP_MCP_AI_Logger::log_error(
				'Teams AI reply: could not determine Graph API endpoint.',
				array(
					'conversation_type' => $conversation_type,
					'team_id'           => ! empty( $team_id ) ? substr( $team_id, 0, 8 ) . '***' : '',
					'channel_id'        => $channel_id,
				)
			);
			return;
		}

		$body = wp_json_encode( $graph_payload );

		if ( false === $body ) {
			WP_MCP_AI_Logger::log_error( 'Teams AI reply: failed to JSON-encode payload.' );
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'teams_ai_reply_sending',
			'Sending Teams AI reply via Graph API.',
			array(
				'assistant_id'      => $assistant_id,
				'conversation_type' => $conversation_type,
				'team_id'           => ! empty( $team_id ) ? substr( $team_id, 0, 8 ) . '***' : '',
			)
		);

		$result = wp_remote_post(
			$graph_endpoint,
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
				'Teams AI reply: Graph API request failed.',
				array( 'error' => $result->get_error_message() )
			);
			return;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $result );

		// Industry standard: handle HTTP 429 Too Many Requests by respecting the
		// Retry-After header returned by the Graph API and rescheduling the job.
		// A retry counter prevents indefinite loops.
		if ( 429 === $http_code ) {
			if ( $retry_count >= self::MAX_RATE_LIMIT_RETRIES ) {
				WP_MCP_AI_Logger::log_error(
					'Teams AI reply: rate limit retry limit reached; giving up.',
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
					'Teams AI reply: rate limited (429). Retrying in %d seconds (attempt %d/%d).',
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

		if ( 201 !== $http_code ) {
			WP_MCP_AI_Logger::log_error(
				'Teams AI reply: Graph API returned non-201.',
				array( 'http_code' => $http_code )
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
		$history = WP_MCP_AI_Webhook_Context_Manager::trim_history_after_response( $history, $max_history, 'teams' );
		set_transient( $history_key, $history, self::CONVERSATION_HISTORY_TTL );

		WP_MCP_AI_Logger::log_event(
			'teams_ai_reply_sent',
			'Teams AI reply sent successfully.',
			array( 'assistant_id' => $assistant_id )
		);

		// Persist the outbound AI reply to the Channel Messages CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'teams',
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
			$ms_contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
				'teams',
				$inbox_contact_id,
				array(
					'connection_id'     => $connection_id,
					'conversation_type' => $inbox_conv_type,
				)
			);
			if ( $ms_contact_row_id ) {
				WP_MCP_AI_Channel_Contacts_CCT::touch( $ms_contact_row_id );
			}
		}
	}

	// =========================================================================
	// Rich Formatting
	// =========================================================================

	/**
	 * Convert standard Markdown to Teams-compatible HTML.
	 *
	 * AI models return responses in standard Markdown. Microsoft Teams renders
	 * a subset of HTML in channel and chat messages (via contentType "html").
	 * This method bridges the gap so that AI replies render with proper bold,
	 * italic, code, link, and list formatting in Teams clients.
	 *
	 * Code spans and fenced code blocks are preserved verbatim (wrapped in
	 * <code> / <pre><code> tags). Headings are converted to <strong> lines.
	 * HTML anchor tags that AI assistants sometimes emit are preserved. All
	 * other unrecognised HTML tags are stripped before processing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Markdown-formatted AI response text.
	 * @return string HTML-formatted text suitable for Teams chat messages.
	 */
	public static function convert_markdown_to_teams_html( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}

		// 1. Extract fenced code blocks and replace with placeholders so that
		// content inside them is not processed by subsequent regex rules.
		$code_blocks            = array();
		$code_block_placeholder = "\x07TMSCB:";
		$cb_index               = 0;

		$text = preg_replace_callback(
			'/```([a-zA-Z0-9_+-]*)\n?([\s\S]*?)```/s',
			function ( $m ) use ( &$code_blocks, &$cb_index, $code_block_placeholder ) {
				$lang                = '' !== $m[1] ? ' class="language-' . esc_attr( $m[1] ) . '"' : '';
				$key                 = $code_block_placeholder . $cb_index . "\x07";
				$code_blocks[ $key ] = '<pre><code' . $lang . '>' . esc_html( rtrim( $m[2], "\n" ) ) . '</code></pre>';
				++$cb_index;
				return $key;
			},
			$text
		);

		// 2. Extract inline code spans and replace with placeholders.
		$inline_codes            = array();
		$inline_code_placeholder = "\x07TMSIC:";
		$ic_index                = 0;

		$text = preg_replace_callback(
			'/`([^`\n]+?)`/',
			function ( $m ) use ( &$inline_codes, &$ic_index, $inline_code_placeholder ) {
				$key                  = $inline_code_placeholder . $ic_index . "\x07";
				$inline_codes[ $key ] = '<code>' . esc_html( $m[1] ) . '</code>';
				++$ic_index;
				return $key;
			},
			$text
		);

		// 3. Preserve existing HTML anchor tags — AI responses sometimes emit
		// raw <a href="…">…</a> which Teams renders correctly.
		$anchors            = array();
		$anchor_placeholder = "\x07TMSAN:";
		$an_index           = 0;

		$text = preg_replace_callback(
			'/<a\b[^>]*\bhref=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/si',
			function ( $m ) use ( &$anchors, &$an_index, $anchor_placeholder ) {
				$url  = esc_url( $m[1] );
				$body = wp_strip_all_tags( $m[2] );
				if ( '' === $url ) {
					return $body;
				}
				$key             = $anchor_placeholder . $an_index . "\x07";
				$anchors[ $key ] = '<a href="' . $url . '">' . esc_html( $body ) . '</a>';
				++$an_index;
				return $key;
			},
			$text
		);

		// 4. Strip any remaining HTML tags before applying Markdown conversions.
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 5. Headings (# … through ######) → <strong> bold line.
		// Teams renders headings inconsistently; bold is the safest equivalent.
		$text = preg_replace( '/^#{1,6}\s+(.+)$/m', '<strong>$1</strong>', $text );

		// 6. Bold: **text** or __text__ → <strong>text</strong>.
		$text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
		$text = preg_replace( '/__(.+?)__/s', '<strong>$1</strong>', $text );

		// 7. Italic: *text* → <em>text</em>.
		// Use lookbehind/lookahead to avoid matching <strong> tokens.
		$text = preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<em>$1</em>', $text );

		// 8. Underscored italic: _text_ → <em>text</em>.
		$text = preg_replace( '/(?<![a-zA-Z0-9])_(?!_)(.+?)(?<![a-zA-Z0-9])_(?!_)/s', '<em>$1</em>', $text );

		// 9. Strikethrough: ~~text~~ → <strike>text</strike>.
		$text = preg_replace( '/~~(.+?)~~/s', '<strike>$1</strike>', $text );

		// 10. Markdown links: [text](url) → <a href="url">text</a>.
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^)]+)\)/',
			function ( $m ) {
				$url = esc_url( trim( $m[2] ) );
				if ( '' === $url ) {
					return esc_html( $m[1] );
				}
				return '<a href="' . $url . '">' . esc_html( $m[1] ) . '</a>';
			},
			$text
		);

		// 11. Bullet lists: lines starting with "- " or "* " → <ul><li>.
		$text = self::convert_bullet_lists_to_html( $text );

		// 12. Numbered lists: lines starting with "1. ", "2. " etc. → <ol><li>.
		$text = self::convert_numbered_lists_to_html( $text );

		// 13. Blockquotes: lines starting with "> " → <blockquote>.
		$text = preg_replace( '/^>\s+(.+)$/m', '<blockquote>$1</blockquote>', $text );

		// 14. Convert blank lines to <br> paragraph breaks for Teams.
		$text = preg_replace( '/\n{2,}/', '<br><br>', $text );
		$text = str_replace( "\n", '<br>', $text );

		// 15. Restore inline code placeholders.
		if ( ! empty( $inline_codes ) ) {
			$text = str_replace( array_keys( $inline_codes ), array_values( $inline_codes ), $text );
		}

		// 16. Restore fenced code block placeholders.
		if ( ! empty( $code_blocks ) ) {
			$text = str_replace( array_keys( $code_blocks ), array_values( $code_blocks ), $text );
		}

		// 17. Restore anchor tag placeholders.
		if ( ! empty( $anchors ) ) {
			$text = str_replace( array_keys( $anchors ), array_values( $anchors ), $text );
		}

		return trim( $text );
	}

	/**
	 * Convert Markdown bullet list lines to HTML <ul><li> blocks.
	 *
	 * Consecutive lines starting with "- " or "* " are grouped into a single
	 * <ul> block. Non-list lines are returned unchanged.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Input text (may contain Markdown bullet lists).
	 * @return string Text with bullet lists replaced by <ul><li> HTML.
	 */
	protected static function convert_bullet_lists_to_html( $text ) {
		$lines  = explode( "\n", $text );
		$output = array();
		$in_ul  = false;

		foreach ( $lines as $line ) {
			if ( preg_match( '/^[ \t]*[-*]\s+(.+)$/', $line, $m ) ) {
				if ( ! $in_ul ) {
					$output[] = '<ul>';
					$in_ul    = true;
				}
				$output[] = '<li>' . trim( $m[1] ) . '</li>';
			} else {
				if ( $in_ul ) {
					$output[] = '</ul>';
					$in_ul    = false;
				}
				$output[] = $line;
			}
		}

		if ( $in_ul ) {
			$output[] = '</ul>';
		}

		return implode( "\n", $output );
	}

	/**
	 * Convert Markdown numbered list lines to HTML <ol><li> blocks.
	 *
	 * Consecutive lines starting with "1. ", "2. " etc. are grouped into a
	 * single <ol> block. Non-list lines are returned unchanged.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text Input text (may contain Markdown numbered lists).
	 * @return string Text with numbered lists replaced by <ol><li> HTML.
	 */
	protected static function convert_numbered_lists_to_html( $text ) {
		$lines  = explode( "\n", $text );
		$output = array();
		$in_ol  = false;

		foreach ( $lines as $line ) {
			if ( preg_match( '/^[ \t]*\d+\.\s+(.+)$/', $line, $m ) ) {
				if ( ! $in_ol ) {
					$output[] = '<ol>';
					$in_ol    = true;
				}
				$output[] = '<li>' . trim( $m[1] ) . '</li>';
			} else {
				if ( $in_ol ) {
					$output[] = '</ol>';
					$in_ol    = false;
				}
				$output[] = $line;
			}
		}

		if ( $in_ol ) {
			$output[] = '</ol>';
		}

		return implode( "\n", $output );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Return the transient key for a Teams user/channel/thread conversation history.
	 *
	 * Thread-scoped history: when $thread_id is supplied (non-empty string) the
	 * key is scoped to that specific thread so that multiple concurrent thread
	 * conversations in the same channel each maintain independent context.
	 * Pass an empty string for DMs and top-level channel messages.
	 *
	 * @since 1.0.0
	 *
	 * @param string $user_id       Teams user AAD object ID.
	 * @param string $channel_id    Teams channel ID.
	 * @param string $connection_id Remote connection ID.
	 * @param string $thread_id     Optional thread message ID for thread-scoped history.
	 * @return string Transient key (hashed, within 172-character limit).
	 */
	protected function get_conversation_history_key( $user_id, $channel_id, $connection_id, $thread_id = '' ) {
		$key_parts = $user_id . '_' . $channel_id . '_' . $connection_id;
		if ( '' !== $thread_id ) {
			$key_parts .= '_' . $thread_id;
		}
		return 'wp_mcp_ai_ms_conv_' . md5( $key_parts );
	}

	/**
	 * Check whether an activity ID has already been processed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $activity_id Teams activity ID.
	 * @return bool True if already processed.
	 */
	protected function is_duplicate_activity( $activity_id ) {
		return (bool) get_transient( 'wp_mcp_ai_ms_dedup_' . md5( $activity_id ) );
	}

	/**
	 * Validate that the payload timestamp is within MAX_REQUEST_AGE seconds.
	 *
	 * Teams includes an ISO 8601 timestamp in the `timestamp` field of every
	 * outgoing webhook payload. Rejecting payloads with stale timestamps
	 * prevents replay attacks where an attacker replays a captured valid request.
	 *
	 * When the timestamp field is absent or unparseable, the check is skipped
	 * to maintain backward compatibility with non-standard integrations.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Decoded Teams activity payload.
	 * @return bool True if the timestamp is valid or absent.
	 */
	protected function is_request_timestamp_valid( array $payload ) {
		if ( empty( $payload['timestamp'] ) || ! is_string( $payload['timestamp'] ) ) {
			// Absent timestamp: skip check for backward compatibility.
			return true;
		}

		$payload_time = strtotime( $payload['timestamp'] );

		if ( false === $payload_time || 0 === $payload_time ) {
			// Unparseable timestamp: skip check.
			return true;
		}

		return abs( time() - $payload_time ) <= self::MAX_REQUEST_AGE;
	}

	/**
	 * Retrieve the HMAC signing secret for a given Teams connection.
	 *
	 * When a `$connection_id` is supplied the signing secret is read from that
	 * specific connection. Falls back to the first active Teams connection that
	 * has a signing secret configured when no connection_id is provided
	 * (backward-compatible behaviour).
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $connection_id Optional connection ID from the request URL.
	 * @return string Base64-encoded signing secret or empty string.
	 */
	protected function get_signing_secret( $connection_id = null ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( ! is_array( $connections ) ) {
			return '';
		}

		// When a specific connection is requested, look it up directly.
		if ( $connection_id ) {
			$connection = $this->find_teams_connection_by_id( $connection_id, $connections );

			if ( ! $connection || empty( $connection['signing_secret'] ) ) {
				return '';
			}

			return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['signing_secret'] );
		}

		// Fallback: return the signing secret from the first active Teams
		// connection that has one configured (backward-compatible).
		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'teams' !== $connection['connection_type'] ) {
				continue;
			}

			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			if ( empty( $connection['signing_secret'] ) ) {
				continue;
			}

			return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['signing_secret'] );
		}

		return '';
	}

	/**
	 * Find a specific Teams connection by its ID.
	 *
	 * Only returns the connection when it is enabled; does not require
	 * assigned assistants so it can be used for signing-secret lookup before
	 * assistants are configured.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID to look up.
	 * @param array  $connections   All stored connections (must already be loaded).
	 * @return array|null Connection array or null if not found.
	 */
	protected function find_teams_connection_by_id( $connection_id, array $connections ) {
		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'teams' !== $connection['connection_type'] ) {
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
	 * Find the first active Microsoft Teams connection with assigned assistants.
	 *
	 * When a `$connection_id` was resolved from the per-connection webhook URL
	 * (stored in `$this->current_connection_id`), the matching connection is
	 * returned directly for per-tenant routing. Without a connection_id the
	 * method falls back to tenant-ID matching, then to the first active Teams
	 * connection with assigned assistants (backward-compatible).
	 *
	 * @since 1.0.0
	 *
	 * @param string $tenant_id Optional tenant ID to match.
	 * @return array|null Connection array or null if none found.
	 */
	protected function get_active_teams_connection( $tenant_id = '' ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( ! is_array( $connections ) ) {
			return null;
		}

		// When a specific connection was resolved from the per-connection URL,
		// return it directly (highest-priority routing for multi-tenant setups).
		if ( $this->current_connection_id ) {
			$conn = $this->find_teams_connection_by_id( $this->current_connection_id, $connections );
			if ( $conn && ! empty( $conn['assigned_assistant_ids'] ) ) {
				return $conn;
			}
		}

		$first_match = null;

		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'teams' !== $connection['connection_type'] ) {
				continue;
			}

			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			if ( empty( $connection['assigned_assistant_ids'] ) || ! is_array( $connection['assigned_assistant_ids'] ) ) {
				continue;
			}

			// Prefer exact tenant_id match when provided.
			if ( '' !== $tenant_id && isset( $connection['tenant_id'] ) && $connection['tenant_id'] === $tenant_id ) {
				return $connection;
			}

			if ( null === $first_match ) {
				$first_match = $connection;
			}
		}

		return $first_match;
	}

	/**
	 * Extract plain-text message from a Teams outgoing webhook activity payload.
	 *
	 * Teams outgoing webhooks send the message in the `text` field (plain or HTML).
	 * This helper strips the bot @mention tag before stripping any HTML tags.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Teams activity payload.
	 * @return string Plain-text message or empty string.
	 */
	protected function extract_message_text( array $payload ) {
		if ( isset( $payload['text'] ) && is_string( $payload['text'] ) ) {
			// Strip the bot @mention before stripping HTML tags so the
			// display-name inside <at>…</at> is removed entirely.
			$text = preg_replace( '/<at>.*?<\/at>\s*/i', '', $payload['text'] );
			$text = trim( wp_strip_all_tags( $text ) );
			return sanitize_textarea_field( trim( $text ) );
		}

		return '';
	}

	/**
	 * Extract the Teams user AAD object ID from an activity payload.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Teams activity payload.
	 * @return string User AAD object ID or empty string.
	 */
	protected function extract_user_id( array $payload ) {
		if ( isset( $payload['from']['aadObjectId'] ) ) {
			return sanitize_text_field( $payload['from']['aadObjectId'] );
		}

		if ( isset( $payload['from']['id'] ) ) {
			return sanitize_text_field( $payload['from']['id'] );
		}

		return '';
	}

	/**
	 * Extract the display name of the Teams user who sent the message.
	 *
	 * Used when creating or updating the Channel Contacts CCT entry so that
	 * contacts have a human-readable name rather than just a raw AAD object ID.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Teams activity payload.
	 * @return string Display name or empty string.
	 */
	protected function extract_display_name( array $payload ) {
		if ( isset( $payload['from']['name'] ) && is_string( $payload['from']['name'] ) ) {
			return sanitize_text_field( $payload['from']['name'] );
		}

		return '';
	}

	/**
	 * Extract the conversation type from a Teams activity payload.
	 *
	 * Teams conversations are classified as:
	 * - `channel`:   A channel post in a team (the most common outgoing webhook target).
	 * - `groupChat`: A group chat between multiple users and the bot.
	 * - `personal`:  A 1-on-1 direct message conversation with the bot.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Teams activity payload.
	 * @return string Conversation type: 'channel', 'groupChat', or 'personal'.
	 */
	protected function extract_conversation_type( array $payload ) {
		if ( isset( $payload['conversation']['conversationType'] ) ) {
			$type = sanitize_text_field( $payload['conversation']['conversationType'] );
			if ( in_array( $type, array( 'channel', 'groupChat', 'personal' ), true ) ) {
				return $type;
			}
		}

		// Infer from channelData: presence of a team ID strongly implies channel.
		if ( ! empty( $payload['channelData']['team']['id'] ) ) {
			return 'channel';
		}

		return 'personal';
	}

	/**
	 * Extract the replyToId from a Teams activity payload.
	 *
	 * When a message is posted as a reply inside an existing Teams channel
	 * thread, the payload contains a `replyToId` field identifying the root
	 * (parent) message. This is used to post the AI reply into the same thread.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Teams activity payload.
	 * @return string Parent message ID or empty string.
	 */
	protected function extract_reply_to_id( array $payload ) {
		if ( isset( $payload['replyToId'] ) && is_string( $payload['replyToId'] ) ) {
			return sanitize_text_field( $payload['replyToId'] );
		}

		return '';
	}

	/**
	 * Extract plain-text content from the internal /mcp-ai/v1/chat response.
	 *
	 * Handles both string and array content segments (multi-provider normalisation
	 * for Gemini and Ollama responses that return content as an array).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data Response data.
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

		if ( ! isset( $first['message']['content'] ) ) {
			return '';
		}

		$content = $first['message']['content'];

		// Handle array content segments (Gemini / Ollama multi-provider format).
		if ( is_array( $content ) ) {
			$text_parts = array();
			foreach ( $content as $part ) {
				if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
					$text_parts[] = $part['text'];
				} elseif ( is_string( $part ) ) {
					$text_parts[] = $part;
				}
			}
			return trim( implode( '', $text_parts ) );
		}

		if ( is_string( $content ) ) {
			return trim( $content );
		}

		return '';
	}

	/**
	 * Return an empty Teams-compatible response body.
	 *
	 * Teams expects a JSON object response; an empty text field is acceptable
	 * and keeps the bot silent until the async cron reply arrives.
	 *
	 * @since 1.0.0
	 *
	 * @return array Empty Teams response.
	 */
	protected function empty_response() {
		return array(
			'type' => 'message',
			'text' => '',
		);
	}

	/**
	 * Check whether any assigned assistant is mentioned by @slug in the message text.
	 *
	 * Used when a connection has require_mention enabled so the bot only replies
	 * when a user explicitly addresses it with @assistant-slug in a channel or
	 * group chat message.
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
	 * Return a valid Microsoft Graph access token for the given connection.
	 *
	 * When the connection was authorized via the 1-click OAuth flow, the stored
	 * access token may have expired (Microsoft tokens typically last 1 hour).
	 * If a refresh token is present and the access token is expired (or within
	 * 5 minutes of expiry), this method exchanges the refresh token for a new
	 * access token and updates the stored connection record automatically.
	 *
	 * Falls back to the raw stored token for connections that use a manually
	 * provided bearer token without a refresh token.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection The connection data array from the Remote Site Manager.
	 * @return string Decrypted access token string, or empty string on failure.
	 */
	protected function get_or_refresh_graph_token( array $connection ) {
		$token_expiry  = isset( $connection['token_expiry'] ) ? (int) $connection['token_expiry'] : 0;
		$access_token  = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['token'] );
		$refresh_token = ! empty( $connection['refresh_token'] )
			? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['refresh_token'] )
			: '';

		// If the token is still valid (with a 5-minute safety buffer), return it directly.
		if ( $access_token && $token_expiry > 0 && ( $token_expiry - 300 ) > time() ) {
			return $access_token;
		}

		// No refresh token available – return whatever we have and let the caller handle errors.
		if ( '' === $refresh_token ) {
			return $access_token;
		}

		// Token is expired or near expiry; exchange refresh token for a new access token.
		$tenant = ! empty( $connection['tenant_id'] ) ? sanitize_text_field( $connection['tenant_id'] ) : 'common';

		$client_id     = isset( $connection['client_id'] ) ? sanitize_text_field( $connection['client_id'] ) : '';
		$client_secret = ! empty( $connection['client_secret'] )
			? WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] )
			: '';

		if ( '' === $client_id || '' === $client_secret ) {
			// OAuth credentials not set; return the existing token as-is.
			return $access_token;
		}

		$response = wp_remote_post(
			'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
					'grant_type'    => 'refresh_token',
				),
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				'Teams: Failed to refresh Graph access token.',
				array(
					'connection_id' => $connection['id'],
					'error'         => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response ),
				)
			);
			// Return existing token as a fallback; it may still work if the expiry is inaccurate.
			return $access_token;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) || empty( $decoded['access_token'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Teams: Invalid token refresh response from Microsoft.',
				array( 'connection_id' => $connection['id'] )
			);
			return $access_token;
		}

		$new_access_token  = trim( (string) $decoded['access_token'] );
		$new_refresh_token = isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : $refresh_token;
		$new_expires_in    = isset( $decoded['expires_in'] ) ? absint( $decoded['expires_in'] ) : 3600;

		// Persist the refreshed token back to the connection.
		$update                              = array(
			'id'                     => $connection['id'],
			'name'                   => $connection['name'],
			'url'                    => $connection['url'],
			'connection_type'        => 'microsoft_teams',
			'auth_type'              => 'none',
			'client_id'              => $client_id,
			// Empty string + _client_secret_encrypted flag tells save_connection to
			// preserve the existing encrypted client_secret without re-encrypting it.
			'client_secret'          => '',
			'token'                  => $new_access_token,
			'refresh_token'          => $new_refresh_token,
			'token_expiry'           => time() + $new_expires_in,
			'tenant_id'              => isset( $connection['tenant_id'] ) ? $connection['tenant_id'] : '',
			'app_id'                 => isset( $connection['app_id'] ) ? $connection['app_id'] : '',
			// Empty string + _signing_secret_encrypted flag preserves the existing encrypted signing_secret.
			'signing_secret'         => '',
			'require_mention'        => ! empty( $connection['require_mention'] ),
			'enabled'                => $connection['enabled'],
			'assigned_assistant_ids' => isset( $connection['assigned_assistant_ids'] ) ? $connection['assigned_assistant_ids'] : array(),
		);
		$update['_client_secret_encrypted']  = true;
		$update['_signing_secret_encrypted'] = true;

		WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $update );

		return $new_access_token;
	}
}

new WP_MCP_AI_Teams_Webhook_Controller();
