<?php
/**
 * Fired when a single agentic-loop iteration completes.
 *
 * Replaces: do_action('wp_mcp_ai_agentic_iteration_complete', ...)
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Event;

final class AgenticIterationComplete {

	public function __construct(
		public readonly int $iteration,
		public readonly int $assistantId,
	) {}
}
