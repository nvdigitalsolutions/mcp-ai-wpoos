<?php

namespace App\Filament\Operator\Resources\Estates\Pages;

use App\Filament\Operator\Resources\Estates\EstateResource;
use Filament\Resources\Pages\ManageRecords;

/**
 * The estates list, with the edit modal (Proposal 149, Wave 2).
 *
 * `ManageRecords` rather than a List/Create/Edit trio: there is no create page
 * (`TenantPolicy::create()` denies it) and a dedicated edit page would give the
 * licence form a URL of its own for no operator benefit. Editing in a modal
 * keeps the write path identical to every other Filament action.
 */
class ManageEstates extends ManageRecords
{
    protected static string $resource = EstateResource::class;
}
