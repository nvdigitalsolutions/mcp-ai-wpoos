<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Support\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Laravel\Adapter\JobStatusStore;

/**
 * Consolidated orchestration overview (`/api/v1/orchestration/stats`).
 *
 * The console's Orchestration page reads exactly these fields
 * (`spa-v2/src/features/orchestration-stats/OrchestrationStatsPage.tsx`):
 * the agent table, the profession counters, the role distribution and four
 * subsystem cards. On this runtime an "agent" **is** an assistant record, so
 * the inventory is real rather than modelled twice.
 *
 * Fields with no Laravel counterpart are reported as the honest empty value
 * rather than invented:
 *
 * - `recent_workflows` lists the last five runs from `nvoos_workflow_runs`
 *   (090-h H3); `tasks_total`/`tasks_done` are the run's node counts.
 * - `patterns` is `{}` and `with_task_patterns` counts zero — the profession
 *   catalogue carries no task patterns.
 * - `last_used` and `workflow_pattern` are `''` — neither has a column.
 * - `seeded_professions` equals `total_professions`: the catalogue *is* the
 *   seeded set here, since there is no CPT to seed into.
 *
 * `is_pro_active` reports whether any gated Pro surface is entitled, which is
 * the closest truthful reading of "Pro is active" when the Pro code is always
 * deployed.
 *
 * An "active" agent is a `publish` assistant — the console uses WordPress's
 * post-status vocabulary here.
 */
class OrchestrationStatsController
{
    public function __invoke(
        ToolRegistry $tools,
        ProfessionRepositoryInterface $professions,
        ProEntitlementInterface $entitlement,
        JobStatusStore $jobs,
        Schedule $schedule,
    ): JsonResponse {
        $assistants = Assistant::query()
            ->where('tenant_id', TenantContext::current())
            ->orderBy('title')
            ->get();

        $agents = array();
        $active = 0;

        foreach ($assistants as $assistant) {
            $toolList = is_array($assistant->tools) ? array_values($assistant->tools) : array();

            			if ( 'publish' === (string) $assistant->status ) {
            				++$active;
            			}

            $agents[] = array(
                'id' => (int) $assistant->id,
                'title' => (string) $assistant->title,
                'slug' => (string) $assistant->slug,
                'status' => (string) $assistant->status,
                'description' => (string) ( $assistant->description ?? '' ),
                'provider' => (string) ( $assistant->provider ?? '' ),
                'model' => (string) ( $assistant->model ?? '' ),
                'tools' => $toolList,
                'tool_count' => count($toolList),
                'primary_roles' => $assistant->primaryRoleSlugs(),
                'last_used' => '',
                'workflow_pattern' => '',
            );
        }

        $catalogue = $professions->getAll();
        $total = count($catalogue);
        $withPatterns = 0;

        foreach ($catalogue as $profession ) {
            if ( is_array($profession) && ! empty($profession['task_patterns']) ) {
                ++$withPatterns;
            }
        }

        $roles = DB::table('users')
            ->select('role', DB::raw('count(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role')
            ->map(static fn ($count): int => (int) $count)
            ->all();

        $queue = $jobs->counts();

        $recentWorkflows = DB::table('nvoos_workflow_runs')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(static function (object $run): array {
                $nodes = json_decode((string) $run->events, true);
                $nodes = is_array($nodes) ? $nodes : array();

                $done = count(
                    array_filter(
                        $nodes,
                        static fn ($node): bool => is_array($node) && 'completed' === ($node['status'] ?? '')
                    )
                );

                return array(
                    'workflow_id' => (string) $run->workflow_id,
                    'state' => (string) $run->status,
                    'tasks_total' => count($nodes),
                    'tasks_done' => $done,
                    'created_at' => (string) $run->created_at,
                    'updated_at' => (string) $run->updated_at,
                    'started_at' => (string) $run->started_at,
                    'completed_at' => (string) ( $run->finished_at ?? '' ),
                    'team_id' => 0,
                    'task_type' => 'run #' . (string) $run->id,
                );
            })
            ->all();

        return response()->json(array(
            'success' => true,
            'data' => array(
                'multi_agent' => array(
                    'installed' => true,
                    'total_agents' => count($agents),
                    'active_agents' => $active,
                    'total_tools' => $tools->count(),
                    'is_pro_active' => $entitlement->allows('schedules')
                        || $entitlement->allows('records')
                        || $entitlement->allows('toolkits'),
                    'patterns' => (object) array(),
                    'agents' => $agents,
                ),
                'orchestration' => array(
                    'total_professions' => $total,
                    'seeded_professions' => $total,
                    'with_task_patterns' => $withPatterns,
                    'seeder_version' => $professions->catalogueVersion(),
                    'roles' => (object) $roles,
                    'system_status' => array(
                        'cron' => array( 'total' => count($schedule->events()) ),
                        'async' => array( 'status' => array() === $queue ? 'idle' : 'active' ),
                        'sse' => array( 'available' => true ),
                        'health' => array( 'status' => 'ok' ),
                    ),
                ),
                'recent_workflows' => $recentWorkflows,
            ),
        ));
    }
}
