<?php
/**
 * Fired after a tool completes execution.
 *
 * Replaces: do_action('wp_mcp_ai_after_tool_execution', ...)
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Event;

final class AfterToolExecution {

	public function __construct(
		public readonly string $toolSlug,
		public readonly array $arguments,
		public readonly array $context,
		public readonly mixed $result,
		public readonly bool $isError,
		public readonly float $durationMs,
	) {}
}
