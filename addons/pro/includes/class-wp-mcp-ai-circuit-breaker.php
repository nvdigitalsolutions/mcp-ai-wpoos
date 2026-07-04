<?php
/**
 * PHP-Side Circuit Breaker for autonomous orchestration sessions.
 *
 * Implements the Circuit Breaker pattern (Pattern 8 of 10 in Loop Engineering
 * Design Patterns) with CLOSED / OPEN / HALF_OPEN states, configurable
 * error thresholds, and persistent state via the autonomous sessions CCT.
 *
 * The browser-side `autonomous-orchestrator.js` has a client-side breaker;
 * this class provides the server-side enforcement layer that the PHP
 * orchestration tools consult before executing further iterations.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Circuit Breaker class.
 */
class WP_MCP_AI_Circuit_Breaker {

	/**
	 * Circuit state constants.
	 */
	const STATE_CLOSED    = 'closed';
	const STATE_OPEN      = 'open';
	const STATE_HALF_OPEN = 'half_open';

	/**
	 * Default thresholds.
	 */
	const DEFAULT_ERROR_THRESHOLD_PCT  = 50;
	const DEFAULT_VOLUME_THRESHOLD     = 5;
	const DEFAULT_RESET_TIMEOUT_SEC    = 300;
	const DEFAULT_MAX_CONSECUTIVE_ERRS = 3;
	const DEFAULT_NO_PROGRESS_CYCLES   = 3;

	/**
	 * Session ID this breaker is monitoring.
	 *
	 * @var string
	 */
	private $session_id;

	/**
	 * Configuration.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param string $session_id Session identifier.
	 * @param array  $config     Optional configuration overrides.
	 */
	public function __construct( $session_id, array $config = array() ) {
		$this->session_id = $session_id;
		$this->config     = wp_parse_args(
			$config,
			array(
				'error_threshold_pct'    => self::DEFAULT_ERROR_THRESHOLD_PCT,
				'volume_threshold'       => self::DEFAULT_VOLUME_THRESHOLD,
				'reset_timeout_sec'      => self::DEFAULT_RESET_TIMEOUT_SEC,
				'max_consecutive_errors' => self::DEFAULT_MAX_CONSECUTIVE_ERRS,
				'no_progress_cycles'     => self::DEFAULT_NO_PROGRESS_CYCLES,
			)
		);
	}

	/**
	 * Get the current circuit state from persistent storage.
	 *
	 * @return string One of STATE_CLOSED, STATE_OPEN, STATE_HALF_OPEN.
	 */
	public function get_state() {
		$session = $this->get_session_data();
		if ( ! $session ) {
			return self::STATE_CLOSED;
		}

		$breaker_setting = isset( $session['circuit_breaker'] )
			? $session['circuit_breaker']
			: ( isset( $session['circuit_breaker_open'] )
				? ( $session['circuit_breaker_open'] ? self::STATE_OPEN : self::STATE_CLOSED )
				: self::STATE_CLOSED );

		// Handle both string and boolean representations.
		if ( is_bool( $breaker_setting ) ) {
			return $breaker_setting ? self::STATE_OPEN : self::STATE_CLOSED;
		}

		$valid = array( self::STATE_CLOSED, self::STATE_OPEN, self::STATE_HALF_OPEN );
		return in_array( $breaker_setting, $valid, true ) ? $breaker_setting : self::STATE_CLOSED;
	}

	/**
	 * Check whether execution is allowed (circuit is not OPEN).
	 *
	 * If the circuit is OPEN but the reset timeout has elapsed,
	 * transitions to HALF_OPEN and allows one trial execution.
	 *
	 * @return bool True if execution is allowed.
	 */
	public function allow_execution() {
		$state = $this->get_state();

		if ( self::STATE_CLOSED === $state ) {
			return true;
		}

		if ( self::STATE_HALF_OPEN === $state ) {
			return true;
		}

		// Circuit is OPEN — check if reset timeout has elapsed.
		if ( $this->is_reset_timeout_elapsed() ) {
			$this->transition_to( self::STATE_HALF_OPEN );
			return true;
		}

		return false;
	}

	/**
	 * Record a successful execution.
	 *
	 * In HALF_OPEN state, a single success transitions back to CLOSED.
	 */
	public function record_success() {
		if ( self::STATE_HALF_OPEN === $this->get_state() ) {
			$this->transition_to( self::STATE_CLOSED );
		}
		$this->reset_error_count();
	}

	/**
	 * Record a failed execution.
	 *
	 * Evaluates whether the error rate exceeds the configured threshold
	 * and opens the circuit if so.
	 */
	public function record_failure() {
		$session = $this->get_session();
		$errors  = $this->increment_error_count( $session );
		$total   = $this->get_total_attempts( $session );

		if ( $total >= $this->config['volume_threshold'] ) {
			$error_rate = $total > 0 ? ( $errors / $total ) * 100 : 0;
			if ( $error_rate >= $this->config['error_threshold_pct'] ) {
				$this->transition_to( self::STATE_OPEN );
				return;
			}
		}

		// Check consecutive errors threshold.
		$consecutive = $this->get_consecutive_errors( $session );
		if ( $consecutive >= $this->config['max_consecutive_errors'] ) {
			$this->transition_to( self::STATE_OPEN );
			return;
		}

		// In HALF_OPEN state, any failure re-opens the circuit.
		if ( self::STATE_HALF_OPEN === $this->get_state() ) {
			$this->transition_to( self::STATE_OPEN );
		}
	}

