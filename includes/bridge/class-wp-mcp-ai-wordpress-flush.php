<?php
/**
 * WordPress platform flush adapter for the oOS SSE streaming handler.
 *
 * Implements PlatformFlushInterface from the nvoos/core streaming layer,
 * delegating to WordPress's native wp_ob_end_flush_all() to clear any
 * output buffering layers WordPress may have added.
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

use Nvoos\Core\Infrastructure\Streaming\PlatformFlushInterface;

/**
 * WordPress-specific output buffer flush for SSE streaming.
 *
 * Called by SseHandler::sendHeaders() before SSE events are emitted to
 * ensure no buffered WordPress output (admin notices, PHP warnings, etc.)
 * leaks into the event stream.
 */
class WP_MCP_AI_WordPress_Flush implements PlatformFlushInterface {

	/**
	 * Flush all WordPress-level output buffers before streaming begins.
	 *
	 * Delegates to wp_ob_end_flush_all() when available; graceful no-op
	 * when the function is unavailable (e.g., in a partial WordPress load
	 * or a non-WordPress test harness).
	 */
	public function flushPlatformBuffers(): void {
		if ( \function_exists( 'wp_ob_end_flush_all' ) ) {
			\wp_ob_end_flush_all();
		}
	}
}
