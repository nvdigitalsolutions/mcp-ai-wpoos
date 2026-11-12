<?php
/**
 * Laravel adapter: PlatformFlushInterface implementation.
 *
 * Laravel owns the HTTP response lifecycle (including StreamedResponse),
 * so there are no framework-level output buffers to clear. This adapter
 * only flushes PHP's native buffer when one is active.
 *
 * @package Nvoos\Laravel
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Infrastructure\Streaming\PlatformFlushInterface;

class PlatformFlush implements PlatformFlushInterface {

	/**
	 * Flush any remaining native output buffer.
	 */
	public function flushPlatformBuffers(): void {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		if ( \ob_get_level() > 0 ) {
			@\ob_flush();
		}
		// phpcs:enable
		\flush();
	}
}
