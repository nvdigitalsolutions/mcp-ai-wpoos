<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\Approval;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The approval queue — Proposal 149 §41.
 *
 * An approval is the human gate in front of a destructive tool call: the console
 * queues one (`POST /api/v1/approvals`) and an authorised person resolves it
 * (`approve`/`deny`). Before this page an estate administrator could not see the
 * queue inside the panel at all, which is the wrong shape for a control whose
 * whole purpose is human oversight.
 *
 * READ-ONLY, deliberately. Approve/deny on the panel is the obvious next step and
 * is *not* shipped here: it would make this surface a second writer, and §25
 * showed what that costs — a panel action writes no request-level audit row
 * (Livewire traffic is excluded), so a decision made here would need its own
 * `recordResource()` call before the button is safe to add. Reading the queue has
 * no such obligation.
 *
 * The estate scope comes from `Approval`'s global scope.
 */
class Approvals extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'approvals';

    protected static ?string $navigationLabel = 'Approvals';

    protected static ?string $title = 'Approvals';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Automation;

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.admin.approvals';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Approval::query())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved' => 'success',
                        'denied' => 'danger',
                        'pending' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('tool')
                    ->label('Tool')
                    ->limit(40)
                    ->searchable(),
                TextColumn::make('requester_id')
                    ->label('Requested by')
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->label('Why')
                    ->limit(60)
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Queued')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->dateTime()
                    ->placeholder('never')
                    ->color(static fn (Approval $record): ?string => $record->is_expired ? 'danger' : null)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'approved' => 'Approved',
                        'denied' => 'Denied',
                    ]),
            ])
            ->recordActions([
                Action::make('inspect')
                    ->label('Inspect')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->modalHeading(fn (Approval $record): string => 'Approval #'.(int) $record->getKey())
                    ->modalContent(fn (Approval $record) => view('filament.admin.approval-record', [
                        'approval' => $record,
                    ]))
                    // Info-only: there is nothing to submit, and a modal offering
                    // "Submit" over a read-only record implies a write.
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('No approvals')
            ->emptyStateDescription('A gated tool call queues here until someone approves or denies it.');
    }
}
