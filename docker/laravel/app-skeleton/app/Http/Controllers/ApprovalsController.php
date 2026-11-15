<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprovalsController
{
    public function index(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->query('limit', 25)));
        $offset = max(0, (int) $request->query('offset', 0));
        $status = (string) $request->query('status', 'pending');

        $query = DB::table('nvoos_approvals');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('created_at')->offset($offset)->limit($limit)->get();

        return response()->json([
            'approvals' => array_map([$this, 'record'], $rows->all()),
            'total' => $total,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tool' => ['required', 'string', 'max:120'],
            'arguments' => ['sometimes', 'array'],
            'assistant_id' => ['sometimes', 'nullable', 'integer'],
            'requester_id' => ['sometimes', 'nullable', 'integer'],
            'session_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'reason' => ['sometimes', 'nullable', 'string'],
            'expires_in' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $id = DB::table('nvoos_approvals')->insertGetId([
            'status' => 'pending',
            'tool' => $data['tool'],
            'arguments' => json_encode($data['arguments'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'assistant_id' => $data['assistant_id'] ?? null,
            'requester_id' => $data['requester_id'] ?? $request->user()?->getAuthIdentifier(),
            'session_id' => $data['session_id'] ?? null,
            'reason' => $data['reason'] ?? null,
            'expires_at' => isset($data['expires_in']) ? now()->addSeconds((int) $data['expires_in']) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($this->record(DB::table('nvoos_approvals')->find($id)), 201);
    }

    public function resolve(Request $request, int $id, string $action): JsonResponse
    {
        if (! in_array($action, ['approve', 'deny'], true)) {
            abort(422, 'Action must be approve or deny.');
        }

        $data = $request->validate([
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $row = DB::table('nvoos_approvals')->find($id);

        if ($row === null) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'not_found', 'message' => 'Approval not found.'],
            ], 404);
        }

        if ($row->status !== 'pending') {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'conflict', 'message' => 'Approval is already resolved.'],
            ], 409);
        }

        DB::table('nvoos_approvals')->where('id', $id)->update([
            'status' => $action === 'approve' ? 'approved' : 'denied',
            'resolution_note' => (string) ($data['note'] ?? ''),
            'resolved_by' => $request->user()?->getAuthIdentifier(),
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'status' => (string) $row->status,
            'tool' => (string) $row->tool,
            'arguments' => json_decode((string) ($row->arguments ?? ''), true) ?: [],
            'assistant_id' => $row->assistant_id !== null ? (int) $row->assistant_id : null,
            'requester_id' => $row->requester_id !== null ? (int) $row->requester_id : null,
            'session_id' => $row->session_id,
            'reason' => $row->reason,
            'resolution_note' => $row->resolution_note,
            'created_at' => $row->created_at !== null ? strtotime((string) $row->created_at) : null,
            'expires_at' => $row->expires_at !== null ? strtotime((string) $row->expires_at) : null,
        ];
    }
}
