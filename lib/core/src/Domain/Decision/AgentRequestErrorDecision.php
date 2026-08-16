<?php
/**
 * AgentRequestErrorDecision — outcome of the agent/request_error waterfall.
 *
 * Retry asks the loop to attempt the failed model request once more
 * (bounded by the retry budget); terminal leaves the failure as-is.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Decision;

final class AgentRequestErrorDecision {

	public const KIND_RETRY    = 'retry';
	public const KIND_TERMINAL = 'terminal';

	public function __construct( public readonly string $kind ) {}

	public static function retry(): self {
		return new self( self::KIND_RETRY );
	}

	public static function terminal(): self {
		return new self( self::KIND_TERMINAL );
	}
}
