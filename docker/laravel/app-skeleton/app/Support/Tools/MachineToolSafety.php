<?php

namespace App\Support\Tools;

use Nvoos\Core\Application\Tool\ReadOnlyToolGuard;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

/**
 * Why a tool may not run unattended — Proposal 159 slice 4, 149 plan §126.
 *
 * ## A veto, never a permit
 *
 * MCP's tool annotations are hints: `readOnlyHint` defaults to false and
 * `destructiveHint` to true, and the specification is explicit that a client
 * should rely on them only for a server it trusts. Adopting those defaults
 * literally would mark every unannotated tool as "not read-only", which would
 * route everything to approval and make the operator allowlist meaningless.
 *
 * So an annotation can only **veto**. `oos.machine_tools` is the operator saying
 * "a machine may run this"; a tool's own declaration can say "not unattended",
 * never "yes, beyond the allowlist". Absence is not a claim either way.
 *
 * ## What vetoes
 *
 *  - `destructiveHint: true` — the tool says it can destroy. Approval.
 *  - `readOnlyHint: false` — the tool says it writes. Approval.
 *  - a declared capability flag in {@see ReadOnlyToolGuard::DEFAULT_GATED_FLAGS}
 *    — the older, class-level declaration. Approval. No tool declares one today,
 *    so this branch exists for tools that adopt it.
 *
 * `openWorldHint` deliberately does **not** veto: it describes where the data
 * comes from, not whether the call mutates anything. A read-only tool that talks
 * to an open system is still a read, and treating it as a write would send every
 * dataset lookup to a human.
 */
final class MachineToolSafety
{
    public function __construct(
        private readonly ToolReadiness $readiness,
        private readonly ToolRegistry $registry,
    ) {
    }

    /**
     * The reason this tool needs a human, or null when it may run unattended.
     */
    public function veto(string $slug): ?string
    {
        $annotations = $this->readiness->annotations($slug);

        if (isset($annotations['destructiveHint']) && true === $annotations['destructiveHint']) {
            return 'the tool declares destructiveHint';
        }

        if (isset($annotations['readOnlyHint']) && false === $annotations['readOnlyHint']) {
            return 'the tool declares readOnlyHint: false';
        }

        $gated = $this->declaredGatedFlags($slug);

        if (array() !== $gated) {
            return 'the tool declares the capability flag ' . implode(', ', $gated);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function declaredGatedFlags(string $slug): array
    {
        $candidate = $this->registry->get($slug);

        if (! $candidate instanceof ToolCapabilityFlagsInterface) {
            return array();
        }

        return array_values(array_intersect(
            array_map('strtolower', $candidate->getCapabilityFlags()),
            ReadOnlyToolGuard::DEFAULT_GATED_FLAGS
        ));
    }
}
