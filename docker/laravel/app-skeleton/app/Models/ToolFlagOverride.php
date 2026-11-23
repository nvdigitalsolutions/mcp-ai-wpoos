<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tool capability-flag additions — Proposal 149 §177.
 *
 * The row is one estate's answer to "this tool under-declares what it can do".
 * It only ever **adds** flags; there is no subtract path, in the table or in the
 * model, because a stored removal would let an estate clear the very flag the
 * read-only profile refuses on.
 *
 * `tool_slug` is not a foreign key for the reason `ToolQuota` records: tools live
 * in code, not in a table, so an override for a slug that no longer exists must
 * stay deletable.
 */
class ToolFlagOverride extends Model
{
    protected $table = 'nvoos_tool_flag_overrides';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tool_slug',
        'flags',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'flags' => 'array',
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

        static::creating(static function (ToolFlagOverride $override): void {
            if (null === $override->tenant_id || '' === (string) $override->tenant_id) {
                $override->tenant_id = TenantContext::current();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }
}
