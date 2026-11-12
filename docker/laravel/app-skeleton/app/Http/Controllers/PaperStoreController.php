<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaperStoreController
{
    public function collections(): JsonResponse
    {
        $collections = DB::table('nvoos_paper_records')
            ->select('collection')
            ->distinct()
            ->orderBy('collection')
            ->pluck('collection')
            ->all();

        return response()->json(['collections' => $collections]);
    }

    public function records(string $collection, Request $request): JsonResponse
    {
        $limit = min(200, max(1, (int) $request->query('limit', 25)));
        $offset = max(0, (int) $request->query('offset', 0));

        $query = DB::table('nvoos_paper_records')->where('collection', $collection);

        if ($request->filled('tag')) {
            $tag = (string) $request->query('tag');
            $query->where('tags', 'like', '%"'.$tag.'"%');
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('updated_at')->offset($offset)->limit($limit)->get();

        return response()->json([
            'records' => array_map([$this, 'record'], $rows->all()),
            'total' => $total,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '') {
            return response()->json(['records' => []]);
        }

        $builder = DB::table('nvoos_paper_records')
            ->where(function ($where) use ($query): void {
                $where->where('title', 'like', '%'.$query.'%')
                    ->orWhere('description', 'like', '%'.$query.'%');
            });

        if ($request->filled('collection')) {
            $builder->where('collection', (string) $request->query('collection'));
        }

        $rows = $builder->orderByDesc('updated_at')
            ->limit(min(50, max(1, (int) $request->query('limit', 50))))
            ->get();

        return response()->json(['records' => array_map([$this, 'record'], $rows->all())]);
    }

    public function show(string $collection, string $id): JsonResponse
    {
        $row = $this->find($collection, $id);

        return $row === null
            ? $this->missing()
            : response()->json($this->record($row));
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

        $id = $this->uniqueId((string) ($data['id'] ?? $data['title']));

        DB::table('nvoos_paper_records')->insert([
            'id' => $id,
            'collection' => $collection,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'tags' => json_encode(array_values($data['tags'] ?? []), JSON_UNESCAPED_UNICODE),
            'status' => $data['status'] ?? 'draft',
            'type' => $data['type'] ?? null,
            'body' => array_key_exists('body', $data) ? json_encode($data['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            'meta' => json_encode($data['meta'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($this->record($this->find($collection, $id)), 201);
    }

    public function update(string $collection, string $id, Request $request): JsonResponse
    {
        $row = $this->find($collection, $id);

        if ($row === null) {
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

        $update = ['updated_at' => now()];

        foreach (['title', 'description', 'status', 'type'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if (array_key_exists('tags', $data)) {
            $update['tags'] = json_encode(array_values($data['tags']), JSON_UNESCAPED_UNICODE);
        }

        if (array_key_exists('body', $data)) {
            $update['body'] = json_encode($data['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (array_key_exists('meta', $data)) {
            $update['meta'] = json_encode($data['meta'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        DB::table('nvoos_paper_records')
            ->where('collection', $collection)
            ->where('id', $id)
            ->update($update);

        return response()->json($this->record($this->find($collection, $id)));
    }

    public function destroy(string $collection, string $id): JsonResponse
    {
        $deleted = DB::table('nvoos_paper_records')
            ->where('collection', $collection)
            ->where('id', $id)
            ->delete();

        return $deleted > 0
            ? response()->json(['ok' => true])
            : $this->missing();
    }

    private function find(string $collection, string $id): ?object
    {
        return DB::table('nvoos_paper_records')
            ->where('collection', $collection)
            ->where('id', $id)
            ->first();
    }

    private function uniqueId(string $value): string
    {
        $base = Str::slug($value) ?: (string) Str::uuid();
        $id = $base;
        $suffix = 2;

        while (DB::table('nvoos_paper_records')->where('id', $id)->exists()) {
            $id = $base.'-'.$suffix;
            $suffix++;
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function record(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'collection' => (string) $row->collection,
            'title' => (string) $row->title,
            'description' => $row->description,
            'tags' => json_decode((string) ($row->tags ?? ''), true) ?: [],
            'status' => (string) $row->status,
            'type' => $row->type,
            'body' => json_decode((string) ($row->body ?? ''), true),
            'meta' => json_decode((string) ($row->meta ?? ''), true) ?: [],
            'updated_at' => (string) ($row->updated_at ?? ''),
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
