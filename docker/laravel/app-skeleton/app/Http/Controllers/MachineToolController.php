<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Support\ActivityRecorder;
use App\Support\Auth\PrincipalScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;

/**
 * Tool execution for a machine principal — Proposal 159 slice 4.
 *
 * The catalogue (`GET /api/v1/tools`) stays readable to any member: it is a
 * directory. *Executing* is an action, so it takes an assistant scope
 * (159 §5 rule 4) and passes through three independent layers, each covering a
 * gap the others leave:
 *
 *  1. **Is this a machine at all?** A session, an Auth0 user or a `['*']` token
 *     is refused here even though {@see \App\Http\Middleware\EnsurePrincipalScope}
 *     lets it through — the console has its own execution paths and its own
 *     guards, and this route exists for machine principals.
 *  2. **May this assistant use this tool?** The assistant's own `tools` list is
 *     the assignment, and it is data the estate already curates.
 *  3. **Is this tool safe for a machine at all?** `oos.machine_tools` is the
 *     allowlist, empty by default. A tool has to be named by an operator as well
 *     as assigned, because "the assistant lists it" was a decision about an
 *     assistant, not about autonomous execution.
 *
 * Every denial is recorded, and the reason names which layer refused, because a
 * 403 with no attribution is the thing that makes machine access hard to debug.
 *
 * ## Why not `ReadOnlyToolGuard`
 *
 * It is the better authority — it gates on declared capability flags — but no
 * tool in this runtime declares those yet (214 classes, 0 declare), and with
 * `blockUndeclared` on it would block every tool while looking like protection.
 * The allowlist is honest about being an interim control; the guard becomes
 * usable as tools start declaring flags.
 */
final class MachineToolController
{
    public function __invoke(
        Request $request,
        string $tool,
        ToolRegistry $registry,
        ActivityRecorder $activity
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

        if (! in_array($tool, array_map('strval', (array) config('oos.machine_tools', array())), true)) {
            return $this->deny(
                $activity,
                403,
                'tool_not_machine_safe',
                sprintf('Tool [%s] is not on the machine allowlist (oos.machine_tools).', $tool),
                $tool,
                $assistantId
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

        $result = $registry->executeResult($tool, $arguments, array(
            'user_id' => $request->user()?->getAuthIdentifier(),
            'auth_provider' => app(AuthProviderInterface::class),
            'assistant_id' => $assistantId,
            'tenant_id' => $assistant->tenant_id,
        ));

        $activity->record(
            $result->isOk() ? 'tool.machine_executed' : 'tool.machine_failed',
            $result->message,
            $assistantId,
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
