<?php

namespace App\Filament\Admin\Resources\Teams;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Filament\Admin\Resources\Teams\Pages\ManageTeams;
use App\Models\Team;
use App\Models\User;
use App\Support\CapabilityMap;
use BackedEnum;
use UnitEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Nvoos\Core\Domain\Contract\TeamServiceInterface;

/**
 * Teams — Wave 2's first tenant-scoped `/admin` resource (Proposal 149).
 *
 * This is the resource that carries the T-0.7 commitment: **no tenant-scoped
 * resource ships without a cross-tenant 404 test**. The test lives in
 * `AdminTeamsTest::test_a_team_belonging_to_another_estate_cannot_be_edited()`.
 *
 * Writes go through `TeamServiceInterface`, not `Model::create()`, because the
 * service already owns three invariants a form cannot be trusted with:
 *
 *  - `tenant_id` comes from `TenantContext` (the panel's resolved estate), never
 *    from the request;
 *  - the slug is derived and kept unique within the estate;
 *  - the creator becomes the team's owner, so a team can never exist without
 *    one.
 *
 * `tenant_id` is therefore absent from the form as well — not merely unlisted,
 * so a hand-crafted payload cannot set it.
 *
 * Reads apply two scopes: Filament's tenant scope (from the panel's ownership
 * relationship) and the REST surface's membership rule — a non-administrator
 * sees only their own teams, while a site administrator sees the estate. That
 * mirrors `TeamController::index()` versus `TeamAccess::isMember()`.
 */
class TeamResource extends Resource
{
    protected static ?string $model = Team::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Workspace;

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'teams';

    protected static ?string $modelLabel = 'team';

    protected static ?string $pluralModelLabel = 'teams';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->maxLength(255)
                    ->helperText('Optional. Generated from the name and kept unique within the estate.'),
                Textarea::make('description')
                    ->rows(3)
                    ->maxLength(2000),
            ]);
    }

    /**
     * Estate scope plus the REST contract's membership rule.
     *
     * @return Builder<Team>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $user = auth()->user();

        if ($user instanceof User && ! CapabilityMap::has($user, 'manage_options')) {
            $query->whereHas(
                'members',
                static fn (Builder $members): Builder => $members->where('user_id', $user->getAuthIdentifier())
            );
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('description')
                    ->limit(60)
                    ->toggleable(),
                TextColumn::make('members_count')
                    ->counts('members')
                    ->label('Members'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters(array())
            ->recordActions([
                EditAction::make()
                    ->using(static fn (Team $record, array $data): Team => self::updateThroughService($record, $data)),
                DeleteAction::make()
                    ->using(static fn (Team $record): bool => self::deleteThroughService($record)),
            ])
            // Deletion is per-record (the last-owner rule needs the record);
            // there is no bulk action that could honour it.
            ->toolbarActions(array());
    }

    public static function getPages(): array
    {
        return array(
            'index' => ManageTeams::route('/'),
        );
    }

    /**
     * Create through the shared service.
     *
     * A service error is surfaced as a form validation error rather than a 500:
     * the operator can fix it in place, and Filament renders the message on the
     * field it belongs to.
     *
     * @param array<string, mixed> $data
     */
    public static function createThroughService(array $data): Team
    {
        $result = app(TeamServiceInterface::class)->create($data + array(
            'owner_id' => (int) auth()->id(),
        ));

        if (! ($result['created'] ?? false)) {
            throw ValidationException::withMessages(array(
                'name' => $result['error'] ?? 'The team could not be created.',
            ));
        }

        return Team::query()->findOrFail((int) $result['team']['id']);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function updateThroughService(Team $record, array $data): Team
    {
        $result = app(TeamServiceInterface::class)->update((int) $record->getKey(), $data);

        if (! ($result['updated'] ?? false)) {
            throw ValidationException::withMessages(array(
                'name' => $result['error'] ?? 'The team could not be updated.',
            ));
        }

        return $record->refresh();
    }

    public static function deleteThroughService(Team $record): bool
    {
        $result = app(TeamServiceInterface::class)->delete((int) $record->getKey());

        if (! ($result['deleted'] ?? false)) {
            throw ValidationException::withMessages(array(
                'name' => $result['error'] ?? 'The team could not be deleted.',
            ));
        }

        return true;
    }

    /**
     * Global search — Proposal 149 §55 step 3.
     *
     * Default Filament searches only `$recordTitleAttribute` (the team name).
     * The description is what an operator remembers about a team they did not
     * create.
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return array('name', 'slug', 'description');
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array('Slug' => (string) $record->slug);
    }

    /**
     * Open the record's edit action on the manage page.
     *
     * Same reason as `AssistantResource`: the default `?tableAction=view` has no
     * action behind it on this resource, so the result was a dead link.
     */
    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        if (! static::canEdit($record)) {
            return static::getUrl();
        }

        return static::getUrl(parameters: array(
            'tableAction' => 'edit',
            'tableActionRecord' => $record->getKey(),
        ));
    }
}
