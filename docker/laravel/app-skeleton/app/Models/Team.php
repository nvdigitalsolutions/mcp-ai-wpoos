<?php

namespace App\Models;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Team extends Model
{
    use SoftDeletes;

    /**
     * The tenant is set by the service, never by a request body.
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    /**
     * The estate that owns this team (Proposal 149 T-0.7).
     *
     * The relationship NAME is the scoping contract: the admin panel declares
     * `ownershipRelationship: 'tenant'`, and Filament scopes a resource with
     * `whereBelongsTo($tenant, 'tenant')`.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }

    public function members(): HasMany
    {
        return $this->hasMany(TeamMember::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')
            ->withPivot(['role', 'profession_slug', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * The console's team record shape.
     *
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'description' => $this->description,
            'member_count' => (int) ($this->members_count ?? $this->members()->count()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
