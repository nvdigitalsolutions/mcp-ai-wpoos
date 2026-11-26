<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A one-shot magic-link sign-in token — Proposal 149 §103.
 *
 * `token_hash` is the SHA-256 of the token that was emailed. Nothing in this
 * model can reconstruct the raw value, which is the point: a database read must
 * not yield a working sign-in link.
 *
 * There is no `revoked_at` column because revoking is a delete (Proposal 149
 * §103: at most one outstanding link per user). `consumed_at` is different — it
 * records a *successful* use and survives as the audit trail.
 */
class MagicLoginToken extends Model
{
    protected $table = 'nvoos_magic_login_tokens';

    /**
     * Attributes that may be set in bulk.
     *
     * `token_hash` is included because only the service writes this model and it
     * builds the hash itself; there is no request payload path to it, which is
     * what makes mass assignment safe here where it is not on `User`.
     *
     * @var list<string>
     */
    protected $fillable = array(
        'user_id',
        'tenant_id',
        'token_hash',
        'expires_at',
        'consumed_at',
    );

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array(
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        );
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return null !== $this->consumed_at;
    }
}
