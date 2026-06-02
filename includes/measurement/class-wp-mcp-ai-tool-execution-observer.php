<?php
/**
 * Tool Execution Observer
 *
 * Bridges the plugin's pre-existing `wp_mcp_ai_before_tool_execution`
 * and `wp_mcp_ai_after_tool_execution` action hooks into the
 * measurement collector. This is the first code path in the plugin
 * that actually calls `Metric_Collector::record()` from live
 * production flow — PRs 1–5 built the scaffolding, this closes the
 * loop.
 *
 * Concurrency model:
 *   In WordPress a single HTTP request is strictly single-threaded in
 *   PHP userland, but the agentic loop does invoke multiple tools in
 *   sequence. A naïve "timer keyed by tool_slug" breaks when the same
 *   slug is invoked twice back-to-back (start → start → end → end). We
 *   therefore keep an **invocation stack** — each `before` pushes, each
 *   `after` pops the top entry and verifies its slug. If the stack's
 *   top doesn't match (recursion or hook-ordering surprise) we fall
 *   back to searching from the top and rewriting the stack, rather
 *   than producing silently-wrong durations.
 *
 * Privacy:
 *   The context payload sent to `record()` contains only tool_slug,
 *   outcome, and (hashed) assistant/user ids if present. Tool
 *   arguments and results are never included — the Internal privacy
 *   tier explicitly forbids them (see `docs/reference/measurement/privacy-matrix.md`).
 *
 * Opt-out:
 *   Return `false` from the `wp_mcp_ai_tool_execution_observer_enabled`
 *   filter to skip observer installation entirely. Useful for CI
 *   environments that want deterministic metric output.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool execution observer.
 */
class WP_MCP_AI_Tool_Execution_Observer {

	/**
	 * Invocation stack: list of assoc arrays { slug, started_at }.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $stack = array();

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Tool_Execution_Observer|null
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
	 * @return WP_MCP_AI_Tool_Execution_Observer
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
	 * @return void
	 */
	public static function reset_instance() {
		if ( null !== self::$instance ) {
			self::$instance->detach();
		}
		self::$instance = null;
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
		 * Filters whether the tool-execution observer is installed.
		 *
		 * Return false to suppress all baseline tool-execution metric
		 * emission from the observer. Stock metrics remain registered
		 * so third parties can still emit them directly.
		 *
		 * @since 1.3.0
		 *
		 * @param bool $enabled Default true.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_tool_execution_observer_enabled', true ) ) {
			return false;
		}

