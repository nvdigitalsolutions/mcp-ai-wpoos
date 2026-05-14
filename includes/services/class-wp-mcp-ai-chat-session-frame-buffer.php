<?php
/**
 * Chat Session Frame Buffer.
 *
 * Transient-backed ring-buffer that stores SSE frames per chat session so
 * that the chat-session SSE stream endpoint can:
 *   1. Replay buffered frames to a client that reconnects with Last-Event-ID.
 *   2. Long-poll for frames pushed by the continuation dispatcher while a
 *      client is actively connected.
 *
 * Storage layout:
 *   Option key: `wp_mcp_ai_css_frames_{session_id}` (transient with TTL equal
 *   to the continuation TTL, default DAY_IN_SECONDS).
 *
 *   Value: array {
 *     frames: array of { id(int), event(string), data(array), ts(int) },
 *     next_id: int  // monotonically incrementing frame counter
 *   }
 *
 * The ring-buffer cap (default 20) is enforced on every write: oldest frames
 * are dropped when the cap is exceeded.  Clients that reconnect after the cap
 * is exceeded will not see the dropped frames but will resume from the oldest
 * still-buffered frame (or from empty if the buffer was pruned).
 *
 * @package WP_MCP_AI
 * @since   1.9.4
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Chat_Session_Frame_Buffer' ) ) {
	/**
	 * Manages the per-session SSE frame ring-buffer.
	 */
	class WP_MCP_AI_Chat_Session_Frame_Buffer {

		/**
		 * Option name prefix for frame buffers.
		 * Full key: `wp_mcp_ai_css_frames_{session_id}`.
		 */
		const OPTION_PREFIX = 'wp_mcp_ai_css_frames_';

		/**
		 * Default ring-buffer cap (frames per session).
		 */
		const DEFAULT_CAP = 20;

		/**
		 * Push a frame into the session's ring-buffer.
		 *
		 * @param string $session_id Chat session identifier.
		 * @param string $event      SSE event name (e.g. `chat:resumed`, `ping`).
		 * @param array  $data       Frame payload (must be JSON-serialisable).
		 *
		 * @return int The monotonic frame ID that was assigned to this frame.
		 */
		public static function push( $session_id, $event, array $data ) {
			$session_id = self::sanitize_session_id( $session_id );
			if ( '' === $session_id ) {
				return 0;
			}

			$cap = (int) apply_filters( 'wp_mcp_ai_chat_session_buffer_size', self::DEFAULT_CAP, $session_id );
			$cap = max( 1, $cap );

			$buffer  = self::load( $session_id );
			$next_id = $buffer['next_id'] + 1;

			$buffer['frames'][] = array(
				'id'    => $next_id,
				'event' => (string) $event,
				'data'  => $data,
				'ts'    => time(),
			);
			$buffer['next_id'] = $next_id;

			// Enforce ring-buffer cap — drop oldest frames first.
			if ( count( $buffer['frames'] ) > $cap ) {
				$buffer['frames'] = array_values(
					array_slice( $buffer['frames'], -$cap )
				);
			}

			self::save( $session_id, $buffer );

			return $next_id;
		}

		/**
		 * Return frames with id > $since_id.
		 *
		 * Used by the SSE polling loop: on every tick it asks for new frames
		 * since the last acknowledged ID.
		 *
		 * @param string $session_id Chat session identifier.
		 * @param int    $since_id   Return only frames with id strictly greater
		 *                           than this value.  Pass 0 to get all buffered.
		 *
		 * @return array<array{id:int,event:string,data:array,ts:int}> Ordered ASC.
		 */
		public static function get_frames_since( $session_id, $since_id = 0 ) {
			$session_id = self::sanitize_session_id( $session_id );
			if ( '' === $session_id ) {
				return array();
			}

			$buffer = self::load( $session_id );
			$result = array();
			foreach ( $buffer['frames'] as $frame ) {
				if ( (int) $frame['id'] > (int) $since_id ) {
					$result[] = $frame;
				}
			}
			return $result;
		}

		/**
		 * Return the highest frame ID currently in the buffer (0 if empty).
		 *
		 * @param string $session_id Chat session identifier.
		 *
		 * @return int
		 */
		public static function latest_id( $session_id ) {
			$session_id = self::sanitize_session_id( $session_id );
			if ( '' === $session_id ) {
				return 0;
			}
			$buffer = self::load( $session_id );
			return (int) $buffer['next_id'];
		}

		/**
		 * Delete all buffered frames for a session.
		 *
		 * Called by the continuation store when a continuation row expires or
		 * is consumed.
		 *
		 * @param string $session_id Chat session identifier.
		 */
		public static function flush( $session_id ) {
			$session_id = self::sanitize_session_id( $session_id );
			if ( '' === $session_id ) {
				return;
			}
			delete_transient( self::OPTION_PREFIX . $session_id );
		}

		// -----------------------------------------------------------------------
		// Internal helpers
		// -----------------------------------------------------------------------

		/**
		 * Load the raw buffer record from the transient store.
		 *
		 * Returns an empty seed if the record does not exist.
		 *
		 * @param string $session_id Sanitized session identifier.
		 *
		 * @return array{frames:array,next_id:int}
		 */
		protected static function load( $session_id ) {
			$raw = get_transient( self::OPTION_PREFIX . $session_id );
			if ( is_array( $raw ) && isset( $raw['frames'], $raw['next_id'] ) ) {
				return $raw;
			}
			return array(
				'frames'  => array(),
				'next_id' => 0,
			);
		}

		/**
		 * Persist the buffer back to the transient store.
		 *
		 * The TTL mirrors the continuation TTL so the buffer and the
		 * continuation row expire together.
		 *
		 * @param string $session_id Sanitized session identifier.
		 * @param array  $buffer     Buffer record to persist.
		 */
		protected static function save( $session_id, array $buffer ) {
			/**
			 * Filter the TTL (in seconds) for the per-session frame buffer
			 * transient.  Defaults to the same value as the continuation TTL
			 * so both records expire at the same time.
			 *
			 * @since 1.9.4
			 *
			 * @param int    $ttl        Time-to-live in seconds.
			 * @param string $session_id Session identifier.
			 */
			$ttl = (int) apply_filters(
				'wp_mcp_ai_chat_session_frame_buffer_ttl',
				defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400,
				$session_id
			);

			set_transient( self::OPTION_PREFIX . $session_id, $buffer, $ttl );
		}

		/**
		 * Sanitize a session_id to a safe string for use as an option suffix.
		 *
		 * The session ID must be an alphanumeric-dash-underscore token of at
		 * most 64 characters.  Any other characters are stripped; an empty
		 * result means the ID was invalid and the caller should bail.
		 *
		 * @param string $session_id Raw session identifier.
		 *
		 * @return string Sanitized value (may be empty on failure).
		 */
		public static function sanitize_session_id( $session_id ) {
			if ( ! is_string( $session_id ) ) {
				return '';
			}
			$sanitized = preg_replace( '/[^a-zA-Z0-9_\-]/', '', $session_id );
			$sanitized = substr( (string) $sanitized, 0, 64 );
			return (string) $sanitized;
		}
	}
}
