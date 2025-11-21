<?php
/**
 * Timeout Detection Service
 *
 * Provides reusable timeout detection and async fallback utilities for long-running tools.
 * This service helps prevent PHP timeouts by detecting when execution is approaching the
 * max_execution_time limit and enabling graceful degradation to async mode.
 *
 * Usage Pattern:
 * 1. Create detector at start of long operation: $detector = new WP_MCP_AI_Timeout_Detection_Service();
 * 2. In polling/processing loop: if ($detector->is_approaching_timeout()) { fallback to async }
 * 3. Service automatically calculates thresholds based on PHP configuration
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Timeout Detection Service class
 *
 * Monitors execution time and provides timeout detection for long-running operations.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Timeout_Detection_Service {

	/**
	 * Operation start time (microtime)
	 *
	 * @var float
	 */
	protected $start_time;

	/**
	 * Maximum execution time in seconds
	 *
	 * @var int
	 */
	protected $max_execution_time;

	/**
	 * Timeout threshold in seconds (when to trigger fallback)
	 *
	 * @var int
	 */
	protected $timeout_threshold;

	/**
	 * Seconds before timeout to trigger fallback (default 10 seconds)
	 *
	 * @var int
	 */
	const DEFAULT_SAFETY_BUFFER = 10;

	/**
	 * Minimum threshold in seconds (fallback if calculated threshold is too low)
	 *
	 * @var int
	 */
	const MINIMUM_THRESHOLD = 5;

	/**
	 * Constructor - initializes timeout detection
	 *
	 * @param int|null $safety_buffer Optional. Seconds before timeout to trigger fallback. Default 10.
	 */
	public function __construct( $safety_buffer = null ) {
		$this->start_time = microtime( true );

		// Get PHP max execution time (0 means unlimited).
		$this->max_execution_time = ini_get( 'max_execution_time' );
		$this->max_execution_time = $this->max_execution_time ? (int) $this->max_execution_time : 30;

		// If unlimited (0), use reasonable default of 30 seconds.
		if ( 0 === $this->max_execution_time ) {
			$this->max_execution_time = 30;
		}

		// Calculate timeout threshold.
		$safety_buffer = null !== $safety_buffer ? absint( $safety_buffer ) : self::DEFAULT_SAFETY_BUFFER;
		$this->timeout_threshold = $this->max_execution_time - $safety_buffer;

		// Ensure minimum threshold to prevent immediate timeout.
		if ( $this->timeout_threshold < self::MINIMUM_THRESHOLD ) {
			$this->timeout_threshold = self::MINIMUM_THRESHOLD;
		}
	}

	/**
	 * Check if execution is approaching timeout
	 *
	 * @return bool True if approaching timeout threshold.
	 */
	public function is_approaching_timeout() {
		$elapsed_time = $this->get_elapsed_time();
		return $elapsed_time >= $this->timeout_threshold;
	}

	/**
	 * Get elapsed time since operation start
	 *
	 * @return float Elapsed time in seconds.
	 */
	public function get_elapsed_time() {
		return microtime( true ) - $this->start_time;
	}

	/**
	 * Get remaining time before timeout
	 *
	 * @return float Remaining time in seconds (can be negative if over threshold).
	 */
	public function get_remaining_time() {
		return $this->timeout_threshold - $this->get_elapsed_time();
	}

	/**
	 * Get timeout threshold
	 *
	 * @return int Timeout threshold in seconds.
	 */
	public function get_timeout_threshold() {
		return $this->timeout_threshold;
	}

	/**
	 * Get max execution time
	 *
	 * @return int Max execution time in seconds.
	 */
	public function get_max_execution_time() {
		return $this->max_execution_time;
	}

	/**
	 * Get timeout detection metadata for logging
	 *
	 * @return array Metadata about timeout detection.
	 */
	public function get_metadata() {
		return array(
			'elapsed_time'       => $this->get_elapsed_time(),
			'remaining_time'     => $this->get_remaining_time(),
			'timeout_threshold'  => $this->timeout_threshold,
			'max_execution_time' => $this->max_execution_time,
			'approaching_timeout' => $this->is_approaching_timeout(),
		);
	}

	/**
	 * Check if a tool should use timeout detection
	 *
	 * @param array $capability_flags Tool capability flags.
	 * @return bool True if tool should use timeout detection.
	 */
	public static function should_use_timeout_detection( $capability_flags ) {
		$timeout_flags = array(
			'may-timeout',
			'long-running',
			'async',
		);

		foreach ( $timeout_flags as $flag ) {
			if ( in_array( $flag, $capability_flags, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Create detector if tool is applicable
	 *
	 * Helper method to conditionally create detector based on tool flags.
	 *
	 * @param array    $capability_flags Tool capability flags.
	 * @param int|null $safety_buffer Optional safety buffer in seconds.
	 * @return WP_MCP_AI_Timeout_Detection_Service|null Detector instance or null if not applicable.
	 */
	public static function create_if_applicable( $capability_flags, $safety_buffer = null ) {
		if ( self::should_use_timeout_detection( $capability_flags ) ) {
			return new self( $safety_buffer );
		}

		return null;
	}
}
