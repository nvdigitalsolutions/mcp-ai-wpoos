<?php
/**
 * AgentRequestDecision — outcome of the agent/request waterfall.
 *
 * Keep delegates to the frozen call configuration; replace substitutes
 * new options (provider/model/max_tokens) for the upcoming model call.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Decision;

final class AgentRequestDecision {

	public const KIND_KEEP    = 'keep';
	public const KIND_REPLACE = 'replace';

	/**
	 * @param string $kind    One of the KIND_* constants.
	 * @param array  $options Replacement call options when replacing.
	 */
	public function __construct(
		public readonly string $kind,
		public readonly array $options = array(),
	) {}

	public static function keep(): self {
		return new self( self::KIND_KEEP );
	}

	public static function replace( array $options ): self {
		return new self( self::KIND_REPLACE, $options );
	}
}
