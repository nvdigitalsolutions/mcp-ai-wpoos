<?php

namespace App\Http\Controllers\Pro;

use App\Support\Schedules\SchedulePresenter;
use App\Support\Schedules\ScheduleRunner;
use App\Support\Schedules\ScheduleTiming;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pro Schedule Manager — the console surface at `/api/v1/pro/schedules`.
 *
 * Speaks the ScheduleManagerClient's `{ ok, ... }` envelope (the Pro
 * controllers' older contract), not the `{ success, data }` shape used by
 * the Phase 1-3 base console.
 *
 * `trigger` executes the schedule's tool handler synchronously and records
 * the outcome in `nvoos_schedule_runs`; the same execution lives in
 * `ScheduleRunner`, which `nvoos:run-schedules` drives every minute.
 */
class SchedulesController
{
    public function index(): JsonResponse
    {
        $rows = DB::table('nvoos_schedules')
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        return $this->ok([
            'schedules' => array_map([$this, 'record'], $rows->all()),
            'total' => $rows->count(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $row = DB::table('nvoos_schedules')->find($id);

        return $row === null
            ? $this->missing()
            : $this->ok(['schedule' => $this->record($row)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'handler' => ['required', 'string', 'max:255'],
            'schedule' => ['required', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string'],
            'schedule_type' => ['sometimes', 'string', 'in:cron,interval,once'],
            'enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:64'],
            'payload' => ['sometimes'],
        ]);

        $id = $this->uniqueId((string) $data['name']);
        $now = now();

        DB::table('nvoos_schedules')->insert([
            'id' => $id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'schedule_type' => $data['schedule_type'] ?? 'cron',
            'enabled' => (bool) ($data['enabled'] ?? true),
            'next_run_at' => ScheduleTiming::nextRunAt((string) $data['schedule']),
            'last_run_at' => null,
            'priority' => (int) ($data['priority'] ?? 10),
            'tags' => json_encode(array_values($data['tags'] ?? []), JSON_UNESCAPED_UNICODE),
            'run_count' => 0,
            'handler' => $data['handler'],
            'payload' => $this->encodePayload($data['payload'] ?? null),
            'cron_expression' => $data['schedule'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->ok(['schedule' => $this->record(DB::table('nvoos_schedules')->find($id))], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = DB::table('nvoos_schedules')->find($id);

        if ($row === null) {
            return $this->missing();
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'handler' => ['sometimes', 'string', 'max:255'],
            'schedule' => ['sometimes', 'string', 'max:64'],
            'description' => ['sometimes', 'nullable', 'string'],
            'schedule_type' => ['sometimes', 'string', 'in:cron,interval,once'],
            'enabled' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:64'],
            'payload' => ['sometimes'],
        ]);

        $update = ['updated_at' => now()];

        foreach (['name', 'description', 'schedule_type', 'enabled', 'priority', 'handler'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field];
            }
        }

        if (array_key_exists('schedule', $data)) {
            $update['cron_expression'] = $data['schedule'];
            $update['next_run_at'] = ScheduleTiming::nextRunAt((string) $data['schedule']);
        }

        // Enabling a schedule that was never timed needs a clock before the
        // next tick, otherwise ScheduleRunner::due() skips it forever.
        if (array_key_exists('enabled', $data) && $data['enabled']
            && ! array_key_exists('schedule', $data) && null === $row->next_run_at) {
            $update['next_run_at'] = ScheduleTiming::nextRunAt((string) $row->cron_expression);
        }

        if (array_key_exists('tags', $data)) {
            $update['tags'] = json_encode(array_values($data['tags']), JSON_UNESCAPED_UNICODE);
        }

        if (array_key_exists('payload', $data)) {
            $update['payload'] = $this->encodePayload($data['payload']);
        }

        DB::table('nvoos_schedules')->where('id', $id)->update($update);

        return $this->ok(['schedule' => $this->record(DB::table('nvoos_schedules')->find($id))]);
    }

    public function destroy(string $id): JsonResponse
    {
        $deleted = DB::table('nvoos_schedules')->where('id', $id)->delete();

        if ($deleted === 0) {
            return $this->missing();
        }

        DB::table('nvoos_schedule_runs')->where('schedule_id', $id)->delete();

        return $this->ok(['deleted' => true]);
    }

    public function toggle(string $id): JsonResponse
    {
        $row = DB::table('nvoos_schedules')->find($id);

        if ($row === null) {
            return $this->missing();
        }

        $enabled = ! (bool) ($row->enabled ?? true);
        $update = [
            'enabled' => $enabled,
            'updated_at' => now(),
        ];

        // Same rule as update(): a schedule that comes back on needs a clock.
        if ($enabled && null === $row->next_run_at) {
            $update['next_run_at'] = ScheduleTiming::nextRunAt((string) $row->cron_expression);
        }

        DB::table('nvoos_schedules')->where('id', $id)->update($update);

        return $this->ok([
            'enabled' => $enabled,
            'schedule' => $this->record(DB::table('nvoos_schedules')->find($id)),
        ]);
    }

    public function trigger(Request $request, string $id, ScheduleRunner $runner): JsonResponse
    {
        $row = DB::table('nvoos_schedules')->find($id);

        if ($row === null) {
            return $this->missing();
        }

        $result = $runner->run($row, (int) ($request->user()?->id ?? 0));

        return $this->ok([
            'run' => $this->runRecord(DB::table('nvoos_schedule_runs')->find($result['run_id'])),
            'schedule' => $this->record(DB::table('nvoos_schedules')->find($id)),
        ], 'success' === $result['status'] ? 200 : 422);
    }

    public function history(string $id): JsonResponse
    {
        if (DB::table('nvoos_schedules')->find($id) === null) {
            return $this->missing();
        }

        $rows = DB::table('nvoos_schedule_runs')
            ->where('schedule_id', $id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return $this->ok([
            'history' => array_map(static function (object $row): array {
                $log = json_decode((string) ($row->log ?? ''), true);

                return [
                    'status' => (string) $row->status,
                    'start_time' => $row->started_at !== null ? (int) strtotime((string) $row->started_at) : null,
                    'duration' => $row->duration !== null ? (float) $row->duration : null,
                    'error' => $row->error,
                    'action_log' => is_array($log) && array_is_list($log) ? $log : [],
                ];
            }, $rows->all()),
        ]);
    }

    public function results(Request $request, string $id): JsonResponse
    {
        if (DB::table('nvoos_schedules')->find($id) === null) {
            return $this->missing();
        }

        $limit = min(100, max(1, (int) $request->query('limit', 10)));

        $rows = DB::table('nvoos_schedule_runs')
            ->where('schedule_id', $id)
            ->whereNotNull('log')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $this->ok([
            'results' => array_map(static function (object $row): array {
                $log = json_decode((string) ($row->log ?? ''), true);

                return [
                    'summary' => is_array($log) && isset($log['message']) && is_string($log['message'])
                        ? $log['message']
                        : 'Run '.$row->status,
                    'data' => $log,
                    'render' => 'json',
                    'status' => (string) $row->status,
                    'error' => $row->error,
                    'generated_at' => $row->finished_at !== null ? (int) strtotime((string) $row->finished_at) : null,
                ];
            }, $rows->all()),
        ]);
    }

    public function fleetStats(): JsonResponse
    {
        $success = DB::table('nvoos_schedule_runs')->where('status', 'success')->count();
        $failure = DB::table('nvoos_schedule_runs')->where('status', 'failure')->count();
        $completed = $success + $failure;

        return $this->ok([
            'stats' => [
                'total_schedules' => DB::table('nvoos_schedules')->count(),
                'enabled_schedules' => DB::table('nvoos_schedules')->where('enabled', true)->count(),
                'total_runs' => DB::table('nvoos_schedule_runs')->count(),
                'success_runs' => $success,
                'failure_runs' => $failure,
                'success_rate' => $completed > 0 ? round(($success / $completed) * 100, 1) : 0.0,
                'failures_24h' => DB::table('nvoos_schedule_runs')
                    ->where('status', 'failure')
                    ->where('started_at', '>=', now()->subDay())
                    ->count(),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function ok(array $payload, int $status = 200): JsonResponse
    {
        return response()->json(array_merge(['ok' => true], $payload), $status);
    }

    private function missing(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'success' => false,
            'message' => 'Schedule not found.',
        ], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(object $row): array
    {
        return SchedulePresenter::present($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function runRecord(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'schedule_id' => (string) $row->schedule_id,
            'status' => (string) $row->status,
            'started_at' => $row->started_at !== null ? (string) $row->started_at : null,
            'finished_at' => $row->finished_at !== null ? (string) $row->finished_at : null,
            'duration' => $row->duration !== null ? (float) $row->duration : null,
            'error' => $row->error,
        ];
    }

    private function uniqueId(string $value): string
    {
        $base = Str::slug($value) ?: (string) Str::uuid();
        $id = $base;
        $suffix = 2;

        while (DB::table('nvoos_schedules')->where('id', $id)->exists()) {
            $id = $base.'-'.$suffix;
            $suffix++;
        }

        return $id;
    }

    private function encodePayload(mixed $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        if (is_string($payload)) {
            return $payload;
        }

        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
