<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvoos\Core\Domain\Contract\TranscriptStoreInterface;

class TranscriptsController
{
    public function index(Request $request, TranscriptStoreInterface $store): JsonResponse
    {
        $perPage = min(200, max(1, (int) $request->query('per_page', 50)));
        $page = max(1, (int) $request->query('page', 1));

        $filters = [
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ];

        if ($request->filled('assistant_id')) {
            $filters['assistant_id'] = (string) $request->query('assistant_id');
        }

        $result = $store->list($filters);

        $sessions = array_map(static function (array $transcript): array {
            $messages = $transcript['messages'] ?? [];
            $preview = '';

            foreach ($messages as $message) {
                if (($message['role'] ?? '') === 'user' && isset($message['content'])) {
                    $preview = mb_substr((string) $message['content'], 0, 100);
                    break;
                }
            }

            return [
                'session_key' => $transcript['session_id'],
                'assistant_id' => $transcript['assistant_id'],
                'turn_count' => count($messages),
                'started_at' => $transcript['created_at'],
                'updated_at' => $transcript['updated_at'],
                'first_created' => $transcript['created_at'],
                'last_created' => $transcript['updated_at'],
                'preview' => $preview,
            ];
        }, $result['transcripts']);

        return response()->json([
            'sessions' => $sessions,
            'total' => $result['total'],
            'per_page' => $perPage,
            'page' => $page,
            'storage_bytes' => 0,
        ]);
    }

    public function show(string $sessionKey, TranscriptStoreInterface $store): JsonResponse
    {
        $result = $store->list(['session_id' => $sessionKey, 'limit' => 1]);
        $transcript = $result['transcripts'][0] ?? null;

        return response()->json([
            'session' => $transcript === null ? null : [
                'session_key' => $transcript['session_id'],
                'assistant_id' => $transcript['assistant_id'],
                'messages' => $transcript['messages'],
                'metadata' => $transcript['metadata'],
            ],
        ]);
    }

    public function store(Request $request, TranscriptStoreInterface $store): JsonResponse
    {
        $data = $request->validate([
            'session_key' => ['required', 'string', 'max:128'],
            'assistant_id' => ['sometimes', 'nullable'],
            'messages' => ['required', 'array'],
            'response_metadata' => ['sometimes', 'array'],
        ]);

        $result = $store->save([
            'session_id' => $data['session_key'],
            'assistant_id' => $data['assistant_id'] ?? null,
            'messages' => $data['messages'],
            'metadata' => $data['response_metadata'] ?? [],
        ]);

        return response()->json([
            'success' => $result['success'],
            'transcript_id' => $result['transcript_id'] ?? '',
        ], $result['success'] ? 201 : 422);
    }

    public function destroy(string $sessionKey, TranscriptStoreInterface $store): Response
    {
        $result = $store->list(['session_id' => $sessionKey, 'limit' => 200]);

        foreach ($result['transcripts'] as $transcript) {
            $store->delete($transcript['transcript_id']);
        }

        return response()->noContent();
    }
}
