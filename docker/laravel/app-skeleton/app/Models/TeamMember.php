<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMember extends Model
{
    /**
     * The database enum is the authority for this list; it is repeated here
     * only so PHP can validate before a write.
     */
    public const ROLES = ['owner', 'admin', 'manager', 'member', 'viewer'];

    protected $fillable = [
        'team_id',
        'user_id',
        'role',
        'profession_slug',
        'invited_by',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Empty profession strings are normalised to null so "no profession" has
     * exactly one representation.
     */
    public function setProfessionSlugAttribute(?string $value): void
    {
        $value = null === $value ? '' : trim($value);

        $this->attributes['profession_slug'] = '' === $value ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toRecord(): array
    {
        return [
            'id' => (int) $this->id,
            'team_id' => (int) $this->team_id,
            'user_id' => (int) $this->user_id,
            'role' => (string) $this->role,
            'profession_slug' => $this->profession_slug,
            'invited_by' => null === $this->invited_by ? null : (int) $this->invited_by,
            'joined_at' => $this->joined_at?->toIso8601String(),
        ];
    }
}
