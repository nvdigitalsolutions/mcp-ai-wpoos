<?php
/**
 * AgentRequest — payload of the agent/request waterfall.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Event;

final class AgentRequest {

	public function __construct(
		public readonly array $options,
		public readonly int $assistantId,
		public readonly int $iteration,
	) {}
}
