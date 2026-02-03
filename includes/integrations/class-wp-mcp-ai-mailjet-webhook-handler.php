<?php
/**
 * Mailjet Webhook Handler for WP MCP AI
 *
 * Handles webhook events from Mailjet API for email event tracking.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' )) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Mailjet_Webhook_Handler' ) ) {
	/**
	 * Processes webhook events from Mailjet.
	 */
	class WP_MCP_AI_Mailjet_Webhook_Handler {

		/**
		 * Register REST API routes for webhook handling.
		 */
		public function register_routes() {
			register_rest_route(
				'mcp-ai/v1',
				'/webhooks/mailjet',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_webhook' ),
					'permission_callback' => array( $this, 'verify_webhook_request' ),
				)
			);
		}

		/**
		 * Verify the webhook request is from Mailjet.
		 *
		 * @param WP_REST_Request $request The request object.
		 * @return bool True if verified.
		 */
		public function verify_webhook_request( $request ) {
			// Always allow webhook requests if no secret is configured.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$secret   = isset( $settings['mailjet_webhook_secret'] ) ? trim( $settings['mailjet_webhook_secret'] ) : '';

			if ( '' === $secret ) {
				// No secret configured, accept all requests.
				return true;
			}

			// Verify signature if secret is configured.
			$signature = $request->get_header( 'X-Mailjet-Signature' );
			if ( empty( $signature ) ) {
				WP_MCP_AI_Admin_Settings::log( 'Mailjet webhook rejected: missing signature', array( 'ip' => $_SERVER['REMOTE_ADDR'] ) );
				return false;
			}

			$body          = $request->get_body();
			$expected_hash = hash_hmac( 'sha256', $body, $secret );

			if ( ! hash_equals( $expected_hash, $signature ) ) {
				WP_MCP_AI_Admin_Settings::log( 'Mailjet webhook rejected: invalid signature', array( 'ip' => $_SERVER['REMOTE_ADDR'] ) );
				return false;
			}

			return true;
		}

		/**
		 * Handle the webhook POST request.
		 *
		 * @param WP_REST_Request $request The request object.
		 * @return WP_REST_Response Response object.
		 */
		public function handle_webhook( $request ) {
			$events = $request->get_json_params();

			if ( empty( $events ) || ! is_array( $events ) ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => 'Invalid webhook payload',
					),
					400
				);
			}

			$processed = 0;

			foreach ( $events as $event ) {
				if ( ! is_array( $event ) || empty( $event['event'] ) ) {
					continue;
				}

				$this->process_event( $event );
				$processed++;
			}

			// Log successful processing.
			WP_MCP_AI_Admin_Settings::log(
				'Mailjet webhook processed',
				array(
					'events_processed' => $processed,
					'total_events'     => count( $events ),
				)
			);

			/**
			 * Action hook fired after Mailjet webhook events are processed.
			 *
			 * @since 1.0.0
			 *
			 * @param array $events All events from the webhook.
			 * @param int   $processed Number of events processed.
			 */
			do_action( 'wp_mcp_ai_mailjet_webhook_processed', $events, $processed );

			return new WP_REST_Response(
				array(
					'success'  => true,
					'processed' => $processed,
				),
				200
			);
		}

		/**
		 * Process a single webhook event.
		 *
		 * @param array $event Event data from Mailjet.
		 */
		protected function process_event( $event ) {
			$event_type = sanitize_key( $event['event'] );
			$email      = isset( $event['email'] ) ? sanitize_email( $event['email'] ) : '';
			$time       = isset( $event['time'] ) ? absint( $event['time'] ) : time();

			// Store event in WordPress options (last 100 events).
			$stored_events = get_option( 'wp_mcp_ai_mailjet_events', array() );

			if ( ! is_array( $stored_events ) ) {
				$stored_events = array();
			}

			$stored_events[] = array(
				'event'      => $event_type,
				'email'      => $email,
				'time'       => $time,
				'data'       => $event,
				'created_at' => current_time( 'mysql' ),
			);

			// Keep only last 100 events.
			if ( count( $stored_events ) > 100 ) {
				$stored_events = array_slice( $stored_events, -100 );
			}

			update_option( 'wp_mcp_ai_mailjet_events', $stored_events, false );

			/**
			 * Action hook fired for each Mailjet webhook event.
			 *
			 * @since 1.0.0
			 *
			 * @param string $event_type Type of event (open, click, bounce, etc.).
			 * @param string $email Email address associated with the event.
			 * @param array  $event Full event data from Mailjet.
			 */
			do_action( 'wp_mcp_ai_mailjet_event', $event_type, $email, $event );
			do_action( "wp_mcp_ai_mailjet_event_{$event_type}", $email, $event );
		}

		/**
		 * Get recent webhook events.
		 *
		 * @param int $limit Number of events to retrieve.
		 * @return array Recent events.
		 */
		public static function get_recent_events( $limit = 10 ) {
			$events = get_option( 'wp_mcp_ai_mailjet_events', array() );

			if ( ! is_array( $events ) ) {
				return array();
			}

			$events = array_slice( $events, -absint( $limit ) );

			return array_reverse( $events );
		}
	}
}
