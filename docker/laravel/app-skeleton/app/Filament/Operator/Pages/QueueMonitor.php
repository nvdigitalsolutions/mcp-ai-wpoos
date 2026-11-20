<?php

namespace App\Filament\Operator\Pages;

use App\Models\FailedJob;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

/**
 * Failed jobs (dead letters) — Wave 2's second `/ops` surface (Proposal 149).
 *
 * Reads Laravel's own `failed_jobs` table, which is distinct from the console's
 * DLQ (`nvoos_jobs`): that one lists durable job-status rows, this one lists
 * framework dead letters written when a worker exhausts its retries. Both are
 * real; neither is a duplicate of the other.
 *
 * Retry does exactly what `queue:retry` does — push the original payload back
 * onto the connection that recorded it, then forget the failed row — but
 * through the provider contract rather than shelling out to Artisan, so it is
 * testable with the database queue and needs no subprocess.
 *
 * Every mutation writes a record-level audit row. Livewire traffic is ignored
 * by `AuditAdminRequests` on purpose (it would drown the log), so the request
 * row does not exist for these actions; without this, an operator retrying or
 * flushing the queue would leave no trace at all.
 *
 * Deliberately absent: the exception stack trace (it belongs in the log; the
 * cell shows the first line), and any editing of a payload — the point of a
 * dead letter is that it is the original.
 */
class QueueMonitor extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'queue';

    protected static ?string $navigationLabel = 'Queue';

    protected static ?string $title = 'Failed jobs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;
    protected static string|UnitEnum|null $navigationGroup = OperatorNavigationGroup::Operations;

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.operator.queue';

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
            ->query(FailedJob::query())
            ->defaultSort('failed_at', 'desc')
            ->columns([
                TextColumn::make('job')
                    ->label('Job')
                    ->getStateUsing(static fn (FailedJob $record): string => $record->jobName()),
                TextColumn::make('queue')
                    ->sortable(),
                TextColumn::make('exception')
                    ->label('Failure')
                    ->getStateUsing(static fn (FailedJob $record): string => $record->exceptionSummary())
                    ->wrap(),
                TextColumn::make('failed_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalDescription('Push this job back onto its queue.')
                    ->action(fn (FailedJob $record) => $this->retryOne($record)),
                Action::make('forget')
                    ->label('Forget')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedTrash)
                    ->requiresConfirmation()
                    ->modalDescription('Delete this failed job without retrying it. This cannot be undone.')
                    ->action(fn (FailedJob $record) => $this->forgetOne($record)),
            ])
            ->emptyStateHeading('No failed jobs')
            ->emptyStateDescription('A job lands here only when a worker exhausts its retries.');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return array(
            Action::make('retryAll')
                ->label('Retry all')
                ->requiresConfirmation()
                ->modalDescription('Push every failed job back onto its queue.')
                ->action(fn () => $this->retryAll()),
            Action::make('flush')
                ->label('Flush all')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Delete every failed job without retrying it. This cannot be undone.')
                ->action(fn () => $this->flushAll()),
        );
    }

    private function retryOne(FailedJob $record): void
    {
        $retried = $this->retryJob((string) $record->getKey(), audit: true);

        $this->resetTable();

        $notification = Notification::make()
            ->title($retried ? 'Job queued for retry' : 'Job no longer exists');

        $retried ? $notification->success() : $notification->warning();

        $notification->send();
    }

    private function forgetOne(FailedJob $record): void
    {
        $uuid = (string) $record->getKey();

        $forgotten = (bool) app(FailedJobProviderInterface::class)->forget($uuid);

        if ($forgotten) {
            $this->audit('forgotten', $uuid, array('queue' => (string) $record->queue));
        }

        $this->resetTable();

        $notification = Notification::make()
            ->title($forgotten ? 'Failed job deleted' : 'Job no longer exists');

        $forgotten ? $notification->success() : $notification->warning();

        $notification->send();
    }

    private function retryAll(): void
    {
        $count = 0;

        foreach (FailedJob::query()->pluck('uuid') as $uuid) {
            if ($this->retryJob((string) $uuid, audit: false)) {
                ++$count;
            }
        }

        if ($count > 0) {
            $this->audit('retried', 'all', array('count' => $count));
        }

        $this->resetTable();

        Notification::make()
            ->success()
            ->title($count > 0 ? "Queued {$count} job(s) for retry" : 'Nothing to retry')
            ->send();
    }

    private function flushAll(): void
    {
        $count = FailedJob::query()->count();

        app(FailedJobProviderInterface::class)->flush();

        if ($count > 0) {
            $this->audit('flushed', 'all', array('count' => $count));
        }

        $this->resetTable();

        Notification::make()
            ->success()
            ->title($count > 0 ? "Deleted {$count} failed job(s)" : 'Nothing to flush')
            ->send();
    }

    /**
     * The same operation `queue:retry` performs, through the provider contract.
     *
     * `audit: false` is used by the bulk path so one row summarises the batch
     * instead of one row per job.
     */
    private function retryJob(string $uuid, bool $audit): bool
    {
        $provider = app(FailedJobProviderInterface::class);
        $job = $provider->find($uuid);

        if (null === $job) {
            return false;
        }

        app(QueueFactory::class)
            ->connection((string) $job->connection)
            ->pushRaw((string) $job->payload, (string) $job->queue);

        $provider->forget($uuid);

        if ($audit) {
            $this->audit('retried', $uuid, array(
                'queue' => (string) $job->queue,
                'connection' => (string) $job->connection,
            ));
        }

        return true;
    }

    /**
     * @param array<string, mixed> $after
     */
    private function audit(string $action, string $recordId, array $after): void
    {
        app(AdminAuditLogger::class)->recordResource('queue', $recordId, $action, array(), $after);
    }
}
