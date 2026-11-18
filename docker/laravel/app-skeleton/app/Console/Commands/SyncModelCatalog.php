<?php

namespace App\Console\Commands;

use App\Support\Models\ModelConfigCatalogue;
use Illuminate\Console\Command;

/**
 * Add the bundled text-model catalogue to the runtime — Proposal 149 §68.
 *
 * Existing rows are left alone: an operator's price or fallback edit is the
 * working copy, and a deploy that overwrote it would silently undo the review.
 * The same operation is offered from `/ops/models` as "Sync catalogue".
 */
class SyncModelCatalog extends Command
{
    protected $signature = 'nvoos:sync-models';

    protected $description = 'Add models from the bundled text-model catalogue; existing rows are not changed.';

    public function handle(ModelConfigCatalogue $catalogue): int
    {
        $result = $catalogue->sync();

        $this->info(sprintf(
            '%d model(s) added; %d already present.',
            $result['added'],
            $result['existing']
        ));

        return self::SUCCESS;
    }
}
