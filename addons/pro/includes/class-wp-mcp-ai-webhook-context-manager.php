<?php
/**
 * Webhook Context Manager — shared BME history trimming for webhook controllers.
 *
 * All 11 webhook controllers (Discord, Slack, WhatsApp, Telegram, etc.)
 * use the same pattern to trim per-user conversation history. This utility
 * replaces the raw array_slice() calls with BME-aware trimming that respects
 * the global context_strategy setting.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Webhook_Context_Manager' ) ) {
	/**
	 * Shared history trimming for webhook controllers.
	 *
	 * When context_strategy is 'bme' or 'bme_rag', preserves the oldest
	 * message (as anchor context) and keeps the last N messages verbatim
	 * — a lightweight BME without the LLM summarization overhead.
	 * Falls back to pure sliding window when strategy is 'sliding_window'.
	 */
	class WP_MCP_AI_Webhook_Context_Manager {

		/**
		 * Default end window size for webhook contexts.
		 *
		 * Webhook conversations are typically shorter and more focused
		 * than chat UI sessions, so a smaller window is appropriate.
		 */
		const DEFAULT_END_WINDOW = 6;

		/**
		 * Trim conversation history using the configured context strategy.
		 *
		 * Call this instead of raw array_slice() in every webhook controller's
		 * handle_*_reply_job method.
		 *
		 * @since 1.4.0
		 *
		 * @param array  $history     Array of message arrays with 'role' and 'content' keys.
		 * @param int    $max_history Max messages to retain (from settings/filter).
		 * @param string $channel     Channel identifier for logging (discord, slack, etc.).
		 * @param int    $reserve     Number of slots to reserve for new messages (typically 1).
		 * @return array Trimmed history array.
		 */
		public static function trim_history( array $history, $max_history, $channel = '', $reserve = 1 ) {
			$history = array_values( $history );

			if ( empty( $history ) ) {
				return $history;
			}

			$max_history = max( 1, absint( $max_history ) );
			$reserve     = max( 0, absint( $reserve ) );

			// No trimming needed.
			if ( count( $history ) < $max_history ) {
				return $history;
			}

			// Read strategy from settings.
			$strategy = 'sliding_window';
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				$settings = WP_MCP_AI_Admin_Settings::get_settings();
				$strategy = isset( $settings['context_strategy'] ) ? sanitize_key( $settings['context_strategy'] ) : 'sliding_window';
			}

			/**
			 * Filter the context strategy for webhook history trimming.
			 *
			 * @since 1.4.0
			 *
			 * @param string $strategy    Context strategy slug.
			 * @param string $channel     Channel identifier.
			 * @param array  $history     Full history array.
			 */
			$strategy = (string) apply_filters( 'wp_mcp_ai_webhook_context_strategy', $strategy, $channel, $history );

			if ( 'sliding_window' === $strategy ) {
				// Original behavior: keep only the most recent messages.
				return array_slice( $history, -( $max_history - $reserve ) );
			}

			// BME / BME+RAG: lightweight anchor + window trimming.
			$end_window = self::DEFAULT_END_WINDOW;
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				$settings   = WP_MCP_AI_Admin_Settings::get_settings();
				$end_window = isset( $settings['end_window_size'] ) ? absint( $settings['end_window_size'] ) : self::DEFAULT_END_WINDOW;
			}
			$end_window = max( 2, min( $end_window, $max_history - $reserve ) );

			// Keep the first message as anchor context (establishes the conversation's purpose).
			$anchor    = array_slice( $history, 0, 1 );
			$end_msgs  = array_slice( $history, -( $end_window - $reserve ) );

			// Avoid duplicating the anchor if it's also in the end window.
			if ( ! empty( $anchor ) && ! empty( $end_msgs ) ) {
				$anchor_content = isset( $anchor[0]['content'] ) ? $anchor[0]['content'] : '';
				$first_end      = isset( $end_msgs[0]['content'] ) ? $end_msgs[0]['content'] : '';
				if ( $anchor_content === $first_end && isset( $anchor[0]['role'] ) && isset( $end_msgs[0]['role'] ) && $anchor[0]['role'] === $end_msgs[0]['role'] ) {
					$anchor = array();
				}
			}

			$trimmed = array_merge( $anchor, $end_msgs );

			// Log the trim for diagnostics.
			if ( function_exists( 'WP_MCP_AI_Logger' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
				WP_MCP_AI_Logger::log_event(
					'webhook_history_trimmed_bme',
					'Webhook conversation history trimmed with BME strategy.',
					array(
						'channel'      => $channel,
						'strategy'     => $strategy,
						'original'     => count( $history ),
						'trimmed'      => count( $trimmed ),
						'end_window'   => $end_window,
						'has_anchor'   => ! empty( $anchor ),
					)
				);
			}

			return $trimmed;
		}

		/**
		 * Trim history AFTER storing a new assistant response.
		 *
		 * This is the second trim point in every webhook controller — called
		 * after the assistant's response has been appended to history.
		 *
		 * @since 1.4.0
		 *
		 * @param array  $history     Array of message arrays.
		 * @param int    $max_history Max messages to retain.
		 * @param string $channel     Channel identifier for logging.
		 * @return array Trimmed history array.
		 */
		public static function trim_history_after_response( array $history, $max_history, $channel = '' ) {
			$history     = array_values( $history );
			$max_history = max( 1, absint( $max_history ) );

			if ( count( $history ) <= $max_history ) {
				return $history;
			}

			// Read strategy from settings.
			$strategy = 'sliding_window';
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				$settings = WP_MCP_AI_Admin_Settings::get_settings();
				$strategy = isset( $settings['context_strategy'] ) ? sanitize_key( $settings['context_strategy'] ) : 'sliding_window';
			}

			if ( 'sliding_window' === $strategy ) {
				return array_slice( $history, -$max_history );
			}

			// BME: keep first message as anchor + last N messages.
			$end_window = self::DEFAULT_END_WINDOW;
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				$settings   = WP_MCP_AI_Admin_Settings::get_settings();
				$end_window = isset( $settings['end_window_size'] ) ? absint( $settings['end_window_size'] ) : self::DEFAULT_END_WINDOW;
			}
			$end_window = max( 2, min( $end_window, $max_history ) );

			$anchor   = array_slice( $history, 0, 1 );
			$end_msgs = array_slice( $history, -$end_window );

			// Avoid duplicate anchor.
			if ( ! empty( $anchor ) && ! empty( $end_msgs ) ) {
				$anchor_content = isset( $anchor[0]['content'] ) ? $anchor[0]['content'] : '';
				$first_end      = isset( $end_msgs[0]['content'] ) ? $end_msgs[0]['content'] : '';
				if ( $anchor_content === $first_end && isset( $anchor[0]['role'] ) && isset( $end_msgs[0]['role'] ) && $anchor[0]['role'] === $end_msgs[0]['role'] ) {
					$anchor = array();
				}
			}

			return array_merge( $anchor, $end_msgs );
		}
	}
}
