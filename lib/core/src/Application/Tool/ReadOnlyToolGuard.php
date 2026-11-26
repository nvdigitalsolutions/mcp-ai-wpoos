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

use Nvoos\Core\Domain\Contract\CapabilityFlagOverridesInterface;
use Nvoos\Core\Domain\Contract\ToolGuardInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagResolution;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;

/**
 * Blocks state-changing tools while a read-only profile is active.
 *
 * @since 1.3.0
 */
final class ReadOnlyToolGuard implements ToolGuardInterface
{
    /**
     * Flags that disqualify a tool while the profile is active.
     *
     * Defined in {@see CapabilityFlagVocabulary}, mirroring the WordPress chat
     * profile's list, so every reader of capability flags agrees on spelling.
     * This constant previously gated `state_changing` (written by 6 corpus
     * tools) against a corpus that writes `state-changing` (275), and omitted
     * `data-destruction`, `irreversible`, `financial-impact`,
     * `access-control-change` and `mass-email`.
     *
     * @var list<string>
     */
    public const DEFAULT_GATED_FLAGS = CapabilityFlagVocabulary::READ_ONLY_GATED;

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
        private readonly ?CapabilityFlagOverridesInterface $overrides = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function evaluate(string $slug, array $arguments, array $context): ?string
    {
        unset($arguments, $context);

        if (in_array($slug, $this->allowedSlugs, true)) {
            return null;
        }

        $tool = $this->tools->get($slug);

        if (null === $tool) {
            // Unknown slugs are the registry's problem, not this guard's.
            return null;
        }

        $flags = $this->declaredFlags($slug, $tool);

        if (null === $flags) {
            return $this->blockUndeclared
                ? sprintf('Tool "%s" is blocked by the read-only profile because it declares no capability flags.', $slug)
                : null;
        }

        $hit = array_values(array_intersect(
            $flags,
            CapabilityFlagVocabulary::normalise($this->gatedFlags)
        ));

        if (array() === $hit) {
            return null;
        }

        return sprintf(
            'Tool "%s" is blocked by the read-only profile (capability: %s).',
            $slug,
            implode(', ', $hit)
        );
    }

    /**
     * Flags for a tool: its own declaration, else the corpus catalogue, plus any
     * administrator additions.
     *
     * The three-step lookup lives in {@see CapabilityFlagResolution} so this
     * guard, `NecessityGuard` and the chat-profile delegate cannot disagree
     * about the order. Additions are unioned in last and cannot remove a flag;
     * an undeclared tool stays undeclared, because otherwise an override could
     * turn "we could not tell" into "we checked and it is fine".
     *
     * @return list<string>|null Null when nothing is declared anywhere.
     */
    private function declaredFlags(string $slug, object $tool): ?array
    {
        return CapabilityFlagResolution::resolve($slug, $tool, $this->overrides);
    }
}
