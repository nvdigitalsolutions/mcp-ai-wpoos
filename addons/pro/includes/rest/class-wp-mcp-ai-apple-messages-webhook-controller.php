<?php
/**
 * Apple Messages for Business (iMessage) Webhook Controller
 *
 * Handles incoming Apple Messages for Business webhook events delivered by an
 * approved Messaging Service Provider (MSP). Implements industry best practices:
 * - HMAC-SHA256 signature validation (MSP-supplied secret)
 * - Message deduplication via transient cache
 * - Per-conversation history for AI auto-reply context
 * - Asynchronous AI reply dispatch via WordPress cron
 * - Interactive event handling (list picker, time picker, authenticate responses)
 * - Privacy-first: user-initiated opt-out is honoured immediately
 *
 * Industry references:
 * - https://developers.apple.com/documentation/businesschatapi/messages_received
 * - https://register.apple.com/resources/messages/msp-required-capabilities.pdf
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
 * Apple Messages for Business webhook REST controller.
 */
class WP_MCP_AI_Apple_Messages_Webhook_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks/apple-messages';

	/**
	 * Cron hook for dispatching AI replies to incoming Apple Messages.
	 */
	const REPLY_CRON_HOOK = 'wp_mcp_ai_apple_messages_send_ai_reply';

	/**
	 * TTL in seconds for the deduplication transient used to prevent double-processing.
	 */
	const DEDUP_TRANSIENT_TTL = 60;

	/**
	 * TTL in seconds for per-conversation history transients (24 hours).
	 */
	const CONVERSATION_HISTORY_TTL = 86400;

	/**
	 * Apple Messages for Business message text length limit.
	 */
	const MAX_MESSAGE_LENGTH = 2000;

	/**
	 * Supported inbound event types.
	 */
	const SUPPORTED_EVENT_TYPES = array(
		'message',           // Customer sent a text message.
		'interactive',       // Customer responded to an interactive widget (list picker, time picker, authenticate).
		'typing',            // Customer is typing (presence event).
		'read',              // Customer read a message (delivery receipt).
		'close',             // Conversation was closed by the customer.
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_apple_reply_job' ) );
	}

	/**
	 * Register REST routes for Apple Messages for Business webhooks.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Primary webhook endpoint (POST) — receives events from the MSP.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_webhook_signature' ),
			)
		);

		// Channel-specific webhook endpoint so multiple Apple Business IDs can
		// each have a dedicated URL: /mcp-ai/v1/webhooks/apple-messages/{connection_id}.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<connection_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_webhook_signature' ),
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
	 * Validate the incoming webhook signature using HMAC-SHA256.
	 *
	 * MSPs typically sign every payload with an HMAC-SHA256 digest using a
	 * shared secret and send the signature in a header such as
	 * X-Apple-Messages-Signature or X-MSP-Signature. The exact header name
	 * varies by provider; this controller checks the most common variants and
	 * falls back gracefully when no secret is configured.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if the signature is valid or no secret is configured.
	 */
	public function validate_webhook_signature( WP_REST_Request $request ) {
		$connection_id = $request->get_param( 'connection_id' );
		$stored_secret = $this->get_webhook_secret( $connection_id );

		if ( empty( $stored_secret ) ) {
			WP_MCP_AI_Logger::log_error(
				'Apple Messages webhook rejected: no signing secret configured. Set a webhook_secret in the connection settings to enable webhook authentication.',
				array( 'connection_id' => $connection_id ? $connection_id : 'default' )
			);

			return new WP_Error(
				'rest_forbidden',
				__( 'Webhook authentication is not configured. Please set a signing secret in the connection settings.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		// Retrieve the raw request body for signature calculation.
		$raw_body = $request->get_body();

		// Try common MSP signature header names.
		$provided_signature = '';
		$header_candidates  = array(
			'x-apple-messages-signature',
			'x-msp-signature',
			'x-hub-signature-256',
			'x-signature',
		);

		foreach ( $header_candidates as $header ) {
			$value = $request->get_header( $header );
			if ( ! empty( $value ) ) {
				$provided_signature = $value;
				break;
			}
		}

		if ( '' === $provided_signature ) {
			WP_MCP_AI_Logger::log_error( 'Apple Messages webhook rejected: no signature header present.' );
			return false;
		}

		// Strip optional "sha256=" prefix used by some MSPs.
		$provided_signature = preg_replace( '/^sha256=/', '', $provided_signature );

		$expected_signature = hash_hmac( 'sha256', $raw_body, $stored_secret );

		if ( ! hash_equals( $expected_signature, strtolower( $provided_signature ) ) ) {
			WP_MCP_AI_Logger::log_error( 'Apple Messages webhook rejected: invalid signature.' );
			return false;
		}

		return true;
	}

	/**
	 * Handle incoming Apple Messages for Business webhook event.
	 *
	 * Always returns 200 to prevent MSP retry storms. All heavy lifting is
	 * dispatched asynchronously via WordPress cron.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function handle_webhook( WP_REST_Request $request ) {
		$payload = $request->get_json_params();

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			WP_MCP_AI_Logger::log_error( 'Apple Messages webhook received with empty or invalid payload.' );
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$event_type = isset( $payload['type'] ) ? sanitize_text_field( $payload['type'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'apple_messages_webhook_received',
			'Apple Messages for Business webhook event received.',
			array( 'event_type' => $event_type )
		);

		// Only process supported event types; silently acknowledge all others.
		if ( ! in_array( $event_type, self::SUPPORTED_EVENT_TYPES, true ) ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		// Extract a unique event ID for deduplication.
		$event_id = isset( $payload['id'] ) ? sanitize_text_field( $payload['id'] ) : '';

		if ( $event_id && $this->is_duplicate_event( $event_id ) ) {
			WP_MCP_AI_Logger::log_event(
				'apple_messages_webhook_duplicate',
				'Apple Messages event already processed; skipping.',
				array( 'event_id' => $event_id )
			);
			return rest_ensure_response( array( 'ok' => true ) );
		}

		if ( $event_id ) {
			set_transient( 'wp_mcp_ai_apple_dedup_' . $event_id, 1, self::DEDUP_TRANSIENT_TTL );
		}

		// Route to the appropriate handler.
		switch ( $event_type ) {
			case 'message':
				$this->process_message_event( $payload, $request );
				break;

			case 'interactive':
				$this->process_interactive_event( $payload );
				break;

			case 'close':
				$this->process_close_event( $payload );
				break;

			// Presence events (typing, read) are acknowledged but not processed further.
			default:
				break;
		}

		// Always return 200 to prevent MSP retries.
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Process an inbound text message event.
	 *
	 * Persists the message via CCT helpers, checks automation rules, and
	 * dispatches an AI reply via cron when appropriate.
	 *
	 * @since 1.0.0
	 *
	 * @param array           $payload Full webhook payload.
	 * @param WP_REST_Request $request Request object (used to retrieve connection_id).
	 */
	protected function process_message_event( array $payload, WP_REST_Request $request ) {
		$conversation_id = isset( $payload['conversationId'] ) ? sanitize_text_field( $payload['conversationId'] ) : '';
		$sender_id       = isset( $payload['senderId'] ) ? sanitize_text_field( $payload['senderId'] ) : '';
		$message_text    = '';

		if ( isset( $payload['body']['text'] ) && is_string( $payload['body']['text'] ) ) {
			$message_text = sanitize_textarea_field( $payload['body']['text'] );
		}

		if ( '' === $conversation_id || '' === $sender_id ) {
			WP_MCP_AI_Logger::log_error(
				'Apple Messages webhook message event missing conversationId or senderId.',
				array( 'payload' => $payload )
			);
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'apple_messages_inbound_message',
			'Apple Messages for Business inbound message received.',
			array(
				'conversation_id' => $this->mask_sensitive_value( $conversation_id ),
				'has_text'        => '' !== $message_text,
			)
		);

		// Persist the inbound message via CCT helper when available.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::store_message(
				array(
					'channel'         => 'apple_messages',
					'conversation_id' => $conversation_id,
					'sender_id'       => $sender_id,
					'direction'       => 'inbound',
					'message'         => $message_text,
					'raw_payload'     => $payload,
				)
			);
		}

		// Persist the contact when available.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) && '' !== $sender_id ) {
			WP_MCP_AI_Channel_Contacts_CCT::upsert_contact(
				array(
					'channel'    => 'apple_messages',
					'contact_id' => $sender_id,
					'meta'       => array(
						'intent' => isset( $payload['intent'] ) ? sanitize_text_field( $payload['intent'] ) : '',
						'locale' => isset( $payload['locale'] ) ? sanitize_text_field( $payload['locale'] ) : '',
					),
				)
			);
		}

		// Respect user opt-out: close events are handled elsewhere; here we just
		// skip AI reply dispatch when the conversation is already marked as closed.
		$is_opted_out = (bool) get_transient( 'wp_mcp_ai_apple_optout_' . md5( $conversation_id ) );
		if ( $is_opted_out ) {
			WP_MCP_AI_Logger::log_event(
				'apple_messages_optout_skip',
				'Skipping AI reply for opted-out Apple Messages conversation.',
				array( 'conversation_id' => $this->mask_sensitive_value( $conversation_id ) )
			);
			return;
		}

		// Check if AI auto-reply is enabled for this connection.
		$connection_id = $request->get_param( 'connection_id' );
		if ( ! $this->is_auto_reply_enabled( $connection_id ) ) {
			return;
		}

		/**
		 * Allow developers to prevent AI auto-reply for a specific message.
		 *
		 * @param bool   $should_reply    Whether to dispatch an AI reply.
		 * @param array  $payload         Full webhook payload.
		 * @param string $connection_id   The connection/channel identifier.
		 */
		$should_reply = apply_filters( 'wp_mcp_ai_apple_messages_should_auto_reply', true, $payload, $connection_id );

		if ( ! $should_reply ) {
			return;
		}

		// Dispatch AI reply asynchronously via cron to avoid webhook timeout.
		if ( ! wp_next_scheduled( self::REPLY_CRON_HOOK, array( $conversation_id, $message_text, $connection_id ) ) ) {
			wp_schedule_single_event(
				time(),
				self::REPLY_CRON_HOOK,
				array( $conversation_id, $message_text, $connection_id )
			);
		}
	}

	/**
	 * Process an interactive response event (list picker, time picker, authentication).
	 *
	 * Interactive responses are fired when the customer selects an option from a
	 * widget sent by the business. Fires an action so other plugin components or
	 * site code can react.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Full webhook payload.
	 */
	protected function process_interactive_event( array $payload ) {
		$conversation_id  = isset( $payload['conversationId'] ) ? sanitize_text_field( $payload['conversationId'] ) : '';
		$interactive_type = isset( $payload['interactiveType'] ) ? sanitize_text_field( $payload['interactiveType'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'apple_messages_interactive_response',
			'Apple Messages for Business interactive response received.',
			array(
				'conversation_id'  => $this->mask_sensitive_value( $conversation_id ),
				'interactive_type' => $interactive_type,
			)
		);

		/**
		 * Fires when an interactive response is received from a customer.
		 *
		 * @param array  $payload          Full webhook payload.
		 * @param string $interactive_type The interactive type (list_picker, time_picker, authenticate).
		 * @param string $conversation_id  The conversation identifier.
		 */
		do_action( 'wp_mcp_ai_apple_messages_interactive_response', $payload, $interactive_type, $conversation_id );
	}

	/**
	 * Process a conversation close event.
	 *
	 * When a customer closes a conversation Apple/MSP sends a close event.
	 * Per Apple's privacy guidelines, businesses must stop sending messages
	 * when a conversation is closed unless re-initiated by the customer.
	 *
	 * @since 1.0.0
	 *
	 * @param array $payload Full webhook payload.
	 */
	protected function process_close_event( array $payload ) {
		$conversation_id = isset( $payload['conversationId'] ) ? sanitize_text_field( $payload['conversationId'] ) : '';

		if ( '' === $conversation_id ) {
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'apple_messages_conversation_closed',
			'Apple Messages for Business conversation closed by customer.',
			array( 'conversation_id' => $this->mask_sensitive_value( $conversation_id ) )
		);

		// Mark conversation as opted-out for 30 days (or until customer re-opens).
		set_transient( 'wp_mcp_ai_apple_optout_' . md5( $conversation_id ), 1, 30 * DAY_IN_SECONDS );

		/**
		 * Fires when a customer closes an Apple Messages conversation.
		 *
		 * @param string $conversation_id The conversation identifier.
		 * @param array  $payload         Full webhook payload.
		 */
		do_action( 'wp_mcp_ai_apple_messages_conversation_closed', $conversation_id, $payload );
	}

	/**
	 * Cron job handler: build AI response and send it back via the MSP.
	 *
	 * This runs asynchronously so that the original webhook response is
	 * returned quickly (within MSP timeout limits).
	 *
	 * @since 1.0.0
	 *
	 * @param string $conversation_id Conversation ID to reply to.
	 * @param string $message_text    The customer's message text.
	 * @param string $connection_id   The connection/channel identifier.
	 */
	public function handle_apple_reply_job( $conversation_id, $message_text, $connection_id ) {
		$conversation_id = sanitize_text_field( $conversation_id );
		$message_text    = sanitize_textarea_field( $message_text );
		$connection_id   = sanitize_text_field( $connection_id );

		if ( '' === $conversation_id ) {
			return;
		}

		$connection = $this->get_connection_settings( $connection_id );
		if ( empty( $connection['msp_api_url'] ) || empty( $connection['api_key'] ) || empty( $connection['business_id'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Apple Messages reply job: incomplete connection settings.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		// Build conversation history for context.
		$history_transient = 'wp_mcp_ai_apple_history_' . md5( $conversation_id );
		$history           = get_transient( $history_transient );

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$history[] = array(
			'role'    => 'user',
			'content' => $message_text,
		);

		// Retrieve configured assistant ID for this connection.
		$assistant_id = ! empty( $connection['assistant_id'] ) ? absint( $connection['assistant_id'] ) : 0;
		if ( ! $assistant_id ) {
			$settings     = get_option( 'wp_mcp_ai_settings', array() );
			$assistant_id = ! empty( $settings['default_assistant_id'] ) ? absint( $settings['default_assistant_id'] ) : 0;
		}

		if ( ! $assistant_id ) {
			WP_MCP_AI_Logger::log_error(
				'Apple Messages reply job: no assistant configured.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		/**
		 * Filter the AI reply for an incoming Apple Messages message.
		 *
		 * Returning a non-null value bypasses the default assistant dispatch and
		 * uses the returned string as the reply text directly.
		 *
		 * @param null   $reply           Set to a string to bypass default assistant.
		 * @param string $message_text    The customer's inbound message.
		 * @param string $conversation_id The conversation identifier.
		 * @param int    $assistant_id    WordPress post ID of the assistant.
		 */
		$reply = apply_filters( 'wp_mcp_ai_apple_messages_ai_reply', null, $message_text, $conversation_id, $assistant_id );

		if ( null === $reply ) {
			// Dispatch via the standard assistant execution hook.
			$reply = apply_filters(
				'wp_mcp_ai_execute_assistant',
				'',
				$assistant_id,
				array(
					'messages' => $history,
					'channel'  => 'apple_messages',
				)
			);
		}

		if ( ! is_string( $reply ) || '' === trim( $reply ) ) {
			WP_MCP_AI_Logger::log_error(
				'Apple Messages reply job: assistant returned empty response.',
				array( 'conversation_id' => $this->mask_sensitive_value( $conversation_id ) )
			);
			return;
		}

		// Enforce max message length.
		if ( mb_strlen( $reply ) > self::MAX_MESSAGE_LENGTH ) {
			$reply = mb_substr( $reply, 0, self::MAX_MESSAGE_LENGTH );
		}

		// Update conversation history.
		$history[] = array(
			'role'    => 'assistant',
			'content' => $reply,
		);

		$settings              = get_option( 'wp_mcp_ai_settings', array() );
		$max_history_messages  = isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : 20;

		if ( count( $history ) > $max_history_messages * 2 ) {
			$history = array_slice( $history, -( $max_history_messages * 2 ) );
		}

		set_transient( $history_transient, $history, self::CONVERSATION_HISTORY_TTL );

		// Send the AI reply back through the MSP.
		$this->send_reply(
			$connection['msp_api_url'],
			$connection['api_key'],
			$connection['business_id'],
			$conversation_id,
			$reply
		);
	}

	/**
	 * Send a reply message via the MSP REST API.
	 *
	 * @since 1.0.0
	 *
	 * @param string $msp_api_url     MSP API endpoint URL.
	 * @param string $api_key         MSP API key / bearer token.
	 * @param string $business_id     Apple Business ID.
	 * @param string $conversation_id Target conversation ID.
	 * @param string $reply_text      Text of the AI reply.
	 */
	protected function send_reply( $msp_api_url, $api_key, $business_id, $conversation_id, $reply_text ) {
		$payload = array(
			'businessId'     => $business_id,
			'conversationId' => $conversation_id,
			'type'           => 'text',
			'body'           => array(
				'text' => $reply_text,
			),
		);

		$body_json = wp_json_encode( $payload );
		if ( false === $body_json ) {
			WP_MCP_AI_Logger::log_error( 'Apple Messages reply job: failed to encode reply payload.' );
			return;
		}

		$response = wp_remote_post(
			$msp_api_url,
			array(
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
				'timeout' => 20,
				'body'    => $body_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_error(
				'Apple Messages reply job: failed to deliver reply via MSP.',
				array( 'error' => $response->get_error_message() )
			);
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			WP_MCP_AI_Logger::log_error(
				'Apple Messages reply job: MSP returned non-2xx status.',
				array( 'http_code' => $code )
			);
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'apple_messages_reply_sent',
			'Apple Messages for Business AI reply delivered successfully.',
			array(
				'conversation_id' => $this->mask_sensitive_value( $conversation_id ),
				'http_code'       => $code,
			)
		);
	}

	/**
	 * Check whether AI auto-reply is enabled for a connection.
	 *
	 * @param string $connection_id Connection identifier.
	 * @return bool
	 */
	protected function is_auto_reply_enabled( $connection_id ) {
		$connection = $this->get_connection_settings( $connection_id );

		return ! empty( $connection['auto_reply'] );
	}

	/**
	 * Retrieve connection settings for a given connection ID.
	 *
	 * Connection settings are stored under the `apple_messages_connections` key
	 * inside the `wp_mcp_ai_settings` WordPress option. Each connection entry is
	 * keyed by its connection ID and may contain:
	 * - msp_api_url
	 * - api_key
	 * - business_id
	 * - webhook_secret
	 * - auto_reply (bool)
	 * - assistant_id (int)
	 *
	 * @param string|null $connection_id Connection identifier (null for default).
	 * @return array Connection settings array (may be empty).
	 */
	protected function get_connection_settings( $connection_id ) {
		$settings    = get_option( 'wp_mcp_ai_settings', array() );
		$connections = isset( $settings['apple_messages_connections'] ) && is_array( $settings['apple_messages_connections'] )
			? $settings['apple_messages_connections']
			: array();

		if ( $connection_id && isset( $connections[ $connection_id ] ) && is_array( $connections[ $connection_id ] ) ) {
			return $connections[ $connection_id ];
		}

		// Fall back to a top-level default connection.
		if ( isset( $connections['default'] ) && is_array( $connections['default'] ) ) {
			return $connections['default'];
		}

		return array();
	}

	/**
	 * Retrieve the webhook signing secret for the given connection.
	 *
	 * @param string|null $connection_id Connection identifier.
	 * @return string Signing secret or empty string.
	 */
	protected function get_webhook_secret( $connection_id ) {
		$connection = $this->get_connection_settings( $connection_id );

		return isset( $connection['webhook_secret'] ) && is_string( $connection['webhook_secret'] )
			? $connection['webhook_secret']
			: '';
	}

	/**
	 * Check whether an event ID has already been processed.
	 *
	 * @param string $event_id Unique event identifier.
	 * @return bool
	 */
	protected function is_duplicate_event( $event_id ) {
		return (bool) get_transient( 'wp_mcp_ai_apple_dedup_' . $event_id );
	}

	/**
	 * Mask a sensitive value so it can be safely logged.
	 *
	 * @param string $value Sensitive value.
	 * @return string
	 */
	protected function mask_sensitive_value( $value ) {
		$value  = (string) $value;
		$length = strlen( $value );

		if ( 0 === $length ) {
			return '';
		}

		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		return substr( $value, 0, 2 ) . str_repeat( '*', $length - 4 ) . substr( $value, -2 );
	}
}
