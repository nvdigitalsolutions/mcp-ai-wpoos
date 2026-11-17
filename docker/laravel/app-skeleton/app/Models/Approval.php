<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A queued tool approval — Proposal 149 §41.
 *
 * Read-only by construction: `ApprovalsController` is the writer (the console
 * queues and resolves), and the panel only inspects. The model exists because a
 * Filament table needs an Eloquent source, and it carries the estate scope so the
 * rule lives in one place rather than in each query.
 */
class Approval extends Model
{
    protected $table = 'nvoos_approvals';

    /** The controller writes both timestamps. */
    public $timestamps = false;

    /** Nothing on this model is mass-assignable. */
    protected $guarded = ['*'];

    /**
     * Scope every query to the current estate.
     *
     * An approval is the human gate in front of a destructive tool call. Without
     * this, the panel would list every estate's queue — and the queue names the
     * tool, its arguments and who asked.
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

    /**
     * `arguments` is a JSON column; a half-written row must not 500 the page.
     *
     * @return array<string, mixed>
     */
    public function getArgumentsAttribute(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether a still-pending approval is past its window.
     *
     * A resolved row is not "expired" — the timestamp only matters while the
     * decision is still owed.
     */
    public function getIsExpiredAttribute(): bool
    {
        if ('pending' !== $this->status || null === $this->expires_at) {
            return false;
        }

        $expires = strtotime((string) $this->expires_at);

        return false !== $expires && $expires <= time();
    }
}
