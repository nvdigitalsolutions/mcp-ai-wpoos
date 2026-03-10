<?php
/**
 * Microsoft Office 365 Outlook Webhook Controller
 *
 * Handles incoming Microsoft Graph change notifications for Outlook mail.
 * Implements enterprise-grade security with client state validation,
 * per-sender conversation history, and AI auto-reply via WordPress cron.
 *
 * @see https://learn.microsoft.com/en-us/graph/webhooks
 * @see https://learn.microsoft.com/en-us/graph/api/resources/mail-api-overview
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
 * Microsoft Office 365 Outlook webhook REST controller.
 */
class WP_MCP_AI_Outlook_Webhook_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks/outlook';

	/**
	 * Cron hook for dispatching AI replies to incoming Outlook messages.
	 */
	const REPLY_CRON_HOOK = 'wp_mcp_ai_outlook_send_ai_reply';

	/**
	 * TTL in seconds for the deduplication transient.
	 */
	const DEDUP_TRANSIENT_TTL = 60;

	/**
	 * TTL in seconds for per-sender conversation history transients (24 hours).
	 */
	const CONVERSATION_HISTORY_TTL = 86400;

	/**
	 * Microsoft Graph API base URL.
	 */
	const GRAPH_API_BASE = 'https://graph.microsoft.com/v1.0';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_outlook_reply_job' ) );
	}

	/**
	 * Register REST routes for Outlook webhook notifications.
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
				'permission_callback' => array( $this, 'validate_outlook_signature' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<connection_id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_outlook_signature' ),
				'args'                => array(
					'connection_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Validate the Outlook webhook notification using clientState.
	 *
	 * Microsoft Graph change notifications include a clientState value that
	 * was specified when the subscription was created. This value is compared
	 * against the stored client state for the connection.
	 *
	 * When the client state is not configured the webhook is allowed through
	 * with a security warning.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if client state is valid or not configured.
	 */
	public function validate_outlook_signature( $request ) {
		// Validation token requests are always allowed through.
		$validation_token = $request->get_param( 'validationToken' );
		if ( null !== $validation_token ) {
			return true;
		}

		$connection_id = $request->get_param( 'connection_id' );
		$client_state  = $this->get_client_state( $connection_id );

		if ( empty( $client_state ) ) {
			WP_MCP_AI_Logger::log_event(
				'outlook_webhook_no_client_state',
				'Outlook webhook received without client state configured. Validation skipped. Configure client_state in the connection settings for enhanced security.',
				array()
			);
			return true;
		}

		$payload = $request->get_json_params();

		if ( empty( $payload ) || ! isset( $payload['value'] ) || ! is_array( $payload['value'] ) ) {
			WP_MCP_AI_Logger::log_error( 'Outlook webhook rejected: invalid notification payload.' );
			return false;
		}

		// Validate clientState on each notification in the value array.
		foreach ( $payload['value'] as $notification ) {
			if ( ! isset( $notification['clientState'] ) || ! hash_equals( $client_state, $notification['clientState'] ) ) {
				WP_MCP_AI_Logger::log_error( 'Outlook webhook rejected: invalid clientState in notification.' );
				return false;
			}
		}

		return true;
	}

	/**
	 * Handle an incoming Outlook webhook notification.
	 *
	 * Supports two flows:
	 * 1. Subscription validation: responds with the validationToken as plain text.
	 * 2. Change notifications: processes new mail notifications and schedules AI replies.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function handle_webhook( $request ) {
		// --- Subscription validation flow ---
		$validation_token = $request->get_param( 'validationToken' );

		if ( null !== $validation_token ) {
			WP_MCP_AI_Logger::log_event(
				'outlook_webhook_validation',
				'Outlook subscription validation token received.',
				array()
			);

			$response = new WP_REST_Response( sanitize_text_field( $validation_token ), 200 );
			$response->header( 'Content-Type', 'text/plain' );
			return $response;
		}

		// --- Change notification flow ---
		$payload = $request->get_json_params();

		if ( empty( $payload ) || ! isset( $payload['value'] ) || ! is_array( $payload['value'] ) ) {
			WP_MCP_AI_Logger::log_error( 'Outlook webhook: empty or invalid notification payload.' );
			return new WP_REST_Response( null, 202 );
		}

		$connection_id = sanitize_key( (string) $request->get_param( 'connection_id' ) );

		foreach ( $payload['value'] as $notification ) {
			$this->process_notification( $notification, $connection_id );
		}

		// Graph expects 202 Accepted for change notifications.
		return new WP_REST_Response( null, 202 );
	}

	/**
	 * Process a single change notification from Microsoft Graph.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $notification  Single notification from the value array.
	 * @param string $connection_id Connection ID from the URL or empty string.
	 */
	protected function process_notification( array $notification, $connection_id ) {
		$change_type     = isset( $notification['changeType'] ) ? sanitize_text_field( $notification['changeType'] ) : '';
		$resource        = isset( $notification['resource'] ) ? sanitize_text_field( $notification['resource'] ) : '';
		$notification_id = isset( $notification['id'] ) ? sanitize_text_field( $notification['id'] ) : '';
		$tenant_id       = isset( $notification['tenantId'] ) ? sanitize_text_field( $notification['tenantId'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'outlook_webhook_received',
			'Outlook change notification received.',
			array(
				'change_type'     => $change_type,
				'notification_id' => $notification_id,
			)
		);

		// Only process newly created messages.
		if ( 'created' !== $change_type ) {
			return;
		}

		if ( '' === $resource ) {
			return;
		}

		// Deduplication via notification ID.
		if ( '' !== $notification_id && $this->is_duplicate_notification( $notification_id ) ) {
			return;
		}

		if ( '' !== $notification_id ) {
			set_transient( 'wp_mcp_ai_ol_dedup_' . md5( $notification_id ), 1, self::DEDUP_TRANSIENT_TTL );
		}

		// Resolve the connection.
		$connection = null;

		if ( '' !== $connection_id ) {
			$connection = $this->get_connection_settings( $connection_id );
		}

		if ( ! $connection ) {
			$connection = $this->get_active_outlook_connection( $tenant_id );
		}

		if ( ! $connection ) {
			WP_MCP_AI_Logger::log_error( 'Outlook webhook: no active Outlook connection with assigned assistants found.' );
			return;
		}

		$resolved_connection_id = isset( $connection['id'] ) ? sanitize_key( $connection['id'] ) : '';

		if ( '' === $resolved_connection_id ) {
			return;
		}

		$assigned_assistant_ids = isset( $connection['assigned_assistant_ids'] ) && is_array( $connection['assigned_assistant_ids'] )
			? array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) )
			: array();

		if ( empty( $assigned_assistant_ids ) ) {
			return;
		}

		// Validate tenant ID if configured on the connection.
		if ( '' !== $tenant_id && ! empty( $connection['tenant_id'] ) && $connection['tenant_id'] !== $tenant_id ) {
			WP_MCP_AI_Logger::log_error(
				'Outlook webhook: tenant ID mismatch.',
				array(
					'expected' => $connection['tenant_id'],
					'received' => $tenant_id,
				)
			);
			return;
		}

		$job_args = array(
			array(
				'assistant_id'  => $assigned_assistant_ids[0],
				'resource'      => $resource,
				'connection_id' => $resolved_connection_id,
				'tenant_id'     => $tenant_id,
			),
		);

		wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
		spawn_cron();
	}

	/**
	 * Cron callback: fetch the message from Graph, generate an AI reply, and send it.
	 *
	 * Implements per-sender conversation history following the same pattern as the
	 * Teams auto-reply handler, respecting the global max_history_messages setting
	 * and the wp_mcp_ai_outlook_max_history_messages filter.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Job arguments set by process_notification().
	 */
	public function handle_outlook_reply_job( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		$assistant_id  = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;
		$resource      = isset( $args['resource'] ) ? (string) $args['resource'] : '';
		$connection_id = isset( $args['connection_id'] ) ? sanitize_key( (string) $args['connection_id'] ) : '';

		if ( ! $assistant_id || '' === $resource || '' === $connection_id ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection || empty( $connection['token'] ) ) {
			WP_MCP_AI_Logger::log_error(
				'Outlook AI reply: connection not found or access token missing.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		$access_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['token'] );

		if ( '' === $access_token ) {
			WP_MCP_AI_Logger::log_error(
				'Outlook AI reply: access token decryption returned empty string.',
				array( 'connection_id' => $connection_id )
			);
			return;
		}

		// Fetch the message from Microsoft Graph.
		$message_data = $this->fetch_message_from_graph( $resource, $access_token );

		if ( ! $message_data ) {
			WP_MCP_AI_Logger::log_error(
				'Outlook AI reply: failed to fetch message from Graph API.',
				array( 'resource' => $resource )
			);
			return;
		}

		$sender_email = $this->extract_sender_email( $message_data );
		$message_text = $this->extract_message_text( $message_data );
		$message_id   = isset( $message_data['id'] ) ? sanitize_text_field( $message_data['id'] ) : '';
		$subject      = isset( $message_data['subject'] ) ? sanitize_text_field( $message_data['subject'] ) : '';

		if ( '' === $message_text || '' === $sender_email ) {
			return;
		}

		// Find or create the contact in the Channel Contacts CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			$contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create(
				'outlook',
				$sender_email,
				array( 'display_name' => $sender_email )
			);
			if ( $contact_row_id ) {
				WP_MCP_AI_Channel_Contacts_CCT::touch( $contact_row_id );
			}
		}

		// Persist inbound message to Channel Messages CCT.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => 'outlook',
					'channel_contact_id' => $sender_email,
					'direction'          => 'inbound',
					'message_id'         => $message_id,
					'message_type'       => 'text',
					'content'            => $message_text,
					'status'             => 'received',
					'connection_id'      => $connection_id,
					'phone_number_id'    => '',
					'timestamp'          => time(),
					'reply_sent'         => 0,
					'assigned_agent'     => (string) $assistant_id,
				)
			);
		}

		// --- Per-sender conversation history (mirrors Teams/WhatsApp pattern) ---
		$history_key = $this->get_conversation_history_key( $sender_email, $connection_id );
		$history     = get_transient( $history_key );
		$history     = is_array( $history ) ? $history : array();

		$max_history = 8;
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings    = WP_MCP_AI_Admin_Settings::get_settings();
			$max_history = isset( $settings['max_history_messages'] ) ? absint( $settings['max_history_messages'] ) : $max_history;
		}

		/**
		 * Filters the maximum number of messages kept in an Outlook conversation history.
		 *
		 * @since 1.0.0
		 *
		 * @param int   $max_history Maximum message count.
		 * @param array $args        Current job arguments.
		 */
		$max_history = (int) apply_filters( 'wp_mcp_ai_outlook_max_history_messages', $max_history, $args );
		$max_history = max( 1, $max_history );

		// When the transient cache is empty (e.g. after expiry or a cache flush),
		// hydrate the conversation context from the Channel Messages CCT so that
		// prior exchanges are never silently dropped. The CCT is the persistent
		// source of truth; the transient is a fast in-memory cache on top of it.
		if ( empty( $history ) && $max_history > 1 && class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			$history = WP_MCP_AI_Channel_Messages_CCT::get_recent_messages(
				'outlook',
				$sender_email,
				$connection_id,
				$max_history - 1
			);
		}

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
				'Outlook AI reply: no administrator user found; internal chat request may fail.',
				array( 'assistant_id' => $assistant_id )
			);
		}

		$response = rest_do_request( $request );
		wp_set_current_user( $original_user_id );

		if ( $response->is_error() ) {
			WP_MCP_AI_Logger::log_error(
				'Outlook AI reply: chat request failed.',
				array( 'assistant_id' => $assistant_id )
			);
			return;
		}

		$content = $this->extract_content_from_chat_response( $response->get_data() );

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error( 'Outlook AI reply: empty content from assistant.' );
			return;
		}

		// Send the reply via Microsoft Graph sendMail API.
		$sent = $this->send_reply_via_graph( $sender_email, $subject, $content, $access_token );

		if ( $sent ) {
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
				'outlook_ai_reply_sent',
				'Outlook AI reply sent successfully.',
				array( 'assistant_id' => $assistant_id )
			);

			// Persist the outbound AI reply to the Channel Messages CCT.
			if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
				WP_MCP_AI_Channel_Messages_CCT::insert(
					array(
						'channel'            => 'outlook',
						'channel_contact_id' => $sender_email,
						'direction'          => 'outbound',
						'message_type'       => 'text',
						'content'            => $content,
						'status'             => 'sent',
						'connection_id'      => $connection_id,
						'phone_number_id'    => '',
						'timestamp'          => time(),
						'reply_sent'         => 1,
						'assigned_agent'     => (string) $assistant_id,
					)
				);
			}

			// Touch the contact record to update last_message_at.
			if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
				$ol_contact_row_id = WP_MCP_AI_Channel_Contacts_CCT::find_or_create( 'outlook', $sender_email );
				if ( $ol_contact_row_id ) {
					WP_MCP_AI_Channel_Contacts_CCT::touch( $ol_contact_row_id );
				}
			}
		}
	}

	/**
	 * Return the transient key for an Outlook sender conversation history.
	 *
	 * @since 1.0.0
	 *
	 * @param string $sender_email  Sender email address.
	 * @param string $connection_id Remote connection ID.
	 * @return string Transient key (hashed, within 172-character limit).
	 */
	protected function get_conversation_history_key( $sender_email, $connection_id ) {
		return 'wp_mcp_ai_ol_conv_' . md5( $sender_email . '_' . $connection_id );
	}

	/**
	 * Extract the sender email address from a Graph message object.
	 *
	 * @since 1.0.0
	 *
	 * @param array $message_data Graph message data.
	 * @return string Sender email address or empty string.
	 */
	protected function extract_sender_email( $message_data ) {
		if ( isset( $message_data['from']['emailAddress']['address'] ) ) {
			return sanitize_email( $message_data['from']['emailAddress']['address'] );
		}

		if ( isset( $message_data['sender']['emailAddress']['address'] ) ) {
			return sanitize_email( $message_data['sender']['emailAddress']['address'] );
		}

		return '';
	}

	/**
	 * Extract plain-text body from a Graph message object.
	 *
	 * Prefers the plain-text body content. Falls back to stripping HTML
	 * from the body when contentType is "html".
	 *
	 * @since 1.0.0
	 *
	 * @param array $message_data Graph message data.
	 * @return string Plain-text message or empty string.
	 */
	protected function extract_message_text( $message_data ) {
		if ( ! isset( $message_data['body']['content'] ) || ! is_string( $message_data['body']['content'] ) ) {
			return '';
		}

		$content      = $message_data['body']['content'];
		$content_type = isset( $message_data['body']['contentType'] ) ? strtolower( $message_data['body']['contentType'] ) : 'text';

		if ( 'html' === $content_type ) {
			$content = wp_strip_all_tags( $content );
		}

		return sanitize_textarea_field( trim( $content ) );
	}

	/**
	 * Get connection settings for a specific connection ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Connection ID.
	 * @return array|null Connection array or null if not found.
	 */
	protected function get_connection_settings( $connection_id ) {
		if ( '' === $connection_id ) {
			return null;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

		if ( ! $connection ) {
			return null;
		}

		// Verify this is an Outlook-type connection.
		if ( ! isset( $connection['connection_type'] ) || 'outlook' !== $connection['connection_type'] ) {
			return null;
		}

		if ( empty( $connection['enabled'] ) ) {
			return null;
		}

		return $connection;
	}

	/**
	 * Get the client state secret from the connection or global settings.
	 *
	 * @since 1.0.0
	 *
	 * @param string $connection_id Optional connection ID.
	 * @return string Client state value or empty string.
	 */
	protected function get_client_state( $connection_id = '' ) {
		if ( '' !== $connection_id ) {
			$connection = $this->get_connection_settings( $connection_id );

			if ( $connection && ! empty( $connection['client_state'] ) ) {
				if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
					require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
				}

				return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_state'] );
			}
		}

		// Fall back to the first active Outlook connection.
		$connection = $this->get_active_outlook_connection();

		if ( ! $connection || empty( $connection['client_state'] ) ) {
			return '';
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_state'] );
	}

	/**
	 * Find the first active Microsoft Outlook connection with assigned assistants.
	 *
	 * Optionally filters by tenant_id for multi-tenant setups.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tenant_id Optional tenant ID to match.
	 * @return array|null Connection array or null if none found.
	 */
	protected function get_active_outlook_connection( $tenant_id = '' ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( ! is_array( $connections ) ) {
			return null;
		}

		$first_match = null;

		foreach ( $connections as $connection ) {
			if ( ! isset( $connection['connection_type'] ) || 'outlook' !== $connection['connection_type'] ) {
				continue;
			}

			if ( empty( $connection['enabled'] ) ) {
				continue;
			}

			if ( empty( $connection['assigned_assistant_ids'] ) || ! is_array( $connection['assigned_assistant_ids'] ) ) {
				continue;
			}

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
	 * Check whether a notification ID has already been processed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $notification_id Graph notification ID.
	 * @return bool True if already processed.
	 */
	protected function is_duplicate_notification( $notification_id ) {
		return (bool) get_transient( 'wp_mcp_ai_ol_dedup_' . md5( $notification_id ) );
	}

	/**
	 * Fetch a mail message from Microsoft Graph API.
	 *
	 * @since 1.0.0
	 *
	 * @param string $resource     The resource path from the notification (e.g. "me/messages/{id}").
	 * @param string $access_token Microsoft Graph access token.
	 * @return array|null Message data array or null on failure.
	 */
	protected function fetch_message_from_graph( $resource, $access_token ) {
		$endpoint = self::GRAPH_API_BASE . '/' . ltrim( $resource, '/' );

		$result = wp_remote_get(
			$endpoint,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Outlook: Graph API message fetch failed.',
				array( 'error' => $result->get_error_message() )
			);
			return null;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $result );

		if ( 200 !== $http_code ) {
			WP_MCP_AI_Logger::log_error(
				'Outlook: Graph API returned non-200 for message fetch.',
				array( 'http_code' => $http_code )
			);
			return null;
		}

		$body = wp_remote_retrieve_body( $result );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Send a reply email via Microsoft Graph sendMail API.
	 *
	 * @since 1.0.0
	 *
	 * @param string $to_email     Recipient email address.
	 * @param string $subject      Email subject (prefixed with "Re: " if not already).
	 * @param string $content      Email body content.
	 * @param string $access_token Microsoft Graph access token.
	 * @return bool True if sent successfully.
	 */
	protected function send_reply_via_graph( $to_email, $subject, $content, $access_token ) {
		$reply_subject = $subject;
		if ( '' !== $reply_subject && 0 !== strncasecmp( $reply_subject, 'Re: ', 4 ) ) {
			$reply_subject = 'Re: ' . $reply_subject;
		}

		$endpoint = self::GRAPH_API_BASE . '/me/sendMail';

		$payload = array(
			'message' => array(
				'subject'      => $reply_subject,
				'body'         => array(
					'contentType' => 'Text',
					'content'     => $content,
				),
				'toRecipients' => array(
					array(
						'emailAddress' => array(
							'address' => $to_email,
						),
					),
				),
			),
			'saveToSentItems' => true,
		);

		$body = wp_json_encode( $payload );

		if ( ! $body ) {
			WP_MCP_AI_Logger::log_error( 'Outlook AI reply: failed to encode sendMail payload.' );
			return false;
		}

		WP_MCP_AI_Logger::log_event(
			'outlook_ai_reply_sending',
			'Sending Outlook AI reply via Graph sendMail API.',
			array(
				'to_email' => sanitize_email( $to_email ),
				'subject'  => $reply_subject,
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
				'Outlook AI reply: Graph sendMail request failed.',
				array( 'error' => $result->get_error_message() )
			);
			return false;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $result );

		// Graph sendMail returns 202 Accepted on success.
		if ( 202 !== $http_code ) {
			WP_MCP_AI_Logger::log_error(
				'Outlook AI reply: Graph sendMail returned non-202.',
				array( 'http_code' => $http_code )
			);
			return false;
		}

		return true;
	}

	/**
	 * Extract plain-text content from the internal /mcp-ai/v1/chat response.
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

		if ( isset( $first['message']['content'] ) && is_string( $first['message']['content'] ) ) {
			return trim( $first['message']['content'] );
		}

		return '';
	}
}

new WP_MCP_AI_Outlook_Webhook_Controller();
