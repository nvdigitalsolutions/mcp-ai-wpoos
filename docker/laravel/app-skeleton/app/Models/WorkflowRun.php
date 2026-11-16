<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A workflow run — Proposal 149 §18.
 *
 * Read-only by construction: nothing in the app updates a run after
 * `WorkflowRunner` closes it, and no surface is allowed to. The model exists for
 * the `/admin` table, which needs an Eloquent source, and it carries the estate
 * scope so the rule lives in one place rather than in each query.
 *
 * No casts on purpose. `input`, `output` and `events` are JSON columns whose
 * consumers (`WorkflowRuns::decode()`, the console payload) expect the raw
 * string; casting them here would silently change the API shape. Dates stay
 * strings for the same reason — the console has always received
 * `Y-m-d H:i:s` and a Carbon instance would serialise differently.
 */
class WorkflowRun extends Model
{
    protected $table = 'nvoos_workflow_runs';

    /** The runner owns every column; the model never mass-assigns. */
    protected $guarded = [];

    /**
     * Scope every query to the current estate.
     *
     * A run records the estate that executed it. Without this, `/admin` would
     * list every estate's runs and the inspector would show another estate's
     * per-node payload — API3/BOLA rather than a display bug.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static function (Builder $query): void {
            $query->where(
                $query->getModel()->getTable().'.tenant_id',
                TenantContext::current()
            );
        });
    }
}
