<?php
/**
 * Serializable callable host for the job queue manager tests.
 *
 * The queue persists jobs (custom table or legacy option fallback), so
 * job callables must survive serialization: closures cannot. Static
 * methods on this class provide deterministic callables whose recorded
 * state the tests can assert on.
 *
 * @package WP_MCP_AI\Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

// phpcs:ignoreFile -- Test helper intentionally mirrors production callable signatures.

/**
 * Serializable callable host for the queue tests.
 */
class WP_MCP_AI_Job_Queue_Manager_Test_Worker {
	/**
	 * Whether the succeed() callable has run.
	 *
	 * @var bool
	 */
	public static $executed = false;

	/**
	 * Execution order recorded by the priority callables.
	 *
	 * @var array
	 */
	public static $execution_order = array();

	/**
	 * Arguments received by the record_args() callable.
	 *
	 * @var array|null
	 */
	public static $received_args = null;

	/**
	 * Reset all recorded state.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$executed        = false;
		self::$execution_order = array();
		self::$received_args   = null;
	}

	/**
	 * Successful job.
	 *
	 * @return string
	 */
	public static function succeed() {
		self::$executed = true;
		return 'success';
	}

	/**
	 * Records low-priority execution.
	 *
	 * @return string
	 */
	public static function record_low() {
		self::$execution_order[] = 'low';
		return 'success';
	}

	/**
	 * Records high-priority execution.
	 *
	 * @return string
	 */
	public static function record_high() {
		self::$execution_order[] = 'high';
		return 'success';
	}

	/**
	 * Records normal-priority execution.
	 *
	 * @return string
	 */
	public static function record_normal() {
		self::$execution_order[] = 'normal';
		return 'success';
	}

	/**
	 * Job that always throws.
	 *
	 * @throws Exception Always.
	 */
	public static function explode() {
		throw new Exception( 'Test exception' );
	}

	/**
	 * Records the arguments it receives.
	 *
	 * @param mixed $arg1 First argument.
	 * @param mixed $arg2 Second argument.
	 * @return string
	 */
	public static function record_args( $arg1, $arg2 ) {
		self::$received_args = array( $arg1, $arg2 );
		return 'success';
	}
}
