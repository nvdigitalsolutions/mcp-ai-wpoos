<?php

namespace App\Filament\Operator\Pages;

use App\Models\Schedule;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Schedules\ScheduleRunner;
use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Scheduler liveness — an `/ops` surface with no console equivalent
 * (Proposal 149, Wave 2).
 *
 * **Not a second Schedule Manager.** The console already owns definitions:
 * `SchedulesController` creates, edits, deletes, toggles, triggers one named
 * schedule and lists its history. Rebuilding that here would maintain one page
 * twice, in two UIs, for no new capability — the reasoning that put `/ops`
 * first in §8.1 of the plan.
 *
 * What no other surface answers is whether the **tick is running**. A schedule
 * is only advanced by `nvoos:run-schedules`, which only fires where the
 * `scheduler` container role runs `php artisan schedule:work`. If that role is
 * missing, every enabled schedule keeps its old `next_run_at` and the console
 * keeps listing definitions that will never run. This page surfaces exactly
 * that: an overdue count in the heading, a per-row health column, and the two
 * recovery operations that already exist as service methods.
 *
 * The two actions are deliberately **not** per-schedule triggers:
 *
 *  - *Time untimed schedules* is `ScheduleRunner::bootstrap()` — what the tick
 *    does before it looks for work. The console has no equivalent.
 *  - *Run due now* is `ScheduleRunner::due()` + `run()` — the tick itself,
 *    invoked by hand after the scheduler was down. The console's `trigger`
 *    fires one named schedule regardless of whether it is due; this fires
 *    whichever are actually overdue, and advances their clocks the same way.
 *
 * Both write a record-level audit row: Livewire traffic is excluded from the
 * request-level audit on purpose, so without it an operator could catch up a
 * backlog of schedules and leave no trace.
 */
class Scheduler extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'scheduler';

    protected static ?string $navigationLabel = 'Scheduler';

    protected static ?string $title = 'Scheduler';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;
    protected static string|UnitEnum|null $navigationGroup = OperatorNavigationGroup::Operations;

    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.operator.scheduler';

    /**
     * The panel already refuses non-operators; this repeats the predicate at
     * the page so the check cannot be lost if the panel config changes.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Schedule::query())
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Schedule')
                    ->getStateUsing(static fn (Schedule $record): string => $record->label())
                    ->searchable()
                    ->sortable(),
                TextColumn::make('health')
                    ->label('Health')
                    ->badge()
                    ->getStateUsing(static fn (Schedule $record): string => $record->healthLabel())
                    ->color(static fn (string $state): string => match ($state) {
                        'overdue' => 'danger',
                        'untimed' => 'warning',
                        'paused' => 'gray',
                        default => 'success',
                    }),
                TextColumn::make('cron_expression')
                    ->label('Cron')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('next_run_at')
                    ->label('Next run')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('last_run_at')
                    ->label('Last run')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('run_count')
                    ->label('Runs')
                    ->numeric(),
                IconColumn::make('enabled')
                    ->label('Enabled')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('priority')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            // Provisioning and editing are the console's job; this page only
            // diagnoses and recovers, so rows carry no actions.
            ->recordActions(array())
            ->toolbarActions(array())
            ->emptyStateHeading('No schedules')
            ->emptyStateDescription('Schedules are created in the console\'s Schedule Manager.');
    }

    /**
     * Enabled schedules whose next run has passed and was never advanced.
     *
     * The page heading reads this so an operator sees the dead tick before
     * reading a single row.
     */
    public function overdueCount(): int
    {
        return Schedule::query()
            ->where('enabled', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<', now())
            ->count();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return array(
            Action::make('timeUntimed')
                ->label('Time untimed schedules')
                ->icon(Heroicon::OutlinedClock)
                ->requiresConfirmation()
                ->modalDescription('Give enabled schedules that have no next run time a first one. This is what nvoos:run-schedules does on every tick, before it looks for work.')
                ->action(fn () => $this->timeUntimed()),
            Action::make('runDue')
                ->label('Run due now')
                ->icon(Heroicon::OutlinedPlay)
                ->requiresConfirmation()
                ->modalDescription('Execute every enabled schedule whose next run has passed, and advance its clock. This is the recovery path when the scheduler tick has stopped.')
                ->action(fn () => $this->runDue()),
        );
    }

    /**
     * Bootstrapping is not firing: the first occurrence is always in the
     * future, so an operator can time a backlog without running it.
     */
    public function timeUntimed(): void
    {
        $timed = app(ScheduleRunner::class)->bootstrap();

        if ($timed > 0) {
            $this->audit('timed', 'untimed', array('count' => $timed));
        }

        $this->resetTable();

        $notification = Notification::make()
            ->title($timed > 0 ? "Timed {$timed} schedule(s)" : 'Nothing to time');

        $timed > 0 ? $notification->success() : $notification->warning();

        $notification->send();
    }

    /**
     * Run each due schedule once, as the operator.
     *
     * A failure is counted, not thrown: `ScheduleRunner::run()` records it in
     * `nvoos_schedule_runs` and still advances the clock, which is what keeps
     * one broken handler from wedging a catch-up.
     */
    public function runDue(): void
    {
        $runner = app(ScheduleRunner::class);
        $due = $runner->due();
        $failed = 0;

        foreach ($due as $row) {
            $result = $runner->run($row, (int) auth()->id());

            if ('success' !== $result['status']) {
                ++$failed;
            }
        }

        $count = count($due);

        if ($count > 0) {
            // One row for the batch: a catch-up of fifty schedules is one
            // operator decision, and fifty rows would bury it.
            $this->audit('ran', 'due', array('count' => $count, 'failed' => $failed));
        }

        $this->resetTable();

        $notification = Notification::make()
            ->title(0 === $count ? 'Nothing due' : "Ran {$count} schedule(s)");

        if ($failed > 0) {
            $notification->body("{$failed} failed. See the run history in the console.");
        }

        $count > 0 ? $notification->success() : $notification->warning();

        $notification->send();
    }

    /**
     * @param array<string, mixed> $after
     */
    private function audit(string $action, string $recordId, array $after): void
    {
        app(AdminAuditLogger::class)->recordResource('scheduler', $recordId, $action, array(), $after);
    }
}
