<?php
/**
 * Fired before a tool is executed.
 *
 * Replaces: do_action('wp_mcp_ai_before_tool_execution', ...)
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Event;

final class BeforeToolExecution {

	public function __construct(
		public readonly string $toolSlug,
		public readonly array $arguments,
		public readonly array $context,
		public readonly float $startedAtMicros,
	) {}
}