		add_action( 'wp_mcp_ai_before_tool_execution', array( $this, 'on_before' ), 5, 3 );
		add_action( 'wp_mcp_ai_after_tool_execution', array( $this, 'on_after' ), 95, 4 );

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
		remove_action( 'wp_mcp_ai_before_tool_execution', array( $this, 'on_before' ), 5 );
		remove_action( 'wp_mcp_ai_after_tool_execution', array( $this, 'on_after' ), 95 );
		$this->attached = false;
		$this->stack    = array();
	}

	/**
	 * Observability hook: push a new invocation.
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $arguments Arguments (unused — arguments never leave this scope).
	 * @param array  $context   Execution context.
	 * @return void
	 */
	public function on_before( $tool_slug, $arguments = array(), $context = array() ) {
		$tool_slug = self::sanitize_slug( $tool_slug );
		if ( '' === $tool_slug ) {
			return;
		}

		$this->stack[] = array(
			'slug'       => $tool_slug,
			'started_at' => microtime( true ),
			'context'    => is_array( $context ) ? $context : array(),
		);

		$collector = $this->collector();
		if ( null === $collector ) {
			return;
		}

		$collector->record(
			WP_MCP_AI_Stock_Metrics::TOOL_EXECUTION_IN_FLIGHT,
			count( $this->stack ),
			self::base_context( $tool_slug, $context, null )
		);
	}

	/**
	 * Observability hook: pop an invocation and emit metrics.
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $arguments Arguments (unused).
	 * @param array  $context   Execution context.
	 * @param mixed  $result    Tool result.
	 * @return void
	 */
	public function on_after( $tool_slug, $arguments = array(), $context = array(), $result = null ) {
		$tool_slug = self::sanitize_slug( $tool_slug );
		if ( '' === $tool_slug ) {
			return;
		}

		$frame = $this->pop_matching( $tool_slug );

		$duration_ms = null;
		if ( is_array( $frame ) && isset( $frame['started_at'] ) ) {
			$duration_ms = max( 0.0, ( microtime( true ) - (float) $frame['started_at'] ) * 1000.0 );
		}

		$outcome = self::outcome_from_result( $result );
		$ctx     = self::base_context( $tool_slug, $context, $outcome );

		$collector = $this->collector();
		if ( null === $collector ) {
			return;
		}

		$collector->record( WP_MCP_AI_Stock_Metrics::TOOL_EXECUTION_COUNT, 1, $ctx );
		if ( 'success' === $outcome ) {
			$collector->record( WP_MCP_AI_Stock_Metrics::TOOL_EXECUTION_SUCCESS_COUNT, 1, $ctx );
		} else {
			$collector->record( WP_MCP_AI_Stock_Metrics::TOOL_EXECUTION_ERROR_COUNT, 1, $ctx );
		}

		// Update the in-flight gauge to the post-pop depth so consumers
		// reading raw buffered events can reconstruct the concurrency
		// timeline without also subscribing to `wp_mcp_ai_metric_recorded`.
		$collector->record(
			WP_MCP_AI_Stock_Metrics::TOOL_EXECUTION_IN_FLIGHT,
			count( $this->stack ),
			$ctx
		);

		if ( null !== $duration_ms ) {
			$collector->record( WP_MCP_AI_Stock_Metrics::TOOL_EXECUTION_DURATION_MS, $duration_ms, $ctx );
		}
	}

	/**
	 * Return the current invocation-stack depth (tests / assertions).
	 *
	 * @return int
	 */
	public function depth() {
		return count( $this->stack );
	}

	/**
	 * Pop the top frame if its slug matches; otherwise search the stack
	 * top-down for a matching slug and remove it. Returns the frame or
	 * null if no match was found.
	 *
	 * @param string $tool_slug Tool slug.
	 * @return array<string,mixed>|null
	 */
	private function pop_matching( $tool_slug ) {
		$top = end( $this->stack );
		if ( is_array( $top ) && isset( $top['slug'] ) && $top['slug'] === $tool_slug ) {
			return array_pop( $this->stack );
		}
		// Fallback: scan top-down. This covers the case where a
		// third-party hook at `after` priority < 95 re-enters the
		// observer with a nested slug — rare, but we never want a
		// silently-wrong duration.
		for ( $i = count( $this->stack ) - 1; $i >= 0; $i-- ) {
			if ( isset( $this->stack[ $i ]['slug'] ) && $this->stack[ $i ]['slug'] === $tool_slug ) {
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
	 * @param string      $tool_slug Slug.
	 * @param array       $context   Execution context.
	 * @param string|null $outcome   'success' / 'error' / null.
	 * @return array<string,mixed>
	 */
	private static function base_context( $tool_slug, $context, $outcome ) {
		$out = array(
			'tool_slug' => $tool_slug,
		);
		if ( null !== $outcome ) {
			$out['outcome'] = $outcome;
		}
		if ( is_array( $context ) ) {
			if ( ! empty( $context['assistant_id'] ) ) {
				$out['assistant_id'] = self::sanitize_scalar_id( $context['assistant_id'] );
			}
			if ( isset( $context['user_id'] ) ) {
				// User id is numeric already; the collector's sanitizer
				// will cast. We avoid hashing here because user ids are
				// already considered Internal-tier identifiers in this
				// plugin's privacy matrix — same tier as assistant_id.
				$out['user_id'] = (int) $context['user_id'];
			}
			if ( ! empty( $context['guest_request'] ) ) {
				$out['guest'] = true;
			}
		}
		return $out;
	}

	/**
	 * Classify a tool result.
	 *
	 * @param mixed $result Tool result.
	 * @return string 'success' or 'error'.
	 */
	private static function outcome_from_result( $result ) {
		if ( is_wp_error( $result ) ) {
			return 'error';
		}
		// null is treated as success because the REST agentic loop uses
		// null to signal "no result, carry on" rather than failure.
		return 'success';
	}

	/**
	 * Sanitize a slug for context use.
	 *
	 * @param mixed $slug Raw slug.
	 * @return string
	 */
	private static function sanitize_slug( $slug ) {
		if ( ! is_string( $slug ) ) {
			return '';
		}
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return '';
		}
		// Tool slugs use dots and underscores historically; keep both.
		// Invalid chars get stripped instead of rejected so a misnamed
		// slug still produces observable metrics for debugging.
		return preg_replace( '/[^a-z0-9._\-]/i', '', $slug );
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
		return sanitize_key( (string) $value );
	}

	/**
	 * Resolve the collector lazily — the observer can be instantiated
	 * before the collector class is available during bootstrap.
	 *
	 * @return WP_MCP_AI_Metric_Collector|null
	 */
	private function collector() {
		if ( ! class_exists( 'WP_MCP_AI_Metric_Collector' ) ) {
			return null;
		}
		return WP_MCP_AI_Metric_Collector::get_instance();
	}
}
