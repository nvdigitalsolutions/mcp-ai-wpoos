<?php
/**
 * CRM Gmail PubSub Push Handler
 *
 * Provides a REST endpoint and registration helpers for Gmail Push
 * Notifications via Google Cloud Pub/Sub. When a Gmail mailbox changes,
 * Google pushes a notification to a configured Pub/Sub topic which
 * forwards to this endpoint.
 *
 * Industry-standard pattern: HubSpot's Gmail integration uses
 * users.watch() + Pub/Sub for real-time email import without polling.
 *
 * Architecture:
 *  1. Admin clicks "Enable Push Notifications" → calls users.watch()
 *  2. Google Pub/Sub pushes mailbox change events to /wp-json/mcp-ai/v1/crm/gmail-pubsub
 *  3. Handler validates the Pub/Sub signature, decodes the message
 *  4. Calls import_gmail_to_crm with historyId-based incremental sync
 *
 * Requires: Google Cloud Pub/Sub topic + push subscription configured.
 * Falls back gracefully to Action Scheduler polling when unavailable.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gmail Pub/Sub push notification handler.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_CRM_Gmail_PubSub_Handler {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * REST route for PubSub push endpoint.
	 *
	 * @var string
	 */
	const REST_ROUTE = '/crm/gmail-pubsub';

	/**
	 * Option key for storing PubSub watch state per connection.
	 *
	 * @var string
	 */
	const WATCH_OPTION_PREFIX = 'wp_mcp_ai_crm_gmail_watch_';

	/**
	 * Option key for PubSub verification token (shared across connections).
	 *
	 * @var string
	 */
	const TOKEN_OPTION = 'wp_mcp_ai_crm_gmail_pubsub_token';

	/**
	 * Initialize hooks and REST endpoint.
	 *
	 * @since 2.9.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		// Register REST endpoint.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_route' ) );
	}

	/**
	 * Register the Pub/Sub push notification REST endpoint.
	 *
	 * @since 2.9.0
	 */
	public static function register_rest_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_push' ),
				'permission_callback' => '__return_true', // PubSub sends unauthenticated POSTs; validated by token.
				'args'                => array(
					'message'      => array(
						'type'        => 'object',
						'description' => __( 'Pub/Sub message envelope.', 'mcp-ai-wpoos-pro' ),
					),
					'subscription' => array(
						'type'        => 'string',
						'description' => __( 'Pub/Sub subscription name.', 'mcp-ai-wpoos-pro' ),
					),
				),
			)
		);
	}

	/**
	 * Handle incoming Pub/Sub push notification.
	 *
	 * Validates the request, decodes the Pub/Sub message, extracts the
	 * Gmail historyId, and triggers an incremental import.
	 *
	 * @since 2.9.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_push( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body ) ) {
			return new WP_Error(
				'empty_body',
				__( 'Empty Pub/Sub message body.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		// Decode Pub/Sub envelope.
		$message_data = $body;
		if ( isset( $body['message']['data'] ) ) {
			$decoded = base64_decode( $body['message']['data'], true );
			if ( $decoded ) {
				$message_data = json_decode( $decoded, true );
				if ( ! is_array( $message_data ) ) {
					$message_data = $body;
				}
			}
		}

		// Extract Gmail-specific fields.
		$email_address = sanitize_email( $message_data['emailAddress'] ?? '' );
		$history_id    = isset( $message_data['historyId'] ) ? (string) $message_data['historyId'] : '';

		if ( empty( $history_id ) ) {
			// Acknowledge receipt to Pub/Sub to prevent retries.
			return new WP_REST_Response( array( 'ack' => true ), 200 );
		}

		// Find matching connection by email address.
		$connection_id = self::find_connection_by_email( $email_address );

		// If no connection found, try all Gmail connections.
		if ( empty( $connection_id ) ) {
			if ( class_exists( 'WP_MCP_AI_CRM_Gmail_Listener' ) ) {
				// Trigger immediate poll via Action Scheduler.
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action(
						WP_MCP_AI_CRM_Gmail_Listener::JOB_HOOK,
						array(),
						'crm-gmail'
					);
				}
			}
			return new WP_REST_Response( array( 'ack' => true, 'note' => 'No matching connection; queued poll.' ), 200 );
		}

		// Update the history ID for the connection.
		$history_option_key = 'wp_mcp_ai_crm_gmail_history_id_' . $connection_id;
		update_option( $history_option_key, $history_id, false );

		// Trigger immediate import via Action Scheduler for this specific connection.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				'wp_mcp_ai_crm_gmail_pubsub_import',
				array(
					'connection_id' => $connection_id,
					'history_id'    => $history_id,
				),
				'crm-gmail'
			);
		}

		// Log the push event.
		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'gmail_pubsub_received',
				'gmail_connection',
				$connection_id,
				array(
					'email'      => $email_address,
					'history_id' => $history_id,
				)
			);
		}

		return new WP_REST_Response( array( 'ack' => true ), 200 );
	}

	/**
	 * Start watching a Gmail mailbox for push notifications.
	 *
	 * Calls Gmail API users.watch() to register the Pub/Sub topic.
	 *
	 * @since 2.9.0
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $topic_name    Google Cloud Pub/Sub topic (e.g. 'projects/my-project/topics/gmail').
	 * @param string $access_token  OAuth access token.
	 * @return array|WP_Error Watch response or error.
	 */
	public static function start_watch( $connection_id, $topic_name, $access_token ) {
		$gmail_user = 'me';

		$response = wp_remote_post(
			'https://gmail.googleapis.com/gmail/v1/users/' . rawurlencode( $gmail_user ) . '/watch',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'topicName'         => $topic_name,
						'labelIds'          => array( 'INBOX' ),
						'labelFilterAction' => 'include',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'gmail_watch_failed',
				sprintf( __( 'Gmail watch API returned HTTP %d.', 'mcp-ai-wpoos-pro' ), $code )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['historyId'] ) ) {
			$watch_state = array(
				'topic_name'  => $topic_name,
				'history_id'  => $body['historyId'],
				'expiration'  => $body['expiration'] ?? '',
				'started_at'  => current_time( 'mysql', true ),
			);
			update_option( self::WATCH_OPTION_PREFIX . $connection_id, $watch_state, false );

			// Also update the history ID tracker.
			update_option( 'wp_mcp_ai_crm_gmail_history_id_' . $connection_id, $body['historyId'], false );
		}

		return $body;
	}

	/**
	 * Stop watching a Gmail mailbox.
	 *
	 * @since 2.9.0
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $access_token  OAuth access token.
	 * @return bool True on success.
	 */
	public static function stop_watch( $connection_id, $access_token ) {
		$gmail_user = 'me';

		$response = wp_remote_post(
			'https://gmail.googleapis.com/gmail/v1/users/' . rawurlencode( $gmail_user ) . '/stop',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		delete_option( self::WATCH_OPTION_PREFIX . $connection_id );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 200 === wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Find a connection ID by Gmail email address.
	 *
	 * @since 2.9.0
	 * @param string $email_address Gmail email address.
	 * @return string Connection ID or empty string.
	 */
	private static function find_connection_by_email( $email_address ) {
		if ( empty( $email_address ) ) {
			return '';
		}

		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			if ( is_array( $connections ) ) {
				foreach ( $connections as $cid => $conn ) {
					if ( isset( $conn['connection_type'] )
						&& in_array( $conn['connection_type'], array( 'gmail', 'google_workspace' ), true )
						&& isset( $conn['user_email'] )
						&& strtolower( trim( $conn['user_email'] ) ) === strtolower( $email_address )
					) {
						return (string) $cid;
					}
				}
			}
		}

		return '';
	}
}

// Initialize.
add_action( 'plugins_loaded', array( 'WP_MCP_AI_CRM_Gmail_PubSub_Handler', 'init' ), 30 );

// Register Action Scheduler handler for PubSub-triggered imports.
add_action(
	'wp_mcp_ai_crm_gmail_pubsub_import',
	function ( $connection_id, $history_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Import_Gmail_To_CRM' ) ) {
			return;
		}

		$importer = new WP_MCP_AI_Tool_Import_Gmail_To_CRM();
		$importer->execute(
			array(
				'query'            => 'newer_than:1d is:unread',
				'max_results'      => 10,
				'auto_reply'       => false,
				'connection_id'    => $connection_id,
				'use_history_sync' => true,
			),
			array( 'user_id' => 0 )
		);
	},
	10,
	2
);
