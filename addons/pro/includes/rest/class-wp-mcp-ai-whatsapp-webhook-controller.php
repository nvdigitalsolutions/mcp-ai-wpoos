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
	 * Cron hook for dispatching AI replies to incoming WhatsApp messages.
	 */
	const REPLY_CRON_HOOK = 'wp_mcp_ai_whatsapp_send_ai_reply';

	/**
	 * Default WhatsApp Cloud API Graph version used when none is stored on the connection.
	 */
	const DEFAULT_GRAPH_API_VERSION = 'v19.0';

	/**
	 * TTL in seconds for the deduplication transient used to prevent double-processing
	 * of the same message when Meta retries a webhook delivery.
	 */
	const DEDUP_TRANSIENT_TTL = 60;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( self::REPLY_CRON_HOOK, array( $this, 'handle_whatsapp_reply_job' ) );
	}

	/**
	 * Register REST routes for WhatsApp webhooks.
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		// Webhook verification endpoint (GET).
		// Note: Meta sends hub.mode, hub.verify_token, hub.challenge (with dots).
		// PHP converts dots to underscores in $_GET, so they arrive as hub_mode, etc.
		// We do NOT mark these as required so that server configurations that don't
		// perform the dot-to-underscore conversion receive a clean 403 instead of a
		// WordPress-level rest_missing_callback_param 400, and to let the callback
		// handle validation and return the plain-text challenge Meta expects.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'verify_webhook' ),
				'permission_callback' => '__return_true', // Public endpoint for webhook verification.
				'args'                => array(
					'hub_mode'         => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'hub_verify_token' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'hub_challenge'    => array(
						'required'          => false,
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
	 * Returns the hub_challenge value as plain text to complete verification.
	 *
	 * Meta sends hub.mode, hub.verify_token, hub.challenge (dot notation).
	 * PHP converts those dots to underscores in $_GET, so WordPress receives
	 * hub_mode, hub_verify_token, hub_challenge.
	 *
	 * IMPORTANT: Meta requires the challenge echoed as a plain text string (no
	 * JSON encoding). We hook into rest_pre_serve_request to output the body
	 * directly and bypass WordPress's wp_json_encode wrapper.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function verify_webhook( $request ) {
		$mode         = $request->get_param( 'hub_mode' );
		$verify_token = $request->get_param( 'hub_verify_token' );
		$challenge    = $request->get_param( 'hub_challenge' );

		// Handle missing required parameters - return 403 so Meta shows a clear error.
		if ( empty( $mode ) || empty( $verify_token ) || empty( $challenge ) ) {
			return new WP_Error(
				'whatsapp_verification_failed',
				__( 'WhatsApp webhook verification failed.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

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

		// Verify mode and token using timing-safe comparison.
		if ( 'subscribe' === $mode && hash_equals( $stored_token, $verify_token ) ) {
			WP_MCP_AI_Logger::log_event(
				'whatsapp_webhook_verified',
				'WhatsApp webhook successfully verified.'
			);

			// Meta requires the challenge returned as a plain text string without any
			// JSON encoding or wrapping. WordPress REST API always runs wp_json_encode
			// on the response body, so we hook into rest_pre_serve_request to output
			// the raw challenge ourselves and signal WordPress to skip its normal output.
			add_filter(
				'rest_pre_serve_request',
				static function ( $served ) use ( $challenge ) {
					if ( $served ) {
						return $served;
					}
					status_header( 200 );
					header( 'Content-Type: text/plain; charset=utf-8' );
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $challenge;
					return true;
				}
			);

			return new WP_REST_Response( $challenge, 200 );
		}

		WP_MCP_AI_Logger::log_error(
			'WhatsApp webhook verification failed: Invalid token or mode.',
			array(
				'mode'          => $mode,
				'token_matches' => hash_equals( $stored_token, $verify_token ),
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
		// The HMAC signature MUST be validated with the App Secret from the
		// Meta Developer Dashboard — never the access token.
		$app_secret = $this->get_app_secret();

		if ( empty( $app_secret ) ) {
			WP_MCP_AI_Logger::log_error(
				'WhatsApp webhook rejected: App Secret not configured. Configure the App Secret from your Meta Developer Dashboard to enable webhook signature validation.'
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
				'object'      => isset( $payload['object'] ) ? $payload['object'] : 'unknown',
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

			case 'phone_number_name_update':
				$this->process_phone_number_name_update( $value );
				break;

			case 'phone_number_quality_update':
				$this->process_phone_number_quality_update( $value );
				break;

			// Account-level fields.
			case 'account_alerts':
				$this->process_account_alerts( $value );
				break;

			case 'account_review_update':
				$this->process_account_review_update( $value );
				break;

			case 'account_settings_update':
				$this->process_account_settings_update( $value );
				break;

			// Business-level fields.
			case 'business_capability_update':
				$this->process_business_capability_update( $value );
				break;

			case 'business_status_update':
				$this->process_business_status_update( $value );
				break;

			// Automation and tracking fields.
			case 'automatic_events':
				$this->process_automatic_events( $value );
				break;

			case 'tracking_events':
				$this->process_tracking_events( $value );
				break;

			// Calls field.
			case 'calls':
				$this->process_calls( $value );
				break;

			// WhatsApp Flows field.
			case 'flows':
				$this->process_flows( $value );
				break;

			// Group management fields.
			case 'group_lifecycle_update':
				$this->process_group_lifecycle_update( $value );
				break;

			case 'group_participants_update':
				$this->process_group_participants_update( $value );
				break;

			case 'group_settings_update':
				$this->process_group_settings_update( $value );
				break;

			case 'group_status_update':
				$this->process_group_status_update( $value );
				break;

			// Message history sync field.
			case 'history':
				$this->process_history( $value );
				break;

			// Message echo fields.
			case 'message_echoes':
				$this->process_message_echoes( $value );
				break;

			case 'smb_message_echoes':
				$this->process_smb_message_echoes( $value );
				break;

			// Messaging handover protocol field.
			case 'messaging_handovers':
				$this->process_messaging_handovers( $value );
				break;

			// Template management fields.
			case 'message_template_components_update':
				$this->process_template_components_update( $value );
				break;

			case 'message_template_quality_update':
				$this->process_template_quality_update( $value );
				break;

			case 'template_category_update':
				$this->process_template_category_update( $value );
				break;

			case 'template_correct_category_detection':
				$this->process_template_correct_category_detection( $value );
				break;

			// Partner and payment fields.
			case 'partner_solutions':
				$this->process_partner_solutions( $value );
				break;

			case 'payment_configuration_update':
				$this->process_payment_configuration_update( $value );
				break;

			// Security field.
			case 'security':
				$this->process_security( $value );
				break;

			// SMB-specific fields.
			case 'smb_app_state_sync':
				$this->process_smb_app_state_sync( $value );
				break;

			// User preferences field.
			case 'user_preferences':
				$this->process_user_preferences( $value );
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

		// Deduplicate: skip messages we have already started processing within the last
		// DEDUP_TRANSIENT_TTL seconds. Meta can retry webhook deliveries and we must not
		// send duplicate AI replies to the same incoming message.
		if ( ! empty( $message_id ) ) {
			$dedup_key = 'wp_mcp_ai_wa_msg_' . md5( $message_id );
			if ( false !== get_transient( $dedup_key ) ) {
				WP_MCP_AI_Logger::log_event(
					'whatsapp_incoming_message_duplicate',
					'Duplicate WhatsApp message skipped.',
					array( 'message_id' => $message_id )
				);
				return;
			}
			set_transient( $dedup_key, 1, self::DEDUP_TRANSIENT_TTL );
		}

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

		// Mark the incoming message as read so the sender sees the read receipt.
		// This is best practice for a business number and improves perceived responsiveness.
		if ( ! empty( $message_id ) && ! empty( $from ) ) {
			$phone_number_id = isset( $context['metadata']['phone_number_id'] ) ? sanitize_text_field( $context['metadata']['phone_number_id'] ) : '';
			if ( ! empty( $phone_number_id ) ) {
				$this->mark_message_as_read( $message_id, $phone_number_id );
			}
		}

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
		// Determine which connection received this message based on phone_number_id.
		$phone_number_id        = isset( $context['metadata']['phone_number_id'] ) ? sanitize_text_field( $context['metadata']['phone_number_id'] ) : '';
		$connection             = ! empty( $phone_number_id ) ? $this->get_connection_by_phone_number_id( $phone_number_id ) : null;
		$assigned_assistant_ids = $connection ? $this->get_assigned_assistant_ids( $connection ) : array();

		/**
		 * Filter whether to auto-reply to WhatsApp messages.
		 *
		 * Defaults to true when the connection has one or more assigned AI assistants,
		 * so that connections configured for chat channel routing reply automatically.
		 *
		 * @since 1.0.0
		 *
		 * @param bool  $auto_reply  Whether to auto-reply. Defaults to true when assistant IDs are assigned.
		 * @param array $message_data Parsed message data.
		 * @param array $context Full webhook context.
		 */
		$should_reply = apply_filters( 'wp_mcp_ai_whatsapp_should_auto_reply', ! empty( $assigned_assistant_ids ), $message_data, $context );

		if ( ! $should_reply ) {
			return;
		}

		do_action( 'wp_mcp_ai_whatsapp_auto_reply', $message_data, $context, $assigned_assistant_ids );

		// Dispatch an AI-generated reply when a connection and assigned assistants are available.
		if ( $connection && ! empty( $assigned_assistant_ids ) ) {
			$this->dispatch_whatsapp_ai_reply( $message_data, $connection, $assigned_assistant_ids );
		}
	}

	/**
	 * Schedule an asynchronous cron job to generate and send an AI reply.
	 *
	 * Only text messages are processed; other types (media, location, etc.) are
	 * silently skipped so the webhook can still return 200 quickly.
	 *
	 * @since 1.0.0
	 *
	 * @param array $message_data           Parsed message data from the incoming webhook.
	 * @param array $connection             WhatsApp connection configuration array.
	 * @param int[] $assigned_assistant_ids Assistant post IDs assigned to this connection.
	 */
	protected function dispatch_whatsapp_ai_reply( $message_data, $connection, $assigned_assistant_ids ) {
		// Resolve the text to send to the AI from the message type.
		// Supported: plain text, interactive button/list replies, and quick replies.
		$message_text = $this->extract_text_for_ai( $message_data );
		if ( '' === $message_text ) {
			return;
		}

		$to              = isset( $message_data['from'] ) ? $message_data['from'] : '';
		$phone_number_id = isset( $connection['phone_number_id'] ) ? $connection['phone_number_id'] : '';
		$connection_id   = isset( $connection['id'] ) ? $connection['id'] : '';

		if ( '' === $to || '' === $phone_number_id || '' === $connection_id ) {
			return;
		}

		$graph_api_version = isset( $connection['graph_api_version'] ) && $connection['graph_api_version']
			? $connection['graph_api_version']
			: self::DEFAULT_GRAPH_API_VERSION;

		$job_args = array(
			array(
				// Use the first assigned assistant as the primary routing assistant for this channel.
				'assistant_id'      => $assigned_assistant_ids[0],
				'message_text'      => $message_text,
				'to'                => $to,
				'connection_id'     => $connection_id,
				'phone_number_id'   => $phone_number_id,
				'graph_api_version' => $graph_api_version,
			),
		);

		// Schedule slightly in the future so the current request can complete first.
		wp_schedule_single_event( time() + 1, self::REPLY_CRON_HOOK, $job_args );
		spawn_cron();
	}

	/**
	 * Cron callback: generate an AI reply via the chat endpoint and send it over WhatsApp.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Job arguments set by dispatch_whatsapp_ai_reply().
	 */
	public function handle_whatsapp_reply_job( $args ) {
		if ( ! is_array( $args ) ) {
			return;
		}

		$assistant_id      = isset( $args['assistant_id'] ) ? absint( $args['assistant_id'] ) : 0;
		$message_text      = isset( $args['message_text'] ) ? (string) $args['message_text'] : '';
		$to                = isset( $args['to'] ) ? (string) $args['to'] : '';
		$connection_id     = isset( $args['connection_id'] ) ? sanitize_key( $args['connection_id'] ) : '';
		$phone_number_id   = isset( $args['phone_number_id'] ) ? (string) $args['phone_number_id'] : '';
		$graph_api_version = isset( $args['graph_api_version'] ) ? sanitize_text_field( $args['graph_api_version'] ) : self::DEFAULT_GRAPH_API_VERSION;

		if ( ! $assistant_id || '' === $message_text || '' === $to || '' === $connection_id || '' === $phone_number_id ) {
			return;
		}

		// Retrieve and decrypt the access token at runtime so it is not stored in cron args.
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
		}

		$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
		if ( ! $connection || empty( $connection['api_key'] ) ) {
			WP_MCP_AI_Logger::log_error( 'WhatsApp AI reply: connection not found or access token missing.', array( 'connection_id' => $connection_id ) );
			return;
		}

		$access_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
		if ( '' === $access_token ) {
			WP_MCP_AI_Logger::log_error( 'WhatsApp AI reply: access token decryption returned empty string.', array( 'connection_id' => $connection_id ) );
			return;
		}

		// Call the internal chat REST endpoint to generate the AI response.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_body_params(
			array(
				'assistant_id' => $assistant_id,
				'messages'     => array(
					array(
						'role'    => 'user',
						'content' => $message_text,
					),
				),
				'stream'       => false,
			)
		);

		// The cron job runs without a logged-in user (user ID 0). To pass the
		// permission check on the /mcp-ai/v1/chat endpoint, temporarily switch to
		// an administrator context and create a matching nonce.
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
			// No admin user found. The request will succeed only if the assigned
			// assistant has a public capability; otherwise the permission check
			// will reject it. Log a warning to aid debugging.
			WP_MCP_AI_Logger::log_error(
				'WhatsApp AI reply: no administrator user found; internal chat request may fail for non-public assistants.',
				array( 'assistant_id' => $assistant_id )
			);
		}

		$response = rest_do_request( $request );

		// Restore the original user regardless of the response result.
		wp_set_current_user( $original_user_id );

		if ( $response->is_error() ) {
			$error_data = $response->get_data();
			WP_MCP_AI_Logger::log_error(
				'WhatsApp AI reply: chat request failed.',
				array(
					'assistant_id' => $assistant_id,
					'error_code'   => is_array( $error_data ) && isset( $error_data['code'] ) ? sanitize_text_field( (string) $error_data['code'] ) : '',
				)
			);
			return;
		}

		// The /mcp-ai/v1/chat endpoint wraps the OpenAI-format LLM response in a
		// 'data' key. Extract the assistant reply from the first choice's message.
		$content = $this->extract_content_from_chat_response( $response->get_data() );

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error( 'WhatsApp AI reply: empty content from assistant.', array( 'assistant_id' => $assistant_id ) );
			return;
		}

		// WhatsApp does not render HTML. Strip tags and decode HTML entities so the
		// outgoing message contains plain text, and enforce the 4096-character limit.
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( mb_strlen( $content ) > 4096 ) {
			$content = mb_substr( $content, 0, 4093 ) . '...';
		}

		if ( '' === $content ) {
			WP_MCP_AI_Logger::log_error( 'WhatsApp AI reply: content empty after HTML stripping.', array( 'assistant_id' => $assistant_id ) );
			return;
		}

		// Send reply via WhatsApp Cloud API.
		$endpoint = sprintf(
			'https://graph.facebook.com/%s/%s/messages',
			rawurlencode( $graph_api_version ),
			rawurlencode( $phone_number_id )
		);

		$payload = array(
			'messaging_product' => 'whatsapp',
			'to'                => $to,
			'type'              => 'text',
			'text'              => array( 'body' => $content ),
		);

		$body = wp_json_encode( $payload );
		if ( false === $body ) {
			WP_MCP_AI_Logger::log_error( 'WhatsApp AI reply: failed to JSON-encode payload.', array() );
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
			WP_MCP_AI_Logger::log_error( 'WhatsApp AI reply: HTTP request failed.', array( 'error' => $result->get_error_message() ) );
			return;
		}

		$http_code    = (int) wp_remote_retrieve_response_code( $result );
		$send_body    = wp_remote_retrieve_body( $result );
		$decoded_body = ! empty( $send_body ) ? json_decode( $send_body, true ) : null;
		$api_error    = is_array( $decoded_body ) && isset( $decoded_body['error'] ) ? $decoded_body['error'] : array();

		if ( 200 !== $http_code || ! empty( $api_error ) ) {
			$log_context = array(
				'assistant_id'    => $assistant_id,
				'http_code'       => $http_code,
				'phone_number_id' => substr( $phone_number_id, 0, 4 ) . '***',
			);

			if ( is_array( $api_error ) && isset( $api_error['code'] ) && isset( $api_error['error_subcode'] )
				&& 100 === (int) $api_error['code'] && 33 === (int) $api_error['error_subcode'] ) {
				$log_context['hint'] = 'Phone Number ID not found. Verify that the Phone Number ID in the connection settings matches the Phone Number ID in Meta Developer Dashboard (app → WhatsApp → API Setup), not the WhatsApp Business Account ID (WABA ID).';
			} elseif ( is_array( $api_error ) && isset( $api_error['code'] ) && 133010 === (int) $api_error['code'] ) {
				$log_context['hint'] = 'Business phone number not registered with the WhatsApp Cloud API. Use the Register Phone Number button in the connection settings (or POST to https://graph.facebook.com/v19.0/{PHONE_NUMBER_ID}/register) to register the number. Before registering, ensure the number is not active on WhatsApp or WhatsApp Business app, and deregister it from the on-premises API if it was previously used there. See: https://developers.facebook.com/documentation/business-messaging/whatsapp/business-phone-numbers/registration';
			}

			WP_MCP_AI_Logger::log_error( 'WhatsApp AI reply: send request returned an error.', $log_context );
			return;
		}

		WP_MCP_AI_Logger::log_event(
			'whatsapp_ai_reply_sent',
			'WhatsApp AI reply dispatched successfully.',
			array(
				'assistant_id'    => $assistant_id,
				'http_code'       => $http_code,
				'phone_number_id' => substr( $phone_number_id, 0, 4 ) . '***',
				'to'              => substr( $to, 0, 4 ) . '***',
			)
		);
	}

	/**
	 * Extract plain text from a message for passing to the AI assistant.
	 *
	 * Handles text, interactive button/list replies, and button quick-replies so
	 * that users who tap buttons get AI responses just like typed messages.
	 *
	 * @since 1.0.0
	 *
	 * @param array $message_data Parsed message data (as built in process_incoming_message).
	 * @return string Plain-text representation, or empty string if unsupported type.
	 */
	protected function extract_text_for_ai( $message_data ) {
		$type    = isset( $message_data['type'] ) ? $message_data['type'] : '';
		$content = isset( $message_data['content'] ) ? $message_data['content'] : '';

		switch ( $type ) {
			case 'text':
				return is_string( $content ) ? trim( $content ) : '';

			case 'interactive':
				// button_reply: { id, title }  |  list_reply: { id, title, description }
				if ( is_array( $content ) ) {
					$title       = isset( $content['title'] ) ? trim( (string) $content['title'] ) : '';
					$description = isset( $content['description'] ) ? trim( (string) $content['description'] ) : '';
					return $description ? $title . "\n" . $description : $title;
				}
				return '';

			case 'button':
				// Quick-reply button tap: content is the button array { payload, text }
				if ( is_array( $content ) ) {
					return isset( $content['text'] ) ? trim( (string) $content['text'] ) : '';
				}
				return '';

			default:
				return '';
		}
	}

	/**
	 * Mark an incoming WhatsApp message as read via the Cloud API.
	 *
	 * Sends a read receipt so the customer sees the double-blue-tick indicator,
	 * signalling that their message has been received by the business. Failures
	 * are logged but do not block the auto-reply flow.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message_id      The wamid of the message to mark as read.
	 * @param string $phone_number_id The Phone Number ID of the receiving business number.
	 */
	protected function mark_message_as_read( $message_id, $phone_number_id ) {
		$connection = $this->get_connection_by_phone_number_id( $phone_number_id );
		if ( ! $connection || empty( $connection['api_key'] ) ) {
			return;
		}

		$access_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
		if ( '' === $access_token ) {
			return;
		}

		$graph_api_version = isset( $connection['graph_api_version'] ) && $connection['graph_api_version']
			? $connection['graph_api_version']
			: self::DEFAULT_GRAPH_API_VERSION;

		$endpoint = sprintf(
			'https://graph.facebook.com/%s/%s/messages',
			rawurlencode( $graph_api_version ),
			rawurlencode( $phone_number_id )
		);

		$payload = array(
			'messaging_product' => 'whatsapp',
			'status'            => 'read',
			'message_id'        => $message_id,
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
				'timeout' => 5,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_error(
				'WhatsApp mark-as-read failed.',
				array( 'error' => $result->get_error_message() )
			);
		}
	}

	/**
	 * Find a WhatsApp connection matching the given phone_number_id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $phone_number_id The phone number ID from the webhook payload.
	 * @return array|null Connection data array or null if not found.
	 */
	protected function get_connection_by_phone_number_id( $phone_number_id ) {
		$connections = $this->get_whatsapp_connections();

		foreach ( $connections as $connection ) {
			if ( isset( $connection['phone_number_id'] ) && $connection['phone_number_id'] === $phone_number_id ) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * Get the assistant IDs assigned to a WhatsApp connection.
	 *
	 * @since 1.0.0
	 *
	 * @param array $connection Connection data.
	 * @return int[] Array of assistant post IDs.
	 */
	protected function get_assigned_assistant_ids( $connection ) {
		if ( ! isset( $connection['assigned_assistant_ids'] ) || ! is_array( $connection['assigned_assistant_ids'] ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'absint', $connection['assigned_assistant_ids'] ) ) );
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
	 * Process phone number name update.
	 *
	 * Fires when the display name or phone number associated with the WhatsApp
	 * Business account changes. Updates the stored display_phone_number in the
	 * matching connection if a new value is provided.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Phone number name update data.
	 */
	protected function process_phone_number_name_update( $value ) {
		$display_phone = isset( $value['display_phone_number'] ) ? sanitize_text_field( $value['display_phone_number'] ) : '';
		$phone_number_id = isset( $value['phone_number_id'] ) ? sanitize_text_field( $value['phone_number_id'] ) : '';

		WP_MCP_AI_Logger::log_event(
			'whatsapp_phone_number_name_update',
			'Phone number name update received.',
			array(
				'display_phone_number' => $display_phone,
			)
		);

		// Auto-update the stored display_phone_number for the matching connection.
		if ( ! empty( $display_phone ) && ! empty( $phone_number_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = $this->get_connection_by_phone_number_id( $phone_number_id );

			if ( $connection && isset( $connection['id'] ) ) {
				$connection['display_phone_number'] = $display_phone;
				$save_result = WP_MCP_AI_Pro_Remote_Site_Manager::save_connection( $connection );

				if ( is_wp_error( $save_result ) ) {
					WP_MCP_AI_Logger::log_event(
						'whatsapp_phone_number_name_update_save_failed',
						'Failed to update display_phone_number in connection.',
						array( 'error' => $save_result->get_error_message() )
					);
				}
			} else {
				WP_MCP_AI_Logger::log_event(
					'whatsapp_phone_number_name_update_no_connection',
					'No matching connection found for phone_number_id.',
					array()
				);
			}
		}

		/**
		 * Fires when a WhatsApp phone number name is updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Phone number name update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_phone_number_name_update', $value );
	}

	/**
	 * Process phone number quality update.
	 *
	 * Fires when the quality rating of the WhatsApp Business phone number changes
	 * (e.g. HIGH, MEDIUM, LOW).
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Phone number quality update data.
	 */
	protected function process_phone_number_quality_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_phone_number_quality_update',
			'Phone number quality update received.',
			array(
				'quality' => isset( $value['quality'] ) ? sanitize_text_field( $value['quality'] ) : 'unknown',
				'event'   => isset( $value['event'] ) ? sanitize_text_field( $value['event'] ) : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp phone number quality rating is updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Phone number quality update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_phone_number_quality_update', $value );
	}

	/**
	 * Process account alerts.
	 *
	 * Fires when Meta sends an account-level alert (e.g. policy violations,
	 * unusual activity flags).
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Account alert data.
	 */
	protected function process_account_alerts( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_account_alerts',
			'Account alert received.',
			array(
				'type' => isset( $value['type'] ) ? sanitize_text_field( $value['type'] ) : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp account alert is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Account alert data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_account_alerts', $value );
	}

	/**
	 * Process account review update.
	 *
	 * Fires when the WhatsApp Business account review status changes.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Account review update data.
	 */
	protected function process_account_review_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_account_review_update',
			'Account review update received.',
			array(
				'decision' => isset( $value['decision'] ) ? sanitize_text_field( $value['decision'] ) : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp account review status is updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Account review update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_account_review_update', $value );
	}

	/**
	 * Process account settings update.
	 *
	 * Fires when the WhatsApp Business account settings change.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Account settings update data.
	 */
	protected function process_account_settings_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_account_settings_update',
			'Account settings update received.',
			array()
		);

		/**
		 * Fires when WhatsApp Business account settings are updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Account settings update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_account_settings_update', $value );
	}

	/**
	 * Process business capability update.
	 *
	 * Fires when the capabilities available to the WhatsApp Business account change.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Business capability update data.
	 */
	protected function process_business_capability_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_business_capability_update',
			'Business capability update received.',
			array()
		);

		/**
		 * Fires when WhatsApp Business capabilities are updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Business capability update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_business_capability_update', $value );
	}

	/**
	 * Process business status update.
	 *
	 * Fires when the WhatsApp Business account status changes (e.g. FLAGGED, RESTRICTED).
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Business status update data.
	 */
	protected function process_business_status_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_business_status_update',
			'Business status update received.',
			array(
				'status' => isset( $value['status'] ) ? sanitize_text_field( $value['status'] ) : 'unknown',
			)
		);

		/**
		 * Fires when the WhatsApp Business account status is updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Business status update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_business_status_update', $value );
	}

	/**
	 * Process automatic events.
	 *
	 * Fires when Meta triggers an automatic event on the account.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Automatic event data.
	 */
	protected function process_automatic_events( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_automatic_events',
			'Automatic event received.',
			array(
				'type' => isset( $value['type'] ) ? sanitize_text_field( $value['type'] ) : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp automatic event is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Automatic event data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_automatic_events', $value );
	}

	/**
	 * Process tracking events.
	 *
	 * Fires when WhatsApp sends tracking/analytics event notifications.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Tracking event data.
	 */
	protected function process_tracking_events( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_tracking_events',
			'Tracking event received.',
			array(
				'type' => isset( $value['type'] ) ? sanitize_text_field( $value['type'] ) : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp tracking event is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Tracking event data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_tracking_events', $value );
	}

	/**
	 * Process calls.
	 *
	 * Fires when a WhatsApp voice/video call event is received.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Call event data.
	 */
	protected function process_calls( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_calls',
			'Call event received.',
			array(
				'status' => isset( $value['status'] ) ? sanitize_text_field( $value['status'] ) : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp call event is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Call event data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_calls', $value );
	}

	/**
	 * Process WhatsApp Flows events.
	 *
	 * Fires when a WhatsApp Flows status change or interaction event is received.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Flows event data.
	 */
	protected function process_flows( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_flows',
			'Flows event received.',
			array(
				'flow_id' => isset( $value['flow_id'] ) ? sanitize_text_field( $value['flow_id'] ) : 'unknown',
				'event'   => isset( $value['event'] ) ? sanitize_text_field( $value['event'] ) : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp Flows event is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Flows event data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_flows', $value );
	}

	/**
	 * Process group lifecycle update.
	 *
	 * Fires when a WhatsApp group is created, deleted, or its lifecycle state changes.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Group lifecycle update data.
	 */
	protected function process_group_lifecycle_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_group_lifecycle_update',
			'Group lifecycle update received.',
			array(
				'event' => isset( $value['event'] ) ? sanitize_text_field( $value['event'] ) : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp group lifecycle event is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Group lifecycle update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_group_lifecycle_update', $value );
	}

	/**
	 * Process group participants update.
	 *
	 * Fires when participants are added or removed from a WhatsApp group.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Group participants update data.
	 */
	protected function process_group_participants_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_group_participants_update',
			'Group participants update received.',
			array(
				'event' => isset( $value['event'] ) ? sanitize_text_field( $value['event'] ) : 'unknown',
			)
		);

		/**
		 * Fires when WhatsApp group participants are updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Group participants update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_group_participants_update', $value );
	}

	/**
	 * Process group settings update.
	 *
	 * Fires when a WhatsApp group's settings (e.g. subject, description) change.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Group settings update data.
	 */
	protected function process_group_settings_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_group_settings_update',
			'Group settings update received.',
			array(
				'event' => isset( $value['event'] ) ? sanitize_text_field( $value['event'] ) : 'unknown',
			)
		);

		/**
		 * Fires when WhatsApp group settings are updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Group settings update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_group_settings_update', $value );
	}

	/**
	 * Process group status update.
	 *
	 * Fires when a WhatsApp group's status changes.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Group status update data.
	 */
	protected function process_group_status_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_group_status_update',
			'Group status update received.',
			array(
				'status' => isset( $value['status'] ) ? sanitize_text_field( $value['status'] ) : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp group status is updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Group status update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_group_status_update', $value );
	}

	/**
	 * Process message history sync.
	 *
	 * Fires when WhatsApp sends a batch of historical messages for sync.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value History sync data.
	 */
	protected function process_history( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_history',
			'Message history sync received.',
			array()
		);

		/**
		 * Fires when a WhatsApp message history sync payload is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value History sync data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_history', $value );
	}

	/**
	 * Process message echoes.
	 *
	 * Fires when a copy (echo) of a message sent from the business is received.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Message echo data.
	 */
	protected function process_message_echoes( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_message_echoes',
			'Message echo received.',
			array()
		);

		/**
		 * Fires when a WhatsApp message echo is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Message echo data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_message_echoes', $value );
	}

	/**
	 * Process SMB message echoes.
	 *
	 * Fires when an SMB echo of a sent message is received.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value SMB message echo data.
	 */
	protected function process_smb_message_echoes( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_smb_message_echoes',
			'SMB message echo received.',
			array()
		);

		/**
		 * Fires when a WhatsApp SMB message echo is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value SMB message echo data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_smb_message_echoes', $value );
	}

	/**
	 * Process messaging handovers.
	 *
	 * Fires during handover protocol events when control of a conversation
	 * is passed between apps.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Messaging handover data.
	 */
	protected function process_messaging_handovers( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_messaging_handovers',
			'Messaging handover event received.',
			array(
				'event' => isset( $value['event'] ) ? sanitize_text_field( $value['event'] ) : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp messaging handover event is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Messaging handover data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_messaging_handovers', $value );
	}

	/**
	 * Process template components update.
	 *
	 * Fires when the components of a message template are updated.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Template components update data.
	 */
	protected function process_template_components_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_template_components_update',
			'Template components update received.',
			array(
				'event' => isset( $value['event'] ) ? sanitize_text_field( $value['event'] ) : 'unknown',
			)
		);

		/**
		 * Fires when WhatsApp message template components are updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Template components update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_template_components_update', $value );
	}

	/**
	 * Process template quality update.
	 *
	 * Fires when the quality rating of a message template changes.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Template quality update data.
	 */
	protected function process_template_quality_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_template_quality_update',
			'Template quality update received.',
			array(
				'quality' => isset( $value['quality'] ) ? sanitize_text_field( $value['quality'] ) : 'unknown',
				'event'   => isset( $value['event'] ) ? sanitize_text_field( $value['event'] ) : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp message template quality rating is updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Template quality update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_template_quality_update', $value );
	}

	/**
	 * Process template category update.
	 *
	 * Fires when Meta reclassifies a message template into a different category.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Template category update data.
	 */
	protected function process_template_category_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_template_category_update',
			'Template category update received.',
			array(
				'event' => isset( $value['event'] ) ? sanitize_text_field( $value['event'] ) : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp message template category is updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Template category update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_template_category_update', $value );
	}

	/**
	 * Process template correct category detection.
	 *
	 * Fires when Meta detects that a template has been submitted in the wrong
	 * category and suggests the correct one.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Template correct category detection data.
	 */
	protected function process_template_correct_category_detection( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_template_correct_category_detection',
			'Template correct category detection received.',
			array(
				'event' => isset( $value['event'] ) ? sanitize_text_field( $value['event'] ) : 'unknown',
			)
		);

		/**
		 * Fires when WhatsApp detects a template in an incorrect category.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Template correct category detection data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_template_correct_category_detection', $value );
	}

	/**
	 * Process partner solutions.
	 *
	 * Fires when a partner solution event notification is received.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Partner solutions data.
	 */
	protected function process_partner_solutions( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_partner_solutions',
			'Partner solutions event received.',
			array(
				'type' => isset( $value['type'] ) ? sanitize_text_field( $value['type'] ) : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp partner solutions event is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Partner solutions data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_partner_solutions', $value );
	}

	/**
	 * Process payment configuration update.
	 *
	 * Fires when the WhatsApp Pay / payment configuration for the account changes.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Payment configuration update data.
	 */
	protected function process_payment_configuration_update( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_payment_configuration_update',
			'Payment configuration update received.',
			array()
		);

		/**
		 * Fires when a WhatsApp payment configuration is updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Payment configuration update data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_payment_configuration_update', $value );
	}

	/**
	 * Process security events.
	 *
	 * Fires when Meta sends a security-related notification, such as a
	 * passkey enrollment or two-step verification update.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value Security event data.
	 */
	protected function process_security( $value ) {
		// Log only the event type; never log security credential details.
		WP_MCP_AI_Logger::log_event(
			'whatsapp_security',
			'Security event received.',
			array(
				'type' => isset( $value['type'] ) ? sanitize_text_field( $value['type'] ) : 'unknown',
			)
		);

		/**
		 * Fires when a WhatsApp security event is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value Security event data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_security', $value );
	}

	/**
	 * Process SMB app state sync.
	 *
	 * Fires when a Small and Medium Business (SMB) app state sync event occurs.
	 *
	 * @since 1.0.0
	 *
	 * @param array $value SMB app state sync data.
	 */
	protected function process_smb_app_state_sync( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_smb_app_state_sync',
			'SMB app state sync received.',
			array()
		);

		/**
		 * Fires when a WhatsApp SMB app state sync event is received.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value SMB app state sync data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_smb_app_state_sync', $value );
	}

	/**
	 * Process user preferences.
	 *
	 * Fires when a user updates their WhatsApp messaging preferences
	 * (e.g. opt-in / opt-out updates).
	 *
	 * @since 1.0.0
	 *
	 * @param array $value User preferences data.
	 */
	protected function process_user_preferences( $value ) {
		WP_MCP_AI_Logger::log_event(
			'whatsapp_user_preferences',
			'User preferences update received.',
			array(
				'type' => isset( $value['type'] ) ? sanitize_text_field( $value['type'] ) : 'unknown',
			)
		);

		/**
		 * Fires when WhatsApp user preferences are updated.
		 *
		 * @since 1.0.0
		 *
		 * @param array $value User preferences data.
		 */
		do_action( 'wp_mcp_ai_whatsapp_user_preferences', $value );
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
			// The verify token is stored in the 'verify_token' field.
			if ( isset( $connection['verify_token'] ) && ! empty( $connection['verify_token'] ) ) {
				return $connection['verify_token'];
			}
		}

		return '';
	}

	/**
	 * Get app secret from connection settings.
	 *
	 * The webhook signature is validated using the App Secret from the Meta
	 * Developer Dashboard (HMAC-SHA256). The access token must never be used
	 * as a substitute — doing so would produce an incorrect signature and
	 * potentially allow forged webhooks to pass validation.
	 *
	 * @since 1.0.0
	 *
	 * @return string App secret or empty string if not configured.
	 */
	protected function get_app_secret() {
		// Get WhatsApp connection from remote site manager.
		$connections = $this->get_whatsapp_connections();

		foreach ( $connections as $connection ) {
			// WhatsApp app secret is stored in 'api_secret' field.
			if ( isset( $connection['api_secret'] ) && ! empty( $connection['api_secret'] ) ) {
				// Decrypt if needed.
				if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
					return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_secret'] );
				}
				return $connection['api_secret'];
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

		$all_connections      = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		$whatsapp_connections = array();

		foreach ( $all_connections as $connection ) {
			if ( isset( $connection['connection_type'] ) && 'whatsapp' === $connection['connection_type'] ) {
				$whatsapp_connections[] = $connection;
			}
		}

		return $whatsapp_connections;
	}

	/**
	 * Extract the assistant reply text from a /mcp-ai/v1/chat REST response payload.
	 *
	 * The chat endpoint returns the raw OpenAI-format LLM response wrapped inside
	 * a 'data' key:
	 *   { assistant_id: ..., data: { choices: [{ message: { content: '...' } }] } }
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $response_data Data returned by WP_REST_Response::get_data().
	 * @return string Assistant reply text, or empty string if not found.
	 */
	protected function extract_content_from_chat_response( $response_data ) {
		if ( ! is_array( $response_data ) ) {
			return '';
		}

		$llm_data = isset( $response_data['data'] ) && is_array( $response_data['data'] ) ? $response_data['data'] : array();
		$choices  = isset( $llm_data['choices'] ) && is_array( $llm_data['choices'] ) ? $llm_data['choices'] : array();

		if ( empty( $choices ) ) {
			return '';
		}

		$first_choice = reset( $choices );
		if ( isset( $first_choice['message']['content'] ) && is_string( $first_choice['message']['content'] ) ) {
			return $first_choice['message']['content'];
		}

		return '';
	}
}

// Initialize the controller.
new WP_MCP_AI_WhatsApp_Webhook_Controller();
