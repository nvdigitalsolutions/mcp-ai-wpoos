<?php

/**
 * Chat-profile guard — per-principal read-only enforcement.
 *
 * The deployment-wide `ReadOnlyToolGuard` answers "is this runtime read-only?".
 * This guard answers "is this *user* read-only?", which is the parity gap
 * between the two runtimes: WordPress lets each user carry a chat profile
 * (`write` or `read-only`) and enforces it at the same point in the pipeline.
 *
 * It is registered as a monotonic guard, so it runs after every pre-execute
 * listener and cannot be re-allowed by one. That placement is the point: a
 * denial here never degrades into a confirmation prompt, matching Claude
 * Code's deny → ask → allow precedence and the `read-only` profile's own
 * promise that writes are refused rather than queried.
 *
 * The flag decision is **not** reimplemented. A restrictive profile builds a
 * {@see ReadOnlyToolGuard} from its own gated flags and allowlist and delegates
 * to it, so there is exactly one place that knows what it means for a tool to
 * carry a gated capability.
 *
 * @package Nvoos\Core
 * @since   1.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Tool;

use Nvoos\Core\Domain\Contract\CapabilityFlagOverridesInterface;
use Nvoos\Core\Domain\Contract\ChatProfileResolverInterface;
use Nvoos\Core\Domain\Contract\ToolGuardInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;

/**
 * Denies gated tools while the resolved profile is restrictive.
 *
 * @since 1.4.0
 */
final class ChatProfileGuard implements ToolGuardInterface
{
    /**
     * One delegate per profile slug, built on first use.
     *
     * @var array<string, ReadOnlyToolGuard>
     */
    private array $delegates = array();

    /**
     * @param ToolResolverInterface        $tools          Resolver used to read a tool's flags.
     * @param ChatProfileResolverInterface $profiles       Trust boundary for the profile.
     * @param bool                         $blockUndeclared Block tools that declare no flags.
     */
    public function __construct(
        private readonly ToolResolverInterface $tools,
        private readonly ChatProfileResolverInterface $profiles,
        private readonly bool $blockUndeclared = true,
        private readonly ?CapabilityFlagOverridesInterface $overrides = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function evaluate(string $slug, array $arguments, array $context): ?string
    {
        $profile = $this->profiles->resolve($context);

        if (! $profile->isRestrictive()) {
            return null;
        }

        $delegate = $this->delegates[ $profile->slug() ] ??= new ReadOnlyToolGuard(
            $this->tools,
            $profile->gatedFlags(),
            $profile->allowedSlugs(),
            $this->blockUndeclared,
            $this->overrides,
        );

        return $delegate->evaluate($slug, $arguments, $context);
    }
}
