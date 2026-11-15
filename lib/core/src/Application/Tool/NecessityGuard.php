<?php

/**
 * Necessity guard — enforces the necessity gate at the tool boundary.
 *
 * `NecessityGate` decides; this surfaces the decision as a denial so the
 * registry fails the call before the tool body runs. It is deliberately thin:
 * all the judgement lives in the domain service, which is also what WordPress
 * drives, so the two runtimes cannot disagree about what is overeager.
 *
 * **Two verdicts are not expressible here.** `ToolGuardInterface` can only
 * allow or deny, so:
 *
 *  - `skip` (unnecessary but harmless) is allowed through. Denying a benign
 *    read-only call would hand the model an error it has to recover from,
 *    which is worse than running it.
 *  - `approval_required` denies, with a reason that says so. No approval
 *    service is wired on this runtime yet, and the registry's own `ask` path
 *    fails closed for the same reason — so failing closed here is consistent
 *    rather than a policy choice.
 *
 * Both are recorded in the port plan rather than silently narrowed.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Tool;

use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Contract\ToolGuardInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;
use Nvoos\Core\Domain\Service\Safety\ActionSafetyProfile;
use Nvoos\Core\Domain\Service\Safety\NecessityGate;

final class NecessityGuard implements ToolGuardInterface
{
    public function __construct(
        private readonly ToolResolverInterface $tools,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function evaluate(string $slug, array $arguments, array $context): ?string
    {
        unset($context);

        if (! $this->enabled) {
            return null;
        }

        $tool = $this->tools->get($slug);

        if (null === $tool) {
            // Unknown slugs are the registry's problem, not this guard's.
            return null;
        }

        $flags = $tool instanceof ToolCapabilityFlagsInterface
            ? \array_map('strtolower', $tool->getCapabilityFlags())
            : array();

        $decision = NecessityGate::decide($flags, $slug, $arguments);

        if (ActionSafetyProfile::VERDICT_BLOCK === $decision['verdict']) {
            return \sprintf('Tool "%s" is blocked by the necessity gate: %s', $slug, $decision['reason']);
        }

        if (ActionSafetyProfile::VERDICT_APPROVAL_REQUIRED === $decision['verdict']) {
            return \sprintf(
                'Tool "%s" requires human approval and none is wired: %s',
                $slug,
                $decision['reason']
            );
        }

        // allow, warn and skip all run. `warn` is a log concern for a runtime
        // that has one; `skip` is not expressible through this contract.
        return null;
    }
}
