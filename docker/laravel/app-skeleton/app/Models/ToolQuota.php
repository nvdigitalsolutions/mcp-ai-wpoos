<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-tool daily quota — Proposal 149 §176.
 *
 * The row is a limit, never a running total: consumption lives in
 * `nvoos_tool_quota_usage`, so the page's "used" column and the guard's refusal
 * are the same number by construction rather than two counters that drift.
 *
 * `daily_tokens = 0` reads as "no limit" and is the same convention
 * `BudgetStore::STATE_UNLIMITED` uses for a zero budget. Adding a row is
 * therefore an explicit act of metering; absence and zero both mean unmetered.
 *
 * `tool_slug` is deliberately not a foreign key: tools are registered in code
 * (`lib/core`), not in a table, so a slug that no longer exists must be
 * removable rather than un-deletable. The page validates against the live
 * registry when a row is written.
 */
class ToolQuota extends Model
{
    protected $table = 'nvoos_tool_quotas';

    /**
     * `tenant_id` is deliberately absent: the estate is supplied by the model's
     * creating hook from `TenantContext`, never by a request body.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tool_slug',
        'daily_tokens',
        'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_tokens' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static function (Builder $query): void {
            $query->where(
                $query->getModel()->getTable().'.tenant_id',
                TenantContext::current()
            );
        });

        static::creating(static function (ToolQuota $quota): void {
            if (null === $quota->tenant_id || '' === (string) $quota->tenant_id) {
                $quota->tenant_id = TenantContext::current();
            }
        });
    }

    /**
     * The estate that owns this limit, for the operator page's estate column.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }
}
