<?php

namespace App\Models;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An estate's profession overlay — Proposal 149 §170.
 *
 * The catalogue has two tiers. The base is code-owned reference data (the
 * snapshot `bin/sync-professions.php` writes). This model is the other tier:
 * the professions an estate authored, or overrode, through `save_profession`.
 *
 * The estate owns the row, so the global tenant scope applies — the same rule
 * `Assistant`, `Approval`, `Memory`, `Transcript`, `WorkflowRun` and `Budget`
 * follow (Proposal 153). Without it a slug lookup would return another estate's
 * override, because `slug` is unique per estate rather than globally.
 *
 * `tenant_id` is deliberately absent from `$fillable`: it is never supplied by a
 * request body. A `creating` hook sets it from the ambient context so every
 * writer — the store, a console command, a test factory — gets a row a reader
 * can actually see, rather than a silent NOT NULL failure or an orphan.
 */
class Profession extends Model
{
    protected $table = 'nvoos_professions';

    /**
     * The catalogue columns a writer may set.
     *
     * `slug` is here because an update may not rename it — the store passes the
     * normalised slug on create and omits it on update. `tenant_id` and `id`
     * are not.
     *
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'title',
        'description',
        'category',
        'role_description',
        'expertise',
        'warnings',
        'knowledge_base',
        'default_tools',
    ];

    /**
     * The three list fields are rendered, never filtered on, so JSON is honest
     * storage for them; everything else is a column because the catalogue is
     * grouped, filtered and counted by it.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expertise' => 'array',
        'warnings' => 'array',
        'default_tools' => 'array',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static function (Builder $query): void {
            $query->where(
                $query->getModel()->getTable().'.tenant_id',
                TenantContext::current()
            );
        });

        // Read at create time, not register time, so a console command that
        // pins an estate (TenantContext::runAs) writes into that estate.
        static::creating(static function (self $model): void {
            if (null === $model->tenant_id || '' === (string) $model->tenant_id) {
                $model->tenant_id = TenantContext::current();
            }
        });
    }

    /**
     * The catalogue record shape `ProfessionRepositoryInterface` speaks.
     *
     * One mapping, used by the overlay reader and by `save()`'s response, so the
     * record a caller gets back is the record the next read will produce.
     *
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        return array(
            'id' => (int) $this->getKey(),
            'slug' => (string) $this->slug,
            'name' => (string) $this->title,
            'description' => (string) ($this->description ?? ''),
            'category' => (string) ($this->category ?? 'other'),
            'role_description' => (string) ($this->role_description ?? ''),
            'expertise' => array_values((array) $this->expertise),
            'warnings' => array_values((array) $this->warnings),
            'knowledge_base' => (string) ($this->knowledge_base ?? ''),
            'default_tools' => array_values((array) $this->default_tools),
        );
    }
}
