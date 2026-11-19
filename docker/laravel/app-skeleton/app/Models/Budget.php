<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An estate budget — Proposal 154.
 *
 * A row is a limit for one estate over one window. It owns no spend total: the
 * figure is `SUM(cost)` over `nvoos_token_usage`, so the budget and the cost
 * pages read the same numbers by construction.
 *
 * The row is estate-owned and takes the global tenant scope, the same rule
 * `Approval`, `Memory`, `Transcript`, `WorkflowRun` and `Assistant` follow
 * (Proposal 153), so a reader cannot pick up another estate's limit by id.
 */
class Budget extends Model
{
    public const DAILY = 'daily';

    public const MONTHLY = 'monthly';

    /** The window vocabulary. Closed; the migration's unique key assumes it. */
    public const WINDOWS = [self::DAILY, self::MONTHLY];

    protected $table = 'nvoos_budgets';

    /**
     * `tenant_id` is deliberately absent: the estate is never supplied by a
     * request body, it is the resolved context.
     *
     * @var list<string>
     */
    protected $fillable = [
        'window',
        'limit_usd',
        'warn_at',
        'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limit_usd' => 'float',
            'warn_at' => 'float',
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

        // The tenant is a server-side fact, set the same way `AssistantWriter`
        // sets it, rather than a column a caller could choose.
        static::creating(static function (Budget $budget): void {
            if (null === $budget->tenant_id || '' === (string) $budget->tenant_id) {
                $budget->tenant_id = TenantContext::current();
            }
        });
    }
}
