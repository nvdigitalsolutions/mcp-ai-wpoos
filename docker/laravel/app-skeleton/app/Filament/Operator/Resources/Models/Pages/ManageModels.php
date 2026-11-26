<?php

namespace App\Filament\Operator\Resources\Models\Pages;

use App\Filament\Operator\Resources\Models\ModelConfigResource;
use Filament\Resources\Pages\ManageRecords;

/**
 * The model list, with create and edit modals — Proposal 149 §68.
 *
 * `ManageRecords` rather than a List/Create/Edit trio: 251 rows are managed in
 * one searchable table, and a dedicated page per row would add navigation
 * without adding an action. The bulk "Sync catalogue" action sits in the
 * header and the empty state.
 */
class ManageModels extends ManageRecords
{
    protected static string $resource = ModelConfigResource::class;
}
