<?php

namespace App\Support;

use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one reader for `nvoos_workflow_runs` — Proposal 149 §18.
 *
 * Both presentations read runs: the console's `/orchestration/runs` endpoints
 * and the `/admin` read-only list. Two readers would drift on two things that
 * matter — which columns are exposed, and which estate's rows are visible — so
 * the query, the summary shape and the JSON decoding live here once (149 R1 in
 * its read form).
 *
 * Isolation lives on the model: `WorkflowRun` applies a `tenant` global scope, so
 * every query built here is estate-scoped without restating the rule. The scope
 * resolves `TenantContext::current()`, which is the estate Filament pinned on
 * `/admin`, and the `default` fallback on the console API until §16.5's
 * tenant-selection rule exists. Scoping to the fallback is the conservative
 * direction: a multi-estate deployment under-reports on the console instead of
 * over-reporting, and a single-estate deployment is unaffected.
 *
 * `nvoos_workflow_runs` is append-only (090-h H3). This class therefore exposes
 * no write method at all: a run is created by {@see WorkflowRunner} and never
 * edited by a surface.
 */
final class WorkflowRuns
{
    /** The console's page size, kept here so both presentations agree. */
    public const PAGE_SIZE = 50;

    /**
     * Runs visible to the current estate, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function summaries(?int $workflowId = null, int $limit = self::PAGE_SIZE): array
    {
        $query = self::query()->orderByDesc('id')->limit(max(1, $limit));

        if (null !== $workflowId) {
            $query->where('workflow_id', $workflowId);
        }

        return $query->get()
            ->map(static fn (WorkflowRun $row): array => self::summary($row))
            ->all();
    }

    /**
     * One run, or null when it belongs to another estate.
     *
     * Null rather than an exception so both callers can answer 404 — R4: a
     * cross-estate record must not be distinguishable from a missing one.
     */
    public static function find(int $id): ?WorkflowRun
    {
        return self::query()->find($id);
    }

    /**
     * The base query. Exposed so the `/admin` table uses the same scope as the
     * console rather than rebuilding it.
     */
    public static function query(): Builder
    {
        return WorkflowRun::query();
    }

    /**
     * The list-row shape. Deliberately excludes `input`/`output`/`events`:
     * a summary is what a table renders, and the per-node payload can be large.
     *
     * @return array<string, mixed>
     */
    public static function summary(WorkflowRun $row): array
    {
        return [
            'id' => (int) $row->id,
            'workflow_id' => (int) $row->workflow_id,
            'status' => (string) $row->status,
            'source' => (string) ($row->source ?? 'manual'),
            'trigger_id' => null === ($row->trigger_id ?? null) ? null : (int) $row->trigger_id,
            'tool_calls' => (int) $row->tool_calls,
            'started_at' => $row->started_at,
            'finished_at' => $row->finished_at,
            'duration' => null === $row->duration ? null : (float) $row->duration,
        ];
    }

    /**
     * Workflow titles for a set of ids, in one query.
     *
     * The run table stores `workflow_id`, not the title, because a workflow can
     * be renamed after it ran. The batch lookup keeps the list one query rather
     * than one per row.
     *
     * @param array<int, int|string> $workflowIds
     * @return array<int, string>
     */
    public static function titles(array $workflowIds): array
    {
        $workflowIds = array_values(array_unique(array_map('intval', $workflowIds)));

        if ([] === $workflowIds) {
            return [];
        }

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
        $model = (string) config('oos.content_model', \Nvoos\Laravel\Models\NvoosPost::class);

        return $model::query()
            ->whereIn('id', $workflowIds)
            ->pluck('title', 'id')
            ->map(static fn ($title): string => (string) $title)
            ->all();
    }

    /**
     * Decode a JSON column defensively.
     *
     * A run written by an older build, or by a half-finished transaction, must
     * not turn the inspector into a 500.
     */
    public static function decode(?string $raw): mixed
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        return json_decode($raw, true);
    }
}
