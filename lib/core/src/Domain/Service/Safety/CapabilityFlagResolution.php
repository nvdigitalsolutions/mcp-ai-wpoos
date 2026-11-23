<?php

/**
 * CapabilityFlagResolution — one place that answers "what flags does this tool
 * have?".
 *
 * `ReadOnlyToolGuard`, `NecessityGuard` and the chat-profile delegate each grew
 * the same three-step lookup: the tool's own declaration, else the code-owned
 * corpus catalogue, else nothing. Adding per-tool overrides to three copies
 * would have been three chances to disagree about the order — and disagreement
 * here is the exact bug class {@see CapabilityFlagVocabulary} was created to
 * end.
 *
 * The order is:
 *
 * 1. the tool's own `getCapabilityFlags()`, when it implements the interface;
 * 2. otherwise `ToolCapabilityFlagCatalogue::for($slug)` — the flags WordPress
 *    declared for the ported tool class, which does not carry them itself;
 * 3. otherwise **undeclared**, and undeclared is preserved as undeclared:
 *    override additions are ignored, because adding a non-gated flag to a tool
 *    that declares nothing would turn "unknown" into "known and safe".
 * 4. overrides are unioned in last, and can only grow the set.
 *
 * @package Nvoos\Core
 * @since   2.2.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Safety;

use Nvoos\Core\Domain\Contract\CapabilityFlagOverridesInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

final class CapabilityFlagResolution
{
    /**
     * Resolve a tool's effective flags.
     *
     * @param string                               $slug      Tool slug.
     * @param object|null                          $tool      The resolved tool, if any.
     * @param CapabilityFlagOverridesInterface|null $overrides Per-tool additions, if a host binds them.
     *
     * @return list<string>|null Null when nothing declares anything.
     */
    public static function resolve(
        string $slug,
        ?object $tool = null,
        ?CapabilityFlagOverridesInterface $overrides = null,
    ): ?array {
        $declared = array();

        if ($tool instanceof ToolCapabilityFlagsInterface) {
            $declared = CapabilityFlagVocabulary::normalise($tool->getCapabilityFlags());
        }

        if (array() === $declared) {
            $declared = CapabilityFlagVocabulary::normalise(ToolCapabilityFlagCatalogue::for($slug));
        }

        // Undeclared stays undeclared: an override may not turn "we could not
        // tell" into "we checked and it is fine".
        if (array() === $declared) {
            return null;
        }

        if (null === $overrides) {
            return $declared;
        }

        $added = CapabilityFlagVocabulary::normalise($overrides->addedFlags($slug));

        if (array() === $added) {
            return $declared;
        }

        // Union, never subtract. Order is the tool's own first so a denial
        // message still names the tool's words before the administrator's.
        return \array_values(\array_unique(\array_merge($declared, $added)));
    }
}
