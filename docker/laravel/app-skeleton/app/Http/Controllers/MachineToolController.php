<?php

namespace App\Http\Controllers;

use App\Models\Approval;
use App\Models\Assistant;
use App\Support\ActivityRecorder;
use App\Support\Auth\PrincipalScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Support\Tools\MachineToolSafety;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;

/**
 * Tool execution for a machine principal — Proposal 159 slice 4.
 *
 * The catalogue (`GET /api/v1/tools`) stays readable to any member: it is a
 * directory. *Executing* is an action, so it takes an assistant scope
 * (159 §5 rule 4) and passes a layered policy:
 *
 *  1. **Is this a machine at all?** A session, an Auth0 user or a `['*']` token
 *     is refused even though {@see \App\Http\Middleware\EnsurePrincipalScope}
 *     lets it through — the console has its own execution paths and guards, and
 *     this route exists for machine principals.
 *  2. **May this assistant use this tool?** The assistant's own `tools` list is
 *     the assignment, and it is estate-curated data.
 *  3. **May it run unattended?** Either an operator allowlisted it in
 *     `oos.machine_tools` *and* the tool declares no gated capability flag, or
 *     the call **asks a human**. A write tool does not execute; it queues an
 *     approval and the caller retries.
 *
 * ## Why write tools ask instead of being denied
 *
 * Denying every non-allowlisted tool left machines read-only forever. The
 * guidance for agent actions is "propose, then let a policy service validate and
 * a human approve" — so this route *proposes*. The first call queues an approval
 * and answers 202; once a person approves it, the same call (same tool, same
 * arguments) executes and consumes the approval.
 *
 * The approval is single-use and bound to a hash of the canonicalised arguments,
 * so one approval cannot authorise a different payload or be replayed. A denial
 * is a decision about *that* call: it answers 403 until its window lapses, at
 * which point the client can ask again.
 *
 * ## Why the allowlist is still the first gate
 *
 * The signal is MCP's own vocabulary, read from the readiness manifest: a tool
 * that declares `destructiveHint: true` or `readOnlyHint: false` is routed to a
 * human even when it is allowlisted. That is a veto, never a permit — see
 * {@see MachineToolSafety} for why the specification's defaults are not applied
 * to tools that declare nothing.
 */
final class MachineToolController
{
    /** How long a machine approval stays actionable. */
    private const APPROVAL_TTL_SECONDS = 86400;

    public function __invoke(
        Request $request,
        string $tool,
        ToolRegistry $registry,
        ActivityRecorder $activity,
        MachineToolSafety $safety
    ): JsonResponse {
        $scope = PrincipalScope::forRequest($request->user());
        $assistantId = $scope->assistantId();

        if (null === $assistantId) {
            return $this->deny(
                $activity,
                403,
                'machine_principal_required',
                'Tool execution is open to a credential scoped to an assistant.',
                $tool,
                null
            );
        }

        $assistant = Assistant::query()->find($assistantId);

        if (null === $assistant) {
            return $this->deny(
                $activity,
                404,
                'assistant_not_found',
                sprintf('No assistant [%d] in this estate.', $assistantId),
                $tool,
                $assistantId
            );
        }

        if (! in_array($tool, array_map('strval', (array) ($assistant->tools ?? array())), true)) {
            return $this->deny(
                $activity,
                403,
                'tool_not_assigned',
                sprintf('Assistant [%d] does not list tool [%s].', $assistantId, $tool),
                $tool,
                $assistantId
            );
        }

        $arguments = $request->input('arguments', array());

        if (! is_array($arguments)) {
            return response()->json(
                array(
                    'success' => false,
                    'ok' => false,
                    'message' => 'arguments must be an object.',
                    'error' => array(
                        'code' => 'invalid_arguments',
                        'message' => 'arguments must be an object.',
                    ),
                ),
                422
            );
        }

        if (! $registry->has($tool)) {
            return $this->deny(
                $activity,
                404,
                'tool_not_registered',
                sprintf('Tool [%s] is not registered in this runtime.', $tool),
                $tool,
                $assistantId
            );
        }

        $allowlisted = in_array(
            $tool,
            array_map('strval', (array) config('oos.machine_tools', array())),
            true
        );

        // The allowlist is the operator permitting; an annotation or flag can
        // only veto. Absence of a claim leaves the operator's decision standing.
        $veto = $safety->veto($tool);

        if ($allowlisted && null === $veto) {
            return $this->run($request, $tool, $arguments, $assistant, $registry, $activity);
        }

        return $this->requestApproval(
            $request,
            $tool,
            $arguments,
            $assistant,
            $registry,
            $activity,
            $veto
        );
    }

    /**
     * Run a tool that may execute unattended.
     *
     * @param array<string, mixed> $arguments
     */
    private function run(
        Request $request,
        string $tool,
        array $arguments,
        Assistant $assistant,
        ToolRegistry $registry,
        ActivityRecorder $activity
    ): JsonResponse {
        $result = $registry->executeResult($tool, $arguments, array(
            'user_id' => $request->user()?->getAuthIdentifier(),
            'auth_provider' => app(AuthProviderInterface::class),
            'assistant_id' => $assistant->getKey(),
            'tenant_id' => $assistant->tenant_id,
        ));

        $activity->record(
            $result->isOk() ? 'tool.machine_executed' : 'tool.machine_failed',
            $result->message,
            (int) $assistant->getKey(),
            array('tool' => $tool)
        );

        if (! $result->isOk()) {
            return response()->json(
                array(
                    'success' => false,
                    'ok' => false,
                    'message' => $result->message,
                    'error' => array(
                        'code' => 'tool_failed',
                        'message' => $result->message,
                        'details' => $result->error?->toArray(),
                    ),
                ),
                422
            );
        }

        return response()->json(
            array(
                'success' => true,
                'ok' => true,
                'message' => $result->message,
                'data' => $result->data,
            )
        );
    }

