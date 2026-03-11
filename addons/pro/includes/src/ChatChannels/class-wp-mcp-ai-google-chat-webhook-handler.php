<?php
/**
 * Google Chat Webhook Handler for WP MCP AI Pro
 *
 * Handles incoming webhook events from the Google Chat API including
 * MESSAGE events (DMs and Space @mentions), ADDED_TO_SPACE, and
 * REMOVED_FROM_SPACE lifecycle events.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Google_Chat_Webhook_Handler' ) ) {

	/**
	 * Processes incoming webhook events from Google Chat.
	 *
	 * Google Chat sends events to a registered endpoint URL. This handler:
	 * - Receives MESSAGE events triggered by DMs or @mentions in Spaces.
	 * - Strips the <users/USER_ID> mention markup from message text.
	 * - Returns a valid JSON response with HTTP 200 within Google's 5-second timeout.
	 * - Fires action hooks for custom handling of each event type.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Google_Chat_Webhook_Handler {

		/**
		 * Supported Google Chat event types.
		 */
		const EVENT_MESSAGE            = 'MESSAGE';
		const EVENT_ADDED_TO_SPACE     = 'ADDED_TO_SPACE';
		const EVENT_REMOVED_FROM_SPACE = 'REMOVED_FROM_SPACE';
		const EVENT_CARD_CLICKED       = 'CARD_CLICKED';

		/**
		 * Register REST API routes for Google Chat webhook handling.
		 *
		 * @since 1.0.0
		 */
		public function register_routes() {
			register_rest_route(
				'mcp-ai/v1',
				'/webhooks/google-chat',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_webhook' ),
					'permission_callback' => '__return_true',
				)
			);
		}

		/**
		 * Handle the incoming Google Chat webhook POST request.
		 *
		 * Google Chat expects a response within 5 seconds. This handler
		 * processes the event immediately and returns a JSON text response.
		 *
		 * @since 1.0.0
		 *
		 * @param WP_REST_Request $request The request object.
		 * @return WP_REST_Response Response object.
		 */
		public function handle_webhook( $request ) {
			$event = $request->get_json_params();

			if ( empty( $event ) || ! is_array( $event ) ) {
				return new WP_REST_Response( array( 'text' => '' ), 200 );
			}

			$event_type = isset( $event['type'] ) ? sanitize_text_field( $event['type'] ) : '';

			if ( ! in_array( $event_type, $this->get_supported_event_types(), true ) ) {
				// Unknown event type — acknowledge with empty 200.
				return new WP_REST_Response( array( 'text' => '' ), 200 );
			}

			WP_MCP_AI_Logger::log_event(
				'google_chat_webhook_received',
				'Google Chat webhook event received.',
				array( 'event_type' => $event_type )
			);

			$response_text = $this->dispatch_event( $event_type, $event );

			/**
			 * Filter the final text response sent back to Google Chat.
			 *
			 * Return an empty string to suppress any response. Return a non-empty
			 * string to send that text back to the space or DM.
			 *
			 * @since 1.0.0
			 *
			 * @param string $response_text The text to send as a reply.
			 * @param string $event_type    The Google Chat event type.
			 * @param array  $event         The full event payload.
			 */
			$response_text = apply_filters( 'wp_mcp_ai_google_chat_response_text', $response_text, $event_type, $event );

			// Google Chat requires HTTP 200 with a JSON body. An empty 'text' key
			// is valid and suppresses any visible reply.
			return new WP_REST_Response( array( 'text' => (string) $response_text ), 200 );
		}

		/**
		 * Dispatch the event to the appropriate handler method.
		 *
		 * @since 1.0.0
		 *
		 * @param string $event_type The Google Chat event type.
		 * @param array  $event      The full event payload.
		 * @return string Response text to send back (may be empty).
		 */
		protected function dispatch_event( $event_type, array $event ) {
			switch ( $event_type ) {
				case self::EVENT_MESSAGE:
					return $this->handle_message_event( $event );

				case self::EVENT_ADDED_TO_SPACE:
					return $this->handle_added_to_space_event( $event );

				case self::EVENT_REMOVED_FROM_SPACE:
					$this->handle_removed_from_space_event( $event );
					return '';

				case self::EVENT_CARD_CLICKED:
					$this->handle_card_clicked_event( $event );
					return '';

				default:
					return '';
			}
		}

		/**
		 * Handle a MESSAGE event from Google Chat.
		 *
		 * This covers both direct messages and @mentions in Spaces.
		 * The raw message text in a Space mention looks like:
		 *   "<users/123456789> what can you do?"
		 *
		 * Google provides an "argumentText" field on the message that already
		 * has the app @mention stripped. That field is used as the primary
		 * source of clean text (regardless of the app display name or any
		 * space characters it may contain). The regex-based stripping is only
		 * applied as a fallback when argumentText is absent.
		 *
		 * @since 1.0.0
		 *
		 * @param array $event Full Google Chat event payload.
		 * @return string Response text.
		 */
		protected function handle_message_event( array $event ) {
			$message    = isset( $event['message'] ) && is_array( $event['message'] ) ? $event['message'] : array();
			$space      = isset( $event['space'] ) && is_array( $event['space'] ) ? $event['space'] : array();
			$space_type = isset( $space['type'] ) ? sanitize_text_field( $space['type'] ) : '';
			$raw_text   = isset( $message['text'] ) ? $message['text'] : '';

			// Google provides "argumentText" with the app @mention already removed.
			// This is the canonical way to get clean text and is immune to variations
			// in the app display name (e.g. names with spaces like "NV oOS").
			// Fall back to our regex-based stripping when the field is absent.
			if ( isset( $message['argumentText'] ) && '' !== trim( $message['argumentText'] ) ) {
				$clean_text = trim( $message['argumentText'] );
			} else {
				$clean_text = $this->strip_mention_markup( $raw_text );
			}

			/**
			 * Action fired when a Google Chat MESSAGE event is received.
			 *
			 * @since 1.0.0
			 *
			 * @param string $clean_text Sanitized message text with mention markup removed.
			 * @param string $raw_text   Original message text as received from Google Chat.
			 * @param string $space_type Space type: "DM" or "ROOM".
			 * @param array  $message    Full message object from Google Chat.
			 * @param array  $event      Full event payload.
			 */
			do_action( 'wp_mcp_ai_google_chat_message', $clean_text, $raw_text, $space_type, $message, $event );

			/**
			 * Action fired when a Google Chat MESSAGE event is received in a Space.
			 *
			 * @since 1.0.0
			 *
			 * @param string $clean_text Sanitized message text with mention markup removed.
			 * @param array  $message    Full message object from Google Chat.
			 * @param array  $event      Full event payload.
			 */
			if ( 'ROOM' === $space_type ) {
				do_action( 'wp_mcp_ai_google_chat_message_in_space', $clean_text, $message, $event );
			}

			/**
			 * Action fired when a Google Chat MESSAGE event is received in a DM.
			 *
			 * @since 1.0.0
			 *
			 * @param string $clean_text Sanitized message text with mention markup removed.
			 * @param array  $message    Full message object from Google Chat.
			 * @param array  $event      Full event payload.
			 */
			if ( 'DM' === $space_type ) {
				do_action( 'wp_mcp_ai_google_chat_message_in_dm', $clean_text, $message, $event );
			}

			/**
			 * Filter the AI response text for a Google Chat MESSAGE event.
			 *
			 * Return a non-empty string to send that reply back to the chat.
			 * Return an empty string to send no reply.
			 *
			 * @since 1.0.0
			 *
			 * @param string $response_text Default empty response.
			 * @param string $clean_text    Sanitized message text.
			 * @param string $space_type    Space type: "DM" or "ROOM".
			 * @param array  $message       Full message object from Google Chat.
			 * @param array  $event         Full event payload.
			 */
			return apply_filters( 'wp_mcp_ai_google_chat_message_response', '', $clean_text, $space_type, $message, $event );
		}

		/**
		 * Handle an ADDED_TO_SPACE event.
		 *
		 * Google Chat fires this when the bot is added to a space or DM.
		 * A greeting response is returned by default.
		 *
		 * @since 1.0.0
		 *
		 * @param array $event Full Google Chat event payload.
		 * @return string Response text.
		 */
		protected function handle_added_to_space_event( array $event ) {
			$space      = isset( $event['space'] ) && is_array( $event['space'] ) ? $event['space'] : array();
			$space_name = isset( $space['displayName'] ) ? sanitize_text_field( $space['displayName'] ) : '';

			/**
			 * Action fired when the bot is added to a Google Chat space.
			 *
			 * @since 1.0.0
			 *
			 * @param string $space_name Display name of the space (may be empty for DMs).
			 * @param array  $space      Full space object from Google Chat.
			 * @param array  $event      Full event payload.
			 */
			do_action( 'wp_mcp_ai_google_chat_added_to_space', $space_name, $space, $event );

			/**
			 * Filter the greeting response when the bot is added to a space.
			 *
			 * @since 1.0.0
			 *
			 * @param string $greeting   Default greeting message.
			 * @param string $space_name Display name of the space.
			 * @param array  $event      Full event payload.
			 */
			$greeting = apply_filters(
				'wp_mcp_ai_google_chat_added_to_space_response',
				/* translators: %s: the site name shown as the bot identity. */
				sprintf( __( "Hello! I'm %s. Mention me with @mention in this space and I'll be happy to help.", 'mcp-ai-wpoos-pro' ), get_bloginfo( 'name' ) ),
				$space_name,
				$event
			);

			return (string) $greeting;
		}

		/**
		 * Handle a REMOVED_FROM_SPACE event.
		 *
		 * @since 1.0.0
		 *
		 * @param array $event Full Google Chat event payload.
		 */
		protected function handle_removed_from_space_event( array $event ) {
			$space      = isset( $event['space'] ) && is_array( $event['space'] ) ? $event['space'] : array();
			$space_name = isset( $space['displayName'] ) ? sanitize_text_field( $space['displayName'] ) : '';

			/**
			 * Action fired when the bot is removed from a Google Chat space.
			 *
			 * @since 1.0.0
			 *
			 * @param string $space_name Display name of the space.
			 * @param array  $space      Full space object from Google Chat.
			 * @param array  $event      Full event payload.
			 */
			do_action( 'wp_mcp_ai_google_chat_removed_from_space', $space_name, $space, $event );
		}

		/**
		 * Handle a CARD_CLICKED event (interactive card interactions).
		 *
		 * @since 1.0.0
		 *
		 * @param array $event Full Google Chat event payload.
		 */
		protected function handle_card_clicked_event( array $event ) {
			$action = isset( $event['action'] ) && is_array( $event['action'] ) ? $event['action'] : array();

			/**
			 * Action fired when a Google Chat card button is clicked.
			 *
			 * @since 1.0.0
			 *
			 * @param array $action The action data from the card click.
			 * @param array $event  Full event payload.
			 */
			do_action( 'wp_mcp_ai_google_chat_card_clicked', $action, $event );
		}

		/**
		 * Strip Google Chat @mention markup from message text.
		 *
		 * When a bot is mentioned in a Space, Google Chat delivers the raw text as:
		 *   "<users/123456789> what can you do?"
		 * This method removes all such <users/USER_ID> tokens so that the clean
		 * message text is available for further processing or AI consumption.
		 *
		 * @since 1.0.0
		 *
		 * @param string $text Raw message text from Google Chat.
		 * @return string Cleaned text with mention markup removed.
		 */
		public function strip_mention_markup( $text ) {
			if ( ! is_string( $text ) ) {
				return '';
			}

			// Remove <users/USER_ID> and <users/all> mention tokens.
			$clean = preg_replace( '/<users\/[^>]+>\s*/u', '', $text );

			if ( null === $clean ) {
				// Regex failed — return original text trimmed.
				return trim( $text );
			}

			return trim( $clean );
		}

		/**
		 * Get the list of supported Google Chat event types.
		 *
		 * @since 1.0.0
		 *
		 * @return string[]
		 */
		protected function get_supported_event_types() {
			return array(
				self::EVENT_MESSAGE,
				self::EVENT_ADDED_TO_SPACE,
				self::EVENT_REMOVED_FROM_SPACE,
				self::EVENT_CARD_CLICKED,
			);
		}
	}
}
