<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved table view — filters, sort and column visibility for one resource,
 * scoped to one user on one estate (Proposal 149 T-0.9).
 *
 * Mutable by design (unlike the audit table): a user renames and deletes their
 * own views. `state` is free-form JSON because the shape belongs to the table
 * it configures, not to this model.
 */
class AdminSavedView extends Model
{
    protected $table = 'nvoos_admin_views';

    /**
     * @var list<string>
     */
    protected $fillable = array(
        'tenant_id',
        'user_id',
        'resource',
        'name',
        'state',
    );

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array(
            'state' => 'array',
        );
    }

    /**
     * The user who saved the view.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
