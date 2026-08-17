<?php
/**
 * ToolsPostExecute — payload of the tools/post_execute waterfall.
 *
 * Listeners accept, replace, or block a normalized dispatch result via a
 * PostToolDecision. Blocked results become errors carrying corrective
 * feedback so the model can retry instead of surfacing raw failures.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Event;

final class ToolsPostExecute {

	public function __construct(
		public readonly string $slug,
		public readonly array $arguments,
		public readonly array $context,
		public readonly mixed $result,
		public readonly bool $isError,
	) {}
}
