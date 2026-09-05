<?php
/**
 * Test clock for the timeout detection service tests.
 *
 * Pins the elapsed time reported by {@see WP_MCP_AI_Timeout_Detection_Service}
 * so the approaching-timeout flip can be verified deterministically without
 * sleeping past the service's 5-second minimum threshold.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Timeout detector with a pinned elapsed-time clock.
 */
class WP_MCP_AI_Timeout_Detection_Service_Test_Clock extends WP_MCP_AI_Timeout_Detection_Service {

	/**
	 * Pinned elapsed time in seconds.
	 *
	 * @var float
	 */
	private $pinned_elapsed = 0.0;

	/**
	 * Pin the elapsed time reported by the detector.
	 *
	 * @param float $seconds Elapsed seconds.
	 */
	public function set_elapsed( $seconds ) {
		$this->pinned_elapsed = (float) $seconds;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_elapsed_time() {
		return $this->pinned_elapsed;
	}
}
