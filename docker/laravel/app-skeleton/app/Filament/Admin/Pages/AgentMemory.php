<?php

namespace App\Filament\Admin\Pages;

use App\Models\Memory;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Agent memory — Proposal 149 §40.
 *
 * The memory tools (`store_agent_context`, `retrieve_agent_memory`,
 * `semantic_context_search`, `scope_memory`) and the console API own every
 * write. What an estate administrator could not do anywhere is *see* what an
 * assistant has remembered — and "why did it forget that" or "what does it know
 * about this customer" are the questions that decide whether memory is safe to
 * leave on.
 *
 * READ-ONLY, enforced by having no mutating action. A delete button would be a
 * second writer over rows the recall path filters by TTL, and the tools already
 * expose deletion (`scope_memory`, the console's `DELETE`).
 *
 * Expired rows stay visible on purpose: recall hides them, so an operator
 * looking at a memory that "disappeared" needs to see that it expired rather
 * than infer it.
 *
 * The estate scope comes from `Memory`'s global scope, so this page and the
 * console tools cannot disagree about which memories exist.
 */
class AgentMemory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'memory';

    protected static ?string $navigationLabel = 'Memory';

    protected static ?string $title = 'Agent memory';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    // Content surfaces sit together: memory is what the estate's assistants
    // retained, not how the runtime runs.
    protected static ?int $navigationSort = 62;

    protected string $view = 'filament.admin.agent-memory';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Memory::query())
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Title')
                    ->limit(48)
                    ->placeholder('(untitled)')
                    ->searchable(),
                TextColumn::make('agent_id')
                    ->label('Agent')
                    ->limit(24)
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('tier')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'core' => 'success',
                        'archival' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('preview')
                    ->label('Content')
                    ->limit(80)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('importance')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('wing')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                TextColumn::make('room')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('never')
                    ->color(static fn (Memory $record): ?string => $record->is_expired ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last changed')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('tier')
                    ->options([
                        'core' => 'Core',
                        'recall' => 'Recall',
                        'archival' => 'Archival',
                    ]),
            ])
            ->recordActions([
                Action::make('inspect')
                    ->label('Inspect')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->modalHeading(fn (Memory $record): string => (string) ($record->title ?: 'Memory '.$record->getKey()))
                    ->modalContent(fn (Memory $record) => view('filament.admin.agent-memory-record', [
                        'memory' => $record,
                    ]))
                    // Info-only: there is nothing to submit, and a modal offering
                    // "Submit" over a read-only record implies a write.
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('No memories yet')
            ->emptyStateDescription('Memory appears here once an assistant stores context. Expired entries stay listed; recall hides them.');
    }
}
