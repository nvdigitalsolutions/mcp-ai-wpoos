<?php
/**
 * Tool Queue Interface for NV oOS.
 *
 * Defines the interface for tools that specify their queue execution preferences.
 * Tools implementing this interface can declare their priority, timeout, and
 * retry behavior when executed via RabbitMQ.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for tools with queue configuration.
 *
 * Tools implementing this interface can specify how they should be handled
 * when RabbitMQ integration is enabled:
 *
 * - Priority level (high, normal, async)
 * - Timeout duration
 * - Retry behavior
 * - Whether queue execution is required or preferred
 */
interface WP_MCP_AI_Tool_Queue_Interface {

	/**
	 * Get queue configuration for this tool.
	 *
	 * Returns an array with the following optional keys:
	 *
	 * - 'queue'          (string) Queue name: 'tool.execution', 'tool.execution.priority.high', 'tool.execution.async'.
	 * - 'priority'       (string) Priority level: 'high', 'normal', 'low'.
	 * - 'timeout'        (int)    Maximum execution time in seconds (default: 300).
	 * - 'max_retries'    (int)    Maximum retry attempts on failure (default: 3).
	 * - 'retry_delay'    (int)    Initial retry delay in milliseconds (default: 1000).
	 * - 'requires_queue' (bool)   If true, tool must use queue (no sync fallback).
	 * - 'prefer_queue'   (bool)   If true, prefer queue execution when available.
	 * - 'idempotent'     (bool)   If true, tool is safe to retry on failure.
	 * - 'parallelizable' (bool)   If true, tool can run in parallel with others.
	 *
	 * Example implementation for a quick tool:
	 *
	 *     public function get_queue_config() {
	 *         return array(
	 *             'priority'    => 'high',
	 *             'timeout'     => 5,
	 *             'max_retries' => 0,
	 *             'idempotent'  => true,
	 *         );
	 *     }
	 *
	 * Example implementation for a long-running tool:
	 *
	 *     public function get_queue_config() {
	 *         return array(
	 *             'queue'          => 'tool.execution.async',
	 *             'priority'       => 'low',
	 *             'timeout'        => 300,
	 *             'max_retries'    => 3,
	 *             'requires_queue' => true,
	 *         );
	 *     }
	 *
	 * @return array Queue configuration array.
	 */
	public function get_queue_config();
}
