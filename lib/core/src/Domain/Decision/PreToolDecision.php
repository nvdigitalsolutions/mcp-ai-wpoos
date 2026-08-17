<?php
/**
 * PreToolDecision — outcome of the tools/pre_execute waterfall.
 *
 * Mirrors the decision idiom of deepseek-harness (docs/subsystems/tools.md):
 * allow runs the call; deny materializes an error; ask runs only after an
 * approval service grants it and otherwise fails closed. Arguments cannot
 * be rewritten — history, audit, UI, and execution must agree.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Decision;

final class PreToolDecision {

	public const KIND_ALLOW = 'allow';
	public const KIND_DENY  = 'deny';
	public const KIND_ASK   = 'ask';

	public function __construct(
		public readonly string $kind,
		public readonly string $reason = '',
	) {}

	public static function allow(): self {
		return new self( self::KIND_ALLOW );
	}

	public static function deny( string $reason ): self {
		return new self( self::KIND_DENY, $reason );
	}

	public static function ask( string $reason ): self {
		return new self( self::KIND_ASK, $reason );
	}
}
