<?php

namespace App\Support\Schedules;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

/**
 * Executes schedules and keeps their clocks.
 *
 * Three entry points share one lifecycle:
 *
 *  - {@see self::run()} is the synchronous composition (open + execute) used by
 *    the `nvoos:run-schedules` tick and the Filament "run due" action. Those
 *    callers own their own time budget.
 *  - {@see self::begin()} + {@see self::execute()} are the split used by the
 *    browser trigger: `POST /schedules/{id}/trigger` must answer long before a
 *    provider round trip finishes, so it opens the record, queues
 *    {@see \App\Jobs\RunSchedule}, and returns the run id for the console to
 *    poll. Holding the HTTP request open instead is how a slow handler becomes
 *    a 504 at the edge while the worker keeps running invisibly.
 *  - {@see self::reapStale()} is the safety net for a worker that died hard
 *    (SIGKILL, OOM, container restart). The job's `failed()` covers a job that
 *    fails normally; nothing covers a process that never reported back, so the
 *    in-flight row would otherwise sit at `running` forever.
 *
 * `next_run_at` is owned here: nothing else writes it, which is what keeps a row
 * from being due forever after a single tick.
 */
final class ScheduleRunner
{
    /** Queued, not started. */
    public const STATUS_PENDING = 'pending';

    /** Picked up by a worker. */
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILURE = 'failure';

    /** Reaped: in flight past the staleness window, never reported back. */
    public const STATUS_TIMEOUT = 'timeout';

