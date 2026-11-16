<?php

namespace App\Filament\Operator\Resources\Estates;

use App\Filament\Operator\Resources\Estates\Pages\ManageEstates;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Nvoos\Core\Domain\Service\Entitlement\ProPlanCatalog;

/**
 * Estates and their licences — Wave 2's first `/ops` surface (Proposal 149).
 *
 * The class is named for the DOMAIN object, not the table: an estate is what an
 * operator reasons about, and `Tenant` is how it is stored. Mapping the two
 * here keeps the model free to be renamed later without a UI rename.
 *
 * Why this surface first: it is the one operator page with **no console
 * equivalent**. Assistants, teams, records and schedules already exist in the
 * Complete Console; porting them to Filament first would maintain one page
 * twice. Licences, by contrast, are the commercial spine (D-1/D-2) and were
 * reachable only through a database client.
 *
 * Deliberate restrictions, each enforced by `TenantPolicy` as well as the UI:
 *
 *  - **No create.** An estate is an authorization boundary; provisioning also
 *    seeds the first membership, which a form cannot do safely.
 *  - **No delete.** Deleting an estate would orphan every assistant and team
 *    scoped to its `tenant_id`.
 *  - **`is_operator` is not editable.** Granting vendor-panel access is a
 *    deploy-time decision, not a checkbox — a mistyped form must not be able to
 *    hand a customer `/ops`.
 *  - **The slug is not editable.** It is the tenant path key the customer panel
 *    resolves (`/admin/{slug}`); renaming it moves every member's URL.
 */
class EstateResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'estates';

    protected static ?string $modelLabel = 'estate';

    protected static ?string $pluralModelLabel = 'estates';

    protected static ?string $navigationLabel = 'Estates';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->disabled()
                    ->dehydrated(false)
                    ->maxLength(64)
                    ->helperText('The tenant path key. Fixed at provisioning.'),
                Select::make('plan')
                    ->options(static function (): array {
                        $plans = ProPlanCatalog::plans();

                        return array_combine($plans, $plans) ?: array();
                    })
                    ->placeholder('No plan recorded')
                    ->helperText('Recorded for reference. Entitlement follows the licence key, not this label.'),
                TextInput::make('licence_key')
                    ->password()
                    ->autocomplete('new-password')
                    // Write-only. A password input hides the characters, not
                    // the value: it is still in the HTML and in Livewire's
                    // snapshot. Blank the field after hydration so the stored
                    // credential never leaves the server, and dehydrate only
                    // when something was actually typed.
                    ->afterStateHydrated(static function (TextInput $component): void {
                        $component->state(null);
                    })
                    ->dehydrated(static fn (?string $state): bool => filled($state))
                    ->maxLength(191)
                    ->helperText('Write-only. Leave blank to keep the stored key.'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_operator')
                    ->label('Operator')
                    ->boolean(),
                TextColumn::make('plan')
                    ->badge()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('licence')
                    // Computed, never bound to `licence_key`: a bound column
                    // would put the decrypted credential in Livewire's
                    // snapshot even though the visible text is a status.
                    ->badge()
                    ->getStateUsing(static fn (Tenant $record): string => filled($record->licence_key) ? 'Set' : 'Not set')
                    ->color(static fn (string $state): string => 'Set' === $state ? 'success' : 'gray'),
                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Members'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters(array())
            ->recordActions([
                EditAction::make(),
            ])
            // No bulk actions: the only one available would be delete, which
            // `TenantPolicy` denies.
            ->toolbarActions(array());
    }

    public static function getPages(): array
    {
        return array(
            'index' => ManageEstates::route('/'),
        );
    }
}
