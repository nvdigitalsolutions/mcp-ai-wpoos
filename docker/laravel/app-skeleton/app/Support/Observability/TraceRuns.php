<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Laravel\Adapter\DatabaseTraceStore;

/**
 * Recent harness runs, read from the trace store — Proposal 149 §84.
 *
 * The WordPress observability view links out to a "Run Timeline" that shows
 * per-run tokens, tool latency and cost. The Laravel runtime has the same data
 * in `nvoos_harness_trace_records` — one row per record, grouped by `run_id` —
 * but nothing ever read it back.
 *
 * A run is one chat turn. Its records are `start`, `routing`, `iteration` and
 * `end`; the `end` record carries the summary (provider calls, cost, duration,
 * cancellation). A run with no `end` record is **incomplete**: the turn died or
 * its records were pruned mid-run, and that is worth seeing rather than hiding.
 *
 * The reader parses JSON in PHP rather than with a `LIKE '%"type":"end"%'`
 * predicate: the record shape is a JSON document, and substring matching on it
 * is the kind of query that works until the encoder changes its spacing.
 */
class TraceRuns
{
    /** Newest runs shown, matching the WordPress timeline's 50. */
    public const LIMIT = 50;

    /**
     * The newest runs, newest first.
     *
     * @return list<array{
     *     run_id: string,
     *     assistant_id: int,
     *     assistant: string,
     *     started_at: string,
     *     ended_at: string,
     *     records: int,
     *     provider_calls: int|null,
     *     cost_usd: float|null,
     *     duration_ms: int|null,
     *     status: string
     * }>
     */
    public function recent(int $limit = self::LIMIT): array
    {
        if (! Schema::hasTable(DatabaseTraceStore::TABLE)) {
            return array();
        }

        $limit = max(1, min(self::LIMIT, $limit));

        $runs = DB::table(DatabaseTraceStore::TABLE)
            ->selectRaw('run_id, assistant_id, MIN(created_at) as started_at, MAX(created_at) as ended_at, COUNT(*) as records')
            ->groupBy('run_id', 'assistant_id')
            ->orderByRaw('MAX(created_at) DESC')
            ->limit($limit)
            ->get();

        if ($runs->isEmpty()) {
            return array();
        }

        $endRecords = $this->endRecords($runs->pluck('run_id')->all());
        $titles = $this->titles($runs->pluck('assistant_id')->map(static fn ($id): int => (int) $id)->all());

        $out = array();

        foreach ($runs as $run) {
            $runId = (string) $run->run_id;
            $end = $endRecords[$runId] ?? array();

            $out[] = array(
                'run_id' => $runId,
                'assistant_id' => (int) $run->assistant_id,
                'assistant' => $titles[(int) $run->assistant_id] ?? 'Assistant #'.(int) $run->assistant_id,
                'started_at' => (string) $run->started_at,
                'ended_at' => (string) $run->ended_at,
                'records' => (int) $run->records,
                'provider_calls' => isset($end['provider_calls']) ? (int) $end['provider_calls'] : null,
                'cost_usd' => isset($end['cost']['cost_usd']) ? (float) $end['cost']['cost_usd'] : null,
                'duration_ms' => isset($end['duration_ms']) ? (int) $end['duration_ms'] : null,
                'status' => array() === $end ? 'incomplete' : ((bool) ($end['cancelled'] ?? false) ? 'aborted' : 'completed'),
            );
        }

        return $out;
    }

    /**
     * The `end` payload for each run, keyed by run_id.
     *
     * @param list<string> $runIds
     * @return array<string, array<string, mixed>>
     */
    private function endRecords(array $runIds): array
    {
        if (array() === $runIds) {
            return array();
        }

        $rows = DB::table(DatabaseTraceStore::TABLE)
            ->whereIn('run_id', $runIds)
            ->orderBy('id')
            ->get(array('run_id', 'payload'));

        $ends = array();

        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);

            if (! is_array($payload) || 'end' !== ($payload['type'] ?? '')) {
                continue;
            }

            $ends[(string) $row->run_id] = $payload;
        }

        return $ends;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    private function titles(array $ids): array
    {
        if (array() === $ids) {
            return array();
        }

        try {
            return \App\Models\Assistant::query()
                ->withoutGlobalScopes()
                ->whereIn('id', $ids)
                ->pluck('title', 'id')
                ->all();
        } catch (\Throwable $exception) {
            report($exception);

            return array();
        }
    }
}
