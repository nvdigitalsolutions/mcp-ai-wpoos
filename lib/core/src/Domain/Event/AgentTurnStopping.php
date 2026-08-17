<?php
/**
 * AgentTurnStopping — serial notification dispatched before a turn closes.
 *
 * Listener order cannot change the outcome; a future steering inbox
 * (Phase 5, R5) will let listeners inject work the loop re-reads.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Event;

final class AgentTurnStopping {

	public function __construct(
		public readonly int $assistantId,
		public readonly int $totalIterations,
	) {}
}