    /**
     * Propose the call to a human: queue, or consume an approval already granted.
     *
     * @param array<string, mixed> $arguments
     */
    private function requestApproval(
        Request $request,
        string $tool,
        array $arguments,
        Assistant $assistant,
        ToolRegistry $registry,
        ActivityRecorder $activity,
        ?string $veto = null
    ): JsonResponse {
        $assistantId = (int) $assistant->getKey();
        $hash = $this->argumentsHash($arguments);

        $pending = $this->matching($tool, $assistantId, $hash)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if (null !== $pending) {
            // Idempotent: a retry while the decision is owed returns the same
            // request rather than filling the queue with duplicates.
            return $this->pending(
                (int) $pending->getKey(),
                // `expires_at` is a plain string on this model: Approval casts
                // nothing, and adding casts would change the panel's payload.
                null === $pending->expires_at
                    ? null
                    : Carbon::parse($pending->expires_at)->toDateTimeString(),
                $tool,
                $pending->reason
            );
        }

        $approved = $this->matching($tool, $assistantId, $hash)
            ->where('status', 'approved')
            ->whereNull('consumed_at')
            ->where(static fn (Builder $query): Builder => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();

        if (null !== $approved) {
            // Claim before executing: two retries racing must not both run. The
            // conditional update is what makes consumption single-use.
            $claimed = Approval::query()
                ->whereKey($approved->getKey())
                ->whereNull('consumed_at')
                ->update(array(
                    'consumed_at' => now(),
                    'status' => 'consumed',
                    'updated_at' => now(),
                ));

            if (1 === $claimed) {
                return $this->run($request, $tool, $arguments, $assistant, $registry, $activity);
            }
        }

        $denied = $this->matching($tool, $assistantId, $hash)
            ->where('status', 'denied')
            ->where(static fn (Builder $query): Builder => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->latest('id')
            ->first();

        if (null !== $denied) {
            return $this->deny(
                $activity,
                403,
                'approval_denied',
                sprintf('Approval for tool [%s] was denied.', $tool),
                $tool,
                $assistantId
            );
        }

        $expiresAt = now()->addSeconds(self::APPROVAL_TTL_SECONDS);

        $id = (int) DB::table('nvoos_approvals')->insertGetId(array(
            'tenant_id' => TenantContext::current(),
            'status' => 'pending',
            'tool' => $tool,
            'arguments' => json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'arguments_hash' => $hash,
            'assistant_id' => $assistantId,
            'requester_id' => $request->user()?->getAuthIdentifier(),
            'session_id' => null,
            'reason' => null === $veto
                ? sprintf('A machine credential requested tool [%s].', $tool)
                : sprintf('A machine credential requested tool [%s]: %s.', $tool, $veto),
            'resolution_note' => null,
            'resolved_by' => null,
            'resolved_at' => null,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ));

        $activity->record(
            'tool.machine_approval_requested',
            sprintf('Tool [%s] queued for approval.', $tool),
            $assistantId,
            array('tool' => $tool, 'approval_id' => $id)
        );

        return $this->pending($id, $expiresAt->toDateTimeString(), $tool, $veto);
    }

    private function pending(int $id, ?string $expiresAt, string $tool, ?string $reason = null): JsonResponse
    {
        return response()->json(
            array(
                'success' => true,
                'ok' => true,
                'status' => 'pending_approval',
                'approval_id' => $id,
                'message' => sprintf(
                    'Tool [%s] needs approval before a machine may run it. Retry this call once it is approved.',
                    $tool
                ),
                'expires_at' => $expiresAt,
                'reason' => $reason,
            ),
            202
        );
    }

    private function matching(string $tool, int $assistantId, string $hash): Builder
    {
        return Approval::query()
            ->where('tool', $tool)
            ->where('assistant_id', $assistantId)
            ->where('arguments_hash', $hash);
    }

    /**
     * A stable hash of the call, independent of key order.
     *
     * @param array<string, mixed> $arguments
     */
    private function argumentsHash(array $arguments): string
    {
        return hash('sha256', (string) json_encode(
            $this->canonical($arguments),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function canonical(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonical($item);
            }
        }

        return $value;
    }

    private function deny(
        ActivityRecorder $activity,
        int $status,
        string $code,
        string $message,
        string $tool,
        ?int $assistantId
    ): JsonResponse {
        $activity->record('tool.machine_denied', $message, $assistantId, array(
            'tool' => $tool,
            'reason' => $code,
        ));

        return response()->json(
            array(
                'success' => false,
                'ok' => false,

                'message' => $message,
                'error' => array(
                    'code' => $code,
                    'message' => $message,
                ),
            ),
            $status
        );
    }
}
