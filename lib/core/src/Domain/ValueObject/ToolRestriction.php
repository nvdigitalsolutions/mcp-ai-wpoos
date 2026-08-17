<?php
/**
 * ToolRestriction — a per-scope filter over inherited tools.
 *
 * Restrictions intersect across a scope chain (deepseek-harness
 * semantics): a tool is visible only if it passes EVERY restriction in
 * the chain. An allow-list keeps only the listed slugs; a deny-list
 * removes the listed slugs. Scope-local registrations are exempt —
 * they shadow anything inherited.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

final class ToolRestriction {

	/**
	 * @param string[]|null $allow Only these inherited slugs stay visible;
	 *                            null = no allow-list (everything admitted
	 *                            by deny).
	 * @param string[]      $deny  These inherited slugs are removed.
	 */
	public function __construct(
		private readonly ?array $allow = null,
		private readonly array $deny = array(),
	) {}

	public static function allowList( array $slugs ): self {
		return new self( allow: array_values( $slugs ) );
	}

	public static function denyList( array $slugs ): self {
		return new self( allow: null, deny: array_values( $slugs ) );
	}

	/**
	 * Whether an inherited tool with the given slug stays visible.
	 */
	public function admits( string $slug ): bool {
		if ( \in_array( $slug, $this->deny, true ) ) {
			return false;
		}

		if ( null !== $this->allow && ! \in_array( $slug, $this->allow, true ) ) {
			return false;
		}

		return true;
	}

	public function allow(): ?array {
		return $this->allow;
	}

	public function deny(): array {
		return $this->deny;
	}
}
