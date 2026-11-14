<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Support\ActivityRecorder;
use App\Support\HealthSampler;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The command-centre overview (090-i).
 *
 *   GET /api/v1/pro/command-center/overview
 *
 * Six KPIs, an agent status map and an activity feed, in the shape the
 * console's `CommandCenterClient` parses. Each number is sourced, and the
 * three projections are named rather than implied:
 *
 *  - **`agents_online`** counts `publish` assistants. This runtime has no
 *    presence channel, so "online" means "enabled" — the same reading
 *    `orchestration/stats` already uses for `active_agents`.
 *  - **`agent_statuses`** is derived from the same field: `publish` is
 *    `online`, anything else `idle`. `last_active` is the assistant's
 *    `updated_at`, not a heartbeat.
 *  - **`active_tasks`** is the queue depth — work accepted and not yet run.
 *    Workflow runs execute synchronously here, so there is no in-flight run
 *    to count; an empty queue is a measured zero rather than a hardcoded one.
 *
 * **`uptime`** is `null` until a sample exists. The client renders that as an
 * em dash; a `0` beside five real tiles would read as an outage.
 *
 * **`restriction_count`** is always `0`: this runtime has no rate-limit or
 * token-budget restriction model, so the banner it drives never shows. That is
 * a true zero, not an unimplemented one — if restrictions are added later, this
 * is where they get counted.
 */
class CommandCenterController
{
    public function __construct(
        private readonly HealthSampler $health,
        private readonly ActivityRecorder $activity,
    ) {
    }

    /**
     * The dashboard payload.
     */
    public function overview(): JsonResponse
    {
        $assistants = Assistant::query()
            ->where('tenant_id', TenantContext::current())
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
            );
        }

        return response()->json(array(
            'success' => true,
            'data' => array(
                'kpis' => array(
                    'total_agents' => count($assistants),
                    'agents_online' => $online,
                    'active_tasks' => $this->queueDepth(),
                    'pending_approvals' => $this->pendingApprovals(),
                    'tokens_today' => $this->tokensToday(),
                    'uptime' => $this->health->uptimePercent(24),
                ),
                'restriction_count' => 0,
                // An object, not a list: the console iterates Object.values().
                'agent_statuses' => (object) $statuses,
                'recent_events' => $this->activity->recent(8),
            ),
        ));
    }

    /**
     * Queued jobs — accepted, not yet run.
     */
    private function queueDepth(): int
    {
        return Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
    }

    /**
     * Approvals awaiting a decision.
     */
    private function pendingApprovals(): int
    {
        if (! Schema::hasTable('nvoos_approvals')) {
            return 0;
        }

        return (int) DB::table('nvoos_approvals')->where('status', 'pending')->count();
    }

    /**
     * Tokens consumed since local midnight.
     */
    private function tokensToday(): int
    {
        if (! Schema::hasTable('nvoos_token_usage')) {
            return 0;
        }

        return (int) DB::table('nvoos_token_usage')
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('total_tokens');
    }
}