	/**
	 * Detect whether the session has made no progress across recent iterations.
	 *
	 * Compares successive tool-call results to identify stagnation.
	 *
	 * @param array $last_outputs Array of output summaries from recent iterations.
	 * @return bool True if no progress detected.
	 */
	public function detect_no_progress( array $last_outputs ) {
		if ( count( $last_outputs ) < $this->config['no_progress_cycles'] ) {
			return false;
		}

		// Take the last N cycles.
		$recent = array_slice( $last_outputs, -$this->config['no_progress_cycles'] );

		// If all recent outputs are identical, no progress is being made.
		$unique = array_unique( $recent );
		if ( 1 === count( $unique ) ) {
			return true;
		}

		// If all recent outputs contain error messages, no progress.
		$all_errors = true;
		foreach ( $recent as $output ) {
			if ( ! $this->looks_like_error( $output ) ) {
				$all_errors = false;
				break;
			}
		}

		return $all_errors;
	}

	/**
	 * Check whether an output string looks like an error.
	 *
	 * @param string $output Output summary text.
	 * @return bool
	 */
	private function looks_like_error( $output ) {
		if ( empty( $output ) ) {
			return false;
		}

		$error_patterns = array(
			'error',
			'failed',
			'exception',
			'WP_Error',
			'circuit breaker',
			'max iterations',
			'token budget',
			'not found',
			'permission denied',
			'unauthorized',
		);

		$lower = strtolower( $output );
		foreach ( $error_patterns as $pattern ) {
			if ( false !== strpos( $lower, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Transition the circuit to a new state and persist.
	 *
	 * @param string $new_state One of STATE_CLOSED, STATE_OPEN, STATE_HALF_OPEN.
	 */
	private function transition_to( $new_state ) {
		$data = array(
			'circuit_breaker'      => $new_state,
			'circuit_breaker_open' => self::STATE_CLOSED !== $new_state,
			'circuit_opened_at'    => self::STATE_OPEN === $new_state ? time() : null,
			'last_activity'        => current_time( 'mysql' ),
		);

		$this->update_session( $data );
	}

	/**
	 * Check if the reset timeout has elapsed since the circuit was opened.
	 *
	 * @return bool
	 */
	private function is_reset_timeout_elapsed() {
		$session = $this->get_session_data();
		if ( ! $session || empty( $session['circuit_opened_at'] ) ) {
			return true;
		}

		$elapsed = time() - (int) $session['circuit_opened_at'];
		return $elapsed >= $this->config['reset_timeout_sec'];
	}

	/**
	 * Get raw session data from storage.
	 *
	 * @return array|null
	 */
	private function get_session_data() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Manage_Autonomous_Session' ) ) {
			return null;
		}

		// Use reflection to call the private get_session method.
		// In practice, this reads from CCT-first storage after Wave 1.
		$tool = new WP_MCP_AI_Pro_Tool_Manage_Autonomous_Session();
		$ref  = new \ReflectionMethod( $tool, 'get_session' );
		$ref->setAccessible( true );

		$session = $ref->invoke( $tool, $this->session_id );
		return is_array( $session ) ? $session : null;
	}

	/**
	 * Get full session data.
	 *
	 * @return array
	 */
	private function get_session() {
		$data = $this->get_session_data();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Update session storage with circuit breaker state changes.
	 *
	 * @param array $data Data to update.
	 */
	private function update_session( array $data ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Manage_Autonomous_Session' ) ) {
			return;
		}

		$tool = new WP_MCP_AI_Pro_Tool_Manage_Autonomous_Session();
		$ref  = new \ReflectionMethod( $tool, 'update_session_storage' );
		$ref->setAccessible( true );
		$ref->invoke( $tool, $this->session_id, $data );
	}

	/**
	 * Increment the error count and return the new value.
	 *
	 * @param array $session Current session data.
	 * @return int New error count.
	 */
	private function increment_error_count( array $session ) {
		$current = isset( $session['error_count'] ) ? (int) $session['error_count'] : 0;
		++$current;

		$consecutive = isset( $session['consecutive_errors'] ) ? (int) $session['consecutive_errors'] : 0;
		++$consecutive;

		$this->update_session(
			array(
				'error_count'        => $current,
				'consecutive_errors' => $consecutive,
			)
		);

		return $current;
	}

	/**
	 * Reset error counters after a success.
	 */
	private function reset_error_count() {
		$this->update_session(
			array(
				'consecutive_errors' => 0,
			)
		);
	}

	/**
	 * Get total execution attempts from session data.
	 *
	 * @param array $session Current session data.
	 * @return int
	 */
	private function get_total_attempts( array $session ) {
		return isset( $session['iteration_count'] )
			? (int) $session['iteration_count']
			: ( isset( $session['iterations'] ) ? (int) $session['iterations'] : 0 );
	}

	/**
	 * Get consecutive error count.
	 *
	 * @param array $session Current session data.
	 * @return int
	 */
	private function get_consecutive_errors( array $session ) {
		return isset( $session['consecutive_errors'] ) ? (int) $session['consecutive_errors'] : 0;
	}
}
