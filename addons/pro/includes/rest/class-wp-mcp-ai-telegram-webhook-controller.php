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
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_telegram_reply_job' ) );
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
	 *    When no token is configured, the request is logged and allowed
	 *    through with a security warning.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if the request passes all configured security checks.
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
		$stored_secret = $this->get_secret_token( $connection_id );

		if ( empty( $stored_secret ) ) {
			WP_MCP_AI_Logger::log_event(
				'telegram_webhook_no_secret_token',
				'Telegram webhook received without secret token configured. Header validation skipped. Configure a secret_token in the connection settings for enhanced security.',
				array( 'connection_id' => $connection_id ? $connection_id : 'default' )
			);
			return true;
		}

		$provided_token = $request->get_header( 'x-telegram-bot-api-secret-token' );

		if ( empty( $provided_token ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram webhook rejected: missing secret token header.' );
			return false;
		}

		if ( ! hash_equals( $stored_secret, $provided_token ) ) {
			WP_MCP_AI_Logger::log_error( 'Telegram webhook rejected: invalid secret token.' );
			return false;
		}

		return true;
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
		$this->current_connection_id = $request->get_param( 'connection_id' ) ?: null;

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
		if ( empty( $message['text'] ) ) {
			// Non-text messages (photos, stickers, etc.) are not handled.
			return;
		}

		$text      = (string) $message['text'];
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

		// ── Group / supergroup gate ──
		// When in a group context, respect the connection's enable_groups setting.
		$is_group = in_array( $chat_type, array( 'group', 'supergroup' ), true );
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

		// In groups, only reply when the bot is mentioned or the message is a
		// reply to one of the bot's own messages – but only when require_mention
		// is explicitly enabled for this connection. Defaults to off so the bot
		// responds to every group message out of the box.
		$require_mention_in_group = $is_group && ! empty( $connection['require_mention'] );
		if ( $require_mention_in_group ) {
			$bot_mentioned  = $this->message_mentions_bot( $text, $connection, $message );
			$reply_to_bot   = $this->is_reply_to_bot( $message, $connection );

			if ( ! $bot_mentioned && ! $reply_to_bot ) {
				// Also check for assistant @slug mentions.
				$automation_rules      = get_option( 'wp_mcp_ai_chat_channels_automation_rules', array() );
				$assigned_assistant_ids = $this->resolve_assistant_ids( $connection, $automation_rules );
				if ( ! $this->message_mentions_assistant( $text, $assigned_assistant_ids ) ) {
					return; // Not addressed to the bot; stay silent.
				}
			}
		}

		// Strip the @bot_username mention from the text before processing so the
		// AI receives a clean prompt without the trigger prefix.
		$text = $this->strip_bot_mention( $text, $connection );

		// --- Automation keyword checks (mirrors WhatsApp maybe_auto_reply) ---
		$automation_rules = get_option( 'wp_mcp_ai_chat_channels_automation_rules', array() );
		$text_lower       = strtolower( $text );

		if ( ! empty( $automation_rules['human_takeover_keywords'] ) && '' !== $text_lower ) {
			$takeover_kws = array_map( 'trim', explode( ',', strtolower( $automation_rules['human_takeover_keywords'] ) ) );
			foreach ( $takeover_kws as $kw ) {
				if ( '' !== $kw && false !== strpos( $text_lower, $kw ) ) {
					if ( '' !== $from_id && class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
						$contact_id = $this->get_channel_contact_id( 'telegram', $from_id );
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
					if ( '' !== $from_id && class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
						$contact_id = $this->get_channel_contact_id( 'telegram', $from_id );
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
		if ( '' !== $from_id && class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			if ( WP_MCP_AI_Channel_Contacts_CCT::is_human_takeover_active( 'telegram', $from_id ) ) {
				WP_MCP_AI_Logger::log_event(
					'telegram_auto_reply_skipped_human_takeover',
					'Auto-reply skipped: human takeover is active for this contact.',
					array( 'from_id' => substr( $from_id, 0, 4 ) . '***' )
				);
				return;
			}
		}

		$assigned_assistant_ids = $this->resolve_assistant_ids( $connection, $automation_rules );

		$connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';

		if ( '' === $connection_id ) {
			return;
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

		do_action( 'wp_mcp_ai_telegram_auto_reply', $message, $automation_rules, $assigned_assistant_ids );

		if ( ! empty( $assigned_assistant_ids ) ) {
			$tg_contact_id = '' !== $from_id ? $from_id : $chat_id;

			// Find or create the contact in the Channel Contacts CCT.
			if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
				$tg_contact_name = '';
				if ( isset( $message['from']['first_name'] ) ) {
					$tg_contact_name = trim( $message['from']['first_name'] . ' ' . ( isset( $message['from']['last_name'] ) ? $message['from']['last_name'] : '' ) );
				}
				$contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
					'telegram',
					$tg_contact_id,
					array( 'display_name' => $tg_contact_name ? $tg_contact_name : $tg_contact_id )
				);
				if ( $contact_row_id ) {
					WP_MCP_AI_Channel_Contacts_CCT::touch( $contact_row_id );
				}
			}

			// Persist inbound message to Channel Messages CCT.
			if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
				$tg_message_id = isset( $message['message_id'] ) ? (string) $message['message_id'] : '';
				WP_MCP_AI_Channel_Messages_CCT::insert(
					array(
						'channel'            => 'telegram',
						'channel_contact_id' => $tg_contact_id,
						'direction'          => 'inbound',
						'message_id'         => $tg_message_id,
						'message_type'       => 'text',
						'content'            => $text,
						'status'             => 'received',
						'connection_id'      => $connection_id,
						'phone_number_id'    => $chat_id,
						'timestamp'          => isset( $message['date'] ) ? absint( $message['date'] ) : time(),
						'reply_sent'         => 0,
						'assigned_agent'     => (string) $assigned_assistant_ids[0],
					)
				);
			}

			$reply_to_message_id = isset( $message['message_id'] ) ? (string) $message['message_id'] : '';

			$job_args = array(
				array(
					'assistant_id'       => $assigned_assistant_ids[0],
					'message_text'       => $text,
					'chat_id'            => $chat_id,
					'from_id'            => '' !== $from_id ? $from_id : $chat_id,
					'connection_id'      => $connection_id,
					'chat_type'          => $chat_type,
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

		$assistant_id  = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;
		$message_text  = isset( $args['message_text'] ) ? (string) $args['message_text'] : '';
		$chat_id              = isset( $args['chat_id'] ) ? (string) $args['chat_id'] : '';
		$from_id              = isset( $args['from_id'] ) ? (string) $args['from_id'] : $chat_id;
		$connection_id        = isset( $args['connection_id'] ) ? sanitize_key( $args['connection_id'] ) : '';
		$chat_type            = isset( $args['chat_type'] ) ? (string) $args['chat_type'] : 'private';
		$reply_to_message_id  = isset( $args['reply_to_message_id'] ) ? (string) $args['reply_to_message_id'] : '';

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

		// --- Per-user conversation history (mirrors PR #3844 for WhatsApp) ---
		$history_key = $this->get_conversation_history_key( $from_id, $connection_id );
		$history     = get_transient( $history_key );
		$history     = is_array( $history ) ? $history : array();

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
				'Telegram AI reply: no administrator user found; internal chat request may fail.',
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

		$response_data = $response->get_data();
		$content       = $this->extract_content_from_chat_response( $response_data );

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error(
				'Telegram AI reply: empty content from assistant.',
				array(
					'assistant_id'  => $assistant_id,
					'has_data'      => isset( $response_data['data'] ),
					'has_choices'   => isset( $response_data['data']['choices'] ),
					'choices_count' => isset( $response_data['data']['choices'] ) ? count( $response_data['data']['choices'] ) : 0,
					'finish_reason' => isset( $response_data['data']['choices'][0]['finish_reason'] ) ? $response_data['data']['choices'][0]['finish_reason'] : '',
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
			'parse_mode' => 'HTML',
		);

		// In group/supergroup chats, reply to the original message to keep the
		// conversation threaded and make it clear which message is being answered.
		// allow_sending_without_reply prevents failures when the original message
		// is unavailable (e.g. deleted, or migrated in supergroups).
		if ( '' !== $reply_to_message_id && in_array( $chat_type, array( 'group', 'supergroup' ), true ) ) {
			$payload['reply_to_message_id']        = (int) $reply_to_message_id;
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
			'telegram_ai_reply_sent',
			'Telegram AI reply sent successfully.',
			array(
				'assistant_id' => $assistant_id,
				'chat_id'      => substr( $chat_id, 0, 4 ) . '***',
			)
		);

		// Persist the outbound AI reply to the Channel Messages CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'telegram',
					'channel_contact_id' => $from_id,
					'direction'          => 'outbound',
					'message_type'       => 'text',
					'content'            => $content,
					'status'             => 'sent',
					'connection_id'      => $connection_id,
					'phone_number_id'    => $chat_id,
					'timestamp'          => time(),
					'reply_sent'         => 1,
					'assigned_agent'     => (string) $assistant_id,
				)
			);
		}

		// Touch the contact record to update last_message_at.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			$tg_contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create( 'telegram', $from_id );
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
	 * property set at the top of handle_webhook()), the method resolves that
	 * specific connection. Falls back to the first active Telegram connection
	 * for backward compatibility with single-bot setups.
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

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( ! is_array( $connections ) ) {
			return null;
		}

		// When a specific connection is requested, look it up directly.
		if ( $target_id ) {
			foreach ( $connections as $connection ) {
				if ( ! isset( $connection['connection_type'] ) || 'telegram' !== $connection['connection_type'] ) {
					continue;
				}

				if ( empty( $connection['enabled'] ) ) {
					continue;
				}

				if ( isset( $connection['id'] ) && $connection['id'] === $target_id ) {
					return $connection;
				}
			}
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
				"SELECT _ID FROM {$table} WHERE channel = %s AND channel_contact_id = %s LIMIT 1",
				sanitize_key( $channel ),
				sanitize_text_field( $channel_contact_id )
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * Extract the plain-text reply from the internal /mcp-ai/v1/chat response.
	 *
	 * The /mcp-ai/v1/chat endpoint wraps the LLM response under a `data` key:
	 *
	 *   { assistant_id, data: { choices: [{ message: { content, role }, finish_reason }] } }
	 *
	 * When an agentic tool-calling workflow runs, OpenAI (and compatible providers)
	 * set `message.content` to null on intermediate responses where
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

		// --- Pass 1: scan every choice for a non-empty string content value.
		// The final response from a completed agentic workflow will normally be
		// found here with finish_reason = 'stop'. We prefer choices whose
		// finish_reason is 'stop' over 'tool_calls' so that a partial tool-call
		// message is not mistakenly returned as the final answer.
		$best_content = '';
		foreach ( $choices as $choice ) {
			$msg     = isset( $choice['message'] ) && is_array( $choice['message'] ) ? $choice['message'] : array();
			$content = isset( $msg['content'] ) && is_string( $msg['content'] ) ? trim( $msg['content'] ) : '';

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
			$content = isset( $msg['content'] ) && is_string( $msg['content'] ) ? trim( $msg['content'] ) : '';
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

		// Built-in command handlers.
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
		$site_name = get_bloginfo( 'name' );
		$text      = sprintf(
			"👋 Welcome to %s!\n\nI'm your AI assistant. You can ask me anything or use these commands:\n\n/help – List available commands\n/tools – Browse AI tools\n/balance – Check credits\n/app – Open the Mini App\n/settings – Open settings\n/status – Check connection status\n/cancel – Reset conversation\n\nJust type your question to get started!",
			$site_name
		);

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
				$text = "🔧 Opening tool: `$tool_slug`\n\nUse the Mini App to execute this tool with a full parameter form.\n\n/app – Open Mini App";
			} elseif ( 0 === strpos( $args, 'content_' ) ) {
				$content_type = sanitize_text_field( substr( $args, 8 ) );
				$text = "📝 Content type: `$content_type`\n\nOpen the Mini App to browse and edit your content.\n\n/app – Open Mini App";
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
		);

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
		$connection = $this->get_active_telegram_connection();
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
			$lines[] = '✅ Connection: Active';
			$automation_rules      = get_option( 'wp_mcp_ai_chat_channels_automation_rules', array() );
			$assigned_assistant_ids = $this->resolve_assistant_ids( $connection, $automation_rules );
			$lines[] = sprintf( '🤖 Assistants: %d configured', count( $assigned_assistant_ids ) );
			$lines[] = sprintf( '👥 Groups: %s', ! empty( $connection['enable_groups'] ) ? 'Enabled' : 'Disabled' );
			$lines[] = sprintf( '📢 Channels: %s', ! empty( $connection['enable_channels'] ) ? 'Enabled' : 'Disabled' );
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
		$connection = $this->get_active_telegram_connection();
		$connection_id = ( $connection && isset( $connection['id'] ) ) ? sanitize_key( $connection['id'] ) : '';

		$sender = '' !== $from_id ? $from_id : $chat_id;

		if ( '' !== $connection_id ) {
			$history_key = $this->get_conversation_history_key( $sender, $connection_id );
			delete_transient( $history_key );
		}

		$this->send_command_reply( $chat_id, '🔄 Conversation history cleared. Send a new message to start fresh!', $message );
	}

	/**
	 * Handle /tools – list available tools or run a tool by name.
	 *
	 * @since 1.1.3
	 *
	 * @param string $chat_id Chat ID.
	 * @param string $args    Optional tool slug to run.
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

		$all_tools = $registry->get_all_tools();
		$count     = is_array( $all_tools ) ? count( $all_tools ) : 0;

		$text  = "🔧 *Available Tools* ($count)\n\n";
		$text .= "Use the Mini App to browse and execute tools with full parameter forms.\n\n";

		$i = 0;
		foreach ( $all_tools as $slug => $tool ) {
			if ( $i >= 20 ) {
				$text .= "\n_… and " . ( $count - 20 ) . " more. Open the Mini App to see all._";
				break;
			}
			$name = method_exists( $tool, 'get_name' ) ? $tool->get_name() : $slug;
			$text .= "• `$slug` – $name\n";
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
			$this->send_command_reply( $chat_id, "💰 Please link your WordPress account first using /settings.", $message );
			return;
		}

		$balance = (int) get_user_meta( $user_id, '_wp_mcp_ai_tma_stars_balance', true );

		$text  = "💰 *Your Balance*\n\n";
		$text .= "⭐ Stars: $balance\n\n";
		$text .= "_Use the Mini App Shop tab to purchase more credits._";

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

		$currency       = isset( $payment['currency'] ) ? sanitize_text_field( $payment['currency'] ) : '';
		$total_amount   = isset( $payment['total_amount'] ) ? absint( $payment['total_amount'] ) : 0;
		$invoice_payload = isset( $payment['invoice_payload'] ) ? sanitize_text_field( $payment['invoice_payload'] ) : '';
		$charge_id      = isset( $payment['telegram_payment_charge_id'] ) ? sanitize_text_field( $payment['telegram_payment_charge_id'] ) : '';
		$provider_id    = isset( $payment['provider_payment_charge_id'] ) ? sanitize_text_field( $payment['provider_payment_charge_id'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'telegram_payment_received',
			'Telegram Stars payment received.',
			array(
				'telegram_id'    => $tg_id,
				'currency'       => $currency,
				'amount'         => $total_amount,
				'payload'        => $invoice_payload,
				'charge_id'      => $charge_id,
				'provider_id'    => $provider_id,
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
				'date'       => gmdate( 'Y-m-d H:i:s' ),
				'currency'   => $currency,
				'amount'     => $total_amount,
				'payload'    => $invoice_payload,
				'charge_id'  => $charge_id,
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
			$text = "✅ *Payment Received!*\n\n";
			$text .= "⭐ Amount: $total_amount $currency\n";
			if ( $user_id ) {
				$new_balance = (int) get_user_meta( $user_id, '_wp_mcp_ai_tma_stars_balance', true );
				$text .= "💰 New Balance: $new_balance Stars\n";
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
		);

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

				$offset        = isset( $entity['offset'] ) ? (int) $entity['offset'] : 0;
				$length        = isset( $entity['length'] ) ? (int) $entity['length'] : 0;
				$mention_text  = mb_substr( $text, $offset, $length );
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
		$chat_id   = isset( $post['chat']['id'] ) ? (string) $post['chat']['id'] : '';
		$chat_title = isset( $post['chat']['title'] ) ? sanitize_text_field( $post['chat']['title'] ) : '';

		if ( '' === $chat_id ) {
			return;
		}

		$connection = $this->get_active_telegram_connection();

		if ( ! $connection ) {
			return;
		}

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

		// Persist to Channel Messages CCT.
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
					'status'             => 'received',
					'connection_id'      => $connection_id,
					'phone_number_id'    => $chat_id,
					'timestamp'          => isset( $post['date'] ) ? absint( $post['date'] ) : time(),
					'reply_sent'         => 0,
				)
			);
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
		$chat      = isset( $update['chat'] ) ? $update['chat'] : array();
		$chat_id   = isset( $chat['id'] ) ? (string) $chat['id'] : '';
		$chat_type = isset( $chat['type'] ) ? (string) $chat['type'] : '';
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
		$left_statuses  = array( 'left', 'kicked' );
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
		//    content inside them is not processed by other regex rules.
		$code_blocks  = array();
		$placeholder  = "\x07TGCB:";  // BEL-based placeholder safe from Markdown pattern matching.
		$block_index  = 0;

		$text = preg_replace_callback(
			'/```([a-zA-Z0-9_+-]*)\n([\s\S]*?)```/',
			function ( $m ) use ( &$code_blocks, &$block_index, $placeholder ) {
				$lang    = trim( $m[1] );
				$code    = $m[2];
				// Remove one trailing newline if present (aesthetic).
				$code    = rtrim( $code, "\n" );
				// Escape HTML entities inside the code block.
				$code    = htmlspecialchars( $code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
				$tag     = '' !== $lang
					? '<pre><code class="language-' . htmlspecialchars( $lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '">' . $code . '</code></pre>'
					: '<pre>' . $code . '</pre>';
				$key     = $placeholder . $block_index . "\x07";
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
				$code = htmlspecialchars( $m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
				$key  = $ic_ph . $ic_index . "\x07";
				$inline_codes[ $key ] = '<code>' . $code . '</code>';
				++$ic_index;
				return $key;
			},
			$text
		);

		// 3. Escape HTML special characters in the remaining text so that raw
		//    `<`, `>`, and `&` do not break Telegram's HTML parser.
		$text = htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		// 4. Headings (# … through ######) → bold text on its own line.
		$text = preg_replace( '/^#{1,6}\s+(.+)$/m', '<b>$1</b>', $text );

		// 5. Bold: **text** or __text__ → <b>text</b>.
		$text = preg_replace( '/\*\*(.+?)\*\*/', '<b>$1</b>', $text );
		$text = preg_replace( '/__(.+?)__/', '<b>$1</b>', $text );

		// 6. Italic: *text* or _text_ → <i>text</i>.
		//    Use a negative lookbehind / lookahead to avoid matching mid-word underscores.
		$text = preg_replace( '/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/', '<i>$1</i>', $text );
		$text = preg_replace( '/(?<![a-zA-Z0-9])_(?!_)(.+?)(?<!_)_(?![a-zA-Z0-9])/', '<i>$1</i>', $text );

		// 7. Strikethrough: ~~text~~ → <s>text</s>.
		$text = preg_replace( '/~~(.+?)~~/', '<s>$1</s>', $text );

		// 8. Links: [text](url) → <a href="url">text</a>.
		//    The URL was HTML-escaped in step 3; restore `&amp;` → `&` inside href
		//    and apply esc_url for security.
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
		//    Collapse consecutive blockquote lines into a single element.
		$text = preg_replace_callback(
			'/(?:^&gt;\s?(.*)$\n?)+/m',
			function ( $m ) {
				// Remove the leading "&gt; " (escaped "> ") from each line.
				$inner = preg_replace( '/^&gt;\s?/m', '', $m[0] );
				return '<blockquote>' . trim( $inner ) . '</blockquote>';
			},
			$text
		);

		// 10. Restore inline code placeholders.
		if ( ! empty( $inline_codes ) ) {
			$text = str_replace( array_keys( $inline_codes ), array_values( $inline_codes ), $text );
		}

		// 11. Restore fenced code block placeholders.
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
}

new WP_MCP_AI_Telegram_Webhook_Controller();
