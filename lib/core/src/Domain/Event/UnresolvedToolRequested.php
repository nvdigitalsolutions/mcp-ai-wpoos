<?php
/**
 * UnresolvedToolRequested — an assistant configured a tool slug that the
 * registry cannot resolve.
 *
 * Dispatched instead of silently dropping the slug so platform adapters
 * can surface a fail-loud admin notice (misconfiguration should never be
 * skipped silently).
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Event;

final class UnresolvedToolRequested {

	public function __construct(
		public readonly string $slug,
		public readonly int $assistantId,
	) {}
}
