<?php

namespace App\Support\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Application\Chat\TraceCapture;
use Nvoos\Laravel\Adapter\DatabaseTraceStore;

/**
 * Aggregates the harness trace records into the figures the catalogue needs —
 * Proposal 155 §4, slice M1.
 *
 * Traces are a **JSON record stream**, not columns: one row per record, with the
 * body in `payload`. They are parsed in PHP rather than with a `LIKE
 * '%"type":"tool"%'` predicate, because substring matching on JSON works until
 * the encoder changes its spacing (the rule §84 already set for `TraceRuns`).
 *
 * That makes the read cost proportional to the window, so it is **capped** at
 * `SCAN_LIMIT` newest records and reports `truncated` when the cap bit. A page
 * that silently averaged over a partial window would be lying about its own
 * numbers; the ceiling is printed instead, the way `/ops/audit-log` prints its
 * row limit.
 *
 * A second honesty note: trace capture is **per assistant and opt-in**
 * (`trace_capture.enabled`), and its retention is a run count rather than a
 * time window. So these figures describe the assistants that are traced, over
 * their retained runs. The page says so.
 */
final class TraceMetrics
{
    /**
     * Records decoded per request.
     *
     * Generous enough that a normal deployment never hits it, small enough that
     * a pathological one cannot exhaust the request.
     */
    public const SCAN_LIMIT = 20000;

    /**
     * The record type `TraceCapture::toolExecution()` writes.
     *
     * Referenced rather than re-typed: a second literal for a record type is a
     * literal that drifts the first time one of them is renamed.
     */
    public const TYPE_TOOL = TraceCapture::TYPE_TOOL;

    /** The record type a run closes with. */
    public const TYPE_END = TraceCapture::TYPE_END;

    /**
     * Aggregate the records written since `$since`.
     *
     * @return array{
     *     records: int,
     *     truncated: bool,
     *     runs: int,
     *     aborted: int,
     *     duration_avg_ms: float|null,
     *     iterations_avg: float|null,
     *     tools: int,
     *     tool_success: int,
     *     tool_error: int,
     *     tool_duration_avg_ms: float|null
     * }
     */
    public function aggregate(CarbonImmutable $since): array
    {
        $out = array(
            'records' => 0,
            'truncated' => false,
            'runs' => 0,
            'aborted' => 0,
            'duration_avg_ms' => null,
            'iterations_avg' => null,
            'tools' => 0,
            'tool_success' => 0,
            'tool_error' => 0,
            'tool_duration_avg_ms' => null,
        );

        if (! Schema::hasTable(DatabaseTraceStore::TABLE)) {
            return $out;
        }

        $rows = DB::table(DatabaseTraceStore::TABLE)
            ->where('created_at', '>=', $since)
            ->orderByDesc('id')
            ->limit(self::SCAN_LIMIT + 1)
            ->get(array('payload'));

        if ($rows->count() > self::SCAN_LIMIT) {
            $out['truncated'] = true;
            $rows = $rows->slice(0, self::SCAN_LIMIT);
        }

        $durationSum = 0.0;
        $durationCount = 0;
        $iterationSum = 0.0;
        $iterationCount = 0;
        $toolDurationSum = 0.0;
        $toolDurationCount = 0;

        foreach ($rows as $row) {
            $record = json_decode((string) $row->payload, true);

            if (! is_array($record)) {
                continue;
            }

            ++$out['records'];

            $type = (string) ($record['type'] ?? '');

            if (self::TYPE_TOOL === $type) {
                ++$out['tools'];

                $outcome = (string) ($record['outcome'] ?? '');
                if ('success' === $outcome) {
                    ++$out['tool_success'];
                } elseif ('error' === $outcome) {
                    ++$out['tool_error'];
                }

                if (isset($record['duration_ms']) && is_numeric($record['duration_ms'])) {
                    $toolDurationSum += (float) $record['duration_ms'];
                    ++$toolDurationCount;
                }

                continue;
            }

            if (self::TYPE_END !== $type) {
                continue;
            }

            ++$out['runs'];

            if ((bool) ($record['cancelled'] ?? false)) {
                ++$out['aborted'];
            }

            if (isset($record['duration_ms']) && is_numeric($record['duration_ms'])) {
                $durationSum += (float) $record['duration_ms'];
                ++$durationCount;
            }

            if (isset($record['iterations']) && is_numeric($record['iterations'])) {
                $iterationSum += (float) $record['iterations'];
                ++$iterationCount;
            }
        }

        $out['duration_avg_ms'] = $durationCount > 0 ? $durationSum / $durationCount : null;
        $out['iterations_avg'] = $iterationCount > 0 ? $iterationSum / $iterationCount : null;
        $out['tool_duration_avg_ms'] = $toolDurationCount > 0 ? $toolDurationSum / $toolDurationCount : null;

        return $out;
    }
}
