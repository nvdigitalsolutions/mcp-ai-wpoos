<?php
/**
 * Platform-level output buffer flush contract.
 *
 * Framework-agnostic interface that SseHandler delegates to for clearing
 * any output buffering layers between PHP's native output and the client.
 * Each platform adapter provides its own implementation without forcing
 * the core streaming library to depend on a specific framework.
 *
 *  - WordPress: flushes wp_ob_end_flush_all() output buffers
 *  - Laravel:   flushes any middleware output buffers
 *  - Standalone: no-op or flushes ob_* layers only
 *
 * @package Nvoos\Core
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Infrastructure\Streaming;

interface PlatformFlushInterface {

	/**
	 * Flush all platform-level output buffers before streaming begins.
	 *
	 * Implementations should clear any output buffering layers that
	 * sit between PHP's native output and the client. This includes
	 * framework-level buffers (WordPress wp_ob_end_flush_all),
	 * compression buffers, and any intermediate proxy buffers.
	 *
	 * Called once by SseHandler::sendHeaders() before any SSE events
	 * are emitted.
	 */
	public function flushPlatformBuffers(): void;
}
