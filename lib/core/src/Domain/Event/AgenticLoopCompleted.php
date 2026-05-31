<?php
/**
 * Fired after the full agentic loop finishes (all iterations done).
 *
 * Replaces: do_action('wp_mcp_ai_agentic_loop_completed', ...)
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Event;

final class AgenticLoopCompleted {

	public function __construct(
		public readonly int $totalIterations,
		public readonly int $assistantId,
		public readonly array $toolResults,
		public readonly bool $limitReached,
	) {}
}
