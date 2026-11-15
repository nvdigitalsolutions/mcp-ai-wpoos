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
 * The console's `POST /schedules/{id}/trigger` and the `nvoos:run-schedules`
 * command share this class so a manual run and a timed run record identical
 * history. `next_run_at` is owned here: nothing else writes it, which is what
 * keeps a row from being due forever after a single tick.
 */
final class ScheduleRunner
{
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
     * Execute one schedule, record the run, and advance its clock.
     *
     * A handler that does not exist is recorded as a failed run with the
     * registry's own message rather than thrown: a schedule whose toolkit is
     * not ported must show up in history, not break the tick.
     *
     * Timed runs have no requesting user, and tools routinely check one, so
     * the actor is resolved once: `oos.schedules.user_id` when configured,
     * otherwise the first administrator. A manual trigger passes its own.
     *
     * @return array{status: string, run_id: int, error: ?string}
     */
    public function run(object $row, ?int $userId = null, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now();
        $userId = $userId ?? $this->actorId();
        $handler = (string) ($row->handler ?? '');
        $arguments = json_decode((string) ($row->payload ?? ''), true);
        $arguments = is_array($arguments) ? $arguments : [];

        $runId = (int) DB::table('nvoos_schedule_runs')->insertGetId([
            'schedule_id' => (string) $row->id,
            'status' => 'running',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
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
            $status = 'failure';
            $log = null;
        } else {
            $error = null;
            $status = 'success';
            $log = null === $result ? null : json_encode(
                $result,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }

        DB::table('nvoos_schedule_runs')->where('id', $runId)->update([
            'status' => $status,
            'finished_at' => $finishedAt,
            'duration' => $duration,
            'error' => $error,
            'log' => $log,
            'updated_at' => $finishedAt,
        ]);

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
}
