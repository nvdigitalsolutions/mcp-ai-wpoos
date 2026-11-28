<?php

namespace App\Filament\Admin\Resources\Assistants;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Filament\Admin\Resources\Assistants\Pages\ManageAssistants;
use App\Models\Assistant;
use App\Services\AssistantWriter;
use App\Support\EstateScope;
use App\Support\Models\ModelConfigCatalogue;
use App\Support\RemoteSites\RemoteSiteStore;
use BackedEnum;
use UnitEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
 * the 15-key `advanced` whitelist, and `is_default` exclusivity. A form that
 * wrote the model directly would be a second implementation of all five, and
 * the REST surface and the panel would disagree the first time either changed.
 *
 * `tenant_id` is absent from the form as well as from the payload schema, so a
 * hand-crafted request cannot pick an estate.
 *
 * `provider` and `model` are dependent selects (Proposal 149 §120). The model
 * list is the catalogue's servable set for the chosen provider, and both fields
 * keep a stored value the catalogue no longer lists as `<value> (current value)`
 * so an unrelated edit cannot silently drop it. The select is an **advisor, not
 * an allowlist**: §90.2 lets an unknown model id through at the chat boundary
 * (a deployment name or a fine-tune must keep working), and a form stricter
 * than the writer would turn that rule into data loss. `getOptionLabelUsing()`
 * is therefore deliberately lenient for a non-empty off-list value.
 *
 * The fifteen `advanced` keys are rendered in a collapsed **Advanced** section
 * with four tabs (Behaviour, Knowledge, Shortcuts, Action & access). The simple
 * lists use `TagsInput` or a single-field `Repeater`; the structured lists use a
 * nested `Repeater`; `tool_prebuilt_shortcuts` is stored as a tool-slug map and
 * is converted to and from Repeater rows by the create/edit actions (see
 * `prebuiltShortcutsToRows()` / `prebuiltShortcutsToMap()`).
 */
class AssistantResource extends Resource
{
    protected static ?string $model = Assistant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Workspace;

