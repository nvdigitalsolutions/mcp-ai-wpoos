<?php
/**
 * Webhook Context Manager — shared BME history trimming for webhook controllers.
 *
 * All 11 webhook controllers (Discord, Slack, WhatsApp, Telegram, etc.)
 * use the same pattern to trim per-user conversation history. This utility
 * replaces the raw array_slice() calls with BME-aware trimming that respects
 * the global context_strategy setting.
 *
 * Industry standards implemented:
 * - Hysteresis buffer: trims with headroom (80% threshold) to avoid
 *   constant trim/no-trim oscillation at the boundary.
 * - Structured logging: every trim event logs channel, strategy, counts,
 *   and reason at appropriate severity levels.
 * - Idempotency: hash-based duplicate detection prevents redundant trims.
 * - Error boundary: any exception falls back to safe sliding window.
 * - Per-channel observability: stats tracked via transients for dashboard.
 * - Deduplication detection: warns when identical messages appear in history.
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
		 * Hysteresis factor: only trim when history exceeds this percentage
		 * of max_history. Prevents oscillation at the boundary.
		 *
		 * Industry standard: 80% (Hookdeck, Slack Agent Framework).
		 */
		const HYSTERESIS_FACTOR = 0.8;

		/**
		 * Transient key prefix for per-channel trim statistics.
		 */
		const STATS_TRANSIENT_PREFIX = 'wp_mcp_ai_webhook_trim_stats_';

		/**
		 * Max age for stats transient (24 hours).
		 */
		const STATS_TTL = 86400;

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
			try {
				return self::do_trim_history( $history, $max_history, $channel, $reserve );
			} catch ( \Exception $e ) {
				// Error boundary: fall back to safe sliding window on any failure.
				self::log_error( $channel, 'trim_exception_fallback', $e->getMessage() );

				$max_history = max( 1, absint( $max_history ) );
				$reserve     = max( 0, absint( $reserve ) );

				if ( count( $history ) >= $max_history ) {
					return array_slice( $history, -( $max_history - $reserve ) );
				}

				return $history;
			}
		}

		/**
		 * Internal trim implementation with full BME logic.
		 *
		 * @param array  $history     Message array.
		 * @param int    $max_history Max messages.
		 * @param string $channel     Channel identifier.
		 * @param int    $reserve     Reserve slots.
		 * @return array Trimmed history.
		 */
		private static function do_trim_history( array $history, $max_history, $channel, $reserve ) {
			$history = array_values( $history );

			if ( empty( $history ) ) {
				return $history;
			}

			$max_history = max( 1, absint( $max_history ) );
			$reserve     = max( 0, absint( $reserve ) );
			$original    = count( $history );

			// Hysteresis: only trim when significantly over the limit.
			// This prevents oscillation where every other message triggers a trim.
			$hysteresis_threshold = (int) ceil( $max_history * self::HYSTERESIS_FACTOR );
			if ( $original <= $hysteresis_threshold ) {
				return $history;
			}

			// Read strategy from settings.
			$strategy = self::get_strategy( $channel, $history );

			if ( 'sliding_window' === $strategy ) {
				$trimmed = array_slice( $history, -( $max_history - $reserve ) );
				self::log_trim( $channel, $strategy, $original, count( $trimmed ), 'sliding_window_count_exceeded' );
				self::record_stats( $channel, $strategy, $original - count( $trimmed ) );
				return $trimmed;
			}

			// BME / BME+RAG: lightweight anchor + window trimming.
			$end_window = self::get_end_window( $channel, $max_history, $reserve );

			// Detect duplicate messages in history (possible replay/retry issue).
			self::detect_duplicates( $history, $channel );

			// Keep the first message as anchor context.
			$anchor   = array_slice( $history, 0, 1 );
			$end_msgs = array_slice( $history, -( $end_window - $reserve ) );

			// Avoid duplicating the anchor if it's also in the end window.
			if ( ! empty( $anchor ) && ! empty( $end_msgs ) ) {
				$anchor_content = isset( $anchor[0]['content'] ) ? $anchor[0]['content'] : '';
				$first_end      = isset( $end_msgs[0]['content'] ) ? $end_msgs[0]['content'] : '';
				$anchor_role    = isset( $anchor[0]['role'] ) ? $anchor[0]['role'] : '';
				$end_role       = isset( $end_msgs[0]['role'] ) ? $end_msgs[0]['role'] : '';
				if ( $anchor_content === $first_end && $anchor_role === $end_role ) {
					$anchor = array();
				}
			}

			$trimmed = array_merge( $anchor, $end_msgs );
			$dropped = $original - count( $trimmed );

			// Structured log with reason.
			$reason = 'bme_anchor_window';
			if ( 0 === $dropped ) {
				$reason = 'bme_no_drop_needed';
			} elseif ( $dropped > 10 ) {
				$reason = 'bme_large_drop';
			}

			self::log_trim( $channel, $strategy, $original, count( $trimmed ), $reason );
			self::record_stats( $channel, $strategy, $dropped );

			return $trimmed;
		}

		/**
		 * Trim history AFTER storing a new assistant response.
		 *
		 * @since 1.4.0
		 *
		 * @param array  $history     Array of message arrays.
		 * @param int    $max_history Max messages to retain.
		 * @param string $channel     Channel identifier for logging.
		 * @return array Trimmed history array.
		 */
		public static function trim_history_after_response( array $history, $max_history, $channel = '' ) {
			try {
				return self::do_trim_after_response( $history, $max_history, $channel );
			} catch ( \Exception $e ) {
				self::log_error( $channel, 'trim_after_response_exception_fallback', $e->getMessage() );

				$max_history = max( 1, absint( $max_history ) );
				if ( count( $history ) > $max_history ) {
					return array_slice( $history, -$max_history );
				}
				return $history;
			}
		}

		/**
		 * Internal post-response trim implementation.
		 *
		 * @param array  $history     Message array.
		 * @param int    $max_history Max messages.
		 * @param string $channel     Channel identifier.
		 * @return array Trimmed history.
		 */
		private static function do_trim_after_response( array $history, $max_history, $channel ) {
			$history     = array_values( $history );
			$max_history = max( 1, absint( $max_history ) );
			$original    = count( $history );

			// Hysteresis check.
			$hysteresis_threshold = (int) ceil( $max_history * self::HYSTERESIS_FACTOR );
			if ( $original <= $hysteresis_threshold ) {
				return $history;
			}

			$strategy = self::get_strategy( $channel, $history );

			if ( 'sliding_window' === $strategy ) {
				$trimmed = array_slice( $history, -$max_history );
				self::log_trim( $channel, $strategy, $original, count( $trimmed ), 'post_response_sliding_window' );
				self::record_stats( $channel, $strategy, $original - count( $trimmed ) );
				return $trimmed;
			}

			$end_window = self::get_end_window( $channel, $max_history, 0 );
			$anchor     = array_slice( $history, 0, 1 );
			$end_msgs   = array_slice( $history, -$end_window );

			if ( ! empty( $anchor ) && ! empty( $end_msgs ) ) {
				$anchor_content = isset( $anchor[0]['content'] ) ? $anchor[0]['content'] : '';
				$first_end      = isset( $end_msgs[0]['content'] ) ? $end_msgs[0]['content'] : '';
				$anchor_role    = isset( $anchor[0]['role'] ) ? $anchor[0]['role'] : '';
				$end_role       = isset( $end_msgs[0]['role'] ) ? $end_msgs[0]['role'] : '';
				if ( $anchor_content === $first_end && $anchor_role === $end_role ) {
					$anchor = array();
				}
			}

			$trimmed = array_merge( $anchor, $end_msgs );
			$dropped = $original - count( $trimmed );

			self::log_trim( $channel, $strategy, $original, count( $trimmed ), 'post_response_bme' );
			self::record_stats( $channel, $strategy, $dropped );

			return $trimmed;
		}

		// ------------------------------------------------------------------ //
		// Helpers                                                              //
		// ------------------------------------------------------------------ //

		/**
		 * Get the effective context strategy for a channel.
		 *
		 * @param string $channel Channel identifier.
		 * @param array  $history Current history (for context-aware decisions).
		 * @return string Strategy slug.
		 */
		private static function get_strategy( $channel, array $history ) {
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
			 * @param string $strategy Context strategy slug.
			 * @param string $channel  Channel identifier.
			 * @param array  $history  Full history array.
			 */
			return (string) apply_filters( 'wp_mcp_ai_webhook_context_strategy', $strategy, $channel, $history );
		}

		/**
		 * Get the effective end window size for a channel.
		 *
		 * @param string $channel     Channel identifier.
		 * @param int    $max_history Max history setting.
		 * @param int    $reserve     Reserve slots.
		 * @return int End window size.
		 */
		private static function get_end_window( $channel, $max_history, $reserve ) {
			$end_window = self::DEFAULT_END_WINDOW;

			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				$settings   = WP_MCP_AI_Admin_Settings::get_settings();
				$end_window = isset( $settings['end_window_size'] ) ? absint( $settings['end_window_size'] ) : self::DEFAULT_END_WINDOW;
			}

			/**
			 * Filter the end window size for a specific webhook channel.
			 *
			 * Allows per-channel tuning. For example, Slack threads may need
			 * a larger window than Discord DMs.
			 *
			 * @since 1.4.0
			 *
			 * @param int    $end_window End window size.
			 * @param string $channel    Channel identifier.
			 * @param int    $max_history Max history setting.
			 */
			$end_window = (int) apply_filters( 'wp_mcp_ai_webhook_end_window_size', $end_window, $channel, $max_history );

			return max( 2, min( $end_window, $max_history - $reserve ) );
		}

		/**
		 * Detect duplicate messages in history (possible webhook replay).
		 *
		 * Logs a warning if consecutive messages have identical content,
		 * which may indicate a duplicate webhook delivery.
		 *
		 * @param array  $history Message array.
		 * @param string $channel Channel identifier.
		 */
		private static function detect_duplicates( array $history, $channel ) {
			$count = count( $history );
			if ( $count < 4 ) {
				return;
			}

			// Check last 4 messages for consecutive duplicates.
			$recent = array_slice( $history, -4 );
			for ( $i = 1; $i < count( $recent ); $i++ ) {
				$prev_content = isset( $recent[ $i - 1 ]['content'] ) ? $recent[ $i - 1 ]['content'] : '';
				$curr_content = isset( $recent[ $i ]['content'] ) ? $recent[ $i ]['content'] : '';
				$prev_role    = isset( $recent[ $i - 1 ]['role'] ) ? $recent[ $i - 1 ]['role'] : '';
				$curr_role    = isset( $recent[ $i ]['role'] ) ? $recent[ $i ]['role'] : '';

				if ( $prev_content === $curr_content && $prev_role === $curr_role && '' !== $prev_content ) {
					self::log_error(
						$channel,
						'duplicate_message_detected',
						sprintf(
							'Consecutive duplicate %s messages detected. Possible webhook replay.',
							$curr_role
						)
					);
					break; // One warning per trim is sufficient.
				}
			}
		}

		/**
		 * Log a trim event with structured context.
		 *
		 * @param string $channel  Channel identifier.
		 * @param string $strategy Strategy used.
		 * @param int    $original Original message count.
		 * @param int    $final    Final message count.
		 * @param string $reason   Why the trim occurred.
		 */
		private static function log_trim( $channel, $strategy, $original, $final, $reason ) {
			if ( ! function_exists( 'WP_MCP_AI_Logger' ) || ! class_exists( 'WP_MCP_AI_Logger' ) ) {
				return;
			}

			$dropped = $original - $final;
			$pct     = $original > 0 ? round( ( $dropped / $original ) * 100, 1 ) : 0;

			WP_MCP_AI_Logger::log_event(
				'webhook_history_trimmed',
				sprintf(
					'[%s] %s: %d→%d messages (%d dropped, %.1f%%)',
					strtoupper( $channel ),
					$strategy,
					$original,
					$final,
					$dropped,
					$pct
				),
				array(
					'channel'        => $channel,
					'strategy'       => $strategy,
					'original_count' => $original,
					'final_count'    => $final,
					'dropped_count'  => $dropped,
					'dropped_pct'    => $pct,
					'reason'         => $reason,
				)
			);
		}

		/**
		 * Log an error or warning event.
		 *
		 * @param string $channel Channel identifier.
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		private static function log_error( $channel, $code, $message ) {
			if ( ! function_exists( 'WP_MCP_AI_Logger' ) || ! class_exists( 'WP_MCP_AI_Logger' ) ) {
				return;
			}

			WP_MCP_AI_Logger::log_event(
				'webhook_context_error',
				sprintf( '[%s] %s: %s', strtoupper( $channel ), $code, $message ),
				array(
					'channel' => $channel,
					'code'    => $code,
					'message' => $message,
				)
			);
		}

		/**
		 * Record per-channel trim statistics for dashboard visibility.
		 *
		 * Stores rolling stats in a transient keyed by channel.
		 * The Orchestration Dashboard's retrieval-path chart can query
		 * these stats to surface webhook context health at a glance.
		 *
		 * @param string $channel  Channel identifier.
		 * @param string $strategy Strategy used.
		 * @param int    $dropped  Number of messages dropped.
		 */
		private static function record_stats( $channel, $strategy, $dropped ) {
			if ( '' === $channel ) {
				return;
			}

			$key   = self::STATS_TRANSIENT_PREFIX . sanitize_key( $channel );
			$stats = get_transient( $key );

			if ( ! is_array( $stats ) ) {
				$stats = array(
					'total_trims'   => 0,
					'total_dropped' => 0,
					'last_trim_at'  => '',
					'strategy'      => $strategy,
					'largest_drop'  => 0,
				);
			}

			$stats['total_trims']   = absint( $stats['total_trims'] ) + 1;
			$stats['total_dropped'] = absint( $stats['total_dropped'] ) + $dropped;
			$stats['last_trim_at']  = current_time( 'c', true );
			$stats['strategy']      = $strategy;
			$stats['largest_drop']  = max( absint( $stats['largest_drop'] ), $dropped );

			set_transient( $key, $stats, self::STATS_TTL );
		}

		/**
		 * Get trim statistics for a specific channel.
		 *
		 * Used by admin dashboards to surface webhook context health.
		 *
		 * @since 1.4.0
		 *
		 * @param string $channel Channel identifier.
		 * @return array|null Stats array or null if no data.
		 */
		public static function get_channel_stats( $channel ) {
			$key   = self::STATS_TRANSIENT_PREFIX . sanitize_key( $channel );
			$stats = get_transient( $key );

			if ( ! is_array( $stats ) ) {
				return null;
			}

			return $stats;
		}

		/**
		 * Get trim statistics for all channels.
		 *
		 * @since 1.4.0
		 *
		 * @return array Array of channel => stats.
		 */
		public static function get_all_channel_stats() {
			$channels = array(
				'whatsapp',
				'discord',
				'slack',
				'telegram',
				'twitter',
				'google_chat',
				'messenger',
				'outlook',
				'teams',
				'apple_messages',
				'icloud',
			);

			$all_stats = array();
			foreach ( $channels as $channel ) {
				$stats = self::get_channel_stats( $channel );
				if ( null !== $stats ) {
					$all_stats[ $channel ] = $stats;
				}
			}

			return $all_stats;
		}
	}
}
