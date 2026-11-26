<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An estate budget — Proposal 154.
 *
 * A row is a limit for one estate over one window, optionally narrowed to one
 * assistant. It owns no spend total: the figure is `SUM(cost)` over
 * `nvoos_token_usage`, so the budget and the cost pages read the same numbers by
 * construction.
 *
 * **Two scopes, one table.** `assistant_id = 0` is the whole estate; a non-zero
 * id narrows the limit to that assistant. The sentinel rather than `NULL` keeps
 * the unique key meaningful — SQL treats NULLs as distinct, so a nullable column
 * would let two estate-wide daily rows exist for the same estate.
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
     * `tenant_id` and `assistant_id` are deliberately absent: the estate and the
     * scope are supplied by the store, never by a request body.
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
            'assistant_id' => 'integer',
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

    /**
     * The estate that owns this limit.
     *
     * Same name and arguments as `Assistant::tenant()`: the operator page names
     * the estate when it writes a row, and the page has to resolve the label
     * through a scoped-off query, so the relation is the only join it needs.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }
}
