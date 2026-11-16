<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A row in `nvoos_schedules` (Proposal 149, Wave 2 `/ops`).
 *
 * Read-only by design, like `FailedJob`: the panel never writes this model.
 * Clocks are owned by `ScheduleRunner` (that is what keeps a row from being
 * due forever after one tick), and creating or editing a definition is the
 * console's Schedule Manager (`SchedulesController`). This model exists so the
 * operator table can paginate, sort and resolve rows with Eloquent instead of
 * hand-rolling a query builder.
 *
 * The reason the operator panel needs a view of this table at all: **nothing
 * else answers "is the tick alive?"**. `nvoos_schedules` is only advanced by
 * `nvoos:run-schedules`, which only runs where the `scheduler` container role
 * runs `php artisan schedule:work`. When that role is missing the schedules
 * silently stop, and every console page still looks healthy — it lists
 * definitions, not liveness.
 */
final class Schedule extends Model
{
    protected $table = 'nvoos_schedules';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array(
            'enabled' => 'boolean',
            'tags' => 'array',
            'priority' => 'integer',
            'run_count' => 'integer',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        );
    }

    /**
     * What to call this schedule in a table cell.
     *
     * `name` is nullable — the original scheduler-only table had no label — so
     * the id is the fallback rather than an empty cell.
     */
    public function label(): string
    {
        $name = trim((string) ($this->name ?? ''));

        return '' !== $name ? $name : (string) $this->getKey();
    }

    /**
     * One of `paused`, `untimed`, `overdue`, `scheduled`.
     *
     * `overdue` is the diagnosis: an enabled row whose next run has passed and
     * was never advanced. `untimed` is the other one: enabled with no clock at
     * all, which `ScheduleRunner::bootstrap()` exists to fix.
     *
     * Lateness is a lagging indicator — a schedule whose next run is still in
     * the future looks healthy even if the tick has died. It is the honest
     * signal available without a scheduler heartbeat, which the runtime does
     * not record.
     */
    public function healthLabel(): string
    {
        if (! $this->enabled) {
            return 'paused';
        }

        if (null === $this->next_run_at) {
            return 'untimed';
        }

        return $this->next_run_at->isPast() ? 'overdue' : 'scheduled';
    }
}
