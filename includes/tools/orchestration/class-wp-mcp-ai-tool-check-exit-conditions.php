<?php
/**
 * Tool: Check Exit Conditions
 *
 * Implements dual-condition exit gate for autonomous loops.
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check Exit Conditions Tool
 */
class WP_MCP_AI_Tool_Check_Exit_Conditions {

	/**
	 * Get tool slug
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'check_exit_conditions';
	}

	/**
	 * Get tool definition
	 *
	 * @return array
	 */
	public function get_definition() {
		return array(
			'name'                => 'check_exit_conditions',
			'description'         => 'Check dual-condition exit gate for autonomous loops. Returns true ONLY when BOTH completion indicators AND explicit EXIT_SIGNAL are present. This prevents premature loop exits.',
			'category'            => 'project_management',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'session_id'       => array(
						'type'        => 'string',
						'description' => 'Session ID to check',
					),
					'completion_score' => array(
						'type'        => 'integer',
						'description' => 'Number of completion indicators detected (from detect_completion_indicators)',
					),
					'exit_signal'      => array(
						'type'        => 'boolean',
						'description' => 'Explicit EXIT_SIGNAL flag (set to true when work is verified complete)',
					),
					'threshold'        => array(
						'type'        => 'integer',
						'description' => 'Minimum completion score required (default: 2)',
						'default'     => 2,
					),
					'force_check'      => array(
						'type'        => 'boolean',
						'description' => 'Force check even if session limits not reached (default: false)',
						'default'     => false,
					),
				),
				'required'   => array( 'session_id' ),
			),
			'required_capability' => 'read',
		);
	}

	/**
	 * Execute the tool
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array
	 */
	public function execute( array $arguments = array(), array $context = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed,Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Required by WP_MCP_AI_Tool_Interface.
		if ( empty( $arguments['session_id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Missing required argument: session_id',
			);
		}

		$session_id       = $arguments['session_id'];
		$completion_score = isset( $arguments['completion_score'] ) ? intval( $arguments['completion_score'] ) : 0;
		$exit_signal      = ! empty( $arguments['exit_signal'] );
		$threshold        = ! empty( $arguments['threshold'] ) ? intval( $arguments['threshold'] ) : 2;
		$force_check      = ! empty( $arguments['force_check'] );

		// Get session.
		$session = $this->get_session( $session_id );

		if ( ! $session ) {
			return array(
				'success' => false,
				'error'   => sprintf( 'Session %s not found', $session_id ),
			);
		}

		// Check various exit conditions.
		$checks = array(
			'completion_indicators_met' => $completion_score >= $threshold,
			'exit_signal_set'           => $exit_signal,
			'max_iterations_reached'    => $session['iteration_count'] >= $session['max_iterations'],
			'token_budget_exceeded'     => $session['token_usage'] >= $session['token_budget'],
			'session_expired'           => ! empty( $session['expires_at'] ) && strtotime( $session['expires_at'] ) < time(),
			'circuit_breaker_open'      => 'open' === $session['circuit_breaker'],
		);

		// Dual-condition gate: BOTH completion indicators AND exit signal.
		$dual_gate_passed = $checks['completion_indicators_met'] && $checks['exit_signal_set'];

		// Should exit if dual gate passed OR any safety limit reached.
		$should_exit = $dual_gate_passed
			|| $checks['max_iterations_reached']
			|| $checks['token_budget_exceeded']
			|| $checks['session_expired']
			|| $checks['circuit_breaker_open'];

		// Determine exit reason.
		$exit_reason = $this->get_exit_reason( $checks, $dual_gate_passed );

		// Calculate metrics.
		$metrics = array(
			'iteration_count'      => $session['iteration_count'],
			'max_iterations'       => $session['max_iterations'],
			'iterations_remaining' => max( 0, $session['max_iterations'] - $session['iteration_count'] ),
			'token_usage'          => $session['token_usage'],
			'token_budget'         => $session['token_budget'],
			'tokens_remaining'     => max( 0, $session['token_budget'] - $session['token_usage'] ),
			'completion_score'     => $completion_score,
			'completion_threshold' => $threshold,
			'health_status'        => $session['health_status'],
			'circuit_breaker'      => $session['circuit_breaker'],
		);

		return array(
			'success'          => true,
			'session_id'       => $session_id,
			'should_exit'      => $should_exit,
			'exit_reason'      => $exit_reason,
			'dual_gate_passed' => $dual_gate_passed,
			'checks'           => $checks,
			'metrics'          => $metrics,
			'recommendation'   => $this->get_recommendation( $should_exit, $dual_gate_passed, $checks, $metrics ),
		);
	}

	/**
	 * Get session
	 *
	 * @param string $session_id Session ID.
	 * @return array|null
	 */
	private function get_session( $session_id ) {
		$transient_key = 'mcp_ai_session_' . $session_id;
		$session       = get_transient( $transient_key );
		return $session ? $session : null;
	}

	/**
	 * Get exit reason
	 *
	 * @param array $checks          Check results.
	 * @param bool  $dual_gate_passed Dual gate status.
	 * @return string
	 */
	private function get_exit_reason( $checks, $dual_gate_passed ) {
		if ( $dual_gate_passed ) {
			return 'COMPLETED: Dual-condition gate passed (completion indicators + EXIT_SIGNAL)';
		}

		if ( $checks['max_iterations_reached'] ) {
			return 'MAX_ITERATIONS: Maximum iteration limit reached';
		}

		if ( $checks['token_budget_exceeded'] ) {
			return 'TOKEN_BUDGET: Token budget exhausted';
		}

		if ( $checks['session_expired'] ) {
			return 'EXPIRED: Session timeout (24 hours)';
		}

		if ( $checks['circuit_breaker_open'] ) {
			return 'CIRCUIT_BREAKER: Circuit breaker opened due to repeated failures';
		}

		return 'CONTINUE: No exit conditions met';
	}

	/**
	 * Get recommendation
	 *
	 * @param bool  $should_exit      Should exit flag.
	 * @param bool  $dual_gate_passed Dual gate status.
	 * @param array $checks           Check results.
	 * @param array $metrics          Session metrics.
	 * @return string
	 */
	private function get_recommendation( $should_exit, $dual_gate_passed, $checks, $metrics ) {
		if ( ! $should_exit ) {
			$warnings = array();

			if ( $metrics['iterations_remaining'] < 5 ) {
				$warnings[] = sprintf( 'Only %d iterations remaining', $metrics['iterations_remaining'] );
			}

			if ( $metrics['tokens_remaining'] < 1000 ) {
				$warnings[] = sprintf( 'Only %d tokens remaining', $metrics['tokens_remaining'] );
			}

			if ( $checks['completion_indicators_met'] && ! $checks['exit_signal_set'] ) {
				$warnings[] = 'Completion indicators met but EXIT_SIGNAL not set. Verify work is complete.';
			}

			if ( ! empty( $warnings ) ) {
				return 'CONTINUE_WITH_CAUTION: ' . implode( '. ', $warnings );
			}

			return 'CONTINUE: Session healthy, continue working.';
		}

		if ( $dual_gate_passed ) {
			return 'EXIT_CLEANLY: Work completed successfully. Both completion criteria met.';
		}

		if ( $checks['max_iterations_reached'] ) {
			return 'EXIT_INCOMPLETE: Maximum iterations reached. Work may be incomplete.';
		}

		if ( $checks['token_budget_exceeded'] ) {
			return 'EXIT_BUDGET: Token budget exhausted. Work may be incomplete.';
		}

		if ( $checks['session_expired'] ) {
			return 'EXIT_TIMEOUT: Session expired after 24 hours. Resume with new session if needed.';
		}

		if ( $checks['circuit_breaker_open'] ) {
			return 'EXIT_ERROR: Circuit breaker opened due to errors. Review logs and retry.';
		}

		return 'EXIT_UNKNOWN: Exit triggered for unknown reason.';
	}
}
