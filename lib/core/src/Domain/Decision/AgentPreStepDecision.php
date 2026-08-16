<?php
/**
 * AgentPreStepDecision — outcome of the agent/pre_step waterfall.
 *
 * Reject closes the turn without a model request; enter replaces the
 * message batch the step will send (the entered batch is authoritative).
 * Mirrors deepseek-harness PreStepDecision.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Decision;

final class AgentPreStepDecision {

	public const KIND_REJECT = 'reject';
	public const KIND_ENTER  = 'enter';

	/**
	 * @param string $kind     One of the KIND_* constants.
	 * @param array  $messages Replacement messages when entering.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly array $messages = array(),
	) {}

	public static function reject(): self {
		return new self( self::KIND_REJECT );
	}

	public static function enter( array $messages ): self {
		return new self( self::KIND_ENTER, $messages );
	}
}
