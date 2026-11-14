<?php

namespace App\Http\Controllers\Pro;

use App\Support\Schedules\SchedulePresenter;
use App\Support\Schedules\ScheduleTiming;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pro schedule presets (`/api/v1/pro/presets`).
 *
 * Mirrors `WP_MCP_AI_SA_REST_Controller` on WordPress: the same catalogue
 * (mirrored to `resources/schedule-presets.json` by
 * `bin/sync-schedule-presets.php`, drift-checked in CI), the same filters,
 * and the same idempotent install contract — replaying a preset with the
 * same overrides returns the original schedule with `replayed: true`.
 *
 * A preset's `hook` is the WordPress action it fires there. This runtime
 * executes tool slugs, so the hook is stored as the handler: a schedule
 * whose toolkit is not ported fails with the registry's typed message in its
 * run history instead of pretending to work.
 */
class PresetsController
{
    /**
     * Override keys accepted by install, matching `override_whitelist()`.
     */
    private const OVERRIDE_KEYS = [
        'name',
        'description',
        'schedule_type',
        'hook',
        'args',
        'schedule',
        'timestamp',
        'enabled',
        'tags',
    ];

    public function index(Request $request): JsonResponse
    {
        $search = strtolower(trim((string) $request->query('search', '')));
        $category = (string) $request->query('category', '');
        $toolkit = (string) $request->query('toolkit', '');
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(50, max(1, (int) $request->query('per_page', 20)));

        $matched = [];

        foreach ($this->catalogue() as $id => $preset) {
            if ('' !== $search) {
                $haystack = strtolower(implode(' ', [
                    $id,
                    (string) ($preset['name'] ?? ''),
                    (string) ($preset['description'] ?? ''),
                    implode(' ', (array) ($preset['tags'] ?? [])),
                ]));

                if (! str_contains($haystack, $search)) {
                    continue;
                }
            }

            if ('' !== $category && ($preset['category'] ?? '') !== $category) {
                continue;
            }

            if ('' !== $toolkit && ($preset['toolkit'] ?? '') !== $toolkit) {
                continue;
            }

            $matched[$id] = ['id' => $id] + $preset;
        }

        $total = count($matched);
        $offset = ($page - 1) * $perPage;

        return response()->json([
            'ok' => true,
            'presets' => array_slice(array_values($matched), $offset, $perPage),
            'total' => $total,
            'filters' => $this->facets(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $preset = $this->catalogue()[$id] ?? null;

        if (null === $preset) {
            return $this->missingPreset();
        }

        return response()->json(['ok' => true, 'preset' => ['id' => $id] + $preset]);
    }

    public function install(Request $request, string $id): JsonResponse
    {
        $preset = $this->catalogue()[$id] ?? null;

        if (null === $preset) {
            return $this->missingPreset();
        }

        $rawOverrides = $request->input('overrides', []);
        $rawOverrides = is_array($rawOverrides) ? $rawOverrides : [];
        $overrides = $this->sanitizeOverrides($rawOverrides);

        // The key hashes the RAW overrides, exactly like WordPress, so the
        // same payload replays the same schedule on either runtime.
        $key = $this->installKey($id, $rawOverrides);

        $existing = DB::table('nvoos_schedules')->where('preset_key', $key)->first();

        if (null !== $existing) {
            return response()->json([
                'ok' => true,
                'schedule' => SchedulePresenter::present($existing),
                'preset_id' => $id,
                'replayed' => true,
            ]);
        }

        $data = array_merge((array) ($preset['schedule_data'] ?? []), $overrides);
        $interval = (string) ($data['schedule'] ?? 'daily');
        $cron = ScheduleTiming::fromWordPressInterval($interval);

        if (null === $cron) {
            return response()->json([
                'ok' => false,
                'success' => false,
                'code' => 'sa_preset_schedule_unsupported',
                'message' => sprintf('The preset interval "%s" cannot be expressed as a cron expression.', $interval),
            ], 422);
        }

        $name = (string) ($data['name'] ?? $preset['name'] ?? $id);
        $scheduleId = $this->uniqueId($name);
        $now = now();
        $payload = is_array($data['args'] ?? null) ? $data['args'] : [];

        DB::table('nvoos_schedules')->insert([
            'id' => $scheduleId,
            'preset_key' => $key,
            'name' => $name,
            'description' => $data['description'] ?? ($preset['description'] ?? null),
            'schedule_type' => 'cron',
            'enabled' => (bool) ($data['enabled'] ?? false),
            'next_run_at' => ScheduleTiming::nextRunAt($cron),
            'last_run_at' => null,
            'priority' => 10,
            'tags' => json_encode(array_values((array) ($data['tags'] ?? ($preset['tags'] ?? []))), JSON_UNESCAPED_UNICODE),
            'run_count' => 0,
            'handler' => (string) ($data['hook'] ?? ''),
            'payload' => [] === $payload ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            'cron_expression' => $cron,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'ok' => true,
            'schedule' => SchedulePresenter::present(DB::table('nvoos_schedules')->find($scheduleId)),
            'preset_id' => $id,
            'replayed' => false,
        ], 201);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function catalogue(): array
    {
        $path = resource_path('schedule-presets.json');

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        $presets = is_array($decoded) ? ($decoded['presets'] ?? null) : null;

        return is_array($presets) ? $presets : [];
    }

    /**
     * Facet values across the whole catalogue, never the filtered page.
     *
     * @return array{toolkits: array<int, string>, categories: array<int, string>}
     */
    private function facets(): array
    {
        $toolkits = [];
        $categories = [];

        foreach ($this->catalogue() as $preset) {
            if (! empty($preset['toolkit'])) {
                $toolkits[] = (string) $preset['toolkit'];
            }

            if (! empty($preset['category'])) {
                $categories[] = (string) $preset['category'];
            }
        }

        return [
            'toolkits' => array_values(array_unique($toolkits)),
            'categories' => array_values(array_unique($categories)),
        ];
    }

    /**
     * Same whitelist and coercion as `sanitize_overrides()`.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function sanitizeOverrides(array $overrides): array
    {
        $clean = [];

        foreach (self::OVERRIDE_KEYS as $key) {
            if (! array_key_exists($key, $overrides)) {
                continue;
            }

            $value = $overrides[$key];

            $clean[$key] = match ($key) {
                'timestamp' => abs((int) $value),
                'enabled' => (bool) $value,
                'args' => is_array($value) ? array_map('strval', $value) : [],
                'tags' => is_array($value)
                    ? array_values(array_filter(array_map('strval', $value), static fn (string $tag): bool => '' !== $tag))
                    : [],
                default => trim(strip_tags((string) $value)),
            };
        }

        return $clean;
    }

    /**
     * `preset:<id>:h<hash>` over the raw overrides — WordPress's format.
     *
     * @param array<string, mixed> $overrides
     */
    private function installKey(string $id, array $overrides): string
    {
        $encoded = json_encode($overrides, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'preset:' . $id . ':h' . substr(hash('sha256', false === $encoded ? '' : $encoded), 0, 16);
    }

    private function uniqueId(string $value): string
    {
        $base = Str::slug($value) ?: (string) Str::uuid();
        $id = $base;
        $suffix = 2;

        while (DB::table('nvoos_schedules')->where('id', $id)->exists()) {
            $id = $base . '-' . $suffix;
            $suffix++;
        }

        return $id;
    }

    private function missingPreset(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'success' => false,
            'code' => 'sa_preset_not_found',
            'message' => 'Preset not found.',
        ], 404);
    }
}