    protected static ?int $navigationSort = 20;

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
                Select::make('provider')
                    ->options(function (Get $get): array {
                        $options = ModelConfigCatalogue::providerOptions();
                        $current = trim((string) $get('provider'));

                        // A provider with no catalogue rows is preserved, or an
                        // existing record becomes unsaveable the first time it
                        // is edited (§120.3, the same rule as the settings form).
                        if ('' !== $current && ! array_key_exists($current, $options)) {
                            $options[$current] = $current.' (current value)';
                        }

                        return $options;
                    })
                    ->live()
                    ->afterStateUpdated(static fn (Set $set) => $set('model', null))
                    ->helperText('Leave empty to use the estate default provider.'),
                Select::make('model')
                    ->options(function (Get $get): array {
                        $provider = trim((string) $get('provider'));
                        $current = trim((string) $get('model'));

                        if ('' === $provider) {
                            // Keep a stored model visible even without a provider:
                            // hiding it would make the form look like it dropped
                            // the value it is about to preserve.
                            return '' === $current ? array() : array($current => $current.' (current value)');
                        }

                        $options = ModelConfigCatalogue::optionsForProvider($provider);

                        if ('' !== $current && ! array_key_exists($current, $options)) {
                            $options[$current] = $current.' (current value)';
                        }

                        return $options;
                    })
                    // Returning a label for any non-empty value is what keeps the
                    // select an advisor: Filament validates a selection against
                    // the option labels, and §90.2 requires an unknown id to pass.
                    ->getOptionLabelUsing(function (mixed $value, Get $get): ?string {
                        if (! is_string($value) || '' === $value) {
                            return null;
                        }

                        $provider = trim((string) $get('provider'));
                        $options = '' === $provider
                            ? array()
                            : ModelConfigCatalogue::optionsForProvider($provider);

                        return $options[$value] ?? $value.' (current value)';
                    })
                    ->searchable()
                    ->disabled(static fn (Get $get): bool => '' === trim((string) $get('provider')))
                    ->placeholder(static fn (Get $get): string => '' === trim((string) $get('provider'))
                        ? 'Pick a provider first'
                        : 'Select a model')
                    ->helperText('Only models the catalogue serves for this provider are listed. An existing off-list id is kept.'),
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
                Section::make('Advanced')
                    ->description('Optional runtime behaviour, base knowledge, shortcut overrides and access control. A blank value clears the stored setting.')
                    // The form grid is two columns; a half-width section clips the
                    // fourth tab behind a horizontal scrollbar.
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Tabs::make('Advanced settings')
                            ->tabs([
                                Tab::make('Behaviour')
                                    ->schema([
                                        TextInput::make('advanced.fallback_chain')
                                            ->label('Fallback chain')
                                            ->placeholder('openai, anthropic')
                                            ->formatStateUsing(static fn (mixed $state): string => is_array($state)
                                                ? implode(', ', array_map('strval', $state))
                                                : (string) $state)
                                            ->dehydrateStateUsing(static function (mixed $state): array {
                                                $items = is_array($state) ? $state : explode(',', (string) $state);
                                                $items = array_map(
                                                    static fn (mixed $item): string => trim((string) $item),
                                                    $items
                                                );

                                                return array_values(array_unique(array_filter(
                                                    $items,
                                                    static fn (string $item): bool => '' !== $item
                                                )));
                                            })
                                            ->helperText('Comma-separated provider slugs, tried in order. Leave empty for health-based failover.')
                                            ->columnSpanFull(),
                                        Select::make('advanced.adaptive_tool_cap')
                                            ->label('Adaptive tool cap')
                                            ->options(array(
                                                'on' => 'On',
                                                'off' => 'Off',
                                            ))
                                            ->placeholder('Use the server default')
                                            ->helperText('On forces the model-aware cap; Off disables it for this assistant; blank inherits the site setting.'),
                                        Toggle::make('advanced.prompt_caching')
                                            ->label('Enable prompt caching')
                                            ->helperText('Caches the stable part of the prompt with providers that support it.'),
                                        Toggle::make('advanced.disable_tool_shortcuts')
                                            ->label('Disable prompt shortcuts')
                                            ->helperText('Hides all tool prompt shortcuts for this assistant.'),
                                    ])
                                    ->columns(2),
                                Tab::make('Knowledge')
                                    ->schema([
                                        TagsInput::make('advanced.memory_files')
                                            ->label('Memory file IDs')
                                            ->placeholder('Add an attachment ID')
                                            ->nestedRecursiveRules(array('integer', 'min:1'))
                                            ->helperText('Media Library attachment IDs preloaded as reference material.'),
                                        TextInput::make('advanced.vector_store_id')
                                            ->label('Vector store ID')
                                            ->maxLength(191)
                                            ->placeholder('vs_…')
                                            ->helperText('Optional external vector store associated with this assistant.'),
                                        TextInput::make('advanced.corpus_name')
                                            ->label('Corpus name')
                                            ->maxLength(191)
                                            ->helperText('Gemini corpus name or ID.'),
                                        Repeater::make('advanced.preferred_datasets')
                                            ->label('Preferred datasets')
                                            ->defaultItems(0)
                                            ->maxItems(10)
                                            ->addActionLabel('Add dataset')
                                            ->reorderableWithButtons()
                                            ->collapsible()
                                            ->collapsed()
                                            ->itemLabel(static fn (array $state): ?string => self::advancedItemLabel($state, 'dataset'))
                                            ->schema([
                                                TextInput::make('dataset')
                                                    ->label('Dataset')
                                                    ->required()
                                                    ->maxLength(191)
                                                    ->placeholder('owner/dataset')
                                                    ->helperText('Dataset identifier. A row without one is ignored.'),
                                                TextInput::make('name')
                                                    ->label('Name')
                                                    ->maxLength(191),
                                                TextInput::make('category')
                                                    ->label('Category')
                                                    ->maxLength(64)
                                                    ->datalist(array('nlp', 'vision', 'audio', 'multimodal'))
                                                    ->helperText('Suggested: nlp, vision, audio, multimodal.'),
                                                TextInput::make('priority')
                                                    ->label('Priority')
                                                    ->maxLength(64)
                                                    ->datalist(array('critical', 'high', 'medium'))
                                                    ->helperText('Suggested: critical, high, medium.'),
                                            ])
                                            ->columns(2)
                                            ->columnSpanFull()
                                            ->helperText('Up to 10 datasets. Empty rows are dropped on save.'),
                                    ])
                                    ->columns(2),
                                Tab::make('Shortcuts')
                                    ->schema([
                                        Repeater::make('advanced.tool_shortcuts')
                                            ->label('Custom prompt shortcuts')
                                            ->defaultItems(0)
                                            ->addActionLabel('Add shortcut')
                                            ->reorderableWithButtons()
                                            ->collapsible()
                                            ->collapsed()
                                            ->itemLabel(static fn (array $state): ?string => self::advancedItemLabel($state, 'label'))
                                            ->schema([
                                                TextInput::make('label')
                                                    ->label('Label')
                                                    ->required()
                                                    ->maxLength(120),
                                                TextInput::make('tool')
                                                    ->label('Tool slug')
                                                    ->maxLength(120)
                                                    ->helperText('Optional. Must match a registered tool or the association is dropped.'),
                                                Textarea::make('payload')
                                                    ->label('Payload')
                                                    ->rows(2)
                                                    ->helperText('The text inserted into the prompt when the shortcut is used.'),
                                                TextInput::make('description')
                                                    ->label('Description')
                                                    ->maxLength(255),
                                                Toggle::make('hidden')
                                                    ->label('Hide from the shortcut menu'),
                                            ])
                                            ->columns(2)
                                            ->columnSpanFull()
                                            ->helperText('Shortcuts appear in the assistant chat menu. A blank label is rejected.'),
                                        Repeater::make('advanced.tool_prebuilt_shortcuts')
                                            ->label('Prebuilt shortcut overrides')
                                            ->defaultItems(0)
                                            ->addActionLabel('Add tool override')
                                            ->reorderableWithButtons()
                                            ->collapsible()
                                            ->collapsed()
                                            ->itemLabel(static fn (array $state): ?string => self::advancedItemLabel($state, 'tool'))
                                            ->schema([
                                                TextInput::make('tool')
                                                    ->label('Tool slug')
                                                    ->required()
                                                    ->maxLength(120)
                                                    ->rule('alpha_dash')
                                                    ->distinct()
                                                    ->helperText('The tool whose prebuilt shortcuts are replaced.'),
                                                Repeater::make('shortcuts')
                                                    ->label('Shortcuts')
                                                    ->defaultItems(0)
                                                    ->addActionLabel('Add shortcut')
                                                    ->reorderableWithButtons()
                                                    ->collapsible()
                                                    ->collapsed()
                                                    ->itemLabel(static fn (array $state): ?string => self::advancedItemLabel($state, 'label'))
                                                    ->schema([
                                                        TextInput::make('label')
                                                            ->label('Label')
                                                            ->required()
                                                            ->maxLength(120),
                                                        Textarea::make('payload')
                                                            ->label('Payload')
                                                            ->rows(2),
                                                        TextInput::make('description')
                                                            ->label('Description')
                                                            ->maxLength(255),
                                                        Toggle::make('hidden')
                                                            ->label('Hide from the shortcut menu'),
                                                    ])
                                                    ->columns(2)
                                                    ->columnSpanFull(),
                                            ])
                                            ->columns(2)
                                            ->columnSpanFull()
                                            ->helperText('Stored as a tool-slug map with mode "custom". Overrides with no shortcuts are dropped.'),
                                        Repeater::make('advanced.tool_role_rules')
                                            ->label('Tool role rules')
                                            ->defaultItems(0)
                                            ->addActionLabel('Add role rule')
                                            ->reorderable(false)
                                            ->collapsible()
                                            ->collapsed()
                                            ->itemLabel(static fn (array $state): ?string => self::advancedItemLabel($state, 'tool'))
                                            ->schema([
                                                TextInput::make('tool')
                                                    ->label('Tool slug')
                                                    ->required()
                                                    ->maxLength(120)
                                                    ->rule('alpha_dash'),
                                                TagsInput::make('roles')
                                                    ->label('Roles')
                                                    ->suggestions(array('subscriber', 'contributor', 'author', 'editor', 'administrator'))
                                                    ->helperText('Leave empty to allow any role.'),
                                                TagsInput::make('groups')
                                                    ->label('Group IDs')
                                                    ->nestedRecursiveRules(array('integer', 'min:1'))
                                                    ->dehydrateStateUsing(static fn (?array $state): array => array_values(array_map('intval', $state ?? array())))
                                                    ->helperText('Optional group IDs.'),
                                                TagsInput::make('flags')
                                                    ->label('Flags')
                                                    ->suggestions(array('allow_authenticated', 'allow_guests', 'allow_all_roles'))
                                                    ->helperText('Optional flags. A rule with no roles, groups or flags is ignored.'),
                                            ])
                                            ->columns(2)
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(1),
                                Tab::make('Action & access')
                                    ->schema([
                                        TextInput::make('advanced.external_action_id')
                                            ->label('Linked action ID')
                                            ->maxLength(191)
                                            ->helperText('Default workflow or assistant ID used by the external-action tool.'),
                                        Select::make('advanced.external_action_type')
                                            ->label('Linked action type')
                                            ->options(array(
                                                'workflow' => 'Workflow',
                                                'assistant' => 'Assistant',
                                            ))
                                            ->placeholder('Use the runtime choice'),
                                        TextInput::make('advanced.required_capability')
                                            ->label('Required capability')
                                            ->maxLength(191)
                                            ->placeholder('manage_options')
                                            ->helperText('Capability a user needs to chat with this assistant.'),
                                        Select::make('advanced.remote_connections')
                                            ->label('Remote site connections')
                                            ->multiple()
                                            ->options(function (Get $get): array {
                                                $options = array();

                                                foreach (app(RemoteSiteStore::class)->all() as $connection) {
                                                    $id = (string) $connection['id'];
                                                    $label = sprintf(
                                                        '%s (%s)',
                                                        (string) $connection['name'],
                                                        (string) $connection['connection_type'],
                                                    );

                                                    if (empty($connection['enabled'])) {
                                                        $label .= ' — disabled';
                                                    }

                                                    $options[$id] = $label;
                                                }

                                                // A stored id that no longer resolves — the
                                                // connection was deleted, or the record was
                                                // imported from another site — stays
                                                // selectable. Hiding it would make the first
                                                // save drop the value silently, the same
                                                // failure the provider select is written to
                                                // avoid.
                                                foreach ((array) $get('advanced.remote_connections') as $stored) {
                                                    if (! is_string($stored) || '' === $stored || array_key_exists($stored, $options)) {
                                                        continue;
                                                    }

                                                    $options[$stored] = $stored.' — current value';
                                                }

                                                return $options;
                                            })
                                            ->helperText('Which Remote Sites connections this assistant may use. Leave empty to allow every connection. Add "settings" to grant the site-wide credential fallback.')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),
                            ]),
                    ]),
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
                    ->mutateRecordDataUsing(static fn (array $data): array => self::prebuiltShortcutsToRows($data))
                    ->mutateDataUsing(static fn (array $data): array => self::prebuiltShortcutsToMap($data))
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

    /**
     * A Repeater item's label, or null to let Filament number it.
     *
     * @param array<string, mixed> $state
     */
    private static function advancedItemLabel(array $state, string $key): ?string
    {
        $value = trim((string) ($state[$key] ?? ''));

        return '' === $value ? null : $value;
    }

    /**
     * Convert the stored `tool_prebuilt_shortcuts` map into Repeater rows.
     *
     * The map's key is the tool slug; the Repeater needs it as an item field.
     * Used by `EditAction::mutateRecordDataUsing()` before the form hydrates.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function prebuiltShortcutsToRows(array $data): array
    {
        $map = $data['advanced']['tool_prebuilt_shortcuts'] ?? null;

        if (! is_array($map)) {
            return $data;
        }

        $rows = array();

        foreach ($map as $tool => $settings) {
            if (! is_string($tool) || ! is_array($settings)) {
                continue;
            }

            $shortcuts = is_array($settings['shortcuts'] ?? null) ? $settings['shortcuts'] : array();

            $rows[] = array(
                'tool' => $tool,
                'shortcuts' => array_values(array_filter($shortcuts, 'is_array')),
            );
        }

        $data['advanced']['tool_prebuilt_shortcuts'] = $rows;

        return $data;
    }

    /**
     * Convert Repeater rows back into the stored tool-slug map.
     *
     * Empty tool rows and overrides with no shortcuts are dropped, matching the
     * WordPress sanitizer. `mode` is always `custom`; `inherit`/`default` are
     * not represented in the editor and are refused by the server.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function prebuiltShortcutsToMap(array $data): array
    {
        $rows = $data['advanced']['tool_prebuilt_shortcuts'] ?? null;

        if (! is_array($rows)) {
            return $data;
        }

        $map = array();

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $tool = trim((string) ($row['tool'] ?? ''));

            if ('' === $tool) {
                continue;
            }

            $shortcuts = is_array($row['shortcuts'] ?? null) ? $row['shortcuts'] : array();
            $shortcuts = array_values(array_filter($shortcuts, 'is_array'));

            if (array() === $shortcuts) {
                continue;
            }

            $map[$tool] = array(
                'mode' => 'custom',
                'shortcuts' => $shortcuts,
            );
        }

        $data['advanced']['tool_prebuilt_shortcuts'] = $map;

        return $data;
    }

    /**
     * Global search — Proposal 149 §55 step 3.
     *
     * Filament's default searches only `$recordTitleAttribute`, so an assistant
     * could only be found by name. An operator looking for "the one that handles
     * refunds" has the description, not the title, and the description is the
     * field the catalogue and the blueprint installer both write.
     *
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return array('title', 'slug', 'description');
    }

    /**
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array('Status' => (string) $record->status);
    }

    /**
     * Open the record's edit action on the manage page.
     *
     * Filament's default resolves to `?tableAction=view`, but this resource has
     * no `view` page and no `view` action — only `edit` — so the default was a
     * dead link: measured in a browser, the result navigated to the index and
     * opened nothing. The action name here is the one `EditAction::make()`
     * registers on the table above, and `tableActionRecord` is the key
     * `InteractsWithActions` reads from the query string.
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
