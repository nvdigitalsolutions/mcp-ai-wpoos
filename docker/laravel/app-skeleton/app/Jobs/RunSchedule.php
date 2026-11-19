<?php

namespace App\Jobs;

use App\Support\Schedules\ScheduleRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Execute one already-opened schedule run in the background.
 *
 * `POST /schedules/{id}/trigger` writes the run row first and then dispatches
 * this job, so the console's request never waits on a provider round trip. The
 * request returns 202 with the run id; the console polls
 * `GET /schedules/{id}/runs/{runId}` for the outcome.
 *
 * Retry policy is deliberately `$tries = 1`. A schedule run is NOT idempotent —
 * it can send email, publish content, or write to a CRM — so a retry is a
 * duplicate side effect, not a recovery. Failures are recorded by
 * {@see ScheduleRunner::execute()} (handler errors) or {@see self::failed()}
 * (infrastructure errors) rather than replayed.
 *
 * The timeout/retry_after interplay is the subtle part. The queue connection's
 * `retry_after` (90 s for `database`) is how long a reserved job may stay
 * invisible before another worker may take it; a job `$timeout` (300 s) above
 * that invites a second worker to start the same run. Raising `retry_after`
 * globally is not safe either — it delays recovery of every other queue's
 * crashed jobs. So execution is guarded instead: `WithoutOverlapping` holds a
 * lock for the whole run, which makes the duplicate attempt a no-op, and
 * `ShouldBeUnique` keeps a second dispatch from entering the queue at all.
 */
class RunSchedule implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** One attempt: rerunning a schedule duplicates its side effects. */
    public int $tries = 1;

    /**
     * Generous enough for an assistant/workflow handler that calls a provider,
     * below the console's `stale_after` (900 s) so the run is closed by this
     * process before the reaper has to.
     */
    public int $timeout = 300;

    public function __construct(
        public readonly string $scheduleId,
        public readonly int $runId,
        public readonly int $userId = 0,
    ) {
    }

    /**
     * One queued run per schedule.
     *
     * `ShouldBeUnique` refuses a second dispatch while this one is queued or
     * running; `uniqueFor` is the backstop for a lock whose worker vanished
     * (the lock outlives the job, so it must expire on its own).
     */
    public function uniqueId(): string
    {
        return self::lockKey($this->scheduleId);
    }

    public function uniqueFor(): int
    {
        return app(ScheduleRunner::class)->staleAfter();
    }

    /**
     * Guard the execution half of the same guarantee.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(self::lockKey($this->scheduleId)))->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(ScheduleRunner $runner): void
    {
        $row = DB::table('nvoos_schedules')->find($this->scheduleId);

        if (null === $row) {
            // Deleted between the trigger and the worker picking it up.
            $runner->abandon(
                $this->runId,
                sprintf('Schedule "%s" no longer exists.', $this->scheduleId),
            );

            return;
        }

        $runner->execute($row, $this->runId, $this->userId);
    }

    /**
     * Terminal failure after the single attempt (DB gone, worker killed the
     * job on timeout, queue threw). Without this the row would stay in flight
     * until the reaper noticed it.
     */
    public function failed(?Throwable $exception): void
    {
        app(ScheduleRunner::class)->abandon(
            $this->runId,
            null !== $exception ? $exception->getMessage() : 'Schedule run failed.',
        );
    }

    /**
     * Shared lock key: the job id, the queue-uniqueness key, and the
     * overlapping-lock name must agree or the three guards protect nothing.
     */
    public static function lockKey(string $scheduleId): string
    {
        return 'schedule.run:'.$scheduleId;
    }
}
