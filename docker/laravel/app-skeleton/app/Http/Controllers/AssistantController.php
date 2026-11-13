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
    /**
     * Advanced assistant settings mirrored from the WordPress editor.
     *
     * Whitelist + coercion rules for the additive `advanced` object
     * (090-B assistant edit parity). Keys are stored flat in `meta` so an
     * `nvoos-assistant` bundle round-trips between WordPress and Laravel.
     * Credentials are deliberately absent — token issuance is its own
     * service with its own capability and audit path.
     *
     * @var array<string, string>
     */
    private const ADVANCED_FIELD_TYPES = [
        'fallback_chain' => 'string_list',
        'adaptive_tool_cap' => 'enum:on,off',
        'memory_files' => 'int_list',
        'vector_store_id' => 'string',
        'corpus_name' => 'string',
        'prompt_caching' => 'bool',
        'disable_tool_shortcuts' => 'bool',
        'tool_shortcuts' => 'list',
        'tool_prebuilt_shortcuts' => 'map',
        'tool_role_rules' => 'list',
        'external_action_id' => 'string',
        'external_action_type' => 'enum:workflow,assistant',
        'required_capability' => 'string',
        'preferred_datasets' => 'list',
    ];

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
        $data = $this->withAdvancedFields($this->withPrimaryRoles($this->validated($request)));
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

        $data = $this->withAdvancedFields($this->withPrimaryRoles($this->validated($request, true), $assistant), $assistant);

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
            'advanced' => ['sometimes', 'array'],
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

    /**
     * Normalise the console's `advanced` object into flat `meta` keys.
     *
     * Mirrors the WordPress REST contract: whitelist only, per-key coercion,
     * absent keys left untouched, present-but-empty values removed. The flat
     * keys match the `_wp_mcp_ai_*` post-meta keys an exported bundle carries,
     * so the same payload imports into either runtime.
     *
     * @param array<string, mixed> $data      Validated payload.
     * @param Assistant|null        $assistant Existing record on update.
     *
     * @return array<string, mixed>
     */
    private function withAdvancedFields(array $data, ?Assistant $assistant = null): array
    {
        if (! array_key_exists('advanced', $data)) {
            return $data;
        }

        $input = is_array($data['advanced']) ? $data['advanced'] : [];
        unset($data['advanced']);

        $meta = $data['meta'] ?? ($assistant->meta ?? []);
        $meta = is_array($meta) ? $meta : [];

        foreach (self::ADVANCED_FIELD_TYPES as $field => $type) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $value = $this->normaliseAdvancedValue($type, $input[$field]);

            if ($value === null) {
                unset($meta[$field]);
                continue;
            }

            $meta[$field] = $value;
        }

        $data['meta'] = $meta;

        return $data;
    }

    /**
     * Coerce one advanced value; null means “clear the key”.
     */
    private function normaliseAdvancedValue(string $type, mixed $value): mixed
    {
        if ($type === 'bool') {
            return (bool) $value ? true : null;
        }

        if (str_starts_with($type, 'enum:')) {
            $allowed = explode(',', substr($type, 5));
            $candidate = is_string($value) ? trim($value) : '';

            return in_array($candidate, $allowed, true) ? $candidate : null;
        }

        if ($type === 'int_list') {
            $ids = [];

            foreach (is_array($value) ? $value : [] as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }

            $ids = array_values(array_unique($ids));

            return $ids === [] ? null : $ids;
        }

        if ($type === 'string_list') {
            $items = [];

            foreach (is_array($value) ? $value : [] as $item) {
                if (! is_string($item)) {
                    continue;
                }

                $item = trim($item);
                if ($item !== '') {
                    $items[] = $item;
                }
            }

            $items = array_values(array_unique($items));

            return $items === [] ? null : $items;
        }

        if ($type === 'list') {
            $items = [];

            foreach (is_array($value) ? $value : [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }

            return $items === [] ? null : $items;
        }

        if ($type === 'map') {
            return (is_array($value) && $value !== []) ? $value : null;
        }

        // Plain string.
        $string = is_string($value) ? trim($value) : '';

        return $string === '' ? null : $string;
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
