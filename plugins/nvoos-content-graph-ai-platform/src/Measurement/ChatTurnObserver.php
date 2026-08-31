<?php
/**
 * Chat-Turn Observer
 *
 * Bridges the plugin's pre-existing `wp_mcp_ai_before_chat_request`,
 * `wp_mcp_ai_after_chat_response`, `wp_mcp_ai_cost_calculated`, and
 * `wp_mcp_ai_agentic_iteration_complete` action hooks into the
 * measurement collector. This is the second live-traffic emission
 * path after the tool-execution observer from PR 6. Iteration hook
 * support was added in PR 7.1 and enables emission of the previously
 * reserved `chat.agentic.iterations` histogram.
 *
 * Concurrency model:
 *   A single PHP request serves one chat turn at a time, but the chat
 *   service can be invoked synchronously from multiple endpoints (REST
 *   /chat, /chat-client, Telegram reply job, etc.) and nothing forbids
 *   nested chat calls. We therefore keep an **invocation stack** — each
 *   `before` pushes, each `after` pops the top frame. The stack is keyed
 *   by assistant_id so nested calls with different assistants can be
 *   disambiguated; if the top frame does not match we scan top-down
 *   rather than silently producing wrong durations.
 *
 * Privacy:
 *   The context payload sent to `record()` contains only provider,
 *   model, assistant_id, user_id, and guest-flag. Prompts, completions,
 *   messages and tool arguments are never included — the Internal
 *   privacy tier explicitly forbids them (see
 *   `docs/reference/measurement/privacy-matrix.md`).
 *
 * Opt-out:
 *   Return `false` from the `wp_mcp_ai_chat_turn_observer_enabled`
 *   filter to skip observer installation entirely. Stock definitions
 *   remain registered so third parties can still emit them directly.
 *
 * @package NvoosContentGraphAiPlatform
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Measurement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat-turn observer.
 */
class ChatTurnObserver {

	/**
	 * Invocation stack: list of assoc arrays { assistant_id, started_at, options }.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $stack = array();

	/**
	 * Per-assistant max iteration count seen during the current turn.
	 * Keyed by `(string) $assistant_id`. Emitted on the corresponding
	 * `wp_mcp_ai_after_chat_response` and cleared immediately.
	 *
	 * @var array<string,int>
	 */
	private $iteration_counts = array();

	/**
	 * Singleton instance.
	 *
	 * @var ChatTurnObserver|null
	 */
	private static $instance = null;

	/**
	 * Whether hooks have been attached.
	 *
	 * @var bool
	 */
	private $attached = false;

	/**
	 * Get singleton instance.
	 *
	 * @return ChatTurnObserver
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset the singleton (tests only).
	 *
	 * Also detaches any stale handler bound to a *previous* instance
	 * of this class that the WordPress test-case harness may have
	 * restored from its pre-test hook snapshot. Without this step,
	 * restored hooks end up registered alongside the new singleton
	 * and the observer double-emits.
	 *
	 * @return void
	 */
	public static function reset_instance() {
		if ( null !== self::$instance ) {
			self::$instance->detach();
		}
		self::detach_all_stale_hooks();
		self::$instance = null;
	}

