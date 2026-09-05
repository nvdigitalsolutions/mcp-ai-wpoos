<?php
/**
 * Agent Capability Boundary — CoSAI Principle 2 (Bounded & Resilient)
 *
 * Enforces strict, purpose-specific entitlements on what tools an agent
 * session can call, regardless of what the LLM decides. Each session is
 * provisioned with an immutable allow-list of tool slugs, a configurable
 * iteration cap, and per-tool rate limits.
 *
 * This class embodies the CoSAI Principle 2 design goal: every agent
 * session operates inside a hard boundary defined at creation time. No
 * runtime code path — filter overrides, tool aliases, or LLM reasoning —
 * can escape that boundary without an explicit, auditable filter hook.
 *
 * Session state is persisted in WordPress transients so that rate-limit
 * counters and execution logs survive across REST requests within the
 * same agentic loop.
 *
 * @package    WP_MCP_AI
 * @since      1.2.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions
 * @license    GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CoSAI Principle 2 Capability Boundary for agent sessions.
 *
 * Immutable at creation: once a session is provisioned with its allowed-tool
 * set, that set cannot be altered. Rate limits and budgets are tracked in
 * WordPress transients keyed to the session ID.
 *
 * ## Design Intent (CoSAI P2 — Bounded & Resilient)
 *
 * - **Allow-list gate:** `can_execute()` consults the immutable set first,
 *   then applies the `wp_mcp_ai_capability_boundary_allow_tool` filter so
 *   that site administrators can carve out explicit overrides — but never
 *   silently.
 * - **Per-tool rate limiting:** `check_rate_limit()` reads a configurable
 *   rate map (filter: `wp_mcp_ai_capability_boundary_rate_limit`) and
 *   compares against a transient-backed counter that is incremented by
 *   `record_execution()`.
 * - **Iteration / token budget:** `is_budget_exhausted()` draws from the
 *   existing `wp_mcp_ai_max_agentic_iterations` filter so the session
 *   boundary respects the same iteration cap as the main agentic loop.
 *
 * @since 1.2.0
 */
class AgentCapabilityBoundary {

	/**
	 * Transient key prefix for boundary session state.
	 *
	 * @since 1.2.0
	 * @var   string
	 */
	const TRANSIENT_PREFIX = 'wp_mcp_ai_boundary_';

	/**
	 * Default rate-limit window in seconds.
	 *
	 * @since 1.2.0
	 * @var   int
	 */
	const DEFAULT_RATE_WINDOW = 60;

	/**
	 * Default maximum tool calls per rate window.
	 *
	 * @since 1.2.0
	 * @var   int
	 */
	const DEFAULT_RATE_MAX_CALLS = 30;

	/**
	 * Immutable session identifier.
	 *
	 * @since 1.2.0
	 * @var   string
	 */
	private $session_id;

	/**
	 * Immutable set of tool slugs this session is allowed to call.
	 *
	 * @since 1.2.0
	 * @var   array<string>
	 */
	private $allowed_tool_slugs;

	/**
	 * Maximum agentic iterations for this session.
	 *
	 * Resolved at construction time via the `wp_mcp_ai_max_agentic_iterations`
	 * filter. Once set, the cap does not change for the lifetime of the
	 * boundary object (though the transient-backed iteration counter
	 * increments with each recorded execution).
	 *
	 * @since 1.2.0
	 * @var   int
	 */
	private $max_iterations;

	/**
	 * Constructor.
	 *
	 * Provisions a new capability boundary for the given session. The
	 * `$allowed_tool_slugs` and `$session_id` are immutable after this
	 * point.
	 *
	 * @since 1.2.0
	 *
	 * @param string        $session_id        Unique session identifier. Sanitised via `sanitize_key()`.
	 * @param array<string> $allowed_tool_slugs List of tool slugs this session is permitted to call.
	 */
	public function __construct( $session_id, array $allowed_tool_slugs ) {
		$this->session_id         = sanitize_key( $session_id );
		$this->allowed_tool_slugs = array_map( 'sanitize_key', $allowed_tool_slugs );

		/**
		 * Resolve the maximum iteration count through the existing filter.
		 *
		 * This ensures the boundary's iteration cap stays in lock-step with
		 * the main agentic loop (REST handler, schedule manager, etc.).
		 *
		 * @since 1.2.0
		 *
		 * @param int   $max_iterations   Default maximum iterations.
		 * @param array $assistant_config Assistant configuration array (empty here — boundary is assistant-agnostic).
		 */
		$default_iterations   = 5;
		$this->max_iterations = (int) apply_filters( 'wp_mcp_ai_max_agentic_iterations', $default_iterations, array() );
		$this->max_iterations = max( 1, min( 50, $this->max_iterations ) );

		// Initialise the transient-backed execution counter if needed.
		if ( false === $this->get_transient( 'iteration_count' ) ) {
			$this->set_transient( 'iteration_count', 0 );
		}
	}

	// -------------------------------------------------------------------------
	// Allow-List Gate
	// -------------------------------------------------------------------------

