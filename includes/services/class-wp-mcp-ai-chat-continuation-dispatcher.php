<?php
/**
 * Chat Continuation Dispatcher.
 *
 * Listens to `wp_mcp_ai_job_completed`, `wp_mcp_ai_job_failed`, and
 * `wp_mcp_ai_job_cancelled` and, when a continuation snapshot exists for
 * the firing job_id, schedules a WP-Cron event so the chat session can
 * be resumed off-hook.
 *
 * The dispatcher itself does *not* call the LLM. It performs the
 * idempotent "load snapshot, inject tool result, fire ready action" step
 * and lets downstream subscribers (the upcoming
 * `WP_MCP_AI_REST::resume_chat_after_job()`, the chat-session SSE
 * channel, OTel exporters, external webhooks) handle delivery.
 *
 * Separating dispatch from delivery keeps each subsystem independently
 * shippable per the implementation slices in the plan and mirrors the
 * Stripe / GitHub webhook handler pattern (`event → queue → workers`).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Chat_Continuation_Dispatcher' ) ) {
	/**
	 * Coordinates the async-job → chat-session resume flow.
	 */
	class WP_MCP_AI_Chat_Continuation_Dispatcher {

		/**
		 * Cron hook used to defer continuation processing off the firing thread.
		 */
		const CRON_HOOK = 'wp_mcp_ai_resume_chat_after_job';

		/**
		 * Default delay (seconds) before the cron worker runs.
		 *
		 * A short delay lets the job notifier finish caching the status so
		 * the cron worker reads a consistent view.
		 */
		const CRON_DELAY = 1;

		/**
		 * Processing lock TTL in seconds.
		 *
		 * Held by the cron worker for the duration of the resume to prevent
		 * duplicate work when WP-Cron retries.
		 */
		const PROCESSING_LOCK_TTL = 300;

		/**
		 * Register dispatcher hooks. Idempotent.
		 */
		public static function init() {
			static $initialized = false;
			if ( $initialized ) {
				return;
			}
			$initialized = true;

			// Priority 20 — run AFTER Job_Notifier (priority 10) has cached status.
			add_action( 'wp_mcp_ai_job_completed', array( __CLASS__, 'on_job_completed' ), 20, 3 );
			add_action( 'wp_mcp_ai_job_failed', array( __CLASS__, 'on_job_failed' ), 20, 3 );
			add_action( 'wp_mcp_ai_job_cancelled', array( __CLASS__, 'on_job_cancelled' ), 20, 3 );

			// Cron worker that drives the resume.
			add_action( self::CRON_HOOK, array( __CLASS__, 'process_resume' ), 10, 1 );
		}

		/**
		 * Reset registration state (test helper).
		 *
		 * @internal Used by PHPUnit only.
		 */
		public static function reset_for_tests() {
			remove_action( 'wp_mcp_ai_job_completed', array( __CLASS__, 'on_job_completed' ), 20 );
			remove_action( 'wp_mcp_ai_job_failed', array( __CLASS__, 'on_job_failed' ), 20 );
			remove_action( 'wp_mcp_ai_job_cancelled', array( __CLASS__, 'on_job_cancelled' ), 20 );
			remove_action( self::CRON_HOOK, array( __CLASS__, 'process_resume' ), 10 );
		}

		/**
		 * Handler for `wp_mcp_ai_job_completed`.
		 *
		 * @param string $job_id   Job identifier.
		 * @param array  $result   Job result data.
		 * @param array  $metadata Job metadata.
		 */
		public static function on_job_completed( $job_id, $result = array(), $metadata = array() ) {
			self::maybe_schedule_resume( $job_id, 'completed', $result, $metadata );
		}

		/**
		 * Handler for `wp_mcp_ai_job_failed`.
		 *
		 * @param string $job_id   Job identifier.
		 * @param mixed  $error    WP_Error or array describing the failure.
		 * @param array  $metadata Job metadata.
		 */
		public static function on_job_failed( $job_id, $error = null, $metadata = array() ) {
			$result = array(
				'success' => false,
				'error'   => is_wp_error( $error )
					? array(
						'code'    => $error->get_error_code(),
						'message' => $error->get_error_message(),
					)
					: ( is_array( $error ) ? $error : array( 'message' => (string) $error ) ),
			);
			self::maybe_schedule_resume( $job_id, 'failed', $result, is_array( $metadata ) ? $metadata : array() );
		}

		/**
		 * Handler for `wp_mcp_ai_job_cancelled`.
		 *
		 * @param string $job_id   Job identifier.
		 * @param mixed  $payload  Optional cancellation context.
		 * @param array  $metadata Job metadata.
		 */
		public static function on_job_cancelled( $job_id, $payload = null, $metadata = array() ) {
			$result = array(
				'success' => false,
				'status'  => 'cancelled',
			);
			if ( is_array( $payload ) ) {
				$result = array_merge( $result, $payload );
			}
			self::maybe_schedule_resume( $job_id, 'cancelled', $result, is_array( $metadata ) ? $metadata : array() );
		}

		/**
		 * Schedule a WP-Cron event to drive the resume, if applicable.
		 *
		 * @param string $job_id           Job identifier.
		 * @param string $terminal_status  One of: completed, failed, cancelled.
		 * @param array  $result           Job result data.
		 * @param array  $metadata         Job metadata.
		 *
		 * @return bool True when an event was scheduled.
		 */
		protected static function maybe_schedule_resume( $job_id, $terminal_status, array $result, array $metadata ) {
			$job_id = is_string( $job_id ) ? $job_id : '';
			if ( '' === $job_id ) {
				return false;
			}

			/**
			 * Site-wide kill switch for async chat continuation.
			 *
			 * @since 1.9.4
			 *
			 * @param bool   $enabled Whether continuation is enabled (default true).
			 * @param string $job_id  Async job identifier.
			 * @param string $terminal_status One of completed|failed|cancelled.
			 */
			$enabled = (bool) apply_filters( 'wp_mcp_ai_chat_continuation_enabled', true, $job_id, $terminal_status );
			if ( ! $enabled ) {
				return false;
			}

			$snapshot = WP_MCP_AI_Chat_Continuation_Store::get( $job_id );
			if ( null === $snapshot ) {
				return false;
			}

			/**
			 * Allow late opt-out of dispatching for a specific continuation.
			 *
			 * Useful for HITL approvals or sub-agent dispatchers that wish
			 * to handle the resume themselves.
			 *
			 * @since 1.9.4
			 *
			 * @param bool   $should_dispatch Default true.
			 * @param array  $snapshot        Continuation snapshot.
			 * @param string $terminal_status One of completed|failed|cancelled.
			 * @param array  $result          Job result data.
			 */
			$should_dispatch = (bool) apply_filters(
				'wp_mcp_ai_chat_continuation_should_dispatch',
				true,
				$snapshot,
				$terminal_status,
				$result
			);
			if ( ! $should_dispatch ) {
				return false;
			}

			// Persist the terminal status alongside the snapshot so the cron
			// worker can read a consistent view (the Job_Notifier cache TTL
			// is independent and may expire first).
			$snapshot['terminal_status'] = $terminal_status;
			$snapshot['terminal_result'] = $result;
			$snapshot['terminal_at']     = time();
			WP_MCP_AI_Chat_Continuation_Store::store( $job_id, $snapshot );

			// Avoid duplicate scheduling.
			if ( wp_next_scheduled( self::CRON_HOOK, array( $job_id ) ) ) {
				return false;
			}

			$delay = (int) apply_filters( 'wp_mcp_ai_chat_continuation_cron_delay', self::CRON_DELAY, $job_id, $terminal_status );
			if ( $delay < 0 ) {
				$delay = 0;
			}

			$scheduled = wp_schedule_single_event( time() + $delay, self::CRON_HOOK, array( $job_id ) );

			// Trigger WordPress cron immediately so the continuation runs without delay.
			// Without this, the continuation sits in the cron queue until the next
			// HTTP request, which may never arrive (especially on SSE connections).
			if ( false !== $scheduled ) {
				spawn_cron();
			}

			return false !== $scheduled;
		}

		/**
		 * Cron worker — load snapshot, inject tool result, fire ready action.
		 *
		 * Idempotent: a processing lock on the continuation row prevents
		 * concurrent workers from double-processing the same job_id.
		 *
		 * @param string $job_id Job identifier.
		 *
		 * @return bool True when processing completed (or already done).
		 */
		public static function process_resume( $job_id ) {
			$job_id = is_string( $job_id ) ? $job_id : '';
			if ( '' === $job_id ) {
				return false;
			}

			$snapshot = WP_MCP_AI_Chat_Continuation_Store::get( $job_id );
			if ( null === $snapshot ) {
				return false;
			}

			// Acquire idempotency lock.
			$locked = WP_MCP_AI_Chat_Continuation_Store::acquire_processing_lock( $job_id, self::PROCESSING_LOCK_TTL );
			if ( ! $locked ) {
				// Either already being processed by another worker or already
				// fully dispatched and consumed by a previous run.
				return false;
			}

			try {
				$terminal_status = isset( $snapshot['terminal_status'] ) ? (string) $snapshot['terminal_status'] : 'completed';
				$terminal_result = isset( $snapshot['terminal_result'] ) && is_array( $snapshot['terminal_result'] )
					? $snapshot['terminal_result']
					: array();

				$tool_message = self::build_tool_result_message( $snapshot, $terminal_status, $terminal_result );

				/**
				 * Filter the tool-result message that is appended to the
				 * conversation before the LLM is re-engaged.
				 *
				 * @since 1.9.4
				 *
				 * @param array  $tool_message    Constructed message: { role:tool, name, tool_call_id, content }.
				 * @param array  $snapshot        Continuation snapshot.
				 * @param string $terminal_status completed|failed|cancelled.
				 * @param array  $terminal_result Job result data.
				 */
				$tool_message = apply_filters(
					'wp_mcp_ai_chat_continuation_message',
					$tool_message,
					$snapshot,
					$terminal_status,
					$terminal_result
				);

				if ( is_array( $tool_message ) && ! empty( $tool_message ) ) {
					$snapshot['messages']   = isset( $snapshot['messages'] ) && is_array( $snapshot['messages'] )
						? $snapshot['messages']
						: array();
					$snapshot['messages'][] = $tool_message;
				}

				/**
				 * Fires after the continuation has been prepared and the
				 * tool-result message has been appended to the conversation.
				 *
				 * Downstream subscribers (the agentic-loop re-entry, the
				 * chat-session SSE channel, OTel exporters, external
				 * webhooks) should listen on this hook to deliver the
				 * resumed assistant message.
				 *
				 * @since 1.9.4
				 *
				 * @param array  $snapshot        Continuation snapshot (with terminal_* fields).
				 * @param string $terminal_status One of completed|failed|cancelled.
				 * @param array  $terminal_result Job result data.
				 */
				do_action( 'wp_mcp_ai_chat_continuation_ready', $snapshot, $terminal_status, $terminal_result );

				/**
				 * Fires after the continuation has been dispatched.
				 *
				 * Mirrors the OTel hook surface used by other slices and is
				 * the canonical observability signal that a continuation
				 * was successfully driven from job completion to the
				 * downstream LLM resume.
				 *
				 * @since 1.9.4
				 *
				 * @param string $job_id          Async job identifier.
				 * @param array  $snapshot        Continuation snapshot.
				 * @param string $terminal_status One of completed|failed|cancelled.
				 */
				do_action( 'wp_mcp_ai_chat_continuation_dispatched', $job_id, $snapshot, $terminal_status );

				return true;
			} finally {
				WP_MCP_AI_Chat_Continuation_Store::release_processing_lock( $job_id );
			}
		}

		/**
		 * Build the OpenAI-compatible tool-result message that will be
		 * appended to the conversation history before the LLM is re-engaged.
		 *
		 * @param array  $snapshot        Continuation snapshot.
		 * @param string $terminal_status One of completed|failed|cancelled.
		 * @param array  $terminal_result Job result data.
		 *
		 * @return array Tool message in OpenAI chat format.
		 */
		protected static function build_tool_result_message( array $snapshot, $terminal_status, array $terminal_result ) {
			$tool_name    = isset( $snapshot['tool_name'] ) ? (string) $snapshot['tool_name'] : '';
			$tool_call_id = isset( $snapshot['tool_call_id'] ) ? (string) $snapshot['tool_call_id'] : '';

			$payload = array(
				'async'  => true,
				'status' => $terminal_status,
				'job_id' => isset( $snapshot['job_id'] ) ? (string) $snapshot['job_id'] : '',
				'result' => $terminal_result,
			);

			$content = wp_json_encode( $payload );
			if ( false === $content ) {
				$content = '{}';
			}

			$message = array(
				'role'    => 'tool',
				'content' => $content,
			);
			if ( '' !== $tool_call_id ) {
				$message['tool_call_id'] = $tool_call_id;
			}
			if ( '' !== $tool_name ) {
				$message['name'] = $tool_name;
			}
			return $message;
		}
	}
}
