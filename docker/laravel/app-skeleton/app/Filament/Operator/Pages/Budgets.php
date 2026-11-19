<?php

namespace App\Filament\Operator\Pages;

use App\Models\Budget;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\Budgets\BudgetStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * Estate budgets — Proposal 154 slice B, `/ops/budgets`.
 *
 * Slice A (§92) made the runtime refuse a turn when an estate is over its limit.
 * Until this page, setting that limit meant a database row: the guard was
 * enforceable but not operable. This is the operator's half of the feature.
 *
 * **Consumption is not a column.** Every "spent" and "used" cell is
 * `BudgetStore::verdict()`, the same call the guard makes, so the badge and the
 * enforcement decision cannot disagree — the page cannot say "92%, fine" while
 * the guard refuses. `verdict()` is memoised per record for the request because
 * three columns read it.
 *
 * **Why the operator owns the limit.** A spend limit the limited party can raise
 * is a self-throttle, not a control (Proposal 154 D8). The estate-facing page
 * (`/admin/budgets`) is therefore read-only: it tells an estate administrator
 * where they stand *before* their team's chat starts returning 429, which is the
 * notice the hard band otherwise gives only at the moment it is too late to act.
 *
 * The row is identity-plus-limit: `(estate, window)` is unique, so editing cannot
 * change either — a different window is a different budget. That is why the edit
 * modal disables both and the create modal asks for both.
 */
