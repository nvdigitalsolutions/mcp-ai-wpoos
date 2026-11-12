<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;

class AssistantController
{
    public function __construct(private readonly ProfessionRepositoryInterface $professions)
    {
    }
    public function index(Request $request): JsonResponse
    {
        $query = Assistant::query()
            ->where('tenant_id', TenantContext::current())
            ->orderBy('title');

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
        $this->ensureCurrentTenant($assistant);

        return response()->json([
            'success' => true,
            'data' => $assistant->toRecord(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->withPrimaryRoles($this->validated($request));
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? $data['title']);
        $data['status'] = $data['status'] ?? 'draft';

        if (! empty($data['is_default'])) {
            Assistant::query()
                ->where('tenant_id', TenantContext::current())
                ->update(['is_default' => false]);
        }

        $assistant = new Assistant($data);
        $assistant->tenant_id = TenantContext::current();
        $assistant->save();

        // The SPA's create client expects the record directly (201).
        return response()->json($assistant->toRecord(), 201);
    }

    public function update(Request $request, Assistant $assistant): JsonResponse
    {
        $this->ensureCurrentTenant($assistant);

        $data = $this->withPrimaryRoles($this->validated($request, true), $assistant);

        if (isset($data['slug'])) {
            $data['slug'] = $this->uniqueSlug($data['slug'], (int) $assistant->id);
        }

        if (! empty($data['is_default'])) {
            Assistant::query()
                ->where('tenant_id', $assistant->tenant_id)
                ->where('id', '!=', $assistant->id)
                ->update(['is_default' => false]);
        }

        $assistant->fill($data)->save();

        return response()->json([
            'success' => true,
            'data' => $assistant->fresh()->toRecord(),
        ]);
    }

    public function destroy(Assistant $assistant): Response
    {
        $this->ensureCurrentTenant($assistant);

        $assistant->delete();

        return response()->noContent();
    }

    /**
     * Model binding resolves the row without tenancy, so the guard lives here.
     */
    private function ensureCurrentTenant(Assistant $assistant): void
    {
        abort_if($assistant->tenant_id !== TenantContext::current(), 404);
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
            'primary_role_slugs' => ['sometimes', 'array', 'max:3'],
            'primary_role_slugs.*' => [
                'string',
                'max:120',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (empty($this->professions->getBySlug((string) $value)['found'])) {
                        $fail("Unknown profession: {$value}.");
                    }
                },
            ],
        ]);
    }

    /**
     * Move the profession selection into `meta`.
     *
     * WordPress keeps up to three profession post IDs in
     * `_wp_mcp_ai_primary_roles`; the catalogue is reference data here, so the
     * slugs live in `meta.primary_role_slugs` and `toRecord()` promotes them.
     *
     * @param array<string, mixed> $data      Validated payload.
     * @param Assistant|null        $assistant Existing record on update.
     *
     * @return array<string, mixed>
     */
    private function withPrimaryRoles(array $data, ?Assistant $assistant = null): array
    {
        if (! array_key_exists('primary_role_slugs', $data)) {
            return $data;
        }

        $slugs = array_values(array_unique($data['primary_role_slugs']));
        unset($data['primary_role_slugs']);

        $meta = $data['meta'] ?? ($assistant->meta ?? []);
        $meta['primary_role_slugs'] = $slugs;
        $data['meta'] = $meta;

        return $data;
    }

    private function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'assistant';
        $slug = $base;
        $suffix = 2;

        while (Assistant::query()
            ->where('tenant_id', TenantContext::current())
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
