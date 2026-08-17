<?php
/**
 * AgentPreStep — payload of the agent/pre_step waterfall.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Event;

final class AgentPreStep {

	public function __construct(
		public readonly array $messages,
		public readonly int $assistantId,
		public readonly int $iteration,
	) {}
}