class Budgets extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'budgets';

    protected static ?string $navigationLabel = 'Budgets';

    protected static ?string $title = 'Budgets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    // With the spend cluster (token usage 13, token analytics 14) rather than
    // beside the scheduler: this page is about money, and an operator looking at
    // cost should find it next to the reports it constrains.
    protected static ?int $navigationSort = 17;

    protected string $view = 'filament.operator.budgets';

    /**
     * `verdict()` per record, memoised for the request.
     *
     * The table reads state, spend and percent from three columns; without this
     * each row would be summed three times, and the exhausted callout would add
     * a fourth pass over the same rows.
     *
     * @var array<int, array{state: string, window: string, limit: float, spend: float, percent: float|null}>
     */
    private array $verdicts = array();

    /**
     * The panel already refuses non-operators; this repeats the predicate at the
     * page so the check cannot be lost if the panel config changes.
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
            // Operated cross-estate by design: `/ops` is the vendor's view of
            // every estate, and the Estate column is what makes that visible.
            // `withoutGlobalScopes()` is the documented opt-out (Proposal 153).
            ->query(Budget::query()->withoutGlobalScopes()->with('tenant'))
            ->defaultSort('tenant_id')
            ->columns([
                TextColumn::make('tenant.name')
                    ->label('Estate')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('window')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (string $state): string => ucfirst($state)),
                TextColumn::make('limit_usd')
                    ->label('Limit')
                    ->money('USD', decimalPlaces: 2),
                TextColumn::make('spent')
                    ->label('Spent')
                    ->getStateUsing(fn (Budget $record): float => $this->verdictFor($record)['spend'])
                    ->money('USD', decimalPlaces: 2)
                    ->tooltip(fn (Budget $record): string => 'Since '.$this->windowStartLabel($record)),
                TextColumn::make('used')
                    ->label('Used')
                    ->badge()
                    ->getStateUsing(fn (Budget $record): string => $this->percentLabel($record))
                    ->color(fn (Budget $record): string => $this->stateColour($record)),
                TextColumn::make('warn_at')
                    ->label('Warns at')
                    ->getStateUsing(static fn (Budget $record): string => (float) $record->warn_at <= 0.0
                        ? 'off'
                        : number_format((float) $record->warn_at * 100, 0).'%'),
                IconColumn::make('enabled')
                    ->label('On')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->modalHeading(fn (Budget $record): string => 'Limit: '.$this->estateLabel($record).' '.$record->window)
                    ->modalDescription('The estate and the window identify the budget and cannot change here. Delete the row and create another to move it.')
                    ->fillForm(fn (Budget $record): array => array(
                        'limit_usd' => (float) $record->limit_usd,
                        'warn_at' => (float) $record->warn_at,
                        'enabled' => (bool) $record->enabled,
                    ))
                    ->schema($this->limitSchema(withIdentity: false))
                    ->action(fn (Budget $record, array $data) => $this->save($record, $data)),
                Action::make('delete')
                    ->label('Delete')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedTrash)
                    ->requiresConfirmation()
                    ->modalDescription('Removes the limit entirely; the estate is no longer refused, and the spend history stays readable on the cost pages.')
                    ->action(fn (Budget $record) => $this->destroy($record)),
            ])
            ->emptyStateHeading('No budgets')
            ->emptyStateDescription('A budget refuses chat turns and workflow agents once an estate\'s recorded spend reaches the limit. Set one to give an estate a ceiling.')
            ->emptyStateActions([
                $this->createAction(),
            ]);
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return array(
            $this->createAction(),
        );
    }

    /**
     * How many enabled budgets the runtime is currently refusing on.
     *
     * Computed from the memoised verdicts, so the heading and the table are the
     * same numbers.
     */
    public function exhaustedCount(): int
    {
        $count = 0;

        foreach ($this->budgets() as $budget) {
            if (BudgetStore::STATE_EXHAUSTED === $this->verdictFor($budget)['state']) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The canonical spend window each row is measured over, as a date the
     * operator can read: "since 00:00" or "since the 1st".
     */
    public function windowStartLabel(Budget $budget): string
    {
        return app(BudgetStore::class)
            ->windowStart((string) $budget->window)
            ->toDayDateTimeString();
    }

    /**
     * @return list<Budget>
     */
    private function budgets(): array
    {
        return app(BudgetStore::class)->all();
    }

    /**
     * @return array{state: string, window: string, limit: float, spend: float, percent: float|null}
     */
    private function verdictFor(Budget $budget): array
    {
        return $this->verdicts[(int) $budget->getKey()]
            ??= app(BudgetStore::class)->verdict($budget);
    }

    private function percentLabel(Budget $budget): string
    {
        $percent = $this->verdictFor($budget)['percent'];

        if (null === $percent) {
            return '—';
        }

        return number_format($percent, 1).'%';
    }

    private function stateColour(Budget $budget): string
    {
        return match ($this->verdictFor($budget)['state']) {
            BudgetStore::STATE_EXHAUSTED => 'danger',
            BudgetStore::STATE_WARN => 'warning',
            BudgetStore::STATE_DISABLED => 'gray',
            BudgetStore::STATE_UNLIMITED => 'gray',
            default => 'success',
        };
    }

    private function estateLabel(Budget $record): string
    {
        $name = $record->relationLoaded('tenant') ? $record->tenant?->name : null;

        return null === $name || '' === $name
            ? (string) $record->tenant_id
            : (string) $name;
    }

    /**
     * The fields a limit has: the identity (on create only), the number, and the
     * two switches.
     *
     * @return array<int, mixed>
     */
    private function limitSchema(bool $withIdentity): array
    {
        $fields = array();

        if ($withIdentity) {
            $fields[] = Select::make('tenant_id')
                ->label('Estate')
                ->options(fn (): array => Tenant::query()
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required()
                ->helperText('Every estate is a valid target; the estate is the subject of the limit, not the reader\'s.');

            $fields[] = Select::make('window')
                ->options(array(
                    Budget::DAILY => 'Daily — resets at midnight',
                    Budget::MONTHLY => 'Monthly — resets on the 1st',
                ))
                ->default(Budget::DAILY)
                ->required();
        }

        $fields[] = TextInput::make('limit_usd')
            ->label('Limit (USD)')
            ->numeric()
            ->minValue(0)
            ->step(0.01)
            ->required()
            ->prefix('$')
            ->helperText('0 means no limit: a zero row is a misconfiguration, and ticking it closed would take an estate offline over a typo.');

        $fields[] = TextInput::make('warn_at')
            ->label('Warn at')
            ->numeric()
            ->minValue(0)
            ->maxValue(1)
            ->step(0.05)
            ->default(0.8)
            ->helperText('A fraction of the limit: 0.8 warns once 80% is spent. 0 turns the warning band off. The warning allows the turn and writes a budget.warn log line.');

        $fields[] = Toggle::make('enabled')
            ->label('Enabled')
            ->default(true)
            ->helperText('A disabled budget keeps its numbers and stops being consulted.');

        return $fields;
    }

    private function createAction(): Action
    {
        return Action::make('create')
            ->label('Set a budget')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Set a budget')
            ->modalDescription('One budget per estate per window. Setting an existing pair replaces its limit.')
            ->schema($this->limitSchema(withIdentity: true))
            ->action(fn (array $data) => $this->store($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function store(array $data): void
    {
        $tenantId = (string) ($data['tenant_id'] ?? '');
        $window = (string) ($data['window'] ?? '');

        // A tampered Livewire payload must not create a limit under an estate
        // that does not exist: it would never apply to a real user, and it would
        // sit in this table looking like a control.
        if ('' === $tenantId || null === Tenant::query()->find($tenantId)) {
            Notification::make()
                ->danger()
                ->title('Budget not saved')
                ->body('Choose an existing estate.')
                ->send();

            return;
        }

        try {
            $budget = app(BudgetStore::class)->put(
                $tenantId,
                $window,
                (float) ($data['limit_usd'] ?? 0),
                (float) ($data['warn_at'] ?? 0),
                (bool) ($data['enabled'] ?? true),
            );
        } catch (InvalidArgumentException $error) {
            Notification::make()
                ->danger()
                ->title('Budget not saved')
                ->body($error->getMessage())
                ->send();

            return;
        }

        $this->audit('created', (string) $budget->getKey(), array(), $this->auditPayload($budget));
        $this->afterWrite("Budget saved for {$this->estateLabel($budget)} ({$budget->window}).");
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save(Budget $budget, array $data): void
    {
        $before = $this->auditPayload($budget);

        try {
            app(BudgetStore::class)->put(
                (string) $budget->tenant_id,
                (string) $budget->window,
                (float) ($data['limit_usd'] ?? 0),
                (float) ($data['warn_at'] ?? 0),
                (bool) ($data['enabled'] ?? true),
            );
        } catch (InvalidArgumentException $error) {
            Notification::make()
                ->danger()
                ->title('Budget not saved')
                ->body($error->getMessage())
                ->send();

            return;
        }

        $budget->refresh();

        $this->audit('updated', (string) $budget->getKey(), $before, $this->auditPayload($budget));
        $this->afterWrite("Budget updated for {$this->estateLabel($budget)} ({$budget->window}).");
    }

    private function destroy(Budget $budget): void
    {
        app(BudgetStore::class)->remove($budget);

        $this->audit('deleted', (string) $budget->getKey(), $this->auditPayload($budget), array());
        $this->afterWrite('Budget deleted.');
    }

    /**
     * Drop the memoised verdicts and the table's page of rows after a write.
     *
     * Without the reset the page would keep showing the numbers it computed
     * before the change — the one thing this page must never do.
     */
    private function afterWrite(string $message): void
    {
        $this->verdicts = array();

        $this->resetTable();

        Notification::make()->success()->title($message)->send();
    }

    /**
     * Record-level audit.
     *
     * Livewire traffic is excluded from the request-level audit on purpose, so a
     * limit changed from this page would otherwise leave no trace. The resource
     * is `budget` and the record is the row id; the payload is the row, never a
     * user's spend.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function audit(string $action, string $recordId, array $before, array $after): void
    {
        app(AdminAuditLogger::class)->recordResource('budget', $recordId, $action, $before, $after);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(Budget $budget): array
    {
        return array(
            'tenant_id' => (string) $budget->tenant_id,
            'window' => (string) $budget->window,
            'limit_usd' => (float) $budget->limit_usd,
            'warn_at' => (float) $budget->warn_at,
            'enabled' => (bool) $budget->enabled,
        );
    }
}
