<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of the admin audit trail — Proposal 149 §25.
 *
 * The table is APPEND-ONLY: `AdminAuditLogger` only ever inserts, there is no
 * `updated_at` column, and this model therefore has `$timestamps = false` and
 * declares `$guarded = ['*']`. An audit trail that can be edited is not
 * evidence.
 *
 * Two kinds of row share the table, distinguished by `resource`:
 *
 *  - `resource` IS NULL     — a request row from `AuditAdminRequests`.
 *    `method`/`path`/`route`/`status`/`payload` are meaningful.
 *  - `resource` IS NOT NULL — a record row from `AdminAuditLogger::recordResource()`.
 *    `action`/`before`/`after` are meaningful and `method` mirrors the action's
 *    HTTP verb.
 *
 * A read-only log page needs a model rather than a query builder so the panel
 * and any future reader share one access rule — the same reason `WorkflowRun`
 * exists. There is no tenant global scope here because the operator panel is
 * deliberately cross-estate; the reader filters by estate explicitly instead.
 */
class AdminAudit extends Model
{
    protected $table = 'nvoos_admin_audit';

    /** Append-only: no `updated_at`, and nothing may be mass assigned. */
    public $timestamps = false;

    protected $guarded = array('*');

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array(
            'payload' => 'array',
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        );
    }
}
