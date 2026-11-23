<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One tool's consumption for one user on one UTC day — Proposal 149 §176.
 *
 * A counter row, not an append-only log. The quota is a daily ceiling, and a
 * log would need a `SUM()` per check on the hot path; the upsert keeps the read
 * one indexed lookup. The trade is that the row cannot explain *how* the tokens
 * were spent — that is the `tool` harness trace record's job, and it is opt-in
 * per assistant for a reason.
 *
 * `user_id = 0` is the machine bucket. It is a real bucket rather than a
 * sentinel: a queued job or a machine principal executes tools with no
 * signed-in user, and a bucket of its own is what makes that traffic countable
 * instead of invisible.
 */
class ToolQuotaUsage extends Model
{
    protected $table = 'nvoos_tool_quota_usage';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'tool_slug',
        'day',
        'tokens',
        'requests',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'day' => 'date',
            'tokens' => 'integer',
            'requests' => 'integer',
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

        static::creating(static function (ToolQuotaUsage $usage): void {
            if (null === $usage->tenant_id || '' === (string) $usage->tenant_id) {
                $usage->tenant_id = TenantContext::current();
            }
        });
    }
}
