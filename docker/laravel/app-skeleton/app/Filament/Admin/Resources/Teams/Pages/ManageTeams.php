<?php

namespace App\Filament\Admin\Resources\Teams\Pages;

use App\Filament\Admin\Resources\Teams\TeamResource;
use App\Models\Team;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

/**
 * The teams list, with the create and edit modals (Proposal 149, Wave 2).
 *
 * Creation is delegated to the resource's service-backed helper so tenant
 * assignment, slug uniqueness and the creator-as-owner rule stay in one place.
 * There is no dedicated create page: the form is three fields, and a modal keeps
 * the write path identical to every other Filament action.
 */
class ManageTeams extends ManageRecords
{
    protected static string $resource = TeamResource::class;

    protected function getHeaderActions(): array
    {
        return array(
            CreateAction::make()
                ->using(static fn (array $data): Team => TeamResource::createThroughService($data)),
        );
    }
}
