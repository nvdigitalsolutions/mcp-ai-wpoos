<?php

namespace App\Filament\Admin\Resources\Assistants;

use App\Filament\Admin\Resources\Assistants\Pages\ManageAssistants;
use App\Models\Assistant;
use App\Services\AssistantWriter;
use App\Support\EstateScope;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Nvoos\Core\Domain\Contract\ProfessionRepositoryInterface;

/**
 * Assistants — the second tenant-scoped `/admin` resource (Proposal 149).
 *
 * Carries its own cross-tenant 404 test, as Wave 2 requires: see
 * `AdminAssistantsTest::test_an_assistant_belonging_to_another_estate_is_not_resolvable()`.
 *
 * Writes go through `AssistantWriter`, not `Model::save()`. The writer is the
 * extraction of the five invariants `AssistantController` already enforced —
 * estate assignment, per-estate slug uniqueness, profession slugs into `meta`,
 * the 14-key `advanced` whitelist, and `is_default` exclusivity. A form that
 * wrote the model directly would be a second implementation of all five, and
 * the REST surface and the panel would disagree the first time either changed.
 *
 * `tenant_id` is absent from the form as well as from the payload schema, so a
 * hand-crafted request cannot pick an estate.
 *
 * Scope note: the fourteen `advanced` keys (fallback chain, vector store,
 * tool shortcuts, …) are **not** on this form yet. They survive a panel edit —
 * the writer only touches `meta` when `advanced` is present, and a form edit
 * never sends it — and remain editable through the console and the REST
 * surface. Rendering `list`/`map` shapes in a Filament form is its own slice;
 * omitting them here keeps this one reviewable.
 */
class AssistantResource extends Resource
{
    protected static ?string $model = Assistant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $slug = 'assistants';

    protected static ?string $modelLabel = 'assistant';

    protected static ?string $pluralModelLabel = 'assistants';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->maxLength(255)
                    ->helperText('Optional. Generated from the title and kept unique within the estate.'),
                Select::make('status')
                    ->options(array(
                        'draft' => 'Draft',
                        'publish' => 'Published',
                        'private' => 'Private',
                    ))
                    ->required()
                    ->default('draft'),
                Textarea::make('description')
                    ->rows(2)
                    ->maxLength(2000),
                Textarea::make('system_prompt')
                    ->rows(6),
                TextInput::make('provider')
                    ->maxLength(40),
                TextInput::make('model')
                    ->maxLength(120),
                TextInput::make('temperature')
                    ->numeric()
                    ->step(0.1)
                    ->minValue(0)
                    ->maxValue(2),
                TextInput::make('max_tokens')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(200000),
                TagsInput::make('tools')
                    ->helperText('Tool slugs this assistant may call.'),
                Select::make('primary_role_slugs')
                    ->label('Professions')
                    ->multiple()
                    // The catalogue is the option set, so the form cannot invent
                    // a profession slug the REST validator would reject. The
                    // three-item cap mirrors `AssistantController::validated()`.
                    ->options(static fn (): array => self::professionOptions())
                    ->rules(array('array', 'max:3'))
                    ->helperText('Up to three. Stored in the assistant metadata.'),
                Toggle::make('is_default')
                    ->helperText('Setting this clears the flag on the estate\'s other assistants.'),
            ]);
    }

    /**
     * Estate scope, applied explicitly as well as by the panel's tenancy.
     *
     * Filament scopes a tenant-scoped resource through the ownership
     * relationship, so `parent::getEloquentQuery()` is already safe. Repeating
     * the rule here is deliberate: if a later change disables panel tenancy,
     * this resource would silently become cross-tenant, and a test would have
     * to be the thing that notices. Failing closed on an unresolved estate
     * makes the failure mode an empty list instead of the whole platform.
     *
     * @return Builder<Assistant>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $tenantId = EstateScope::currentId();

        if (null === $tenantId) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('assistants.tenant_id', $tenantId);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(static fn (string $state): string => match ($state) {
                        'publish' => 'success',
                        'private' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('provider')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('model')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('title')
            ->filters(array())
            ->recordActions([
                EditAction::make()
                    ->using(static fn (Assistant $record, array $data): Assistant => self::updateThroughWriter($record, $data)),
                DeleteAction::make()
                    ->using(static fn (Assistant $record): bool => self::deleteThroughWriter($record)),
            ])
            // A bulk delete would bypass the per-record estate check the
            // policy performs, so it is not offered.
            ->toolbarActions(array());
    }

    public static function getPages(): array
    {
        return array(
            'index' => ManageAssistants::route('/'),
        );
    }

    /**
     * Create through the shared writer.
     *
     * @param array<string, mixed> $data
     */
    public static function createThroughWriter(array $data): Assistant
    {
        return app(AssistantWriter::class)->create($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function updateThroughWriter(Assistant $record, array $data): Assistant
    {
        return app(AssistantWriter::class)->update($record, $data);
    }

    public static function deleteThroughWriter(Assistant $record): bool
    {
        app(AssistantWriter::class)->delete($record);

        return true;
    }

    /**
     * The profession catalogue as `slug => name`.
     *
     * `getAll()` is the same source the REST validator uses, so the two cannot
     * offer different professions. The key falls back to the array key because
     * the contract documents the entry shape but not the keying.
     *
     * @return array<string, string>
     */
    private static function professionOptions(): array
    {
        $options = array();

        foreach (app(ProfessionRepositoryInterface::class)->getAll() as $key => $profession) {
            $slug = (string) ($profession['slug'] ?? $key);

            if ('' === $slug) {
                continue;
            }

            $options[$slug] = (string) ($profession['name'] ?? $slug);
        }

        ksort($options);

        return $options;
    }
}
