<?php

namespace App\Support;

use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;

/**
 * Workflow triggers — the `trigger → execute` half of 090-h.
 *
 * WordPress hooks trigger types onto WP actions (`save_post`,
 * `user_register`, `add_attachment`, a cron hook) and calls
 * `WP_MCP_AI_Workflow_Trigger_CPT::fire_trigger()`, which records the firing
 * and dispatches the workflow. This runtime has no WordPress actions, so the
 * portable subset is:
 *
 * - `cron_schedule` (aliased `schedule`) — a schedule expression in the
 *   trigger's `config`, fired by `nvoos:fire-triggers`.
 * - everything else — fired by an explicit `POST /orchestration/triggers/{id}/fire`,
 *   which is also how an operator tests a trigger before relying on it.
 *
 * `config.cron` wins over `config.schedule`, which accepts either a WP-style
 * interval name (`hourly`, `twicedaily`, `daily`, `weekly`, `monthly`) or a
 * five-field expression. `config.every_minutes` is the third fallback.
 *
 * The payload becomes the run's `input`, exactly as WordPress passes the
 * hook arguments through, so `{{ input.… }}` in the graph resolves against
 * what the event carried.
 */
final class TriggerDispatcher
{
    /** WP interval names, plus the obvious extras. */
    private const NAMED_SCHEDULES = [
        'every_minute' => '* * * * *',
        'hourly' => '0 * * * *',
        'twicedaily' => '0 */12 * * *',
        'daily' => '0 0 * * *',
        'weekly' => '0 0 * * 1',
        'monthly' => '0 0 1 * *',
    ];

    /** Trigger types this class can schedule. */
    private const SCHEDULE_TYPES = ['cron_schedule', 'schedule'];

    public function __construct(
        private readonly ContentStoreInterface $content,
        private readonly WorkflowRunner $runner,
    ) {
    }

    /**
     * Fire one trigger now.
     *
     * @param object               $trigger Trigger row.
     * @param array<string, mixed> $payload Event payload, used as the run input.
     * @param int                  $userId  Caller identity for capability checks.
     * @return array<string, mixed> The runner's payload.
     *
     * @throws TriggerNotRunnable When the trigger cannot fire.
     */
    public function fire(object $trigger, array $payload = [], int $userId = 0): array
    {
        if (! (bool) $trigger->enabled) {
            throw new TriggerNotRunnable('trigger_disabled', 'This trigger is disabled.', 409);
        }

        $workflowId = (int) ($trigger->workflow_id ?? 0);

        if ($workflowId <= 0) {
            throw new TriggerNotRunnable(
                'trigger_without_workflow',
                'This trigger has no workflow to run.',
                422
            );
        }

        $workflow = WorkflowRecords::find($this->content, $workflowId);

        if (null === $workflow) {
            throw new TriggerNotRunnable(
                'workflow_not_found',
                "Workflow {$workflowId} no longer exists.",
                404
            );
        }

        DB::table('nvoos_triggers')->where('id', (int) $trigger->id)->update([
            'last_fired_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->runner->run(
            $workflow,
            $payload,
            $userId,
            'trigger',
            (int) $trigger->id,
        );
    }

    /**
     * Enabled schedule triggers whose latest slot has not fired yet.
     *
     * @return list<object> Trigger rows.
     */
    public function due(?DateTimeInterface $now = null): array
    {
        $now = $now ?? Carbon::now();

        $rows = DB::table('nvoos_triggers')
            ->where('enabled', true)
            ->whereIn('type', self::SCHEDULE_TYPES)
            ->orderBy('id')
            ->get();

        $due = [];

        foreach ($rows as $row) {
            if ((int) ($row->workflow_id ?? 0) <= 0) {
                continue;
            }

            if ($this->isDue($row, $now)) {
                $due[] = $row;
            }
        }

        return $due;
    }

    /**
     * Whether the trigger's most recent scheduled slot is unfired.
     *
     * A trigger that has never fired is due immediately — the same behaviour
     * as `wp_schedule_event( time(), … )` at creation.
     */
    public function isDue(object $trigger, ?DateTimeInterface $now = null): bool
    {
        $cron = $this->expression($trigger);

        if (null === $cron) {
            return false;
        }

        $now = $now ?? Carbon::now();
        $previous = $cron->getPreviousRunDate($now, 0, true);

        $lastFired = $trigger->last_fired_at ?? null;

        if (null === $lastFired || '' === $lastFired) {
            return true;
        }

        $lastFiredAt = Carbon::parse((string) $lastFired);

        return $lastFiredAt->lt(Carbon::instance($previous));
    }

    /**
     * Resolve a trigger's schedule expression, or null when it has none.
     */
    public function expression(object $trigger): ?CronExpression
    {
        $config = json_decode((string) ($trigger->config ?? ''), true);
        $config = is_array($config) ? $config : [];

        $candidate = $config['cron'] ?? $config['schedule'] ?? null;

        if (is_string($candidate) && '' !== trim($candidate)) {
            $candidate = trim($candidate);
            $candidate = self::NAMED_SCHEDULES[strtolower($candidate)] ?? $candidate;

            return CronExpression::isValidExpression($candidate)
                ? new CronExpression($candidate)
                : null;
        }

        if (isset($config['every_minutes']) && is_numeric($config['every_minutes'])) {
            $minutes = (int) $config['every_minutes'];

            if ($minutes >= 1 && $minutes <= 59) {
                return new CronExpression("*/{$minutes} * * * *");
            }
        }

        return null;
    }
}