    /** States that are not outcomes yet. Fleet stats and the UI ignore them. */
    public const IN_FLIGHT = array( self::STATUS_PENDING, self::STATUS_RUNNING );

    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ErrorFactoryInterface $errors,
    ) {
    }

    /**
     * Give enabled, never-timed schedules their first run time.
     *
     * Creating a schedule must not fire it on the next tick, so the first
     * occurrence is always in the future. Returns the number of rows timed.
     */
    public function bootstrap(?Carbon $now = null): int
    {
        $now = $now ?? Carbon::now();
        $timed = 0;

        $rows = DB::table('nvoos_schedules')
            ->where('enabled', true)
            ->whereNull('next_run_at')
            ->get();

        foreach ($rows as $row) {
            $next = ScheduleTiming::nextRunAt((string) ($row->cron_expression ?? ''), $now);

            if (null === $next) {
                continue;
            }

            DB::table('nvoos_schedules')->where('id', $row->id)->update([
                'next_run_at' => $next,
                'updated_at' => $now,
            ]);

            $timed++;
        }

        return $timed;
    }

    /**
     * Enabled schedules whose next run is due, highest priority first.
     *
     * @return array<int, object>
     */
    public function due(?Carbon $now = null): array
    {
        return DB::table('nvoos_schedules')
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now ?? Carbon::now())
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Open a run row and return its id. Nothing executes yet.
     *
     * `started_at` is the moment the run was accepted, not the moment a worker
     * picked it up: the console shows it as the run's start time and a queued
     * run that never gets picked up still has an honest timestamp. The
     * execution `duration` is measured around the handler call only, so queue
     * latency never inflates it.
     */
    public function begin(object $row, string $trigger = 'cron', ?Carbon $now = null): int
    {
        $now = $now ?? Carbon::now();

        return (int) DB::table('nvoos_schedule_runs')->insertGetId([
            'schedule_id' => (string) $row->id,
            'status' => self::STATUS_PENDING,
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Execute one already-opened run and record its outcome.
     *
     * A handler that does not exist is recorded as a failed run with the
     * registry's own message rather than thrown: a schedule whose toolkit is
     * not ported must show up in history, not break the tick (or the job).
     *
     * Timed runs have no requesting user, and tools routinely check one, so
     * the actor is resolved once: `oos.schedules.user_id` when configured,
     * otherwise the first administrator. A manual trigger passes its own.
     *
     * @return array{status: string, run_id: int, error: ?string}
     */
    public function execute(object $row, int $runId, ?int $userId = null, ?Carbon $now = null): array
    {
        $userId = ($userId ?? 0) > 0 ? $userId : $this->actorId();
        $handler = (string) ($row->handler ?? '');
        $arguments = json_decode((string) ($row->payload ?? ''), true);
        $arguments = is_array($arguments) ? $arguments : [];

        DB::table('nvoos_schedule_runs')->where('id', $runId)->update([
            'status' => self::STATUS_RUNNING,
            'updated_at' => $now ?? Carbon::now(),
        ]);

        $clock = microtime(true);
        $failure = null;
        $result = null;

        try {
            $result = $this->tools->execute($handler, $arguments, [
                'user_id' => $userId,
                'auth_provider' => app(AuthProviderInterface::class),
            ]);
        } catch (\Throwable $e) {
            $failure = $e->getMessage();
        }

        $duration = round(microtime(true) - $clock, 4);
        $finishedAt = Carbon::now();

        if (null !== $failure || null === $result || $this->errors->isError($result)) {
            $normalized = null === $result ? [] : $this->errors->normalize($result);
            $error = $failure ?? (string) ($normalized['message'] ?? 'Schedule handler failed.');
            $status = self::STATUS_FAILURE;
            $log = null;
        } else {
            $error = null;
            $status = self::STATUS_SUCCESS;
            $log = null === $result ? null : json_encode(
                $result,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }

        $this->close($runId, $status, $duration, $error, $log, $finishedAt);

        DB::table('nvoos_schedules')->where('id', $row->id)->update([
            'last_run_at' => $finishedAt,
            'run_count' => (int) ($row->run_count ?? 0) + 1,
            'next_run_at' => ScheduleTiming::nextRunAt((string) ($row->cron_expression ?? ''), $finishedAt),
            'updated_at' => $finishedAt,
        ]);

        return [
            'status' => $status,
            'run_id' => $runId,
            'error' => $error,
        ];
    }

    /**
     * Close a run that will never execute (schedule deleted, dispatch failed,
     * job exhausted its attempts) without pretending a handler ran.
     */
    public function abandon(int $runId, string $error, ?Carbon $now = null): void
    {
        $now = $now ?? Carbon::now();
        $started = DB::table('nvoos_schedule_runs')->where('id', $runId)->value('started_at');
        $duration = null === $started
            ? null
            : round(max(0, $now->getTimestamp() - Carbon::parse((string) $started)->getTimestamp()), 4);

        $this->close($runId, self::STATUS_FAILURE, $duration, $error, null, $now);
    }

    /**
     * Execute one schedule from start to finish (synchronous callers only).
     *
     * @return array{status: string, run_id: int, error: ?string}
     */
    public function run(object $row, ?int $userId = null, ?Carbon $now = null): array
    {
        $runId = $this->begin($row, 'cron', $now);

        return $this->execute($row, $runId, $userId, $now);
    }

    /**
     * The newest in-flight run for a schedule, ignoring rows old enough to be
     * stale (a dead worker must not block the schedule forever).
     */
    public function activeRun(string $scheduleId, ?int $staleAfter = null): ?object
    {
        $staleAfter = $staleAfter ?? $this->staleAfter();

        return DB::table('nvoos_schedule_runs')
            ->where('schedule_id', $scheduleId)
            ->whereIn('status', self::IN_FLIGHT)
            ->where('started_at', '>', Carbon::now()->subSeconds($staleAfter))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * One run row, presented for the poll route.
     *
     * @return array<string, mixed>|null
     */
    public function status(string $scheduleId, int $runId): ?array
    {
        $row = DB::table('nvoos_schedule_runs')
            ->where('schedule_id', $scheduleId)
            ->where('id', $runId)
            ->first();

        if ($row === null) {
            return null;
        }

        $status = (string) $row->status;

        return [
            'run_id' => (int) $row->id,
            'schedule_id' => (string) $row->schedule_id,
            'status' => $status,
            'finished' => ! in_array($status, self::IN_FLIGHT, true),
            'error' => $row->error,
            'duration' => $row->duration !== null ? (float) $row->duration : null,
            'time' => $row->started_at !== null ? (int) strtotime((string) $row->started_at) : null,
            'finished_at' => $row->finished_at !== null ? (int) strtotime((string) $row->finished_at) : null,
        ];
    }

    /**
     * Mark in-flight runs that will never report back as `timeout`.
     *
     * Idempotent and cheap (indexed on schedule_id/status). Scoped to one
     * schedule when the console polls that schedule, fleet-wide from the tick.
     *
     * A reaped run also advances `next_run_at`: leaving it in the past would
     * make the schedule permanently due and re-trigger a handler that already
     * proved it can kill its worker. `run_count` and `last_run_at` are left
     * alone — nothing ran to completion, and history holds the truth.
     */
    public function reapStale(?int $seconds = null, ?Carbon $now = null, ?string $scheduleId = null): int
    {
        $seconds = $seconds ?? $this->staleAfter();
        $now = $now ?? Carbon::now();
        $cutoff = $now->copy()->subSeconds($seconds);

        $query = DB::table('nvoos_schedule_runs')
            ->whereIn('status', self::IN_FLIGHT)
            ->where('started_at', '<', $cutoff);

        if (null !== $scheduleId) {
            $query->where('schedule_id', $scheduleId);
        }

        $rows = $query->get(['id', 'schedule_id', 'started_at']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $message = sprintf(
            'Run did not report back within %d seconds; marked timed out.',
            $seconds,
        );

        foreach ($rows as $row) {
            $started = Carbon::parse((string) $row->started_at);
            $duration = round(max(0, $cutoff->getTimestamp() - $started->getTimestamp()), 4);

            $this->close((int) $row->id, self::STATUS_TIMEOUT, $duration, $message, null, $now);
        }

        foreach ($rows->pluck('schedule_id')->unique() as $id) {
            $schedule = DB::table('nvoos_schedules')->find((string) $id);

            if (null === $schedule) {
                continue;
            }

            DB::table('nvoos_schedules')->where('id', (string) $id)->update([
                'next_run_at' => ScheduleTiming::nextRunAt((string) ($schedule->cron_expression ?? ''), $now),
                'updated_at' => $now,
            ]);
        }

        return $rows->count();
    }

    /**
     * The user a timed run acts as.
     */
    public function actorId(): int
    {
        $configured = (int) config('oos.schedules.user_id', 0);

        if ($configured > 0) {
            return $configured;
        }

        return (int) (User::query()
            ->where('role', 'administrator')
            ->orderBy('id')
            ->value('id') ?? 0);
    }

    /**
     * Seconds an in-flight run may stay silent before it is reaped.
     */
    public function staleAfter(): int
    {
        return max(60, (int) config('oos.schedules.stale_after', 900));
    }

    /**
     * @param array<string, mixed>|null $log
     */
    private function close(
        int $runId,
        string $status,
        ?float $duration,
        ?string $error,
        ?string $log,
        Carbon $finishedAt,
    ): void {
        DB::table('nvoos_schedule_runs')->where('id', $runId)->update([
            'status' => $status,
            'finished_at' => $finishedAt,
            'duration' => $duration,
            'error' => $error,
            'log' => $log,
            'updated_at' => $finishedAt,
        ]);
    }
}