	/**
	 * Remove any chat-turn-observer callbacks registered on the
	 * three observed hooks, regardless of which instance they were
	 * bound to. Safe to call outside the singleton lifecycle.
	 *
	 * @return void
	 */
	private static function detach_all_stale_hooks() {
		global $wp_filter;
		if ( ! is_array( $wp_filter ) ) {
			return;
		}
		$pairs = array(
			'wp_mcp_ai_before_chat_request'        => 'on_before',
			'wp_mcp_ai_after_chat_response'        => 'on_after',
			'wp_mcp_ai_cost_calculated'            => 'on_cost',
			'wp_mcp_ai_agentic_iteration_complete' => 'on_agentic_iteration',
		);
		foreach ( $pairs as $hook_name => $method ) {
			if ( ! isset( $wp_filter[ $hook_name ] ) ) {
				continue;
			}
			$hook = $wp_filter[ $hook_name ];
			if ( ! isset( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) {
				continue;
			}
			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $id => $entry ) {
					if ( ! isset( $entry['function'] ) || ! is_array( $entry['function'] ) ) {
						continue;
					}
					$target = $entry['function'][0];
					if ( $target instanceof self && ( $entry['function'][1] ?? '' ) === $method ) {
						unset( $hook->callbacks[ $priority ][ $id ] );
					}
				}
				if ( empty( $hook->callbacks[ $priority ] ) ) {
					unset( $hook->callbacks[ $priority ] );
				}
			}
		}
	}

	/**
	 * Attach hooks. Idempotent.
	 *
	 * @return bool True if attached (or already attached).
	 */
	public function attach() {
		if ( $this->attached ) {
			return true;
		}

		/**
		 * Filters whether the chat-turn observer is installed.
		 *
		 * Return false to suppress all baseline chat-turn metric
		 * emission. Stock definitions remain registered so third
		 * parties can still emit them directly.
		 *
		 * @since 1.3.0
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_chat_turn_observer_enabled', true ) ) {
			return false;
		}

		add_action( 'wp_mcp_ai_before_chat_request', array( $this, 'on_before' ), 5, 4 );
		add_action( 'wp_mcp_ai_after_chat_response', array( $this, 'on_after' ), 95, 3 );
		add_action( 'wp_mcp_ai_cost_calculated', array( $this, 'on_cost' ), 95, 5 );
		add_action( 'wp_mcp_ai_agentic_iteration_complete', array( $this, 'on_agentic_iteration' ), 10, 2 );

		$this->attached = true;
		return true;
	}

	/**
	 * Detach hooks. Primarily for tests.
	 *
	 * @return void
	 */
	public function detach() {
		if ( ! $this->attached ) {
			return;
		}
		remove_action( 'wp_mcp_ai_before_chat_request', array( $this, 'on_before' ), 5 );
		remove_action( 'wp_mcp_ai_after_chat_response', array( $this, 'on_after' ), 95 );
		remove_action( 'wp_mcp_ai_cost_calculated', array( $this, 'on_cost' ), 95 );
		remove_action( 'wp_mcp_ai_agentic_iteration_complete', array( $this, 'on_agentic_iteration' ), 10 );
		$this->attached         = false;
		$this->stack            = array();
		$this->iteration_counts = array();
	}

	/**
	 * Observability hook: push a new chat-turn frame.
	 *
	 * Signature matches `do_action( 'wp_mcp_ai_before_chat_request', $assistant_id, $messages, $options, $request )`.
	 * `$messages` is accepted but never stored or recorded.
	 *
	 * @param mixed $assistant_id Assistant identifier.
	 * @param mixed $messages     Messages array (unused — never leaves scope).
	 * @param mixed $options      Options array (provider/model inspected).
	 * @param mixed $request      REST request or null.
	 * @return void
	 */
	public function on_before( $assistant_id = null, $messages = null, $options = null, $request = null ) {
		unset( $messages, $request );

		$this->stack[] = array(
			'assistant_id' => self::sanitize_scalar_id( $assistant_id ),
			'started_at'   => microtime( true ),
			'options'      => is_array( $options ) ? self::redact_options( $options ) : array(),
		);
	}

	/**
	 * Observability hook: pop a chat-turn frame and emit turn-level metrics.
	 *
	 * Signature matches `do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request )`.
	 *
	 * @param mixed $assistant_id Assistant identifier.
	 * @param mixed $response     Response array or WP_Error.
	 * @param mixed $request      REST request or null.
	 * @return void
	 */
	public function on_after( $assistant_id = null, $response = null, $request = null ) {
		unset( $request );

		$frame = $this->pop_matching( self::sanitize_scalar_id( $assistant_id ) );

		$duration_ms = null;
		if ( is_array( $frame ) && isset( $frame['started_at'] ) ) {
			$duration_ms = max( 0.0, ( microtime( true ) - (float) $frame['started_at'] ) * 1000.0 );
		}

		$options = is_array( $frame ) && isset( $frame['options'] ) && is_array( $frame['options'] )
			? $frame['options']
			: array();

		$outcome = self::outcome_from_response( $response );
		$ctx     = self::base_context( $assistant_id, $options, $outcome, $response );

		$collector = $this->collector();
		if ( null === $collector ) {
			return;
		}

		$collector->record( ChatTurnMetrics::CHAT_TURN_COUNT, 1, $ctx );
		if ( 'error' === $outcome ) {
			$collector->record( ChatTurnMetrics::CHAT_TURN_ERROR_COUNT, 1, $ctx );
		}

		if ( null !== $duration_ms ) {
			$collector->record( ChatTurnMetrics::CHAT_TURN_DURATION_MS, $duration_ms, $ctx );
		}

		// Emit the reserved `chat.agentic.iterations` histogram when the
		// base plugin's agentic-loop hook fired at least once during this
		// turn. Tracked per-assistant so nested calls in the invocation
		// stack cannot cross-contaminate one another.
		$aid_key = (string) self::sanitize_scalar_id( $assistant_id );
		if ( '' !== $aid_key && isset( $this->iteration_counts[ $aid_key ] ) ) {
			$iterations = (int) $this->iteration_counts[ $aid_key ];
			unset( $this->iteration_counts[ $aid_key ] );
			if ( $iterations > 0 ) {
				$collector->record( ChatTurnMetrics::CHAT_AGENTIC_ITERATIONS, $iterations, $ctx );
			}
		}

		// Emit token-usage metrics directly from the response when the
		// provider supplied them. `wp_mcp_ai_cost_calculated` supplies
		// cost but not raw tokens, and may not fire for errored or
		// token-less responses — we want the token histograms to be
		// populated even when cost is unavailable.
		if ( is_array( $response ) && isset( $response['usage'] ) && is_array( $response['usage'] ) ) {
			$prompt     = isset( $response['usage']['prompt_tokens'] ) ? (int) $response['usage']['prompt_tokens'] : 0;
			$completion = isset( $response['usage']['completion_tokens'] ) ? (int) $response['usage']['completion_tokens'] : 0;
			if ( $prompt > 0 ) {
				$collector->record( ChatTurnMetrics::TOKEN_USAGE_PROMPT, $prompt, $ctx );
			}
			if ( $completion > 0 ) {
				$collector->record( ChatTurnMetrics::TOKEN_USAGE_COMPLETION, $completion, $ctx );
			}
		}
	}

	/**
	 * Observability hook: emit realised-cost metric.
	 *
	 * Signature matches `do_action( 'wp_mcp_ai_cost_calculated', $cost_data, $assistant_id, $user_id, $response, $request )`.
	 *
	 * @param mixed $cost_data    Cost data array.
	 * @param mixed $assistant_id Assistant identifier.
	 * @param mixed $user_id      User identifier.
	 * @param mixed $response     Provider response (used to derive provider/model if absent from cost_data).
	 * @param mixed $request      REST request (unused).
	 * @return void
	 */
	public function on_cost( $cost_data = null, $assistant_id = null, $user_id = null, $response = null, $request = null ) {
		unset( $request );

		if ( ! is_array( $cost_data ) || ! isset( $cost_data['cost_usd'] ) ) {
			return;
		}

		$cost = (float) $cost_data['cost_usd'];
		if ( $cost <= 0 ) {
			return;
		}

		$options = array();
		if ( isset( $cost_data['provider'] ) ) {
			$options['provider'] = $cost_data['provider'];
		} elseif ( is_array( $response ) && isset( $response['provider'] ) ) {
			$options['provider'] = $response['provider'];
		}
		if ( isset( $cost_data['model'] ) ) {
			$options['model'] = $cost_data['model'];
		} elseif ( is_array( $response ) && isset( $response['model'] ) ) {
			$options['model'] = $response['model'];
		}

		$ctx = self::base_context( $assistant_id, $options, null, $response );
		if ( null !== $user_id && '' !== $user_id ) {
			$ctx['user_id'] = (int) $user_id;
		}

		$collector = $this->collector();
		if ( null === $collector ) {
			return;
		}

		$collector->record( ChatTurnMetrics::TOKEN_USAGE_TOTAL_COST_USD, $cost, $ctx );
	}

	/**
	 * Observability hook: record a completed agentic-loop iteration.
	 *
	 * Fires from the base plugin's REST chat path (non-streaming and
	 * streaming). Each call carries the total iteration count reached
	 * so far — we keep the maximum per assistant_id and emit a single
	 * `chat.agentic.iterations` histogram sample when the matching
	 * `wp_mcp_ai_after_chat_response` pops the turn's frame.
	 *
	 * Signature matches `do_action( 'wp_mcp_ai_agentic_iteration_complete', $iteration, $assistant_id )`.
	 *
	 * @param mixed $iteration    Iterations completed so far (1-based).
	 * @param mixed $assistant_id Assistant identifier.
	 * @return void
	 */
	public function on_agentic_iteration( $iteration = 0, $assistant_id = null ) {
		$count = (int) $iteration;
		if ( $count <= 0 ) {
			return;
		}
		$key = (string) self::sanitize_scalar_id( $assistant_id );
		if ( '' === $key ) {
			return;
		}
		$prev                           = isset( $this->iteration_counts[ $key ] ) ? (int) $this->iteration_counts[ $key ] : 0;
		$this->iteration_counts[ $key ] = max( $prev, $count );
	}

	/**
	 * Current invocation-stack depth (tests / assertions).
	 *
	 * @return int
	 */
	public function depth() {
		return count( $this->stack );
	}

	/**
	 * Pop the top frame if its assistant_id matches; otherwise scan
	 * top-down for a match and remove it. Returns the frame or null.
	 *
	 * @param string|int $assistant_id Assistant id.
	 * @return array<string,mixed>|null
	 */
	private function pop_matching( $assistant_id ) {
		$top = end( $this->stack );
		if ( is_array( $top ) && isset( $top['assistant_id'] ) && (string) $top['assistant_id'] === (string) $assistant_id ) {
			return array_pop( $this->stack );
		}
		for ( $i = count( $this->stack ) - 1; $i >= 0; $i-- ) {
			if ( isset( $this->stack[ $i ]['assistant_id'] )
				&& (string) $this->stack[ $i ]['assistant_id'] === (string) $assistant_id ) {
				$frame = $this->stack[ $i ];
				array_splice( $this->stack, $i, 1 );
				return $frame;
			}
		}
		return null;
	}

	/**
	 * Build the context payload passed to `record()`. Kept deliberately
	 * small to stay inside the Internal privacy tier.
	 *
	 * @param mixed                     $assistant_id Assistant id.
	 * @param array<string,mixed>       $options      Request options (provider/model only).
	 * @param string|null               $outcome      'success' / 'error' / null.
	 * @param array<string,mixed>|mixed $response   Response (provider/model fallback).
	 * @return array<string,mixed>
	 */
	private static function base_context( $assistant_id, $options, $outcome, $response = null ) {
		$out = array();
		$aid = self::sanitize_scalar_id( $assistant_id );
		if ( '' !== $aid && 0 !== $aid ) {
			$out['assistant_id'] = $aid;
		}
		if ( null !== $outcome ) {
			$out['outcome'] = $outcome;
		}
		if ( is_array( $options ) ) {
			if ( ! empty( $options['provider'] ) && is_string( $options['provider'] ) ) {
				$out['provider'] = sanitize_key( $options['provider'] );
			}
			if ( ! empty( $options['model'] ) && is_string( $options['model'] ) ) {
				$out['model'] = preg_replace( '/[^a-z0-9._\-:\/]/i', '', $options['model'] );
			}
			if ( ! empty( $options['guest_request'] ) ) {
				$out['guest'] = true;
			}
		}
		if ( empty( $out['provider'] ) && is_array( $response ) && ! empty( $response['provider'] ) && is_string( $response['provider'] ) ) {
			$out['provider'] = sanitize_key( $response['provider'] );
		}
		if ( empty( $out['model'] ) && is_array( $response ) && ! empty( $response['model'] ) && is_string( $response['model'] ) ) {
			$out['model'] = preg_replace( '/[^a-z0-9._\-:\/]/i', '', $response['model'] );
		}
		return $out;
	}

	/**
	 * Redact an options array down to just the fields allowed at the
	 * Internal privacy tier. Only `provider`, `model`, and `guest_request`
	 * survive — everything else (system prompts, user ids, API keys,
	 * attachments) is discarded.
	 *
	 * @param array<string,mixed> $options Raw options.
	 * @return array<string,mixed>
	 */
	private static function redact_options( $options ) {
		$out = array();
		if ( isset( $options['provider'] ) && is_string( $options['provider'] ) ) {
			$out['provider'] = $options['provider'];
		}
		if ( isset( $options['model'] ) && is_string( $options['model'] ) ) {
			$out['model'] = $options['model'];
		}
		if ( ! empty( $options['guest_request'] ) ) {
			$out['guest_request'] = true;
		}
		return $out;
	}

	/**
	 * Classify a chat response.
	 *
	 * `WP_Error` → error. Provider responses with a truthy top-level
	 * `error` key → error. Everything else (including arrays with only
	 * `choices`) is success.
	 *
	 * @param mixed $response Chat response.
	 * @return string 'success' or 'error'.
	 */
	private static function outcome_from_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return 'error';
		}
		if ( is_array( $response ) && ! empty( $response['error'] ) ) {
			return 'error';
		}
		return 'success';
	}

	/**
	 * Sanitize an id-like scalar for context inclusion.
	 *
	 * @param mixed $value Raw value.
	 * @return string|int
	 */
	private static function sanitize_scalar_id( $value ) {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		if ( is_string( $value ) ) {
			return sanitize_key( $value );
		}
		return '';
	}

	/**
	 * Resolve the collector lazily.
	 *
	 * @return MetricCollector
	 */
	private function collector() {
		return MetricCollector::get_instance();
	}
}
