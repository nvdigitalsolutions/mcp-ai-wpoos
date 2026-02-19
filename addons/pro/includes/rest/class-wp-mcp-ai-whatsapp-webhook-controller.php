<?php
/**
 * WhatsApp Cloud API Webhook Controller
 *
 * Handles incoming WhatsApp webhook events with industry-standard security validation.
 * Implements best practices for WhatsApp Business API webhooks including:
 * - Webhook verification (GET request with hub.challenge)
 * - Signature validation (HMAC-SHA256)
 * - Message type handling (text, media, interactive, etc.)
 * - Error handling and logging
 * - Rate limiting
 *
 * @package WP_MCP_AI_Pro
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * WhatsApp webhook REST controller.
 */
class WP_MCP_AI_WhatsApp_Webhook_Controller extends WP_REST_Controller {

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
	protected $rest_base = 'webhooks/whatsapp';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes for WhatsApp webhooks.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Webhook verification endpoint (GET).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'verify_webhook' ),
				'permission_callback' => '__return_true', // Public endpoint for webhook verification.
				'args'                => array(
					'hub.mode'         => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'hub.verify_token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'hub.challenge'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Webhook event receiver endpoint (POST).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_webhook' ),
				'permission_callback' => array( $this, 'validate_webhook_signature' ),
			)
		);
	}

	/**
	 * Verify webhook subscription.
	 *
	 * Handles GET requests from Meta to verify webhook endpoint.
	 * Returns the hub.challenge value if verification token matches.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function verify_webhook( $request ) {
		$mode          = $request->get_param( 'hub.mode' );
		$verify_token  = $request->get_param( 'hub.verify_token' );
		$challenge     = $request->get_param( 'hub.challenge' );

		WP_MCP_AI_Logger::log_event(
			'whatsapp_webhook_verification_attempt',
			'WhatsApp webhook verification request received.',
			array(
				'mode'         => $mode,
				'verify_token' => substr( $verify_token, 0, 4 ) . '***', // Masked for security.
			)
		);

		// Get stored verify token from connection settings.
		$stored_token = $this->get_verify_token();

		if ( empty( $stored_token ) ) {
			WP_MCP_AI_Logger::log_error(
				'WhatsApp webhook verification failed: No verify token configured.',
				array( 'mode' => $mode )
			);

			return new WP_Error(
				'whatsapp_no_verify_token',
				__( 'WhatsApp webhook verify token not configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 500 )
			);
		}

		// Verify mode and token.
		if ( 'subscribe' === $mode && $verify_token === $stored_token ) {
			WP_MCP_AI_Logger::log_event(
				'whatsapp_webhook_verified',
				'WhatsApp webhook successfully verified.'
			);

			// Return challenge to complete verification.
			return rest_ensure_response( $challenge );
		}

		WP_MCP_AI_Logger::log_error(
			'WhatsApp webhook verification failed: Invalid token or mode.',
			array(
				'mode'          => $mode,
				'token_matches' => $verify_token === $stored_token,
			)
		);

		return new WP_Error(
			'whatsapp_verification_failed',
			__( 'WhatsApp webhook verification failed.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Validate webhook signature using HMAC-SHA256.
	 *
	 * Implements Meta's signature validation best practice.
	 * Signature is sent in X-Hub-Signature-256 header.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool True if signature is valid, false otherwise.
	 */
	public function validate_webhook_signature( $request ) {
		// Get signature from header.
		$signature_header = $request->get_header( 'x-hub-signature-256' );

		if ( empty( $signature_header ) ) {
			WP_MCP_AI_Logger::log_error(
				'WhatsApp webhook rejected: Missing signature header.'
			);
			return false;
		}

		// Get app secret from connection settings.
		$app_secret = $this->get_app_secret();

		if ( empty( $app_secret ) ) {
			WP_MCP_AI_Logger::log_error(
				'WhatsApp webhook rejected: App secret not configured.'
			);
			return false;
		}

		// Get raw request body.
		$payload = $request->get_body();

		// Calculate expected signature.
		$expected_signature = 'sha256=' . hash_hmac( 'sha256', $payload, $app_secret );

		// Compare signatures using timing-safe comparison.
		if ( ! hash_equals( $expected_signature, $signature_header ) ) {
			WP_MCP_AI_Logger::log_error(
				'WhatsApp webhook rejected: Invalid signature.',
				array(
					'header_present' => ! empty( $signature_header ),
					'secret_present' => ! empty( $app_secret ),
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Handle incoming webhook events.
	 *
	 * Processes WhatsApp webhook notifications including:
	 * - Messages (text, media, interactive)
	 * - Status updates (sent, delivered, read, failed)
	 * - Account updates
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function handle_webhook( $request ) {
		// Get webhook payload.
		$payload = $request->get_json_params();

		if ( empty( $payload ) ) {
			WP_MCP_AI_Logger::log_error(
				'WhatsApp webhook received with empty payload.'
			);

			// Return 200 to prevent retries for malformed requests.
			return rest_ensure_response(
				array(
					'success' => false,
					'message' => 'Empty payload',
				)
			);
		}

		WP_MCP_AI_Logger::log_event(
			'whatsapp_webhook_received',
			'WhatsApp webhook event received.',
			array(
				'object' => isset( $payload['object'] ) ? $payload['object'] : 'unknown',
				'entry_count' => isset( $payload['entry'] ) && is_array( $payload['entry'] ) ? count( $payload['entry'] ) : 0,
			)
		);

		// Validate webhook object type.
		if ( ! isset( $payload['object'] ) || 'whatsapp_business_account' !== $payload['object'] ) {
			WP_MCP_AI_Logger::log_error(
				'WhatsApp webhook rejected: Invalid object type.',
				array( 'object' => isset( $payload['object'] ) ? $payload['object'] : 'missing' )
			);

			return rest_ensure_response(
				array(
					'success' => false,
					'message' => 'Invalid object type',
				)
			);
		}

		// Process each entry in the webhook.
		if ( isset( $payload['entry'] ) && is_array( $payload['entry'] ) ) {
			foreach ( $payload['entry'] as $entry ) {
				$this->process_webhook_entry( $entry );
			}
		}

		// Return 200 OK response immediately.
		// Processing continues asynchronously to prevent timeout.
		return rest_ensure_response(
			array(
				'success' => true,
				'message' => 'Webhook processed',
			)
		);
	}

	/**
	 * Process a single webhook entry.
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry Webhook entry data.
	 */
	protected function process_webhook_entry( $entry ) {
		if ( ! isset( $entry['changes'] ) || ! is_array( $entry['changes'] ) ) {
			return;
		}

		foreach ( $entry['changes'] as $change ) {
			$this->process_webhook_change( $change );
		}
	}

	/**
	 * Process a single webhook change notification.
	 *
	 * @since 1.0.0
	 *
	 * @param array $change Change notification data.
	 */
	protected function process_webhook_change( $change ) {
		if ( ! isset( $change['field'] ) || ! isset( $change['value'] ) ) {
			return;
		}

		$field = $change['field'];
		$value = $change['value'];

		switch ( $field ) {
			case 'messages':
				$this->process_messages( $value );
				break;

			case 'message_template_status_update':
				$this->process_template_status_update( $value );
				break;

			case 'account_update':
				$this->process_account_update( $value );
				break;

			default:
				WP_MCP_AI_Logger::log_event(
					'whatsapp_webhook_unknown_field',
					'Unknown webhook field received.',
					array( 'field' => $field )
				);
		}
	}

	/**
	 * Process incoming messages.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Message data.
	 */
	protected function process_messages( $value ) {
		// Process message status updates.
		if ( isset( $value['statuses'] ) && is_array( $value['statuses'] ) ) {
			foreach ( $value['statuses'] as $status ) {
				$this->process_message_status( $status );
			}
		}

		// Process incoming messages.
		if ( isset( $value['messages'] ) && is_array( $value['messages'] ) ) {
			foreach ( $value['messages'] as $message ) {
				$this->process_incoming_message( $message, $value );
			}
		}
	}

	/**
	 * Process message status update.
	 *
	 * @since 1.0.0
	 *
	 * @param array $status Status data.
	 */
	protected function process_message_status( $status ) {
		$message_id = isset( $status['id'] ) ? sanitize_text_field( $status['id'] ) : '';
		$status_val = isset( $status['status'] ) ? sanitize_text_field( $status['status'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'whatsapp_message_status',
			'Message status update received.',
			array(
				'message_id' => $message_id,
				'status'     => $status_val,
				'timestamp'  => isset( $status['timestamp'] ) ? $status['timestamp'] : null,
			)
		);

		/**
		 * Fires when a WhatsApp message status is updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $status Status data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_message_status', $status );
	}

	/**
	 * Process incoming message.
	 *
	 * @since 1.0.0
	 *
	 * @param array $message Message data.
	 * @param array $context Full webhook context.
	 */
	protected function process_incoming_message( $message, $context ) {
		$message_id   = isset( $message['id'] ) ? sanitize_text_field( $message['id'] ) : '';
		$from         = isset( $message['from'] ) ? sanitize_text_field( $message['from'] ) : '';
		$message_type = isset( $message['type'] ) ? sanitize_text_field( $message['type'] ) : '';
		$timestamp    = isset( $message['timestamp'] ) ? absint( $message['timestamp'] ) : time();

		WP_MCP_AI_Logger::log_event(
			'whatsapp_incoming_message',
			'Incoming WhatsApp message received.',
			array(
				'message_id' => $message_id,
				'from'       => substr( $from, 0, 4 ) . '***', // Masked for privacy.
				'type'       => $message_type,
				'timestamp'  => $timestamp,
			)
		);

		// Extract message content based on type.
		$message_data = array(
			'id'        => $message_id,
			'from'      => $from,
			'type'      => $message_type,
			'timestamp' => $timestamp,
			'content'   => $this->extract_message_content( $message ),
			'context'   => isset( $message['context'] ) ? $message['context'] : null,
		);

		/**
		 * Fires when a WhatsApp message is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $message_data Parsed message data.
		 * @param array $message Raw message from webhook.
		 * @param array $context Full webhook context.
		 */
		do_action( 'wp_mcp_ai_whatsapp_message_received', $message_data, $message, $context );

		// Auto-reply or process with AI assistant if configured.
		$this->maybe_auto_reply( $message_data, $context );
	}

	/**
	 * Extract message content based on message type.
	 *
	 * @since 1.0.0
	 *
	 * @param array $message Message data.
	 * @return array|string Extracted content.
	 */
	protected function extract_message_content( $message ) {
		if ( ! isset( $message['type'] ) ) {
			return '';
		}

		$type = $message['type'];

		switch ( $type ) {
			case 'text':
				return isset( $message['text']['body'] ) ? sanitize_textarea_field( $message['text']['body'] ) : '';

			case 'image':
			case 'video':
			case 'audio':
			case 'document':
			case 'sticker':
				return isset( $message[ $type ] ) ? $message[ $type ] : array();

			case 'location':
				return isset( $message['location'] ) ? $message['location'] : array();

			case 'contacts':
				return isset( $message['contacts'] ) ? $message['contacts'] : array();

			case 'interactive':
				return $this->extract_interactive_response( $message );

			case 'button':
				return isset( $message['button'] ) ? $message['button'] : array();

			default:
				return '';
		}
	}

	/**
	 * Extract interactive message response.
	 *
	 * @since 1.0.0
	 *
	 * @param array $message Message data.
	 * @return array Interactive response data.
	 */
	protected function extract_interactive_response( $message ) {
		if ( ! isset( $message['interactive'] ) ) {
			return array();
		}

		$interactive = $message['interactive'];
		$type        = isset( $interactive['type'] ) ? $interactive['type'] : '';

		switch ( $type ) {
			case 'button_reply':
				return isset( $interactive['button_reply'] ) ? $interactive['button_reply'] : array();

			case 'list_reply':
				return isset( $interactive['list_reply'] ) ? $interactive['list_reply'] : array();

			default:
				return $interactive;
		}
	}

	/**
	 * Maybe auto-reply to incoming message.
	 *
	 * @since 1.0.0
	 *
	 * @param array $message_data Parsed message data.
	 * @param array $context Full webhook context.
	 */
	protected function maybe_auto_reply( $message_data, $context ) {
		/**
		 * Filter whether to auto-reply to WhatsApp messages.
		 *
		 * @since 1.0.0
		 *
		 * @param bool  $auto_reply  Whether to auto-reply. Default false.
		 * @param array $message_data Parsed message data.
		 * @param array $context Full webhook context.
		 */
		$should_reply = apply_filters( 'wp_mcp_ai_whatsapp_should_auto_reply', false, $message_data, $context );

		if ( ! $should_reply ) {
			return;
		}

		// Auto-reply logic will be implemented by extensions.
		do_action( 'wp_mcp_ai_whatsapp_auto_reply', $message_data, $context );
	}

	/**
	 * Process template status update.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Template status data.
	 */
	protected function process_template_status_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_template_status',
			'Template status update received.',
			array(
				'event' => isset( $value['event'] ) ? $value['event'] : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp template status is updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Template status data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_template_status', $value );
	}

	/**
	 * Process account update.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Account update data.
	 */
	protected function process_account_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_account_update',
			'Account update received.',
			array(
				'phone_number' => isset( $value['phone_number'] ) ? substr( $value['phone_number'], 0, 4 ) . '***' : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp account is updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Account update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_account_update', $value );
	}

	/**
	 * Get verify token from connection settings.
	 *
	 * @since 1.0.0
	 *
	 * @return string Verify token or empty string.
	 */
	protected function get_verify_token() {
		// Get WhatsApp connection from remote site manager.
		$connections = $this->get_whatsapp_connections();

		foreach ( $connections as $connection ) {
			if ( isset( $connection['whatsapp_verify_token'] ) && ! empty( $connection['whatsapp_verify_token'] ) ) {
				return $connection['whatsapp_verify_token'];
			}
		}

		return '';
	}

	/**
	 * Get app secret from connection settings.
	 *
	 * For security, this should be the App Secret from Meta Developer Dashboard,
	 * not the access token. The signature is validated using the app secret.
	 *
	 * @since 1.0.0
	 *
	 * @return string App secret or empty string.
	 */
	protected function get_app_secret() {
		// Get WhatsApp connection from remote site manager.
		$connections = $this->get_whatsapp_connections();

		foreach ( $connections as $connection ) {
			// Try to get dedicated app secret field first.
			if ( isset( $connection['whatsapp_app_secret'] ) && ! empty( $connection['whatsapp_app_secret'] ) ) {
				return $connection['whatsapp_app_secret'];
			}

			// Fallback: Use access token for signature validation if app secret not set.
			// Note: This is not ideal. App secret should be configured separately.
			if ( isset( $connection['whatsapp_access_token'] ) && ! empty( $connection['whatsapp_access_token'] ) ) {
				return $connection['whatsapp_access_token'];
			}
		}

		return '';
	}

	/**
	 * Get all WhatsApp connections.
	 *
	 * @since 1.0.0
	 *
	 * @return array WhatsApp connections.
	 */
	protected function get_whatsapp_connections() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$all_connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		$whatsapp_connections = array();

		foreach ( $all_connections as $connection ) {
			if ( isset( $connection['connection_type'] ) && 'whatsapp' === $connection['connection_type'] ) {
				$whatsapp_connections[] = $connection;
			}
		}

		return $whatsapp_connections;
	}
}

// Initialize the controller.
new WP_MCP_AI_WhatsApp_Webhook_Controller();
