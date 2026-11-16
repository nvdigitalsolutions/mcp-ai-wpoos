<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An estate — the tenancy unit (Proposal 149 T-0.3).
 *
 * `id` is a string because every `tenant_id` column in the schema is already
 * `string(64)`. Do not "normalise" it to a bigint without a data migration.
 *
 * Two flags carry the Proposal 149 decisions:
 *
 *  - `is_operator` (Q-1) — operator status belongs to the ESTATE, not the user.
 *    `/ops` access is membership here plus `manage_options`, which is why a
 *    customer's estate administrator can never reach the vendor panel.
 *  - `licence_key` / `plan` (Q-2) — entitlement is per estate, so each estate
 *    resolves through the shared ProEntitlementInterface with its own licence.
 */
class Tenant extends Model
{
    /** Must equal `Assistant::DEFAULT_TENANT`; pinned by TenantModelTest. */
    public const DEFAULT_ID = 'default';

    /** The vendor's own estate. Never created by a production migration. */
    public const OPERATOR_ID = 'nvoos';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'name',
        'slug',
        'is_operator',
        'licence_key',
        'plan',
    ];

    /**
     * Never serialise the licence key.
     *
     * The `encrypted` cast protects the database, not the HTTP boundary: an
     * encrypted attribute is decrypted on read, so `toArray()`/`toJson()` would
     * emit the plaintext credential. Hiding it makes the class's documented
     * promise true for every serialization path, including a console endpoint
     * added later that returns a model by accident.
     *
     * @var list<string>
     */
    protected $hidden = [
        'licence_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_operator' => 'boolean',
            // A licence key is a credential. The encrypted cast keeps it out of
            // a database dump and out of any accidental `toArray()`. Nothing
            // queries by this column, so losing searchability costs nothing.
            'licence_key' => 'encrypted',
            'plan' => 'string',
        ];
    }

    /**
     * Members of this estate, with their membership role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Assistants owned by this estate.
     */
    public function assistants(): HasMany
    {
        return $this->hasMany(Assistant::class, 'tenant_id', 'id');
    }

    /**
     * Whether this is the vendor's own estate.
     */
    public function isOperator(): bool
    {
        return (bool) $this->is_operator;
    }

    /**
     * Limit a query to operator estates.
     */
    public function scopeOperators($query)
    {
        return $query->where('is_operator', true);
    }
}
