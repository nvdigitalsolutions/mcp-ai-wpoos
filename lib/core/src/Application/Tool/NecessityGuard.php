<?php

/**
 * Necessity guard — enforces the necessity gate at the tool boundary.
 *
 * `NecessityGate` decides; this surfaces the decision as a denial so the
 * registry fails the call before the tool body runs. It is deliberately thin:
 * all the judgement lives in the domain service, which is also what WordPress
 * drives, so the two runtimes cannot disagree about what is overeager.
 *
 * `$preApproved` is passed straight through to the gate, so an operator can
 * waive the approval step for tools whose risk is spend rather than damage.
 * `null` keeps the shared default list; `array()` prompts for everything.
 *
 * **Two verdicts are not expressible here.** `ToolGuardInterface` can only
 * allow or deny, so:
 *
 *  - `skip` (unnecessary but harmless) is allowed through. Denying a benign
 *    read-only call would hand the model an error it has to recover from,
 *    which is worse than running it.
 *  - `approval_required` denies until a ticket is spent. When an approval
 *    store is bound the call issues (or reuses) a ticket bound to the exact
 *    arguments, and a retry with the same arguments runs once that ticket is
 *    approved. Without a store it fails closed, matching the registry's own
 *    `ask` path rather than silently running an unapproved write.
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

use Nvoos\Core\Domain\Contract\ApprovalTicketInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Contract\ToolGuardInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;
use Nvoos\Core\Domain\Service\Safety\ActionSafetyProfile;
use Nvoos\Core\Domain\Service\Safety\NecessityGate;
use Nvoos\Core\Domain\Service\Safety\ToolCapabilityFlagCatalogue;
use Nvoos\Core\Domain\ValueObject\ApprovalRequest;

final class NecessityGuard implements ToolGuardInterface
{
    public function __construct(
        private readonly ToolResolverInterface $tools,
        private readonly bool $enabled = true,
        private readonly ?ApprovalTicketInterface $approvals = null,
        private readonly ?array $preApproved = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function evaluate(string $slug, array $arguments, array $context): ?string
    {

        if (! $this->enabled) {
            return null;
        }

        $tool = $this->tools->get($slug);

        if (null === $tool) {
            // Unknown slugs are the registry's problem, not this guard's.
            return null;
        }

        $flags = $tool instanceof ToolCapabilityFlagsInterface
            ? $tool->getCapabilityFlags()
            : array();

        if (array() === $flags) {
            // The core tool classes carry no flags of their own, so the corpus
            // catalogue supplies what WordPress declared. Absence stays empty,
            // and the gate's own "undeclared is not safe" rule takes over.
            $flags = ToolCapabilityFlagCatalogue::for($slug);
        }

        $decision = NecessityGate::decide($flags, $slug, $arguments, null, null, $this->preApproved);

        if (ActionSafetyProfile::VERDICT_BLOCK === $decision['verdict']) {
            return \sprintf('Tool "%s" is blocked by the necessity gate: %s', $slug, $decision['reason']);
        }

        if (ActionSafetyProfile::VERDICT_APPROVAL_REQUIRED === $decision['verdict']) {
            $request = self::approvalRequest(
                $slug,
                $arguments,
                $context,
                (string) $decision['reason_code']
            );

            // A retry after a human said yes spends the ticket. Anything the
            // ticket store cannot prove is treated as unapproved.
            if (null !== $this->approvals && $this->approvals->consume($request)) {
                return null;
            }

            if (null === $this->approvals) {
                return \sprintf(
                    'Tool "%s" requires human approval and no approval store is bound: %s',
                    $slug,
                    $decision['reason']
                );
            }

            return \sprintf(
                'Tool "%s" requires human approval — ticket %s is pending: %s',
                $slug,
                $this->approvals->request($request),
                $decision['reason']
            );
        }

        // allow, warn and skip all run. `warn` is a log concern for a runtime
        // that has one; `skip` is not expressible through this contract.
        return null;
    }

    /**
     * Build the ticket subject from the call and its context.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param array<string, mixed> $context   Execution context.
     */
    private static function approvalRequest(
        string $slug,
        array $arguments,
        array $context,
        string $reason
    ): ApprovalRequest {
        $userId  = isset($context['user_id']) ? (int) $context['user_id'] : 0;
        $session = $context['session_id'] ?? null;

        return new ApprovalRequest(
            $slug,
            $arguments,
            $userId > 0 ? $userId : null,
            \is_string($session) && '' !== $session ? $session : null,
            $reason
        );
    }
}
