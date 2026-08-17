<?php
/**
 * PostToolDecision — outcome of the tools/post_execute waterfall.
 *
 * Accept keeps the dispatch result unchanged; replace substitutes new
 * model-facing content (success results only); block turns the result into
 * an error carrying corrective feedback — the pre/post guardrail pattern
 * where flagged output is fed back to the model instead of surfaced raw.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Decision;

final class PostToolDecision {

	public const KIND_ACCEPT  = 'accept';
	public const KIND_REPLACE = 'replace';
	public const KIND_BLOCK   = 'block';

	/**
	 * @param string $kind    One of the KIND_* constants.
	 * @param mixed  $content Replacement content (KIND_REPLACE) or corrective
	 *                        feedback (KIND_BLOCK).
	 */
	public function __construct(
		public readonly string $kind,
		public readonly mixed $content = null,
	) {}

	public static function accept(): self {
		return new self( self::KIND_ACCEPT );
	}

	public static function replace( mixed $content ): self {
		return new self( self::KIND_REPLACE, $content );
	}

	public static function block( mixed $feedback ): self {
		return new self( self::KIND_BLOCK, $feedback );
	}
}