	/**
	 * Get the session identifier for this boundary.
	 *
	 * @since 1.2.0
	 * @return string
	 */
	public function get_session_id() {
		return $this->session_id;
	}

	/**
	 * Check whether a tool slug is permitted to execute in this session.
	 *
	 * Consults the immutable allow-list first. If the tool is not listed,
	 * the `wp_mcp_ai_capability_boundary_allow_tool` filter is fired so
	 * that other code can grant a one-off override — for example, an
	 * admin-whitelist that adds emergency tools mid-session.
	 *
	 * @since 1.2.0
	 *
	 * @param string $tool_slug Tool slug to check.
	 * @return bool True if the tool may execute.
	 */
	public function can_execute( $tool_slug ) {
		$tool_slug = sanitize_key( $tool_slug );

		$allowed = in_array( $tool_slug, $this->allowed_tool_slugs, true );

		/**
		 * Filters whether a tool is allowed to execute within a capability boundary.
		 *
		 * Use this filter to grant access to tools that were not part of the
		 * original allow-list — for example, when a site administrator has
		 * temporarily elevated a session's privileges.
		 *
		 * @since 1.2.0
		 *
		 * @param bool   $allowed    Whether the tool is currently allowed.
		 * @param string $tool_slug  The tool slug being evaluated.
		 * @param string $session_id The session identifier.
		 */
		return (bool) apply_filters( 'wp_mcp_ai_capability_boundary_allow_tool', $allowed, $tool_slug, $this->session_id );
	}

	// -------------------------------------------------------------------------
	// Rate Limiting
	// -------------------------------------------------------------------------

	/**
	 * Check whether a tool has exceeded its per-window rate limit.
	 *
	 * Each tool has an independent rate limit defined by the
	 * `wp_mcp_ai_capability_boundary_rate_limit` filter (default:
	 * DEFAULT_RATE_MAX_CALLS calls per DEFAULT_RATE_WINDOW seconds).
	 *
	 * @since 1.2.0
	 *
	 * @param string $tool_slug Tool slug to check.
	 * @return bool True if the tool is within its rate limit (allowed to proceed).
	 */
	public function check_rate_limit( $tool_slug ) {
		$tool_slug = sanitize_key( $tool_slug );

		/**
		 * Filters the per-tool rate-limit configuration within a capability boundary.
		 *
		 * Return an associative array with optional keys:
		 * - `max_calls` (int): Maximum calls per window. Default 30.
		 * - `window`    (int): Window duration in seconds. Default 60.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $rate_config Current rate configuration.
		 * @param string $tool_slug   The tool slug being evaluated.
		 * @param string $session_id  The session identifier.
		 */
		$rate_config = apply_filters(
			'wp_mcp_ai_capability_boundary_rate_limit',
			array(
				'max_calls' => self::DEFAULT_RATE_MAX_CALLS,
				'window'    => self::DEFAULT_RATE_WINDOW,
			),
			$tool_slug,
			$this->session_id
		);

		$max_calls = isset( $rate_config['max_calls'] ) ? absint( $rate_config['max_calls'] ) : self::DEFAULT_RATE_MAX_CALLS;
		$window    = isset( $rate_config['window'] ) ? absint( $rate_config['window'] ) : self::DEFAULT_RATE_WINDOW;

		$rate_data = $this->get_transient( 'rate_limits' );

		if ( ! is_array( $rate_data ) ) {
			$rate_data = array();
		}

		$tool_key = $tool_slug;

		if ( ! isset( $rate_data[ $tool_key ] ) ) {
			return true; // No calls recorded yet — within limit.
		}

		$record = $rate_data[ $tool_key ];

		// If the window has expired, reset the counter.
		if ( isset( $record['window_start'] ) && ( time() - $record['window_start'] ) > $window ) {
			unset( $rate_data[ $tool_key ] );
			$this->set_transient( 'rate_limits', $rate_data );
			return true;
		}

		$current_count = isset( $record['count'] ) ? absint( $record['count'] ) : 0;

		return $current_count < $max_calls;
	}

