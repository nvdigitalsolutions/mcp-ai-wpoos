<?php
/**
 * Cumulative byte-budget tracker for the agentic loop.
 *
 * Phase 3 of the massive-data hardening plan. Tracks the total bytes of
 * tool output that have entered the LLM context across all iterations of
 * a single agentic loop, and provides a single source of truth for the
 * agentic-loop output guard to spill oversized tool results to artifacts
 * rather than blowing up token cost / context size.
 *
 * Per-request scoped: callers should `start( $request_id )` at the top of
 * the loop and `reset()` (or scope to a fresh tracker) on completion.
 *
 * Filters:
 *   - `wp_mcp_ai_agentic_loop_byte_budget` (int) — overall ceiling per
 *     request. Default 1 MiB.
 *   - `wp_mcp_ai_agentic_loop_per_message_byte_budget` (int) — ceiling for a
 *     single tool message before we artifact-spill it. Default 64 KiB
 *     (matches `WP_MCP_AI_REST_Validator::TOOL_RESULT_MAX_BYTES`).
 *
 * Action: `wp_mcp_ai_tool_output_truncated` fires whenever a tool message is
 * spilled, with `( $tool_name, $original_bytes, $artifact_id, $request_id )`.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cumulative byte-budget tracker for the agentic loop.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Data_Budget_Tracker {

	/**
	 * Default overall request budget (1 MiB).
	 */
	const DEFAULT_REQUEST_BUDGET_BYTES = 1048576;

	/**
	 * Default per-message budget (64 KiB).
	 */
	const DEFAULT_PER_MESSAGE_BUDGET_BYTES = 65536;

	/**
	 * Bytes consumed in the current request.
	 *
	 * @var int
	 */
	private $consumed_bytes = 0;

	/**
	 * Optional request identifier for diagnostics.
	 *
	 * @var string
	 */
	private $request_id = '';

	/**
	 * Number of times a message has been spilled to an artifact.
	 *
	 * @var int
	 */
	private $spill_count = 0;

	/**
	 * Constructor.
	 *
	 * @param string $request_id Optional request identifier.
	 */
	public function __construct( $request_id = '' ) {
		$this->request_id = (string) $request_id;
	}

	/**
	 * Resolve the overall request budget.
	 *
	 * @return int
	 */
	public function get_request_budget() {
		$budget = (int) apply_filters(
			'wp_mcp_ai_agentic_loop_byte_budget',
			self::DEFAULT_REQUEST_BUDGET_BYTES,
			$this->request_id
		);

		return max( 1024, $budget );
	}

	/**
	 * Resolve the per-message budget.
	 *
	 * @return int
	 */
	public function get_per_message_budget() {
		$budget = (int) apply_filters(
			'wp_mcp_ai_agentic_loop_per_message_byte_budget',
			self::DEFAULT_PER_MESSAGE_BUDGET_BYTES,
			$this->request_id
		);

		return max( 512, $budget );
	}

	/**
	 * Record bytes consumed by a tool message.
	 *
	 * @param int $bytes Bytes consumed.
	 * @return void
	 */
	public function record( $bytes ) {
		$this->consumed_bytes += max( 0, (int) $bytes );
	}

	/**
	 * Return total bytes consumed.
	 *
	 * @return int
	 */
	public function consumed() {
		return (int) $this->consumed_bytes;
	}

	/**
	 * Return remaining bytes in the request budget.
	 *
	 * @return int
	 */
	public function remaining() {
		return max( 0, $this->get_request_budget() - $this->consumed_bytes );
	}

	/**
	 * Whether the overall request budget has been exhausted.
	 *
	 * @return bool
	 */
	public function is_exhausted() {
		return $this->consumed_bytes >= $this->get_request_budget();
	}

	/**
	 * Whether a single tool message of the supplied size should be
	 * spilled to an artifact (because it would push us over the
	 * per-message ceiling, or would exhaust the request budget).
	 *
	 * @param int $bytes Bytes the message will contribute.
	 * @return bool
	 */
	public function should_spill( $bytes ) {
		$bytes = max( 0, (int) $bytes );

		if ( $bytes > $this->get_per_message_budget() ) {
			return true;
		}

		if ( ( $this->consumed_bytes + $bytes ) > $this->get_request_budget() ) {
			return true;
		}

		return false;
	}

	/**
	 * Increment the spill counter (called by the loop integration).
	 *
	 * @return void
	 */
	public function note_spill() {
		++$this->spill_count;
	}

	/**
	 * Spills observed in this request.
	 *
	 * @return int
	 */
	public function spill_count() {
		return (int) $this->spill_count;
	}

	/**
	 * Reset state.
	 *
	 * @param string $request_id Optional new request identifier.
	 * @return void
	 */
	public function reset( $request_id = '' ) {
		$this->consumed_bytes = 0;
		$this->spill_count    = 0;
		$this->request_id     = (string) $request_id;
	}

	/**
	 * Request identifier (read-only).
	 *
	 * @return string
	 */
	public function request_id() {
		return $this->request_id;
	}
}
