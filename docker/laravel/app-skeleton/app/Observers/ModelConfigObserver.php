<?php

namespace App\Observers;

use App\Observers\Concerns\RecordsAdminChanges;
use Illuminate\Database\Eloquent\Model;

/**
 * Record-level audit for model-catalogue edits — Proposal 149 §68.
 *
 * A bulk sync bypasses Eloquent events (`insertOrIgnore`), so the 251-row seed
 * produces one `synced` audit row from the Filament action rather than 251
 * `created` rows. A single create/edit/delete made through the panel still
 * records exactly what changed, through the shared trait.
 *
 * The audit row names `model_id`, not the surrogate key: `/ops/models` shows
 * the provider id, and "updated 17" would be unreadable after a reseed.
 */
final class ModelConfigObserver
{
    use RecordsAdminChanges;

    protected function auditResource(): string
    {
        return 'models';
    }

    protected function auditRecordId(Model $model): string
    {
        return (string) $model->getAttribute('model_id');
    }
}
