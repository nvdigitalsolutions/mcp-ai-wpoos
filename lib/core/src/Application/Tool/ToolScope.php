<?php
/**
 * ToolScope — a scoped view over the tool registry.
 *
 * Scopes shape tool VISIBILITY for one agent/assistant:
 *  - scope-local registrations shadow anything inherited (and are exempt
 *    from restrictions, so a delegated child keeps the tools it answers
 *    through);
 *  - ToolRestrictions intersect across the scope chain — a tool is
 *    visible only when every restriction in the chain admits it.
 *
 * Execution is NOT a scope concern: dispatch always happens on the owning
 * ToolRegistry (which runs the policy pipeline). Scopes only answer
 * get/has/schemas.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Tool;

use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;
use Nvoos\Core\Domain\ValueObject\ToolRestriction;

final class ToolScope implements ToolResolverInterface {

	/**
	 * Scope-local tools that shadow inherited ones.
	 *
	 * @var array<string, ToolInterface>
	 */
	private array $local = array();

	/**
	 * Restrictions applied to inherited tools (all must admit).
	 *
	 * @var ToolRestriction[]
	 */
	private array $restrictions = array();

	/**
	 * @param string[] $seed_slugs Optional slug universe used when the parent
	 *                             resolver is not enumerable — generic
	 *                             ToolResolverInterface parents are resolved
	 *                             through this list in inheritedTools().
	 */
	public function __construct(
		private readonly ToolResolverInterface $parent,
		private readonly array $seed_slugs = array(),
	) {}

	/**
	 * Register a tool in this scope only. Shadows any inherited tool with
	 * the same slug and is exempt from restrictions.
	 */
	public function register( ToolInterface $tool ): void {
		$this->local[ $tool->getSlug() ] = $tool;
	}

	/**
	 * Add a restriction for inherited tools (intersects with existing ones).
	 */
	public function restrict( ToolRestriction $restriction ): void {
		$this->restrictions[] = $restriction;
	}

	public function get( string $slug ): ?ToolInterface {
		// Scope-local tools shadow everything and are restriction-exempt.
		if ( isset( $this->local[ $slug ] ) ) {
			return $this->local[ $slug ];
		}

		$tool = $this->parent->get( $slug );

		if ( null === $tool ) {
			return null;
		}

		// A restricted-away global reads as absent.
		return $this->isVisible( $slug, $tool ) ? $tool : null;
	}

	public function has( string $slug ): bool {
		$tool = $this->parent->get( $slug );

		if ( null === $tool ) {
			return false;
		}

		return $this->isVisible( $slug, $tool );
	}

	/**
	 * Project the visible inherited + local tools onto model-facing schemas.
	 *
	 * @return array<int, array{type: string, function: array}>
	 */
	public function schemas(): array {
		$definitions = array();

		// Inherited tools first (restrictions applied), then scope-local
		// tools shadow by slug.
		foreach ( $this->inheritedTools() as $slug => $tool ) {
			if ( $this->isVisible( $slug, $tool ) ) {
				$definitions[ $slug ] = $this->schemaFor( $slug, $tool );
			}
		}

		foreach ( $this->local as $slug => $tool ) {
			$definitions[ $slug ] = $this->schemaFor( $slug, $tool );
		}

		return array_values( $definitions );
	}

	/**
	 * Visible slugs, in inherited-then-local order.
	 *
	 * @return string[]
	 */
	public function visibleSlugs(): array {
		$slugs = array();

		foreach ( $this->inheritedTools() as $slug => $tool ) {
			if ( $this->isVisible( $slug, $tool ) ) {
				$slugs[] = $slug;
			}
		}

		foreach ( $this->local as $slug => $tool ) {
			if ( ! \in_array( $slug, $slugs, true ) ) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * All tools visible through the parent resolver (slug → instance).
	 *
	 * @return array<string, ToolInterface>
	 */
	private function inheritedTools(): array {
		if ( $this->parent instanceof ToolRegistry ) {
			return $this->parent->enabled();
		}

		if ( $this->parent instanceof self ) {
			$tools = array();
			foreach ( $this->parent->visibleSlugs() as $slug ) {
				$tool = $this->parent->get( $slug );
				if ( null !== $tool ) {
					$tools[ $slug ] = $tool;
				}
			}

			return $tools;
		}

		// Generic resolver: enumerate the seeded universe so
		// non-enumerable parents still produce schemas and slug views.
		$tools = array();

		foreach ( $this->seed_slugs as $slug ) {
			$tool = $this->parent->get( $slug );

			if ( null !== $tool ) {
				$tools[ $slug ] = $tool;
			}
		}

		return $tools;
	}

	/**
	 * Whether an inherited tool survives every restriction in the chain.
	 */
	private function isVisible( string $slug, ToolInterface $tool ): bool {
		// Scope-local tools are exempt and shadow everything.
		if ( isset( $this->local[ $slug ] ) ) {
			return true;
		}

		foreach ( $this->restrictions as $restriction ) {
			if ( ! $restriction->admits( $slug ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @return array{type: string, function: array}
	 */
	private function schemaFor( string $slug, ToolInterface $tool ): array {
		return array(
			'type'     => 'function',
			'function' => array(
				'name'        => $slug,
				'description' => $tool->getDescription(),
				'parameters'  => $tool->getParametersSchema(),
			),
		);
	}
}
