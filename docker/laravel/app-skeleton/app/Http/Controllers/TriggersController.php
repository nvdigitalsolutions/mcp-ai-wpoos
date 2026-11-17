<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use App\Support\TriggerDispatcher;
use App\Support\TriggerNotRunnable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Workflow triggers (`/orchestration/triggers`, `/triggers`) — 090-h.
 *
 * ESTATE SCOPE (149 §29). The table had no tenant column and every method named
 * it directly, while the routes already resolved an estate — so any tenant user
 * could list, rewrite, delete and FIRE another estate's triggers. `fire` was the
 * sharp edge: it executes the trigger's workflow with the caller's user id.
 *
 * Every query now starts at `query()`, and `TriggerDispatcher::due()` stays
 * deliberately unscoped: the scheduler is a system job that must see every
 * estate's due triggers, and `nvoos:fire-triggers` sets the estate context per
 * trigger before firing.
 */
class TriggersController
{
    public function index(): JsonResponse
    {
        $rows = $this->query()->orderBy('name')->get();

        return response()->json([
            'triggers' => array_map([$this, 'record'], $rows->all()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:40'],
            'config' => ['sometimes', 'array'],
            'workflow_id' => ['sometimes', 'nullable', 'integer'],
            'enabled' => ['sometimes', 'boolean'],
            'webhook_url' => ['sometimes', 'nullable', 'url', 'max:255'],
        ]);

        $id = $this->query()->insertGetId([
            'tenant_id' => TenantContext::current(),
            'name' => $data['name'],
            'type' => $data['type'],
            'config' => json_encode($data['config'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'workflow_id' => $data['workflow_id'] ?? null,
            'enabled' => (bool) ($data['enabled'] ?? true),
            'webhook_url' => $data['webhook_url'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($this->record($this->query()->find($id)), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'max:40'],
            'config' => ['sometimes', 'array'],
            'workflow_id' => ['sometimes', 'nullable', 'integer'],
            'enabled' => ['sometimes', 'boolean'],
            'webhook_url' => ['sometimes', 'nullable', 'url', 'max:255'],
        ]);

        $row = $this->query()->find($id);

        if ($row === null) {
            return $this->missing();
        }

        $update = ['updated_at' => now()];

        foreach (['name', 'type', 'workflow_id', 'enabled', 'webhook_url'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if (array_key_exists('config', $data)) {
            $update['config'] = json_encode($data['config'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $this->query()->where('id', $id)->update($update);

        return response()->json($this->record($this->query()->find($id)));
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = $this->query()->where('id', $id)->delete();

        return $deleted > 0
            ? response()->json(['deleted' => true])
            : $this->missing();
    }

    /**
     * POST /orchestration/triggers/{id}/fire — run the trigger's workflow now.
     *
     * 090-h — the manual half of `trigger → execute`. WordPress has no such
     * endpoint because its triggers are hooked to WP actions; here it is also
     * how an operator verifies a trigger before relying on the scheduler.
     */
    public function fire(Request $request, int $id): JsonResponse
    {
        $row = $this->query()->find($id);

        if (null === $row) {
            return $this->missing();
        }

        $payload = $request->input('input', $request->input('payload', []));
        $payload = is_array($payload) ? $payload : [];

        try {
            $result = app(TriggerDispatcher::class)->fire(
                $row,
                $payload,
                (int) $request->user()->getAuthIdentifier(),
            );
        } catch (TriggerNotRunnable $exception) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => $exception->reason,
                    'message' => $exception->getMessage(),
                ],
            ], $exception->status);
        }

        return response()->json($result, $result['success'] ? 200 : 500);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'type' => (string) $row->type,
            'config' => json_decode((string) ($row->config ?? ''), true) ?: [],
            'workflow_id' => $row->workflow_id !== null ? (int) $row->workflow_id : 0,
            'enabled' => (bool) $row->enabled,
            'last_fired_at' => $row->last_fired_at !== null ? strtotime((string) $row->last_fired_at) : 0,
            'webhook_url' => (string) ($row->webhook_url ?? ''),
        ];
    }

    private function missing(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => 'not_found', 'message' => 'Trigger not found.'],
        ], 404);
    }

    /**
     * Every query in this controller starts here, so no method can forget the
     * scope. A trigger id from another estate answers 404 exactly like a
     * nonexistent one, so the routes cannot confirm which ids exist.
     */
    private function query(): Builder
    {
        return DB::table('nvoos_triggers')->where('tenant_id', TenantContext::current());
    }
}
