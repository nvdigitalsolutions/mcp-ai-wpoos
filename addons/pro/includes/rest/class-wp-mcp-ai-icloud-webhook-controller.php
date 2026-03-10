<?php
/**
 * iCloud Drive Webhook Controller
 *
 * Handles incoming webhook notifications from an iCloud gateway service for
 * iCloud Drive file events. Since Apple does not provide a direct third-party
 * REST API for iCloud, this controller communicates with a user-configured
 * gateway service that bridges to Apple CloudKit or iCloud services.
 *
 * Implements enterprise-grade security with HMAC-SHA256 signature validation,
 * per-user conversation history, and AI auto-reply via WordPress cron.
 *
 * @see https://developer.apple.com/documentation/cloudkit
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
 * iCloud Drive webhook REST controller.
 */
class WP_MCP_AI_iCloud_Webhook_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks/icloud';

	/**
	 * Cron hook for dispatching AI replies to incoming iCloud Drive events.
	 */
	const REPLY_CRON_HOOK = 'wp_mcp_ai_icloud_send_ai_reply';

	/**
	 * TTL in seconds for the deduplication transient used to prevent double-processing.
	 */
	const DEDUP_TRANSIENT_TTL = 60;

	/**
	 * TTL in seconds for per-conversation history transients (24 hours).
	 */
	const CONVERSATION_HISTORY_TTL = 86400;

	/**
	 * Supported inbound event types from the iCloud gateway.
	 */
	const SUPPORTED_EVENT_TYPES = array(
		'file_created',  // New file uploaded to iCloud Drive.
		'file_modified', // Existing file was modified.
		'file_deleted',  // File was removed.
		'file_shared',   // File sharing event.
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_icloud_reply_job' ) );
	}

	/**
	 * Register REST routes for iCloud Drive webhooks.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Primary webhook endpoint (POST) — receives events from the gateway.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_webhook_signature' ),
			)
		);

		// Connection-specific webhook endpoint so multiple iCloud accounts can
		// each have a dedicated URL: /mcp-ai/v1/webhooks/icloud/{connection_id}.
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
	 * Gateway services typically sign every payload with an HMAC-SHA256 digest
	 * using a shared secret and send the signature in a header. The exact header
	 * name varies by provider; this controller checks the most common variants
	 * and falls back gracefully when no secret is configured.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if the signature is valid or no secret is configured.
	 */
	public function validate_webhook_signature( WP_REST_Request $request ) {
		$connection_id = $request->get_param( 'connection_id' );
		$stored_secret = $this->get_signing_secret( $connection_id );

		if ( empty( $stored_secret ) ) {
			WP_MCP_AI_Logger::log_error(
				'iCloud webhook rejected: no signing secret configured. Set a signing_secret in the connection settings to enable webhook authentication.',
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

		// Try common gateway signature header names.
		$provided_signature = '';
		$header_candidates  = array(
			'x-icloud-signature',
			'x-gateway-signature',
			'x-webhook-signature',
		);

		foreach ( $header_candidates as $header ) {
			$value = $request->get_header( $header );
			if ( ! empty( $value ) ) {
				$provided_signature = $value;
				break;
			}
		}

		if ( '' === $provided_signature ) {
			WP_MCP_AI_Logger::log_error( 'iCloud webhook rejected: no signature header present.' );
			return false;
		}

		// Strip optional "sha256=" prefix used by some gateways.
		$provided_signature = preg_replace( '/^sha256=/', '', $provided_signature );

		$expected_signature = hash_hmac( 'sha256', $raw_body, $stored_secret );

		if ( ! hash_equals( $expected_signature, strtolower( $provided_signature ) ) ) {
			WP_MCP_AI_Logger::log_error( 'iCloud webhook rejected: invalid signature.' );
			return false;
		}

		return true;
	}

	/**
	 * Handle incoming iCloud Drive webhook event.
	 *
	 * Always returns 200 to prevent gateway retry storms. All heavy lifting is
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
			WP_MCP_AI_Logger::log_error( 'iCloud webhook received with empty or invalid payload.' );
			return rest_ensure_response( array( 'ok' => true ) );
		}

		$event_type = isset( $payload['event_type'] ) ? sanitize_text_field( $payload['event_type'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'icloud_webhook_received',
			'iCloud Drive webhook event received.',
			array( 'event_type' => $event_type )
		);

		// Only process supported event types; silently acknowledge all others.
		if ( ! in_array( $event_type, self::SUPPORTED_EVENT_TYPES, true ) ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		// Extract a unique event ID for deduplication.
		$event_id = isset( $payload['file_id'] ) ? sanitize_text_field( $payload['file_id'] ) : '';
		$timestamp = isset( $payload['timestamp'] ) ? sanitize_text_field( $payload['timestamp'] ) : '';
		$dedup_key = $event_id && $timestamp ? $event_id . '_' . $timestamp : $event_id;

		if ( $dedup_key && $this->is_duplicate_event( $dedup_key ) ) {
			WP_MCP_AI_Logger::log_event(
				'icloud_webhook_duplicate',
				'iCloud event already processed; skipping.',
				array( 'file_id' => $event_id )
			);
			return rest_ensure_response( array( 'ok' => true ) );
		}

		if ( $dedup_key ) {
			set_transient( 'wp_mcp_ai_ic_dedup_' . $dedup_key, 1, self::DEDUP_TRANSIENT_TTL );
		}

		$connection_id = $request->get_param( 'connection_id' );
		$this->process_file_event( $payload, $connection_id );

		// Always return 200 to prevent gateway retries.
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Process an iCloud Drive file event.
	 *
	 * Persists the event via CCT helpers, fires actions, and dispatches an
	 * AI-powered response via cron when appropriate.
	 *
	 * @since 1.0.0
	 *
	 * @param array       $payload       Full webhook payload.
	 * @param string|null $connection_id Connection identifier.
	 */
	protected function process_file_event( $payload, $connection_id ) {
		$event_type = isset( $payload['event_type'] ) ? sanitize_text_field( $payload['event_type'] ) : '';
		$file_id    = isset( $payload['file_id'] ) ? sanitize_text_field( $payload['file_id'] ) : '';
		$file_name  = isset( $payload['file_name'] ) ? sanitize_text_field( $payload['file_name'] ) : '';
		$file_path  = isset( $payload['file_path'] ) ? sanitize_text_field( $payload['file_path'] ) : '';
		$user_id    = isset( $payload['user_id'] ) ? sanitize_text_field( $payload['user_id'] ) : '';

		if ( '' === $file_id ) {
			WP_MCP_AI_Logger::log_error(
				'iCloud webhook file event missing file_id.',
				array( 'payload' => $payload )
			);
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'icloud_file_event',
			'iCloud Drive file event processed.',
			array(
				'event_type' => $event_type,
				'file_name'  => $file_name,
				'file_id'    => $this->mask_sensitive_value( $file_id ),
				'user_id'    => $this->mask_sensitive_value( $user_id ),
			)
		);

		// Persist the inbound event via CCT helper when available.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::store_message(
				array(
					'channel'         => 'icloud',
					'conversation_id' => $file_id,
					'sender_id'       => $user_id,
					'direction'       => 'inbound',
					'message'         => sprintf(
						/* translators: 1: event type, 2: file name, 3: file path */
						__( '%1$s: %2$s at %3$s', 'mcp-ai-wpoos-pro' ),
						$event_type,
						$file_name,
						$file_path
					),
					'raw_payload'     => $payload,
				)
			);
		}

		// Persist the contact when available.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) && '' !== $user_id ) {
			WP_MCP_AI_Channel_Contacts_CCT::upsert_contact(
				array(
					'channel'    => 'icloud',
					'contact_id' => $user_id,
					'meta'       => array(
						'last_event'  => $event_type,
						'last_file'   => $file_name,
					),
				)
			);
		}

		/**
		 * Fires when an iCloud Drive file event is received.
		 *
		 * @param array       $payload       Full webhook payload.
		 * @param string      $event_type    The event type (file_created, file_modified, etc.).
		 * @param string|null $connection_id The connection identifier.
		 */
		do_action( 'wp_mcp_ai_icloud_file_event', $payload, $event_type, $connection_id );

		// Check if AI auto-reply is enabled for this connection.
		if ( ! $this->is_auto_reply_enabled( $connection_id ) ) {
			return;
		}

		/**
		 * Allow developers to prevent AI auto-reply for a specific file event.
		 *
		 * @param bool        $should_reply  Whether to dispatch an AI reply.
		 * @param array       $payload       Full webhook payload.
		 * @param string|null $connection_id The connection/channel identifier.
		 */
		$should_reply = apply_filters( 'wp_mcp_ai_icloud_should_auto_reply', true, $payload, $connection_id );

		if ( ! $should_reply ) {
			return;
		}

		// Build a human-readable summary of the event for the AI assistant.
		$event_summary = sprintf(
			/* translators: 1: event type, 2: file name, 3: file path */
			__( 'iCloud Drive event: %1$s — File: %2$s — Path: %3$s', 'mcp-ai-wpoos-pro' ),
			$event_type,
			$file_name,
			$file_path
		);

		// Dispatch AI reply asynchronously via cron to avoid webhook timeout.
		$cron_args = array( $user_id, $file_id, $event_summary, $connection_id );
		if ( ! wp_next_scheduled( self::REPLY_CRON_HOOK, $cron_args ) ) {
			wp_schedule_single_event( time(), self::REPLY_CRON_HOOK, $cron_args );
		}
	}

	/**
	 * Cron job handler: build AI response for an iCloud Drive file event.
	 *
	 * This runs asynchronously so that the original webhook response is
	 * returned quickly (within gateway timeout limits).
	 *
	 * @since 1.0.0
	 *
	 * @param string $user_id        iCloud user ID.
	 * @param string $file_id        iCloud file ID.
	 * @param string $event_summary  Human-readable event summary.
	 * @param string $connection_id  The connection/channel identifier.
	 */
	public function handle_icloud_reply_job( $user_id, $file_id, $event_summary, $connection_id ) {
		$user_id       = sanitize_text_field( $user_id );
		$file_id       = sanitize_text_field( $file_id );
		$event_summary = sanitize_textarea_field( $event_summary );
		$connection_id = sanitize_text_field( $connection_id );

		if ( '' === $file_id ) {
			return;
		}

		$connection = $this->get_connection_settings( $connection_id );
		if ( empty( $connection['gateway_api_url'] ) || empty( $connection['api_key'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'iCloud reply job: incomplete connection settings.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		// Build conversation history for context.
		$history_key = $this->get_conversation_history_key( $user_id, $connection_id );
		$history     = get_transient( $history_key );

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$history[] = array(
			'role'    => 'user',
			'content' => $event_summary,
		);

		// Retrieve configured assistant ID for this connection.
		$assistant_id = ! empty( $connection['assistant_id'] ) ? absint( $connection['assistant_id'] ) : 0;
		if ( ! $assistant_id ) {
			$settings     = get_option( 'wp_mcp_ai_settings', array() );
			$assistant_id = ! empty( $settings['default_assistant_id'] ) ? absint( $settings['default_assistant_id'] ) : 0;
		}

		if ( ! $assistant_id ) {
			WP_MCP_AI_Logger::log_error(
				'iCloud reply job: no assistant configured.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		/**
		 * Filter the AI reply for an incoming iCloud Drive file event.
		 *
		 * Returning a non-null value bypasses the default assistant dispatch and
		 * uses the returned string as the reply text directly.
		 *
		 * @param null   $reply         Set to a string to bypass default assistant.
		 * @param string $event_summary The event summary sent to the assistant.
		 * @param string $file_id       The iCloud file identifier.
		 * @param int    $assistant_id  WordPress post ID of the assistant.
		 */
		$reply = apply_filters( 'wp_mcp_ai_icloud_ai_reply', null, $event_summary, $file_id, $assistant_id );

		if ( null === $reply ) {
			// Dispatch via the standard assistant execution hook.
			$reply = apply_filters(
				'wp_mcp_ai_execute_assistant',
				'',
				$assistant_id,
				array(
					'messages' => $history,
					'channel'  => 'icloud',
				)
			);
		}

		if ( ! is_string( $reply ) || '' === trim( $reply ) ) {
			WP_MCP_AI_Logger::log_error(
				'iCloud reply job: assistant returned empty response.',
				array( 'file_id' => $this->mask_sensitive_value( $file_id ) )
			);
			return;
		}

		// Update conversation history.
		$history[] = array(
			'role'    => 'assistant',
			'content' => $reply,
		);

		$settings             = get_option( 'wp_mcp_ai_settings', array() );
		$max_history_messages = isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : 20;

		if ( count( $history ) > $max_history_messages * 2 ) {
			$history = array_slice( $history, -( $max_history_messages * 2 ) );
		}

		set_transient( $history_key, $history, self::CONVERSATION_HISTORY_TTL );

		// Send the AI reply back through the gateway.
		$this->send_reply(
			$connection['gateway_api_url'],
			$connection['api_key'],
			$file_id,
			$user_id,
			$reply
		);
	}

	/**
	 * Send a reply via the iCloud gateway REST API.
	 *
	 * @since 1.0.0
	 *
	 * @param string $gateway_api_url Gateway API endpoint URL.
	 * @param string $api_key         Gateway API key / bearer token.
	 * @param string $file_id         iCloud file identifier.
	 * @param string $user_id         iCloud user identifier.
	 * @param string $reply_text      Text of the AI reply.
	 */
	protected function send_reply( $gateway_api_url, $api_key, $file_id, $user_id, $reply_text ) {
		$payload = array(
			'file_id' => $file_id,
			'user_id' => $user_id,
			'type'    => 'ai_response',
			'body'    => array(
				'text' => $reply_text,
			),
		);

		$body_json = wp_json_encode( $payload );
		if ( false === $body_json ) {
			WP_MCP_AI_Logger::log_error( 'iCloud reply job: failed to encode reply payload.' );
			return;
		}

		$response = wp_remote_post(
			$gateway_api_url,
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
				'iCloud reply job: failed to deliver reply via gateway.',
				array( 'error' => $response->get_error_message() )
			);
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			WP_MCP_AI_Logger::log_error(
				'iCloud reply job: gateway returned non-2xx status.',
				array( 'http_code' => $code )
			);
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'icloud_reply_sent',
			'iCloud Drive AI reply delivered successfully.',
			array(
				'file_id'   => $this->mask_sensitive_value( $file_id ),
				'http_code' => $code,
			)
		);
	}

	/**
	 * Get the transient key for conversation history.
	 *
	 * Keys are prefixed with 'wp_mcp_ai_ic_conv_' and kept within the
	 * WordPress transient key length limit of 172 characters.
	 *
	 * @since 1.0.0
	 *
	 * @param string      $user_id       iCloud user identifier.
	 * @param string|null $connection_id Connection identifier.
	 * @return string Transient key (max 172 chars).
	 */
	public function get_conversation_history_key( $user_id, $connection_id ) {
		$raw = $user_id . '_' . ( $connection_id ? $connection_id : 'default' );

		// md5 produces 32 chars; prefix is 19 chars → 51 chars total, well within 172.
		return 'wp_mcp_ai_ic_conv_' . md5( $raw );
	}

	/**
	 * Check whether AI auto-reply is enabled for a connection.
	 *
	 * @param string|null $connection_id Connection identifier.
	 * @return bool
	 */
	protected function is_auto_reply_enabled( $connection_id ) {
		$connection = $this->get_connection_settings( $connection_id );

		return ! empty( $connection['auto_reply'] );
	}

	/**
	 * Retrieve connection settings for a given connection ID.
	 *
	 * Connection settings are stored under the `icloud_connections` key inside
	 * the `wp_mcp_ai_settings` WordPress option. Each connection entry is keyed
	 * by its connection ID and may contain:
	 * - gateway_api_url
	 * - api_key
	 * - signing_secret
	 * - auto_reply (bool)
	 * - assistant_id (int)
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $connection_id Connection identifier (null for default).
	 * @return array Connection settings array (may be empty).
	 */
	public function get_connection_settings( $connection_id ) {
		$settings    = get_option( 'wp_mcp_ai_settings', array() );
		$connections = isset( $settings['icloud_connections'] ) && is_array( $settings['icloud_connections'] )
			? $settings['icloud_connections']
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
	 * Retrieve the signing secret for the given connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $connection_id Connection identifier.
	 * @return string Signing secret or empty string.
	 */
	public function get_signing_secret( $connection_id ) {
		$connection = $this->get_connection_settings( $connection_id );

		return isset( $connection['signing_secret'] ) && is_string( $connection['signing_secret'] )
			? $connection['signing_secret']
			: '';
	}

	/**
	 * Check whether an event has already been processed.
	 *
	 * @param string $dedup_key Unique deduplication key.
	 * @return bool
	 */
	protected function is_duplicate_event( $dedup_key ) {
		return (bool) get_transient( 'wp_mcp_ai_ic_dedup_' . $dedup_key );
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
