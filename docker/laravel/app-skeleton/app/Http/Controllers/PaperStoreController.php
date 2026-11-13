<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Nvoos\Core\Domain\Contract\PaperStoreInterface;

/**
 * Paper Store API (090-f Phase 3).
 *
 * Backed by the framework-agnostic `PaperStoreInterface` rather than a database
 * table: the store is flat-file JSON on both runtimes, so a record written by
 * WordPress is readable here and vice versa. Response shapes are unchanged from
 * the database-backed version.
 */
class PaperStoreController
{
    public function __construct(private readonly PaperStoreInterface $store)
    {
    }

    public function collections(): JsonResponse
    {
        return response()->json(['collections' => $this->store->collections()]);
    }

    public function records(string $collection, Request $request): JsonResponse
    {
        $page = $this->store->query($collection, [
            'tags' => (string) $request->query('tag', ''),
            'status' => (string) $request->query('status', ''),
            'limit' => (int) $request->query('limit', 25),
            'offset' => (int) $request->query('offset', 0),
        ]);

        return response()->json([
            'records' => array_map([$this, 'record'], $page['records']),
            'total' => $page['total'],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json(['records' => []]);
        }

        $limit = min(50, max(1, (int) $request->query('limit', 50)));
        $collection = (string) $request->query('collection', '');

        $collections = $collection !== '' ? [$collection] : $this->store->collections();

        $records = [];

        foreach ($collections as $name) {
            $page = $this->store->query($name, [
                'search' => $query,
                'limit' => $limit,
            ]);

            $records = array_merge($records, $page['records']);
        }

        usort(
            $records,
            static fn (array $a, array $b): int => strcmp(
                (string) ($b['updated_at'] ?? ''),
                (string) ($a['updated_at'] ?? '')
            )
        );

        return response()->json([
            'records' => array_map([$this, 'record'], array_slice($records, 0, $limit)),
        ]);
    }

    public function show(string $collection, string $id): JsonResponse
    {
        $record = $this->store->find($collection, $id);

        return $record === null
            ? $this->missing()
            : response()->json($this->record($record));
    }

    public function store(string $collection, Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:64'],
            'status' => ['sometimes', 'in:published,draft,archived'],
            'type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'body' => ['sometimes'],
            'meta' => ['sometimes', 'array'],
        ]);

        $data['id'] = $this->uniqueId($collection, (string) ($data['id'] ?? $data['title']));

        $record = $this->store->save($collection, $data);

        return response()->json($this->record($record), 201);
    }

    public function update(string $collection, string $id, Request $request): JsonResponse
    {
        $existing = $this->store->find($collection, $id);

        if ($existing === null) {
            return $this->missing();
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:64'],
            'status' => ['sometimes', 'in:published,draft,archived'],
            'type' => ['sometimes', 'nullable', 'string', 'max:40'],
            'body' => ['sometimes'],
            'meta' => ['sometimes', 'array'],
        ]);

        $record = $this->store->save($collection, array_merge($existing, $data, ['id' => $id]));

        return response()->json($this->record($record));
    }

    public function destroy(string $collection, string $id): JsonResponse
    {
        return $this->store->delete($collection, $id)
            ? response()->json(['ok' => true])
            : $this->missing();
    }

    /**
     * Slugify the requested id and make it unique within the collection.
     */
    private function uniqueId(string $collection, string $value): string
    {
        $base = Str::slug($value) ?: (string) Str::uuid();
        $base = substr($base, 0, 64);
        $id = $base;
        $suffix = 2;

        while ($this->store->has($collection, $id)) {
            $id = $base.'-'.$suffix;
            $suffix++;
        }

        return $id;
    }

    /**
     * Shape a stored record for the API.
     *
     * The key set is unchanged from the database-backed version — the console
     * reads these fields positionally in its list and detail views.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function record(array $record): array
    {
        return [
            'id' => (string) $record['id'],
            'collection' => (string) $record['collection'],
            'title' => (string) $record['title'],
            'description' => $record['description'] === '' ? null : (string) $record['description'],
            'tags' => array_values((array) $record['tags']),
            'status' => (string) $record['status'],
            'type' => $record['type'] === '' ? null : (string) $record['type'],
            'body' => $record['body'],
            'meta' => (array) $record['meta'],
            'updated_at' => (string) ($record['updated_at'] ?? ''),
        ];
    }

    private function missing(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => 'not_found', 'message' => 'Paper Store record not found.'],
        ], 404);
    }
}
