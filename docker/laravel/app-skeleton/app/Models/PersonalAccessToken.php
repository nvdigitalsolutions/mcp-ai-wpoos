<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-side projection of Sanctum's `personal_access_tokens` table — Proposal
 * 149 §114.
 *
 * **Not an authentication model.** Sanctum resolves its own class from
 * `config('sanctum.model')`; there is no `config/sanctum.php` in this
 * application, so that stays the vendor default and nothing in the request path
 * touches this class. It exists for one reason: Filament's `Table::query()`
 * accepts only an Eloquent builder, and the alternative — `->records()` —
 * silently turns `searchable()` and `sortable()` into no-ops, because both are
 * SQL-level.
 *
 * **No casts, and timestamps switched off, deliberately.** The readers in
 * {@see \App\Support\PersonalTokens} deal in the raw stored strings: abilities
 * are decoded by hand, and timestamps go through `iso()`, which parses the
 * database value and re-emits it as UTC. Inheriting Sanctum's casts would turn
 * `abilities` into an array (so `(string)` yields `"Array"` and the ability list
 * is silently lost) and `created_at` into a Carbon instance. Disabling
 * `$timestamps` is what keeps those columns out of Eloquent's automatic date
 * casting — the table does have `created_at` and `updated_at`, but this model
 * never writes, so nothing else depends on them.
 *
 * Writes go through the query builder in {@see \App\Support\PersonalTokens},
 * never through this model.
 */
final class PersonalAccessToken extends Model
{
    protected $table = 'personal_access_tokens';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    /**
     * The table carries `created_at` / `updated_at`, but this model is
     * read-only, and leaving timestamps on would make Eloquent cast
     * `created_at` to a Carbon instance on hydration. See the class docblock.
     */
    public $timestamps = false;
}
