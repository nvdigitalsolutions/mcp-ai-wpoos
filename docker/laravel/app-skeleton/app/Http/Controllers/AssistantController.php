<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class AssistantController
{
    public function index(Request $request): JsonResponse
    {
        $query = Assistant::query()->orderBy('title');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $assistants = $query->get()
            ->map(static fn (Assistant $assistant): array => $assistant->toRecord())
            ->all();

        // The SPA's list client reads this shape directly (no envelope).
        return response()->json([
            'assistants' => $assistants,
            'total' => count($assistants),
        ]);
    }

    public function show(Assistant $assistant): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $assistant->toRecord(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['title']);
        $data['status'] = $data['status'] ?? 'draft';

        if (! empty($data['is_default'])) {
            Assistant::query()->update(['is_default' => false]);
        }

        $assistant = Assistant::create($data);

        // The SPA's create client expects the record directly (201).
        return response()->json($assistant->toRecord(), 201);
    }

    public function update(Request $request, Assistant $assistant): JsonResponse
    {
        $data = $this->validated($request, true);

        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], (int) $assistant->id);
        }

        if (! empty($data['is_default'])) {
            Assistant::query()->where('id', '!=', $assistant->id)->update(['is_default' => false]);
        }

        $assistant->fill($data)->save();

        return response()->json([
            'success' => true,
            'data' => $assistant->fresh()->toRecord(),
        ]);
    }

    public function destroy(Assistant $assistant): Response
    {
        $assistant->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $titleRule = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$titleRule, 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'in:draft,publish,private'],
            'description' => ['sometimes', 'nullable', 'string'],
            'system_prompt' => ['sometimes', 'nullable', 'string'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:40'],
            'model' => ['sometimes', 'nullable', 'string', 'max:120'],
            'temperature' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:200000'],
            'tools' => ['sometimes', 'array'],
            'tools.*' => ['string', 'max:120'],
            'meta' => ['sometimes', 'array'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    private function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'assistant';
        $slug = $base;
        $suffix = 2;

        while (Assistant::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, static fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
