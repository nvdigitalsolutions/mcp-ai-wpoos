<?php
/**
 * Speculative Tool Executor Service
 *
 * Implements speculative tool execution — drafting a block of tools ahead
 * of execution and verifying results batch-style. After each tool in the
 * predicted chain executes, its result is compared against the prediction;
 * execution stops at the first rejection. Inspired by DSpark's speculative
 * decoding approach.
 *
 * Part of Phase 2: Load Balancing & Efficiency enhancements.
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Speculative Tool Executor class
 *
 * Drafts and executes tool blocks speculatively, verifying each result
 * against predictions before proceeding to the next tool in the chain.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Speculative_Tool_Executor {

	/**
	 * Maximum number of tools to include in a speculative block by default.
	 *
	 * @var int
	 */
	const DEFAULT_BLOCK_SIZE = 4;

	/**
	 * Minimum acceptance rate required to continue speculating.
	 *
	 * @var float
	 */
	const MIN_ACCEPTANCE_RATE = 0.6;

	/**
	 * Absolute ceiling on speculative block size.
	 *
	 * @var int
	 */
	const MAX_BLOCK_SIZE = 6;

	/**
	 * Maximum number of acceptance records retained in memory.
	 *
	 * @var int
	 */
	const ACCEPTANCE_HISTORY_LIMIT = 100;

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $registry = null;

	/**
	 * Tool chain predictor instance.
	 *
	 * @var WP_MCP_AI_Tool_Chain_Predictor|null
	 */
	protected $predictor = null;

	/**
	 * In-memory acceptance history for the current session.
	 *
	 * Each entry is an associative array with keys:
	 *   - tool_slug (string)
	 *   - accepted  (bool)
	 *   - timestamp (int)
	 *
	 * @var array
	 */
	protected $acceptance_history = array();

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Tool_Registry|null        $registry  Tool registry instance.
	 * @param WP_MCP_AI_Tool_Chain_Predictor|null $predictor Tool chain predictor instance.
	 */
	public function __construct( $registry = null, $predictor = null ) {
		$this->registry  = $registry;
		$this->predictor = $predictor;
	}

	/**
	 * Execute a speculative block of tools.
	 *
	 * Iterates through the predicted chain, executing each tool via the
	 * registry. After every execution the result is compared against the
	 * prediction; if verification fails the block is halted immediately and
	 * only results up to (but not including) the rejected tool are returned.
	 *
	 * @param array $predicted_chain Ordered list of predicted tool calls.
	 *                               Each item may contain:
	 *                               - tool_slug        (string, required)
	 *                               - arguments        (array, optional)
	 *                               - predicted_result (mixed, optional)
	 *                               - confidence       (float, optional)
	 * @param array $context         Execution context (assistant ID, user
	 *                               capabilities, etc.).
	 * @return array|WP_Error Associative array with keys:
	 *                        - results        (array)  tool slug => result pairs
	 *                        - accepted_count (int)    number of accepted tools
	 *                        - rejected_at    (int|null) zero-based index of first
	 *                          rejection, or null if all accepted
	 *                        - verified       (bool)   whether full chain was verified
	 *                        Returns WP_Error when the chain is empty or malformed.
	 */
	public function execute_speculative_block( $predicted_chain, $context = array() ) {
		if ( ! is_array( $predicted_chain ) || empty( $predicted_chain ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_chain',
				__( 'The predicted tool chain is empty or malformed.', 'mcp-ai-wpoos' )
			);
		}

		// Sanitize context.
		$context = $this->sanitize_context( $context );

		// Guard against oversized blocks.
		$block_size = min( count( $predicted_chain ), self::MAX_BLOCK_SIZE );
		if ( count( $predicted_chain ) > self::MAX_BLOCK_SIZE ) {
			$predicted_chain = array_slice( $predicted_chain, 0, self::MAX_BLOCK_SIZE );
		}

		$registry = $this->get_registry();
		if ( ! $registry ) {
			return new WP_Error(
				'wp_mcp_ai_no_registry',
				__( 'Tool registry is unavailable for speculative execution.', 'mcp-ai-wpoos' )
			);
		}

		$results        = array();
		$accepted_count = 0;
		$rejected_at    = null;
		$verified       = true;

		foreach ( $predicted_chain as $index => $prediction ) {
			// Validate prediction shape.
			if ( ! is_array( $prediction ) || empty( $prediction['tool_slug'] ) ) {
				$rejected_at = $index;
				$verified    = false;

				$this->record_acceptance( 'unknown', false );
				break;
			}

			$tool_slug        = sanitize_key( $prediction['tool_slug'] );
			$arguments        = isset( $prediction['arguments'] ) && is_array( $prediction['arguments'] )
				? $prediction['arguments']
				: array();
			$predicted_result = array_key_exists( 'predicted_result', $prediction )
				? $prediction['predicted_result']
				: null;

			// Execute the tool.
			$actual_result = $registry->execute_tool( $tool_slug, $arguments, $context );

			// Verify the result against prediction.
			$accepted = $this->verify_tool_result( $predicted_result, $actual_result );

			$this->record_acceptance( $tool_slug, $accepted );

			if ( $accepted ) {
				$results[ $tool_slug ] = $actual_result;
				++$accepted_count;
			} else {
				$rejected_at = $index;
				$verified    = false;
				break;
			}
		}

		return array(
			'results'        => $results,
			'accepted_count' => $accepted_count,
			'rejected_at'    => $rejected_at,
			'verified'       => $verified,
		);
	}

	/**
	 * Determine if speculative execution is appropriate.
	 *
	 * Evaluates context, confidence score, system load, and historical
	 * acceptance rate to decide whether speculating is worthwhile.
	 *
	 * @param array $context    Execution context (e.g. task type, assistant ID).
	 * @param float $confidence Confidence score for the predicted chain (0.0 - 1.0).
	 * @return bool True if speculative execution should proceed.
	 */
	public function should_speculate( $context, $confidence ) {
		$context    = $this->sanitize_context( $context );
		$confidence = (float) $confidence;

		// Minimum confidence threshold.
		if ( $confidence < self::MIN_ACCEPTANCE_RATE ) {
			return false;
		}

		// Check historical acceptance rate — if too low, speculating is wasteful.
		$stats = $this->get_acceptance_stats();
		if ( $stats['total'] > 0 && $stats['acceptance_rate'] < self::MIN_ACCEPTANCE_RATE ) {
			return false;
		}

		// Respect system load — do not speculate under heavy load.
		if ( function_exists( 'wp_get_server_load' ) ) {
			$load = wp_get_server_load();
			if ( $load > 0.85 ) {
				return false;
			}
		}

		// Favour speculation for read-heavy task types; avoid for write-heavy ones.
		$task_type = isset( $context['task_type'] ) ? sanitize_key( $context['task_type'] ) : 'general';

		$speculation_friendly_types = array( 'research', 'list', 'analyze', 'general' );
		if ( ! in_array( $task_type, $speculation_friendly_types, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Return acceptance rate statistics for the current session.
	 *
	 * @return array Associative array with keys:
	 *               - total           (int)   total recorded attempts
	 *               - accepted        (int)   accepted count
	 *               - rejected        (int)   rejected count
	 *               - acceptance_rate (float) ratio accepted / total, or 1.0 if no data
	 *               - recent_tools    (array) last 10 recorded slugs (most recent first)
	 */
	public function get_acceptance_stats() {
		$total    = count( $this->acceptance_history );
		$accepted = 0;
		$rejected = 0;
		$recent   = array();

		foreach ( $this->acceptance_history as $entry ) {
			if ( ! empty( $entry['accepted'] ) ) {
				++$accepted;
			} else {
				++$rejected;
			}
		}

		// Collect most recent tools (last 10).
		$recent_entries = array_slice( $this->acceptance_history, -10 );
		foreach ( $recent_entries as $entry ) {
			$recent[] = isset( $entry['tool_slug'] ) ? $entry['tool_slug'] : 'unknown';
		}

		return array(
			'total'           => $total,
			'accepted'        => $accepted,
			'rejected'        => $rejected,
			'acceptance_rate' => $total > 0 ? round( $accepted / $total, 4 ) : 1.0,
			'recent_tools'    => array_reverse( $recent ),
		);
	}

	/**
	 * Compare predicted tool output against actual result.
	 *
	 * When a predicted_result is provided, performs a structural comparison;
	 * when absent, accepts any non-WP_Error result (successful execution).
	 *
	 * @param mixed $predicted_result The expected output, or null if none.
	 * @param mixed $actual_result    The actual output from tool execution.
	 * @return bool True if the result is accepted.
	 */
	protected function verify_tool_result( $predicted_result, $actual_result ) {
		// Reject WP_Error results outright.
		if ( is_wp_error( $actual_result ) ) {
			return false;
		}

		// If no prediction was provided, accept any successful result.
		if ( null === $predicted_result ) {
			return true;
		}

		// Type mismatch is always a rejection.
		if ( gettype( $predicted_result ) !== gettype( $actual_result ) ) {
			return false;
		}

		// Array comparison: check key existence and value types.
		if ( is_array( $predicted_result ) && is_array( $actual_result ) ) {
			foreach ( $predicted_result as $key => $expected_value ) {
				if ( ! array_key_exists( $key, $actual_result ) ) {
					return false;
				}

				// Structural match: same type and, for scalars, same value.
				$actual_value = $actual_result[ $key ];
				if ( gettype( $expected_value ) !== gettype( $actual_value ) ) {
					return false;
				}

				if ( is_scalar( $expected_value ) && $expected_value !== $actual_value ) {
					return false;
				}
			}

			return true;
		}

		// Scalar loose comparison.
		if ( is_scalar( $predicted_result ) ) {
			return $predicted_result == $actual_result; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Intentional loose comparison for numeric/string flexibility.
		}

		// Objects: strict reference comparison (speculative objects are unlikely, but covered).
		return $predicted_result === $actual_result;
	}

	/**
	 * Store an acceptance data point for metrics.
	 *
	 * Maintains an in-memory FIFO buffer of the most recent acceptance
	 * records, bounded by ACCEPTANCE_HISTORY_LIMIT.
	 *
	 * @param string $tool_slug The tool slug that was executed.
	 * @param bool   $accepted  Whether the result was accepted.
	 * @return void
	 */
	protected function record_acceptance( $tool_slug, $accepted ) {
		$tool_slug = sanitize_key( $tool_slug );
		$accepted  = (bool) $accepted;

		$this->acceptance_history[] = array(
			'tool_slug' => $tool_slug,
			'accepted'  => $accepted,
			'timestamp' => time(),
		);

		// Trim to limit.
		if ( count( $this->acceptance_history ) > self::ACCEPTANCE_HISTORY_LIMIT ) {
			$this->acceptance_history = array_slice(
				$this->acceptance_history,
				-self::ACCEPTANCE_HISTORY_LIMIT
			);
		}
	}

	/**
	 * Retrieve the tool registry, falling back to the global instance.
	 *
	 * @return WP_MCP_AI_Tool_Registry|null
	 */
	private function get_registry() {
		if ( null !== $this->registry ) {
			return $this->registry;
		}

		if ( function_exists( 'wp_mcp_ai_get_tool_registry' ) ) {
			$this->registry = wp_mcp_ai_get_tool_registry();
		}

		return $this->registry;
	}

	/**
	 * Retrieve the tool chain predictor, falling back to the global instance.
	 *
	 * @return WP_MCP_AI_Tool_Chain_Predictor|null
	 */
	private function get_predictor() {
		if ( null !== $this->predictor ) {
			return $this->predictor;
		}

		if ( function_exists( 'wp_mcp_ai_get_tool_chain_predictor' ) ) {
			$this->predictor = wp_mcp_ai_get_tool_chain_predictor();
		}

		return $this->predictor;
	}

	/**
	 * Sanitize the execution context array, returning only allowed keys.
	 *
	 * @param array $context Raw context array.
	 * @return array Sanitized context.
	 */
	private function sanitize_context( $context ) {
		if ( ! is_array( $context ) ) {
			return array();
		}

		$sanitized    = array();
		$allowed_keys = array( 'assistant_id', 'user_id', 'task_type', 'capabilities', 'session_id' );
		$integer_keys = array( 'assistant_id', 'user_id' );
		$array_keys   = array( 'capabilities' );
		$string_keys  = array( 'task_type', 'session_id' );

		foreach ( $allowed_keys as $key ) {
			if ( ! array_key_exists( $key, $context ) ) {
				continue;
			}

			if ( in_array( $key, $integer_keys, true ) ) {
				$sanitized[ $key ] = absint( $context[ $key ] );
			} elseif ( in_array( $key, $array_keys, true ) ) {
				$sanitized[ $key ] = is_array( $context[ $key ] )
					? array_map( 'sanitize_text_field', $context[ $key ] )
					: array();
			} elseif ( in_array( $key, $string_keys, true ) ) {
				$sanitized[ $key ] = sanitize_text_field( $context[ $key ] );
			}
		}

		return $sanitized;
	}
}
