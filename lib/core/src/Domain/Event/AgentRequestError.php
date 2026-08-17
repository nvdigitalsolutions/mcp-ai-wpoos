<?php
/**
 * AgentRequestError — payload of the agent/request_error waterfall.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Event;

final class AgentRequestError {

	public function __construct(
		public readonly mixed $error,
		public readonly int $assistantId,
		public readonly int $iteration,
	) {}
}
