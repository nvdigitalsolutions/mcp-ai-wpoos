<?php
/**
 * ToolsPreExecute — payload of the tools/pre_execute waterfall.
 *
 * Listeners return a PreToolDecision (allow/deny/ask) or short-circuit;
 * the registry converts the decision into execution or a structured error.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Event;

final class ToolsPreExecute {

	public function __construct(
		public readonly string $slug,
		public readonly array $arguments,
		public readonly array $context,
	) {}
}
