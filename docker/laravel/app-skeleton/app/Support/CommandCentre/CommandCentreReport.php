<?php

namespace App\Support\CommandCentre;

use App\Models\Assistant;
use App\Support\ActivityRecorder;
use App\Support\HealthSampler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The command-centre overview — 090-i, extended by Proposal 149 §106.
 *
 * One projection, two readers: `GET /api/v1/pro/command-center/overview` (what
 * the console's dashboard renders) and `/admin/{estate}/command-centre` (the
 * ported panel page). The API was the only reader when it shipped, so the
 * assembly lived in its controller. Putting the page on top of that controller
 * would have meant either an HTTP call from a Livewire component or a second
 * copy of six definitions — and a second copy is how the two drift, which is
 * exactly what §105 found when the console and the magic-link endpoint
 * disagreed. So the numbers live here and both callers read them.
 *
 * Each figure is sourced, and the three projections are named rather than
 * implied:
 *
 *  - **`agents_online`** counts `publish` assistants. This runtime has no
 *    presence channel, so "online" means "enabled" — the same reading
 *    `orchestration/stats` already uses for `active_agents`.
 *  - **`agent_statuses`** is derived from the same field: `publish` is
 *    `online`, anything else `idle`. `last_active` is the assistant's
 *    `updated_at`, not a heartbeat. `title` rides along for the panel's map;
 *    the console's `AgentStatusEntry` interface reads the other two keys and
 *    ignores it, so this is an additive key rather than a contract change.
 *  - **`active_tasks`** is the queue depth — work accepted and not yet run.
 *    Workflow runs execute synchronously here, so there is no in-flight run
 *    to count; an empty queue is a measured zero rather than a hardcoded one.
 *
 * **`uptime`** is `null` until a sample exists. Both surfaces render that as an
 * em dash; a `0` beside five real tiles would read as an outage.
 *
 * **`restriction_count`** is always `0`: this runtime has no rate-limit or
 * token-budget restriction model, so the banner it drives never shows. That is
 * a true zero, not an unimplemented one — if restrictions are added later, this
 * is where they get counted.
 *
 * `$tenantId` is required, not defaulted (§152 §3 D5): every figure on this
 * surface is one estate's, and there is no operator variant of it.
 */
final class CommandCentreReport
{
    /** How many feed entries the overview carries. */
    public const EVENT_LIMIT = 8;

    public function __construct(
        private readonly HealthSampler $health,
        private readonly ActivityRecorder $activity,
    ) {
    }

    /**
     * The overview payload for one estate.
     *
     * `agent_statuses` is returned as a PHP array keyed by assistant id. The
     * JSON caller casts it to an object, because the console iterates
     * `Object.values()` and a PHP array of integer keys would encode as a JSON
     * list and render empty.
     *
     * @return array{
     *     kpis: array<string, int|float|null>,
     *     restriction_count: int,
     *     agent_statuses: array<int, array{status: string, last_active: int, title: string}>,
     *     recent_events: list<array<string, mixed>>
     * }
     */
    public function report(string $tenantId): array
    {
        // `Assistant` carries a `tenant` global scope, so an explicit `where` on
        // top of it would AND against whatever `TenantContext` happens to hold:
        // `report('acme')` called from a request pinned to `globex` would return
        // an empty estate rather than acme's. The tenant is an argument here, so
        // it is the only scope — the ambient one is dropped rather than trusted.
        $assistants = Assistant::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->get();

        $statuses = array();
        $online = 0;

        foreach ($assistants as $assistant) {
            $isOnline = 'publish' === (string) $assistant->status;

            if ($isOnline) {
                ++$online;
            }

            $statuses[(int) $assistant->id] = array(
                'status' => $isOnline ? 'online' : 'idle',
                'last_active' => (int) (strtotime((string) $assistant->updated_at) ?: 0),
                'title' => (string) $assistant->title,
            );
        }

        return array(
            'kpis' => array(
                'total_agents' => count($assistants),
                'agents_online' => $online,
                'active_tasks' => $this->queueDepth(),
                'pending_approvals' => $this->pendingApprovals($tenantId),
                'tokens_today' => $this->tokensToday($tenantId),
                'uptime' => $this->health->uptimePercent(24),
            ),
            'restriction_count' => 0,
            'agent_statuses' => $statuses,
            'recent_events' => $this->activity->recent(self::EVENT_LIMIT, $tenantId),
        );
    }

    /**
     * Queued jobs — accepted, not yet run.
     *
     * Platform-wide by nature: the queue is one worker pool shared by every
     * estate, so a per-estate depth would be an invented number. Counted from
     * the jobs table directly rather than through a queue facade so that a
     * per-estate queue connection cannot change the meaning silently.
     */
    private function queueDepth(): int
    {
        return Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
    }

    /**
     * Approvals awaiting a decision, for this estate.
     *
     * Scoped in the query rather than relying on the model's global scope: this
     * class is not a Filament resource and must not come to depend on panel
     * context being live.
     */
    private function pendingApprovals(string $tenantId): int
    {
        if (! Schema::hasTable('nvoos_approvals')) {
            return 0;
        }

        return (int) DB::table('nvoos_approvals')
            ->where('tenant_id', $tenantId)
            ->where('status', 'pending')
            ->count();
    }

    /**
     * Tokens consumed since local midnight, for this estate.
     *
     * Scoped like every other number on the command centre: an estate admin
     * must not see another estate's consumption (Proposal 152 §2).
     */
    private function tokensToday(string $tenantId): int
    {
        if (! Schema::hasTable('nvoos_token_usage')) {
            return 0;
        }

        return (int) DB::table('nvoos_token_usage')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('total_tokens');
    }
}
