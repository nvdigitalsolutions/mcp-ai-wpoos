<?php

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Models\WorkflowRun;
use App\Policies\AdminAccessPolicy;
use App\Support\WorkflowRuns;
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
 * Workflow runs — Proposal 149 §14.1, the one orchestration surface that is
 * genuinely admin-shaped.
 *
 * Definitions stay console-owned by ruling (§13.1): rebuilding the workflow
 * editor in Filament would maintain one page twice. What the console serves
 * badly is *after the fact* — "this run failed, which node skipped, what did the
 * tool return" is post-incident work, and it is the kind of question an estate
 * administrator asks without wanting the builder.
 *
 * READ-ONLY, and the class enforces it by having no mutating action: no re-run,
 * no graph edit, no delete. A run is an append-only record of what happened
 * (090-h H3), and a surface that can replay one is a different feature with a
 * different blast radius.
 *
 * The estate scope comes from `WorkflowRun`'s global scope, so this page and the
 * console endpoints cannot disagree about which runs exist.
 */
class WorkflowRunLog extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'workflow-runs';

    protected static ?string $navigationLabel = 'Workflow runs';

    protected static ?string $title = 'Workflow runs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    // Below the resources, above settings: it is an operational log, not a
    // configuration page.
    protected static ?int $navigationSort = 80;

    protected string $view = 'filament.admin.workflow-runs';

    /**
     * Workflow titles, memoised per request.
     *
     * The run row stores `workflow_id`, not the title, because a workflow can be
     * renamed after it ran. Resolving one id per row is bounded by the page size
     * and keeps the query free of a join that would make `id` ambiguous with the
     * content table.
     *
     * @var array<int, string>
     */
    private array $titles = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(WorkflowRuns::query())
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('workflow_id')
                    ->label('Workflow')
                    ->getStateUsing(fn (WorkflowRun $record): string => $this->titleFor((int) $record->workflow_id))
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed', 'error' => 'danger',
                        'running' => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('tool_calls')
                    ->label('Tools')
                    ->numeric(),
                TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('duration')
                    ->label('Duration')
                    ->numeric(decimalPlaces: 2)
                    ->suffix('s')
                    ->placeholder('—'),
                TextColumn::make('error')
                    ->label('Error')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                        'running' => 'Running',
                    ]),
            ])
            ->recordActions([
                Action::make('inspect')
                    ->label('Inspect')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->modalHeading(fn (WorkflowRun $record): string => sprintf('Run #%d — %s', (int) $record->id, $this->titleFor((int) $record->workflow_id)))
                    ->modalContent(fn (WorkflowRun $record) => view('filament.admin.workflow-run', [
                        'run' => $record,
                    ]))
                    // Info-only: there is nothing to submit, and a modal that
                    // offers "Submit" on a read-only record implies a write.
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ])
            ->emptyStateHeading('No runs yet')
            ->emptyStateDescription('Runs appear here after a workflow is executed or a trigger fires.');
    }

    /**
     * Resolve a workflow title, memoised.
     */
    private function titleFor(int $workflowId): string
    {
        if (isset($this->titles[$workflowId])) {
            return $this->titles[$workflowId];
        }

        $title = WorkflowRuns::titles([$workflowId])[$workflowId] ?? null;

        return $this->titles[$workflowId] = null === $title || '' === $title
            ? 'Workflow #'.$workflowId
            : $title;
    }
}
