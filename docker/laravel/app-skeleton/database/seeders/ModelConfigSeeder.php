<?php

namespace Database\Seeders;

use App\Support\Models\ModelConfigCatalogue;
use Illuminate\Database\Seeder;

/**
 * Seed the bundled text-model catalogue — Proposal 149 §68.
 *
 * A fresh install gets the same 251 rows `/ops/models` would get from "Sync
 * catalogue". Existing rows are preserved by the catalogue service.
 */
class ModelConfigSeeder extends Seeder
{
    public function run(ModelConfigCatalogue $catalogue): void
    {
        $catalogue->sync();
    }
}
