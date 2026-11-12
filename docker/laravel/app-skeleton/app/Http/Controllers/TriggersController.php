<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TriggersController
{
    public function index(): JsonResponse
    {
        $rows = DB::table('nvoos_triggers')->orderBy('name')->get();

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

        $id = DB::table('nvoos_triggers')->insertGetId([
            'name' => $data['name'],
            'type' => $data['type'],
            'config' => json_encode($data['config'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'workflow_id' => $data['workflow_id'] ?? null,
            'enabled' => (bool) ($data['enabled'] ?? true),
            'webhook_url' => $data['webhook_url'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($this->record(DB::table('nvoos_triggers')->find($id)), 201);
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

        $row = DB::table('nvoos_triggers')->find($id);

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

        DB::table('nvoos_triggers')->where('id', $id)->update($update);

        return response()->json($this->record(DB::table('nvoos_triggers')->find($id)));
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = DB::table('nvoos_triggers')->where('id', $id)->delete();

        return $deleted > 0
            ? response()->json(['deleted' => true])
            : $this->missing();
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
}
