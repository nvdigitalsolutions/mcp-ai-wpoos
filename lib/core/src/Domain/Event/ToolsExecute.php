<?php
/**
 * ToolsExecute — payload of the tools/execute around-dispatch waterfall.
 *
 * Listeners wrap the tool body (timeout, retry, metrics): receive
 * ($event, $next) and return the result of $next($event) — or short-circuit
 * with their own outcome. The registry re-invokes the fallback when every
 * listener delegates.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Event;

final class ToolsExecute {

	public function __construct(
		public readonly string $slug,
		public readonly array $arguments,
		public readonly array $context,
	) {}
}
