<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;

class AssistantController
{
    public function __construct(
        private readonly ProfessionRepositoryInterface $professions,
        private readonly AssistantWriter $writer
    ) {
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
        $this->writer->ensureInCurrentEstate($assistant);

        return response()->json([
            'success' => true,
            'data' => $assistant->toRecord(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $assistant = $this->writer->create($this->validated($request));

        // The SPA's create client expects the record directly (201).
        return response()->json($assistant->toRecord(), 201);
    }

    public function update(Request $request, Assistant $assistant): JsonResponse
    {
        $this->writer->ensureInCurrentEstate($assistant);

        $assistant = $this->writer->update($assistant, $this->validated($request, true));

        return response()->json([
            'success' => true,
            'data' => $assistant->fresh()->toRecord(),
        ]);
    }

    public function destroy(Assistant $assistant): Response
    {
        $this->writer->ensureInCurrentEstate($assistant);

        $this->writer->delete($assistant);

        return response()->noContent();
    }

    /**
     * Transport-level validation.
     *
     * Field rules belong here (and in the Filament form) rather than in
     * `AssistantWriter`: the invariants a transport cannot be trusted with —
     * estate, slug, profession flattening, `advanced`, `is_default` exclusivity
     * — live in the writer, and both surfaces share them.
     *
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
}
