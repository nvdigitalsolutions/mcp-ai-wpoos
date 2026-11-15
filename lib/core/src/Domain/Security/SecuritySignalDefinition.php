<?php

/**
 * One security-posture signal definition.
 *
 * Definitions live in the shared catalogue so both runtimes score the same
 * control with the same id, weight and remediation target. Only the *result*
 * is runtime-specific; a control that does not exist on a runtime is reported
 * as not applicable instead of being silently dropped or faked.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Security;

final class SecuritySignalDefinition
{
    /**
     * @param string             $id        Stable signal id shared by every runtime.
     * @param string             $label     English default; a runtime may supply a translated label.
     * @param int                $weight    Contribution to the weighted score (the scorer normalises by applicable weight).
     * @param string             $subtab    Owning settings subtab (network|access|audit|ai_safety).
     * @param string             $anchor    Anchor on that subtab that fixes the signal.
     * @param array<int, string> $runtimes  Runtimes where the control can be evaluated.
     * @param string             $reference Standard this control comes from, when there is one.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly int $weight,
        public readonly string $subtab,
        public readonly string $anchor,
        public readonly array $runtimes,
        public readonly string $reference = '',
    ) {
    }

    public function appliesTo(string $runtime): bool
    {
        return in_array($runtime, $this->runtimes, true);
    }
}
