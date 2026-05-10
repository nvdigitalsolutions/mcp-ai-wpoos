<?php
/**
 * Optional interface for tools that operate on potentially large datasets.
 *
 * Implementing this interface lets the tool registry (and migration runner)
 * detect bulk-capable tools and route them through the async job queue when
 * the estimated row count exceeds a configurable threshold.
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
 * Marker interface for tools that operate on large datasets.
 *
 * @since 1.2.0
 */
interface WP_MCP_AI_Tool_Bulk_Operation_Interface {

	/**
	 * Preferred batch size for this tool's primary loop.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	public function get_batch_size();

	/**
	 * Whether this tool can resume from a checkpoint mid-run.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_resumable();

	/**
	 * Stable checkpoint key derived from the call arguments.
	 *
	 * Two calls with arguments that produce the same key are considered the
	 * same logical run and will share a checkpoint / resume cursor.
	 *
	 * @since 1.2.0
	 *
	 * @param array $arguments Tool call arguments.
	 * @return string
	 */
	public function get_checkpoint_key( $arguments );

	/**
	 * Best-effort estimate of the total number of items the call will touch.
	 *
	 * Used by the registry to decide whether to dispatch the call to the
	 * async queue. Return -1 if no estimate is possible.
	 *
	 * @since 1.2.0
	 *
	 * @param array $arguments Tool call arguments.
	 * @return int
	 */
	public function estimate_total( $arguments );
}
