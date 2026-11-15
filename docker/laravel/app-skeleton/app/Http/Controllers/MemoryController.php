<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class MemoryController
{
    public function preferences(SettingsStoreInterface $store): JsonResponse
    {
        return response()->json([
            'enabled' => (bool) $store->get('memory_enabled', true),
            'autosummarize' => (bool) $store->get('memory_autosummarize', false),
        ]);
    }

    public function updatePreferences(Request $request, SettingsStoreInterface $store): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'autosummarize' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('enabled', $data)) {
            $store->set('memory_enabled', (bool) $data['enabled']);
        }

        if (array_key_exists('autosummarize', $data)) {
            $store->set('memory_autosummarize', (bool) $data['autosummarize']);
        }

        return $this->preferences($store);
    }

    public function recall(Request $request, MemoryStoreInterface $store): JsonResponse
    {
        $agentId = (string) ($request->query('agent_id') ?: $request->user()?->getAuthIdentifier() ?: '0');
        $limit = min(50, max(1, (int) $request->query('limit', 20)));
        $filters = ['agent_id' => $agentId];

        foreach (['wing', 'room'] as $key) {
            if ($request->filled($key)) {
                $filters[$key] = (string) $request->query($key);
            }
        }

        $query = (string) $request->query('query', '');

        $items = $query !== ''
            ? $store->search($query, $filters, $limit)
            : $store->listByAgent($agentId, $filters, $limit)['memories'];

        return response()->json(['contexts' => $items]);
    }

    public function store(Request $request, MemoryStoreInterface $store): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['sometimes', 'nullable'],
            'content' => ['required', 'string'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'importance' => ['sometimes', 'nullable'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:64'],
            'wing' => ['sometimes', 'nullable', 'string', 'max:128'],
            'room' => ['sometimes', 'nullable', 'string', 'max:128'],
            'ttl' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $result = $store->store([
            'agent_id' => (string) ($data['agent_id'] ?? $request->user()?->getAuthIdentifier() ?? '0'),
            'content' => $data['content'],
            'title' => $data['title'] ?? null,
            'importance' => $this->importance($data['importance'] ?? null),
            'tags' => $data['tags'] ?? [],
            'wing' => $data['wing'] ?? null,
            'room' => $data['room'] ?? null,
            'ttl' => $data['ttl'] ?? null,
        ]);

        if (! ($result['success'] ?? false)) {
            abort(422, $result['error'] ?? 'Memory could not be stored.');
        }

        $record = $store->get((string) $result['memory_id'])['record'] ?? [];

        return response()->json($record, 201);
    }

    public function update(string $id, Request $request, MemoryStoreInterface $store): Response
    {
        $data = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'content' => ['sometimes', 'string'],
            'importance' => ['sometimes', 'nullable'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:64'],
            'wing' => ['sometimes', 'nullable', 'string', 'max:128'],
            'room' => ['sometimes', 'nullable', 'string', 'max:128'],
            'ttl' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        if (array_key_exists('importance', $data)) {
            $data['importance'] = $this->importance($data['importance']);
        }

        $store->update($id, $data);

        return response()->noContent();
    }

    public function destroy(string $id, MemoryStoreInterface $store): Response
    {
        $store->delete($id);

        return response()->noContent();
    }

    public function audit(): JsonResponse
    {
        // A durable memory audit trail is a Phase 3 follow-up; the console
        // renders an empty state rather than an error.
        return response()->json(['entries' => []]);
    }

    private function importance(mixed $value): float
    {
        if (is_numeric($value)) {
            return min(1.0, max(0.0, (float) $value));
        }

        return match (is_string($value) ? strtolower($value) : '') {
            'critical' => 1.0,
            'high' => 0.75,
            'low' => 0.25,
            default => 0.5,
        };
    }
}
