<?php

namespace App\Filament\Operator\Resources\Models;

use App\Filament\Operator\Resources\Models\Pages\ManageModels;
use App\Models\ModelConfig;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Models\ModelConfigCatalogue;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The text-model catalogue — Proposal 149 §68.
 *
 * This is the operator's copy of the WordPress Model Manager table: context,
 * TPM/RPM, per-1M input and output prices, a fallback and lifecycle status.
 * Three decisions shape it:
 *
 *  - **Operator scope.** Rates and limits are provider reference data. An estate
 *    chooses a model in its settings; it does not rewrite what the model costs,
 *    the same reason §64/§66 kept endpoints and security infrastructure on
 *    `/ops`.
 *  - **The table is the working copy.** "Sync catalogue" inserts models that are
 *    absent and never overwrites an edit. A refresh that silently replaced an
 *    operator's price would be the settings-port bug in reverse.
 *  - **No media models.** Image/video/audio live in `media-model-catalog.json`
 *    with a capability schema and per-image/per-second prices. Forcing them into
 *    a per-1M token table would make half the columns meaningless.
 *
 * Individual create/edit/delete writes are audited by `ModelConfigObserver`.
 * The bulk sync bypasses Eloquent events, so it records one `synced` row here.
 */
class ModelConfigResource extends Resource
{
    protected static ?string $model = ModelConfig::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $slug = 'models';

    protected static ?string $modelLabel = 'model';

    protected static ?string $pluralModelLabel = 'models';

    protected static ?string $navigationLabel = 'Models';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('model_id')
                    ->required()
                    ->maxLength(191)
                    ->unique(ignoreRecord: true)
                    // The model id is the key settings and fallbacks resolve.
                    // Renaming it would silently break both, so it is fixed
                    // after creation rather than merely discouraged.
                    ->disabled(fn (?ModelConfig $record): bool => null !== $record)
                    ->dehydrated(fn (?ModelConfig $record): bool => null === $record)
                    ->helperText('The provider API identifier. Fixed after creation.'),
                TextInput::make('name')
                    ->required()
                    ->maxLength(191),
                Select::make('provider')
                    ->options(ModelConfigCatalogue::providerOptions())
                    ->required()
                    ->searchable(),
                Select::make('status')
                    ->options(ModelConfigCatalogue::statusOptions())
                    ->required()
                    ->default('active'),
                TextInput::make('context_window')
                    ->label('Context window')
                    ->numeric()
                    ->minValue(0)
                    ->step(1),
                TextInput::make('max_output_tokens')
                    ->numeric()
                    ->minValue(0)
                    ->step(1),
                TextInput::make('input_cost_per_million')
                    ->label('Input $ / 1M')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.000001)
                    ->prefix('$')
                    ->helperText('USD per 1M input tokens.'),
                TextInput::make('output_cost_per_million')
                    ->label('Output $ / 1M')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.000001)
                    ->prefix('$')
                    ->helperText('USD per 1M output tokens.'),
                TextInput::make('tpm_limit')
                    ->label('TPM')
                    ->numeric()
                    ->minValue(0)
                    ->step(1),
                TextInput::make('rpm_limit')
                    ->label('RPM')
                    ->numeric()
                    ->minValue(0)
                    ->step(1),
                TextInput::make('tpd_limit')
                    ->label('TPD')
                    ->numeric()
                    ->minValue(0)
                    ->step(1),
                TextInput::make('rpd_limit')
                    ->label('RPD')
                    ->numeric()
                    ->minValue(0)
                    ->step(1),
                Select::make('fallback_model_id')
                    ->label('Fallback model')
                    ->options(function (?ModelConfig $record): array {
                        $options = ModelConfigCatalogue::fallbackOptions($record?->fallback_model_id);

                        if (null !== $record) {
                            unset($options[$record->model_id]);
                        }

                        return $options;
                    })
                    ->searchable()
                    ->placeholder('None')
                    ->rules([
                        static fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            if (blank($value)) {
                                return;
                            }

                            if ((string) $value === (string) $get('model_id')) {
                                $fail('A model cannot fall back to itself.');

                                return;
                            }

                            if (! ModelConfig::query()->where('model_id', (string) $value)->exists()) {
                                $fail('The fallback model is not in the catalogue.');
                            }
                        },
                    ]),
                Toggle::make('supports_streaming')
                    ->default(true),
                Toggle::make('supports_function_calling'),
                Toggle::make('supports_vision'),
                DatePicker::make('sunset_date'),
                Textarea::make('notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('model_id')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->limit(30)
                    ->width('14rem'),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(30)
                    ->width('12rem')
                    ->toggleable(),
                TextColumn::make('provider')
                    ->badge()
                    ->sortable()
                    ->width('8rem')
                    ->formatStateUsing(static fn (string $state): string => ModelConfigCatalogue::providerLabel($state)),
                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->width('7rem')
                    ->formatStateUsing(static fn (string $state): string => ucfirst($state))
                    ->color(static fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'preview' => 'info',
                        'deprecated' => 'warning',
                        'retired' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('context_window')
                    ->label('Context')
                    ->numeric()
                    ->sortable()
                    ->width('7rem')
                    ->formatStateUsing(static fn (mixed $state): string => number_format((int) $state)),
                TextColumn::make('input_cost_per_million')
                    ->label('In $/1M')
                    ->sortable()
                    ->width('7rem')
                    ->formatStateUsing(static fn (mixed $state): string => '$'.number_format((float) $state, 2)),
                TextColumn::make('output_cost_per_million')
                    ->label('Out $/1M')
                    ->sortable()
                    ->width('7rem')
                    ->formatStateUsing(static fn (mixed $state): string => '$'.number_format((float) $state, 2)),
                TextColumn::make('tpm_limit')
                    ->label('TPM')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rpm_limit')
                    ->label('RPM')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('fallback_model_id')
                    ->label('Fallback')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('provider')
            ->filters([
                SelectFilter::make('provider')
                    ->options(ModelConfigCatalogue::providerOptions())
                    ->searchable(),
                SelectFilter::make('status')
                    ->options(ModelConfigCatalogue::statusOptions()),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton(),
            ])
            ->toolbarActions([
                CreateAction::make(),
                self::syncAction(),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No models yet')
            ->emptyStateDescription('Sync the bundled catalogue to add the text models, or create one manually.')
            ->emptyStateActions([self::syncAction()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageModels::route('/'),
        ];
    }

    /**
     * Add missing catalogue rows, and record one audit row for the bulk write.
     */
    private static function syncAction(): Action
    {
        return Action::make('sync')
            ->label('Sync catalogue')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalHeading('Sync the model catalogue')
            ->modalDescription('Adds models from the bundled catalogue. Existing rows, including edited prices, are not changed.')
            ->action(function (): void {
                try {
                    $result = app(ModelConfigCatalogue::class)->sync();
                } catch (\Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->danger()
                        ->title('Catalogue sync failed')
                        ->body($exception->getMessage())
                        ->send();

                    return;
                }

                app(AdminAuditLogger::class)->recordResource(
                    'models',
                    'catalogue',
                    'synced',
                    array(),
                    array('added' => $result['added'], 'existing' => $result['existing'])
                );

                Notification::make()
                    ->success()
                    ->title(sprintf('%d model(s) added', $result['added']))
                    ->body(sprintf('%d already present; existing rows were left alone.', $result['existing']))
                    ->send();
            });
    }
}