	/**
	 * Record a tool execution for rate-limiting and budgeting purposes.
	 *
	 * Increments the per-tool rate-limit counter, bumps the iteration
	 * counter, and appends a lightweight execution record to the session
	 * log transient. Call this AFTER the tool's `execute()` method has
	 * returned.
	 *
	 * @since 1.2.0
	 *
	 * @param string $tool_slug The tool slug that was executed.
	 * @param array  $arguments The sanitised arguments passed to the tool.
	 * @param mixed  $result    The result returned by the tool (array or WP_Error).
	 * @return void
	 */
	public function record_execution( $tool_slug, $arguments, $result ) {
		$tool_slug = sanitize_key( $tool_slug );

		// --- Rate-limit counter ------------------------------------------------

		$rate_data = $this->get_transient( 'rate_limits' );

		if ( ! is_array( $rate_data ) ) {
			$rate_data = array();
		}

		$tool_key = $tool_slug;

		if ( ! isset( $rate_data[ $tool_key ] ) ) {
			$rate_data[ $tool_key ] = array(
				'window_start' => time(),
				'count'        => 1,
			);
		} else {
			$rate_data[ $tool_key ]['count'] = absint( $rate_data[ $tool_key ]['count'] ) + 1;
			if ( ! isset( $rate_data[ $tool_key ]['window_start'] ) ) {
				$rate_data[ $tool_key ]['window_start'] = time();
			}
		}

		$this->set_transient( 'rate_limits', $rate_data );

		// --- Iteration counter -------------------------------------------------

		$iteration_count = absint( $this->get_transient( 'iteration_count' ) );
		$this->set_transient( 'iteration_count', $iteration_count + 1 );

		// --- Execution log (lightweight, append-only) --------------------------

		$execution_log = $this->get_transient( 'execution_log' );

		if ( ! is_array( $execution_log ) ) {
			$execution_log = array();
		}

		$execution_log[] = array(
			'tool_slug' => $tool_slug,
			'timestamp' => time(),
			'success'   => ! is_wp_error( $result ),
		);

		// Keep only the last 100 entries to bound transient size.
		if ( count( $execution_log ) > 100 ) {
			$execution_log = array_slice( $execution_log, -100 );
		}

		$this->set_transient( 'execution_log', $execution_log );
	}

	// -------------------------------------------------------------------------
	// Budget Tracking
	// -------------------------------------------------------------------------

	/**
	 * Return the remaining budget for this session.
	 *
	 * Budget dimensions:
	 * - `iterations_used` / `iterations_max`: consumed vs. cap.
	 * - `tool_calls_used`  / `tool_calls_max` : per-tool calls consumed vs. cap (aggregate).
	 * - `token_budget`     : reserved for future CoSAI P2 token-budget integration. Always `null` for now.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string,int|null> Budget snapshot.
	 */
	public function get_remaining_budget() {
		$iteration_count = absint( $this->get_transient( 'iteration_count' ) );

		// Aggregate tool-call count across all rate-tracked tools.
		$rate_data  = $this->get_transient( 'rate_limits' );
		$tool_calls = 0;
		$tool_max   = 0;

		if ( is_array( $rate_data ) ) {
			foreach ( $rate_data as $record ) {
				$tool_calls += isset( $record['count'] ) ? absint( $record['count'] ) : 0;
			}
		}

		// Sum the per-tool max_calls for an aggregate ceiling.
		$tool_max = count( $this->allowed_tool_slugs ) * self::DEFAULT_RATE_MAX_CALLS;

		return array(
			'iterations_used' => $iteration_count,
			'iterations_max'  => $this->max_iterations,
			'tool_calls_used' => $tool_calls,
			'tool_calls_max'  => $tool_max,
			'token_budget'    => null, // Reserved — not yet implemented.
		);
	}

	/**
	 * Check whether the session budget has been exhausted.
	 *
	 * Returns true when the iteration counter has reached or exceeded the
	 * `wp_mcp_ai_max_agentic_iterations` cap.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if no further tool executions should be allowed.
	 */
	public function is_budget_exhausted() {
		$budget = $this->get_remaining_budget();

		return $budget['iterations_used'] >= $budget['iterations_max'];
	}

	// -------------------------------------------------------------------------
	// Internal Helpers
	// -------------------------------------------------------------------------

	/**
	 * Read a boundary-scoped transient value.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key Short key name (e.g. 'rate_limits', 'iteration_count').
	 * @return mixed The transient value, or false if not set.
	 */
	private function get_transient( $key ) {
		$transient_key = self::TRANSIENT_PREFIX . $this->session_id . '_' . sanitize_key( $key );
		return get_transient( $transient_key );
	}

	/**
	 * Write a boundary-scoped transient value.
	 *
	 * Transient expiration is set to 1 hour (3600 seconds) by default,
	 * which is generous for a single agentic-loop session while still
	 * preventing orphaned data from accumulating indefinitely.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key        Short key name.
	 * @param mixed  $value      Value to store. Must be serialisable.
	 * @param int    $expiration Optional. Expiration in seconds. Default 3600.
	 * @return bool True on success, false on failure.
	 */
	private function set_transient( $key, $value, $expiration = 3600 ) {
		$transient_key = self::TRANSIENT_PREFIX . $this->session_id . '_' . sanitize_key( $key );
		return set_transient( $transient_key, $value, absint( $expiration ) );
	}

	/**
	 * Delete a boundary-scoped transient value.
	 *
	 * Useful for tearing down session state after the agentic loop completes.
	 *
	 * @since 1.2.0
	 *
	 * @param string $key Short key name.
	 * @return bool True on success, false on failure.
	 */
	public function delete_transient( $key ) {
		$transient_key = self::TRANSIENT_PREFIX . $this->session_id . '_' . sanitize_key( $key );
		return delete_transient( $transient_key );
	}

	/**
	 * Tear down all transient state for this session.
	 *
	 * Call this when the agentic loop finishes (success or failure) to
	 * prevent stale boundary data from leaking into subsequent requests.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function destroy() {
		$this->delete_transient( 'rate_limits' );
		$this->delete_transient( 'iteration_count' );
		$this->delete_transient( 'execution_log' );
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile
