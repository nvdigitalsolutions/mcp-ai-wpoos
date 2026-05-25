<?php
/**
 * Chat Continuation Store.
 *
 * Persists per-job conversation snapshots so that when an async tool job
 * (e.g. Veo video generation, long-running browser automation, batch image
 * generation) completes, the chat session that started it can be resumed
 * and the LLM can be re-engaged to produce a follow-up message.
 *
 * Mirrors the industry-standard "durable conversation state + correlation
 * IDs" pattern used by OpenAI Responses API (`background=true` /
 * `response.id`), Anthropic message batches, A2A `pushNotificationConfig`,
 * and LangGraph checkpoints.
 *
 * Storage strategy
 * ----------------
 * Each continuation is stored as a single transient (default TTL: 24 hours,
 * filterable via `wp_mcp_ai_chat_continuation_ttl`) keyed by the async
 * `job_id`. A secondary option-backed index maps `chat_session_id` →
 * list of `job_id`s so that the chat-session SSE channel (Slice 3) and the
 * Tasks Drawer can enumerate continuations belonging to a session without
 * scanning the wp_options table.
 *
 * The index is bounded (`MAX_CONTINUATIONS_PER_SESSION`) and the global
 * count is bounded with LRU eviction (`MAX_TOTAL_CONTINUATIONS`) to
 * protect sites with thousands of stale jobs.
 *
 * @link    https://platform.openai.com/docs/guides/background
 * @credit  Storage pattern inspired by Stripe webhook + idempotency model.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Chat_Continuation_Store' ) ) {
	/**
	 * Durable store for chat continuation snapshots keyed by async job_id.
	 */
	class WP_MCP_AI_Chat_Continuation_Store {

		/**
		 * Transient prefix for continuation rows.
		 *
		 * Each row stores a full snapshot of the conversation state at the
		 * moment the agentic loop exited because a tool returned
		 * `{ async: true, status: 'pending' }`.
		 *
		 * Pattern: `wp_mcp_ai_chat_continuation_{job_id}`
		 */
		const TRANSIENT_PREFIX = 'wp_mcp_ai_chat_cont_';

		/**
		 * Option key for the session_id → job_ids secondary index.
		 *
		 * Structure: `array<string $session_id, array<int, string> $job_ids>`.
		 */
		const INDEX_OPTION_KEY = 'wp_mcp_ai_chat_continuation_index';

		/**
		 * Option key holding the LRU-ordered list of all known job_ids.
		 *
		 * Newest entries are appended to the tail; oldest entries are
		 * removed from the head when `MAX_TOTAL_CONTINUATIONS` is exceeded.
		 *
		 * Structure: `array<int, array{ job_id: string, created_at: int }>`.
		 */
		const LRU_OPTION_KEY = 'wp_mcp_ai_chat_continuation_lru';

		/**
		 * Default TTL in seconds for a continuation snapshot.
		 *
		 * Matches the plan's stated default and is filterable via
		 * `wp_mcp_ai_chat_continuation_ttl`.
		 */
		const DEFAULT_TTL = DAY_IN_SECONDS;

		/**
		 * Hard cap on total continuations stored site-wide.
		 *
		 * When exceeded, oldest entries are pruned from both the LRU list
		 * and their backing transients. The cap is filterable via
		 * `wp_mcp_ai_chat_continuation_max_total`.
		 */
		const MAX_TOTAL_CONTINUATIONS = 500;

		/**
		 * Per-session cap on simultaneous continuations.
		 *
		 * Filterable via `wp_mcp_ai_chat_continuation_max_per_session`.
		 */
		const MAX_CONTINUATIONS_PER_SESSION = 32;

		/**
		 * Default upper bound on the serialized size of `messages[]`.
		 *
		 * Filterable via `wp_mcp_ai_chat_continuation_max_messages_size`.
		 */
		const DEFAULT_MAX_MESSAGES_SIZE_BYTES = 524288; // 512 KB.

		/**
		 * Store a continuation snapshot keyed by `job_id`.
		 *
		 * If a row already exists for the same `job_id` it is overwritten
		 * (last-writer-wins). Callers should treat the existence of a row
		 * for a given job_id as the correlation guarantee.
		 *
		 * @param string $job_id  Async job identifier (must be non-empty).
		 * @param array  $payload Snapshot payload. See `normalize_payload()`
		 *                       for the expected shape.
		 *
		 * @return true|WP_Error True on success, WP_Error on validation or
		 *                       persistence failure.
		 */
		public static function store( $job_id, array $payload ) {
			$job_id = self::sanitize_job_id( $job_id );
			if ( '' === $job_id ) {
				return new WP_Error(
					'invalid_job_id',
					__( 'A non-empty job_id is required to store a continuation snapshot.', 'mcp-ai-wpoos' )
				);
			}

			$normalized = self::normalize_payload( $job_id, $payload );
			if ( is_wp_error( $normalized ) ) {
				return $normalized;
			}

			$ttl = (int) apply_filters( 'wp_mcp_ai_chat_continuation_ttl', self::DEFAULT_TTL, $job_id, $normalized );
			if ( $ttl <= 0 ) {
				$ttl = self::DEFAULT_TTL;
			}

			$ok = set_transient( self::TRANSIENT_PREFIX . $job_id, $normalized, $ttl );
			if ( ! $ok ) {
				return new WP_Error(
					'continuation_persist_failed',
					__( 'Failed to persist chat continuation snapshot.', 'mcp-ai-wpoos' )
				);
			}

			self::index_add( $normalized['chat_session_id'], $job_id );
			self::lru_push( $job_id, (int) $normalized['created_at'] );
			self::enforce_global_cap();

			/**
			 * Fires after a chat continuation snapshot is persisted.
			 *
			 * @since 1.9.4
			 *
			 * @param string $job_id     Async job identifier.
			 * @param array  $normalized Normalized snapshot payload.
			 */
			do_action( 'wp_mcp_ai_chat_continuation_stored', $job_id, $normalized );

			return true;
		}

		/**
		 * Retrieve a continuation by `job_id`.
		 *
		 * @param string $job_id Async job identifier.
		 *
		 * @return array|null Snapshot payload, or null if missing/expired.
		 */
		public static function get( $job_id ) {
			$job_id = self::sanitize_job_id( $job_id );
			if ( '' === $job_id ) {
				return null;
			}

			$row = get_transient( self::TRANSIENT_PREFIX . $job_id );
			if ( ! is_array( $row ) || empty( $row['job_id'] ) ) {
				return null;
			}
			return $row;
		}

		/**
		 * Delete a continuation row and remove it from all indices.
		 *
		 * Idempotent — calling delete on a missing job_id returns true.
		 *
		 * @param string $job_id Async job identifier.
		 *
		 * @return bool True when delete completed (regardless of prior existence).
		 */
		public static function delete( $job_id ) {
			$job_id = self::sanitize_job_id( $job_id );
			if ( '' === $job_id ) {
				return false;
			}

			$row = get_transient( self::TRANSIENT_PREFIX . $job_id );
			delete_transient( self::TRANSIENT_PREFIX . $job_id );

			$session_id = is_array( $row ) && isset( $row['chat_session_id'] )
				? (string) $row['chat_session_id']
				: '';

			if ( '' !== $session_id ) {
				self::index_remove( $session_id, $job_id );
			} else {
				// Best-effort sweep when we don't know the session.
				self::index_remove_unknown_session( $job_id );
			}

			self::lru_remove( $job_id );

			return true;
		}

		/**
		 * Mark a continuation as "currently being processed".
		 *
		 * Used by the Dispatcher to ensure only one cron worker processes a
		 * given job_id at a time (idempotency guard for cron retries).
		 *
		 * @param string $job_id   Async job identifier.
		 * @param int    $ttl_secs Lock TTL in seconds.
		 *
		 * @return bool True when the lock was acquired; false when already held.
		 */
		public static function acquire_processing_lock( $job_id, $ttl_secs = 300 ) {
			$job_id = self::sanitize_job_id( $job_id );
			if ( '' === $job_id ) {
				return false;
			}

			$row = self::get( $job_id );
			if ( null === $row ) {
				return false;
			}

			$now           = time();
			$existing_lock = isset( $row['processing_at'] ) ? (int) $row['processing_at'] : 0;
			$existing_ttl  = isset( $row['processing_ttl'] ) ? (int) $row['processing_ttl'] : 0;

			if ( $existing_lock > 0 && ( $existing_lock + $existing_ttl ) > $now ) {
				return false;
			}

			$row['processing_at']  = $now;
			$row['processing_ttl'] = max( 1, (int) $ttl_secs );

			$ttl = (int) apply_filters( 'wp_mcp_ai_chat_continuation_ttl', self::DEFAULT_TTL, $job_id, $row );
			if ( $ttl <= 0 ) {
				$ttl = self::DEFAULT_TTL;
			}

			return (bool) set_transient( self::TRANSIENT_PREFIX . $job_id, $row, $ttl );
		}

		/**
		 * Release the processing lock for a job_id.
		 *
		 * @param string $job_id Async job identifier.
		 *
		 * @return bool True if the lock was released.
		 */
		public static function release_processing_lock( $job_id ) {
			$job_id = self::sanitize_job_id( $job_id );
			if ( '' === $job_id ) {
				return false;
			}
			$row = self::get( $job_id );
			if ( null === $row ) {
				return false;
			}

			unset( $row['processing_at'], $row['processing_ttl'] );

			$ttl = (int) apply_filters( 'wp_mcp_ai_chat_continuation_ttl', self::DEFAULT_TTL, $job_id, $row );
			if ( $ttl <= 0 ) {
				$ttl = self::DEFAULT_TTL;
			}
			return (bool) set_transient( self::TRANSIENT_PREFIX . $job_id, $row, $ttl );
		}

		/**
		 * Get all `job_id`s currently indexed for a chat session.
		 *
		 * @param string $session_id Chat session identifier.
		 *
		 * @return array<int, string> Ordered list of job_ids (oldest first).
		 */
		public static function get_jobs_for_session( $session_id ) {
			$session_id = self::sanitize_session_id( $session_id );
			if ( '' === $session_id ) {
				return array();
			}

			$index = get_option( self::INDEX_OPTION_KEY, array() );
			if ( ! is_array( $index ) || empty( $index[ $session_id ] ) ) {
				return array();
			}

			$jobs = $index[ $session_id ];
			return is_array( $jobs ) ? array_values( $jobs ) : array();
		}

		/**
		 * Reset all stored data (test helper).
		 *
		 * @internal Used by PHPUnit only.
		 */
		public static function reset_for_tests() {
			$lru = get_option( self::LRU_OPTION_KEY, array() );
			if ( is_array( $lru ) ) {
				foreach ( $lru as $entry ) {
					if ( is_array( $entry ) && ! empty( $entry['job_id'] ) ) {
						delete_transient( self::TRANSIENT_PREFIX . $entry['job_id'] );
					}
				}
			}
			delete_option( self::INDEX_OPTION_KEY );
			delete_option( self::LRU_OPTION_KEY );
		}

		// -----------------------------------------------------------------
		// Internal helpers
		// -----------------------------------------------------------------

		/**
		 * Normalize and validate a snapshot payload.
		 *
		 * @param string $job_id  Async job identifier.
		 * @param array  $payload Raw payload from the caller.
		 *
		 * @return array|WP_Error Normalized snapshot, or WP_Error on validation failure.
		 */
		protected static function normalize_payload( $job_id, array $payload ) {
			$session_id = self::sanitize_session_id( isset( $payload['chat_session_id'] ) ? $payload['chat_session_id'] : '' );
			if ( '' === $session_id ) {
				return new WP_Error(
					'invalid_chat_session_id',
					__( 'chat_session_id is required to store a continuation.', 'mcp-ai-wpoos' )
				);
			}

			$messages = isset( $payload['messages'] ) && is_array( $payload['messages'] ) ? $payload['messages'] : array();

			$max_messages_size = (int) apply_filters(
				'wp_mcp_ai_chat_continuation_max_messages_size',
				self::DEFAULT_MAX_MESSAGES_SIZE_BYTES,
				$job_id,
				$payload
			);
			if ( $max_messages_size <= 0 ) {
				$max_messages_size = self::DEFAULT_MAX_MESSAGES_SIZE_BYTES;
			}

			$encoded = wp_json_encode( $messages );
			if ( false !== $encoded && strlen( $encoded ) > $max_messages_size ) {
				return new WP_Error(
					'continuation_messages_too_large',
					sprintf(
						/* translators: 1: serialized size in bytes; 2: maximum allowed size. */
						__( 'Chat continuation messages array is too large (%1$d bytes > %2$d byte limit).', 'mcp-ai-wpoos' ),
						strlen( $encoded ),
						$max_messages_size
					)
				);
			}

			$now = time();

			$normalized = array(
				'job_id'          => self::sanitize_job_id( $job_id ),
				'chat_session_id' => $session_id,
				'assistant_id'    => isset( $payload['assistant_id'] ) ? absint( $payload['assistant_id'] ) : 0,
				'user_id'         => isset( $payload['user_id'] ) ? absint( $payload['user_id'] ) : 0,
				'guest_token'     => isset( $payload['guest_token'] ) ? (string) $payload['guest_token'] : '',
				'tool_call_id'    => isset( $payload['tool_call_id'] ) ? (string) $payload['tool_call_id'] : '',
				'tool_name'       => isset( $payload['tool_name'] ) ? (string) $payload['tool_name'] : '',
				'provider'        => isset( $payload['provider'] ) ? (string) $payload['provider'] : '',
				'model'           => isset( $payload['model'] ) ? (string) $payload['model'] : '',
				'options'         => isset( $payload['options'] ) && is_array( $payload['options'] ) ? $payload['options'] : array(),
				'harness_profile' => isset( $payload['harness_profile'] ) && is_array( $payload['harness_profile'] ) ? $payload['harness_profile'] : array(),
				'messages'        => $messages,
				'created_at'      => isset( $payload['created_at'] ) ? (int) $payload['created_at'] : $now,
				'expires_at'      => isset( $payload['expires_at'] ) ? (int) $payload['expires_at'] : ( $now + self::DEFAULT_TTL ),
			);

			// Preserve dispatcher-managed passthrough keys when callers (e.g. the
			// dispatcher) round-trip a snapshot back through store() to record
			// terminal state alongside the conversation snapshot.
			$passthrough = array( 'terminal_status', 'terminal_result', 'terminal_at', 'processing_at', 'processing_ttl' );
			foreach ( $passthrough as $key ) {
				if ( array_key_exists( $key, $payload ) ) {
					$normalized[ $key ] = $payload[ $key ];
				}
			}

			return $normalized;
		}

		/**
		 * Add a job_id to the session-id index.
		 *
		 * @param string $session_id Chat session identifier.
		 * @param string $job_id     Job identifier.
		 */
		protected static function index_add( $session_id, $job_id ) {
			$session_id = self::sanitize_session_id( $session_id );
			if ( '' === $session_id ) {
				return;
			}

			$index = get_option( self::INDEX_OPTION_KEY, array() );
			if ( ! is_array( $index ) ) {
				$index = array();
			}
			if ( ! isset( $index[ $session_id ] ) || ! is_array( $index[ $session_id ] ) ) {
				$index[ $session_id ] = array();
			}

			// De-duplicate, append.
			$index[ $session_id ]   = array_values( array_diff( $index[ $session_id ], array( $job_id ) ) );
			$index[ $session_id ][] = $job_id;

			$max_per_session = (int) apply_filters(
				'wp_mcp_ai_chat_continuation_max_per_session',
				self::MAX_CONTINUATIONS_PER_SESSION
			);
			if ( $max_per_session > 0 && count( $index[ $session_id ] ) > $max_per_session ) {
				$dropped              = array_slice( $index[ $session_id ], 0, count( $index[ $session_id ] ) - $max_per_session );
				$index[ $session_id ] = array_slice( $index[ $session_id ], -$max_per_session );
				foreach ( $dropped as $dropped_job ) {
					delete_transient( self::TRANSIENT_PREFIX . $dropped_job );
					self::lru_remove( $dropped_job );
				}
			}

			update_option( self::INDEX_OPTION_KEY, $index, false );
		}

		/**
		 * Remove a job_id from the session-id index.
		 *
		 * @param string $session_id Chat session identifier.
		 * @param string $job_id     Job identifier.
		 */
		protected static function index_remove( $session_id, $job_id ) {
			$session_id = self::sanitize_session_id( $session_id );
			if ( '' === $session_id ) {
				return;
			}

			$index = get_option( self::INDEX_OPTION_KEY, array() );
			if ( ! is_array( $index ) || empty( $index[ $session_id ] ) || ! is_array( $index[ $session_id ] ) ) {
				return;
			}

			$index[ $session_id ] = array_values( array_diff( $index[ $session_id ], array( $job_id ) ) );
			if ( empty( $index[ $session_id ] ) ) {
				unset( $index[ $session_id ] );
			}

			update_option( self::INDEX_OPTION_KEY, $index, false );
		}

		/**
		 * Sweep the index for a job_id when its session is unknown.
		 *
		 * @param string $job_id Job identifier.
		 */
		protected static function index_remove_unknown_session( $job_id ) {
			$index = get_option( self::INDEX_OPTION_KEY, array() );
			if ( ! is_array( $index ) ) {
				return;
			}
			$changed = false;
			foreach ( $index as $session_id => $jobs ) {
				if ( ! is_array( $jobs ) ) {
					continue;
				}
				if ( in_array( $job_id, $jobs, true ) ) {
					$index[ $session_id ] = array_values( array_diff( $jobs, array( $job_id ) ) );
					if ( empty( $index[ $session_id ] ) ) {
						unset( $index[ $session_id ] );
					}
					$changed = true;
				}
			}
			if ( $changed ) {
				update_option( self::INDEX_OPTION_KEY, $index, false );
			}
		}

		/**
		 * Append a job_id to the LRU list.
		 *
		 * @param string $job_id     Job identifier.
		 * @param int    $created_at Creation timestamp.
		 */
		protected static function lru_push( $job_id, $created_at ) {
			$lru = get_option( self::LRU_OPTION_KEY, array() );
			if ( ! is_array( $lru ) ) {
				$lru = array();
			}

			// Remove any existing entry first so the move-to-tail is O(N) but correct.
			foreach ( $lru as $i => $entry ) {
				if ( is_array( $entry ) && isset( $entry['job_id'] ) && $entry['job_id'] === $job_id ) {
					unset( $lru[ $i ] );
				}
			}
			$lru   = array_values( $lru );
			$lru[] = array(
				'job_id'     => $job_id,
				'created_at' => (int) $created_at,
			);

			update_option( self::LRU_OPTION_KEY, $lru, false );
		}

		/**
		 * Remove a job_id from the LRU list.
		 *
		 * @param string $job_id Job identifier.
		 */
		protected static function lru_remove( $job_id ) {
			$lru = get_option( self::LRU_OPTION_KEY, array() );
			if ( ! is_array( $lru ) ) {
				return;
			}
			$changed = false;
			foreach ( $lru as $i => $entry ) {
				if ( is_array( $entry ) && isset( $entry['job_id'] ) && $entry['job_id'] === $job_id ) {
					unset( $lru[ $i ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_option( self::LRU_OPTION_KEY, array_values( $lru ), false );
			}
		}

		/**
		 * Enforce the global LRU cap by evicting oldest rows.
		 */
		protected static function enforce_global_cap() {
			$max = (int) apply_filters(
				'wp_mcp_ai_chat_continuation_max_total',
				self::MAX_TOTAL_CONTINUATIONS
			);
			if ( $max <= 0 ) {
				return;
			}

			$lru = get_option( self::LRU_OPTION_KEY, array() );
			if ( ! is_array( $lru ) || count( $lru ) <= $max ) {
				return;
			}

			$over    = count( $lru ) - $max;
			$dropped = array_slice( $lru, 0, $over );
			$kept    = array_slice( $lru, $over );

			foreach ( $dropped as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['job_id'] ) ) {
					continue;
				}
				$job_id = $entry['job_id'];
				delete_transient( self::TRANSIENT_PREFIX . $job_id );
				self::index_remove_unknown_session( $job_id );
			}

			update_option( self::LRU_OPTION_KEY, array_values( $kept ), false );
		}

		/**
		 * Sanitize an arbitrary string into a safe job_id.
		 *
		 * Allowed characters: A-Z, a-z, 0-9, underscore, dot, hyphen.
		 * Dots and hyphens are intentionally preserved because async tools
		 * (notably Gemini Veo) emit dotted operation names like
		 * `operations/abc.123` and asset ids may carry hyphens.
		 *
		 * @param mixed $job_id Input value.
		 *
		 * @return string Sanitized job_id (may be empty).
		 */
		protected static function sanitize_job_id( $job_id ) {
			if ( ! is_string( $job_id ) && ! is_numeric( $job_id ) ) {
				return '';
			}
			$job_id = (string) $job_id;
			$job_id = preg_replace( '/[^A-Za-z0-9_.\-]/', '', $job_id );
			if ( ! is_string( $job_id ) ) {
				return '';
			}
			return substr( $job_id, 0, 128 );
		}

		/**
		 * Sanitize a chat-session id.
		 *
		 * @param mixed $session_id Input value.
		 *
		 * @return string Sanitized session_id (may be empty).
		 */
		protected static function sanitize_session_id( $session_id ) {
			if ( ! is_string( $session_id ) && ! is_numeric( $session_id ) ) {
				return '';
			}
			$session_id = (string) $session_id;
			$session_id = preg_replace( '/[^A-Za-z0-9_.\-]/', '', $session_id );
			if ( ! is_string( $session_id ) ) {
				return '';
			}
			return substr( $session_id, 0, 128 );
		}

		/**
		 * Generate a fresh UUID-v4-shaped chat session identifier.
		 *
		 * Filterable via `wp_mcp_ai_chat_session_id_generated` so that
		 * external systems (e.g. multi-tenant SaaS) can supply their own
		 * IDs (such as a thread id from a remote system).
		 *
		 * @param array $context Optional context (assistant_id, user_id).
		 *
		 * @return string A non-empty session identifier.
		 */
		public static function generate_session_id( array $context = array() ) {
			$session_id = function_exists( 'wp_generate_uuid4' )
				? wp_generate_uuid4()
				: sprintf(
					'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
					wp_rand( 0, 0xffff ),
					wp_rand( 0, 0xffff ),
					wp_rand( 0, 0xffff ),
					wp_rand( 0, 0x0fff ) | 0x4000,
					wp_rand( 0, 0x3fff ) | 0x8000,
					wp_rand( 0, 0xffff ),
					wp_rand( 0, 0xffff ),
					wp_rand( 0, 0xffff )
				);

			/**
			 * Filter the freshly-minted chat session identifier.
			 *
			 * @since 1.9.4
			 *
			 * @param string $session_id Generated session identifier (UUID v4 by default).
			 * @param array  $context    Caller-supplied context.
			 */
			$session_id = apply_filters( 'wp_mcp_ai_chat_session_id_generated', $session_id, $context );

			$session_id = self::sanitize_session_id( $session_id );
			if ( '' === $session_id ) {
				// Last-resort fallback if a filter returned something unusable.
				// `random_bytes()` is available since PHP 7.0; the base plugin
				// targets PHP 7.4+, so this is safe.
				$session_id = 'sess_' . bin2hex( random_bytes( 16 ) );
			}
			return $session_id;
		}
	}
}
