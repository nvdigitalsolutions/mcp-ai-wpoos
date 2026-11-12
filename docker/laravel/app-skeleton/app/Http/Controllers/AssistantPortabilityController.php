<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Laravel port of the assistant portability surface.
 *
 * Produces and consumes the canonical `nvoos-assistant` v1 bundle. The WP
 * engine carries every plugin meta key; this port preserves the core fields
 * and the full `meta` document, which is enough for the Service Exchange
 * bundle import and a round-trip between Laravel estates.
 */
class AssistantPortabilityController
{
    public function export(Request $request): JsonResponse
    {
        $ids = $request->input('ids');
        $query = Assistant::query();

        if (is_array($ids) && $ids !== []) {
            $query->whereIn('id', array_map('intval', $ids));
        }

        $assistants = $query->orderBy('title')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'format' => 'nvoos-assistant',
                'format_version' => 1,
                'plugin_version' => (string) config('app.version', '1.2.0'),
                'exported_at' => now()->toIso8601String(),
                'assistants' => $assistants
                    ->map(static fn (Assistant $assistant): array => $assistant->toBundleEntry())
                    ->all(),
            ],
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'json' => ['required', 'string'],
            'mode' => ['sometimes', 'in:skip,overwrite,duplicate'],
            'dry_run' => ['sometimes', 'boolean'],
            'status_override' => ['sometimes', 'in:draft,publish,private'],
        ]);

        $bundle = json_decode($payload['json'], true);

        if (! is_array($bundle) || ($bundle['format'] ?? '') !== 'nvoos-assistant') {
            abort(422, 'The payload is not an nvoos-assistant bundle.');
        }

        if ((int) ($bundle['format_version'] ?? 0) !== 1) {
            abort(422, 'Only nvoos-assistant format_version 1 is supported.');
        }

        $entries = $bundle['assistants'] ?? null;

        if (! is_array($entries) || $entries === []) {
            abort(422, 'The bundle contains no assistants.');
        }

        $mode = $payload['mode'] ?? 'skip';
        $dryRun = (bool) ($payload['dry_run'] ?? false);
        $statusOverride = $payload['status_override'] ?? null;

        $report = [
            'dry_run' => $dryRun,
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'assistants' => [],
        ];

        foreach ($entries as $entry) {
            if (! is_array($entry) || empty($entry['title'])) {
                continue;
            }

            $title = (string) $entry['title'];
            $slug = Str::slug((string) ($entry['slug'] ?? $title)) ?: 'assistant';
            $existing = Assistant::query()->where('slug', $slug)->first()
                ?? Assistant::query()->where('title', $title)->first();

            if ($existing !== null && $mode === 'skip') {
                $report['skipped']++;
                $report['assistants'][] = ['title' => $title, 'id' => $existing->id, 'action' => 'skipped'];
                continue;
            }

            if ($existing !== null && $mode === 'duplicate') {
                $slug = $this->uniqueSlug($slug);
                $existing = null;
            }

            $data = $this->entryToData($entry, $slug, $statusOverride);

            if ($dryRun) {
                $action = $existing !== null ? 'updated' : 'imported';
                $existing !== null ? $report['updated']++ : $report['imported']++;
                $report['assistants'][] = [
                    'title' => $title,
                    'id' => $existing?->id,
                    'action' => $action,
                    'dry_run' => true,
                ];
                continue;
            }

            if ($existing !== null) {
                $existing->fill($data)->save();
                $assistant = $existing;
                $report['updated']++;
                $action = 'updated';
            } else {
                $assistant = Assistant::create($data);
                $report['imported']++;
                $action = 'imported';
            }

            if (! empty($data['is_default'])) {
                Assistant::query()->where('id', '!=', $assistant->id)->update(['is_default' => false]);
            }

            $report['assistants'][] = [
                'title' => $title,
                'id' => $assistant->id,
                'action' => $action,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function entryToData(array $entry, string $slug, ?string $statusOverride): array
    {
        $meta = is_array($entry['meta'] ?? null) ? $entry['meta'] : [];
        $status = $statusOverride ?? (string) ($entry['status'] ?? 'draft');

        if (! in_array($status, ['draft', 'publish', 'private'], true)) {
            $status = 'draft';
        }

        return [
            'title' => (string) $entry['title'],
            'slug' => $slug,
            'status' => $status,
            'description' => $entry['excerpt'] ?? null,
            'system_prompt' => $entry['content'] ?? null,
            'provider' => $meta['provider'] ?? null,
            'model' => $meta['model'] ?? null,
            'temperature' => isset($meta['temperature']) ? (float) $meta['temperature'] : null,
            'max_tokens' => isset($meta['max_tokens']) ? (int) $meta['max_tokens'] : null,
            'tools' => is_array($meta['tools'] ?? null) ? array_values($meta['tools']) : [],
            'meta' => $meta,
            'is_default' => (bool) ($meta['is_default'] ?? false),
        ];
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base.'-copy';
        $suffix = 2;

        while (Assistant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-copy-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
