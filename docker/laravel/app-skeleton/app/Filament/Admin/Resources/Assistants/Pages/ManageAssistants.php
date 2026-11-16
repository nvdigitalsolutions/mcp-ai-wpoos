<?php

namespace App\Filament\Admin\Resources\Assistants\Pages;

use App\Filament\Admin\Resources\Assistants\AssistantResource;
use App\Models\Assistant;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

/**
 * The assistants list, with the create and edit modals (Proposal 149, Wave 2).
 *
 * Creation is delegated to the resource's writer-backed helper so estate
 * assignment, slug uniqueness and `is_default` exclusivity stay in one place —
 * the same arrangement `ManageTeams` uses for `TeamServiceInterface`.
 *
 * There is no dedicated create page: the form is long enough to want one
 * eventually, but a modal keeps the write path identical to every other
 * Filament action, which is what makes the audit trail uniform.
 */
class ManageAssistants extends ManageRecords
{
    protected static string $resource = AssistantResource::class;

    protected function getHeaderActions(): array
    {
        return array(
            CreateAction::make()
                ->using(static fn (array $data): Assistant => AssistantResource::createThroughWriter($data)),
        );
    }
}
