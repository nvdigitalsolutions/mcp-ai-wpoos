<?php
/**
 * Read-only tool guard — deny-only policy for read-only deployments.
 *
 * A deployment can run the whole runtime against a read-only profile: useful
 * for staging mirrors, demos, and audit-only access. This guard enforces that
 * at the last possible moment — after the pre-execute waterfall, immediately
 * before the tool body — so no listener can talk its way past it.
 *
 * Mirrors the WordPress `read-only` chat profile's decision shape
 * (`WP_MCP_AI_Read_Only_Profile_Gate::is_tool_allowed()`): an allowlisted slug
 * always runs, and a tool whose capability flags intersect the gated set is
 * blocked. It is deliberately stricter in one direction: a tool that declares
 * no flags is blocked by default (`blockUndeclared`), because "I could not tell
 * whether this writes" is not a reason to let it write. WordPress sits behind a
 * richer profile system and can lean on the flag table; a runtime-wide switch
 * cannot.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Tool;

use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Contract\ToolGuardInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;

/**
 * Blocks state-changing tools while a read-only profile is active.
 *
 * @since 1.3.0
 */
final class ReadOnlyToolGuard implements ToolGuardInterface {

	/**
	 * Flags that disqualify a tool while the profile is active.
	 *
	 * @var list<string>
	 */
	public const DEFAULT_GATED_FLAGS = array(
		'write',
		'state_changing',
		'mutating',
		'destructive',
		'external_side_effect',
	);

	/**
	 * @param ToolResolverInterface $tools          Resolver used to read a tool's flags.
	 * @param list<string>          $gatedFlags     Flags that disqualify a tool.
	 * @param list<string>          $allowedSlugs   Slugs that always run.
	 * @param bool                  $blockUndeclared Block tools that declare no flags.
	 */
	public function __construct(
		private readonly ToolResolverInterface $tools,
		private readonly array $gatedFlags = self::DEFAULT_GATED_FLAGS,
		private readonly array $allowedSlugs = array(),
		private readonly bool $blockUndeclared = true,
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function evaluate( string $slug, array $arguments, array $context ): ?string {
		unset( $arguments, $context );

		if ( in_array( $slug, $this->allowedSlugs, true ) ) {
			return null;
		}

		$tool = $this->tools->get( $slug );

		if ( null === $tool ) {
			// Unknown slugs are the registry's problem, not this guard's.
			return null;
		}

		if ( ! $tool instanceof ToolCapabilityFlagsInterface ) {
			return $this->blockUndeclared
				? sprintf( 'Tool "%s" is blocked by the read-only profile because it declares no capability flags.', $slug )
				: null;
		}

		$flags = array_map( 'strtolower', $tool->getCapabilityFlags() );
		$hit   = array_values( array_intersect( $flags, $this->gatedFlags ) );

		if ( array() === $hit ) {
			return null;
		}

		return sprintf(
			'Tool "%s" is blocked by the read-only profile (capability: %s).',
			$slug,
			implode( ', ', $hit )
		);
	}
}
