<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\Assistant;
use App\Models\Budget;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Budgets\BudgetStore;
use BackedEnum;
use UnitEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Spend and limits — Proposal 154 slice B, `/admin/budgets`.
 *
 * **Read-only, deliberately** (Proposal 154 D8). A spend limit the limited party
 * can raise is a self-throttle, not a control, so the limit is the operator's to
 * set (`/ops/budgets`) and this page is the estate administrator's view of it.
 * What it adds is **notice**: the hard band otherwise reaches an estate only as a
 * 429 on somebody's chat turn, at the exact moment it is too late to change
 * anything.
 *
 * Read-only is enforced by having no mutating action, the same way `Transcripts`
 * and `Security` are — not by a disabled button, which would imply a permission
 * that does not exist.
 *
 * The estate comes from `Budget`'s global scope (Proposal 153), so this page and
 * the guard cannot disagree about whose limit is being read. Consumption is
 * `BudgetStore::verdict()`, the guard's own call, so the badge here and the
 * refusal a turn gets are computed by one function.
 */
class Budgets extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'budgets';

    // "Spend" rather than "Budgets": the page cannot set one, and a label that
    // implies otherwise sends an administrator looking for a control that is
    // not there.
    protected static ?string $navigationLabel = 'Spend';

    protected static ?string $title = 'Spend and limits';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Activity;

    // After Transcripts (10) and Security (20): what the estate's people
    // produced, then what it is protected by, then what it costs.
    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.admin.budgets';

    /**
     * `verdict()` per record, memoised for the request: the table reads state,
     * spend and percent from three columns, and the callouts add another pass.
     *
    /**
     * @var array<int, array{state: string, window: string, assistant_id: int, limit: float, spend: float, percent: float|null}>
     */
    private array $verdicts = array();

    /**
     * Assistant titles by id, for the rows on screen.
     *
     * @var array<int, string>|null
     */
    private ?array $assistantNames = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    public function table(Table $table): Table
    {
        return $table
            // Estate-scoped by the model, not by a filter on this query.
            ->query(Budget::query())
            ->defaultSort('window')
            ->columns([
                TextColumn::make('window')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(static fn (string $state): string => ucfirst($state)),
                TextColumn::make('scope')
                    ->label('Applies to')
                    ->badge()
                    ->getStateUsing(fn (Budget $record): string => $this->scopeLabel($record))
                    ->color(fn (Budget $record): string => (int) $record->assistant_id > 0 ? 'info' : 'gray'),
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
            ])
            // No record actions, no toolbar actions, no bulk actions: there is
            // no write path on this page by design (D8).
            ->recordActions(array())
            ->toolbarActions(array())
            ->emptyStateHeading('No limit is set for this estate')
            ->emptyStateDescription('Spend is still metered — the cost pages show what has been used. Limits are set by the platform operator.');
    }

    /**
     * Enabled budgets the runtime is currently refusing on.
     */
    public function exhaustedCount(): int
    {
        return $this->countInState(BudgetStore::STATE_EXHAUSTED);
    }

    /**
     * Enabled budgets between the warning fraction and the limit.
     */
    public function warnCount(): int
    {
        return $this->countInState(BudgetStore::STATE_WARN);
    }

    /**
     * The window each row is measured over, for the tooltip on Spent.
     */
    public function windowStartLabel(Budget $budget): string
    {
        return app(BudgetStore::class)
            ->windowStart((string) $budget->window)
            ->toDayDateTimeString();
    }

    private function countInState(string $state): int
    {
        $count = 0;

        foreach ($this->budgets() as $budget) {
            if ($state === $this->verdictFor($budget)['state']) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Every budget of this estate, not the table's current page: the callout
     * counts all of them, and a count that shrank with pagination would be a
     * lie about how much of the estate is refused.
     *
     * @return list<Budget>
     */
    private function budgets(): array
    {
        return Budget::query()->get()->all();
    }

    /**
     * @return array{state: string, window: string, limit: float, spend: float, percent: float|null}
     */
    private function verdictFor(Budget $budget): array
    {
        return $this->verdicts[(int) $budget->getKey()]
            ??= app(BudgetStore::class)->verdict($budget);
    }

    /**
     * "Whole estate" or the assistant's title.
     *
     * An assistant limit the estate cannot see by name would read as an anonymous
     * refusal, so the title matters more here than on `/ops`: this is the page a
     * technician visits after a 429 on one agent.
     */
    private function scopeLabel(Budget $record): string
    {
        $assistantId = (int) $record->assistant_id;

        if ($assistantId <= 0) {
            return 'Whole estate';
        }

        $name = $this->assistantNames()[$assistantId] ?? '';

        return '' !== $name ? $name : 'Assistant #'.$assistantId;
    }

    /**
     * Assistant titles for the rows currently configured, loaded once.
     *
     * `withoutGlobalScopes()` because the map is keyed by the budget row's own
     * assistant, not by the ambient estate; ids are unique, so a title cannot be
     * attributed to the wrong agent.
     *
     * @return array<int, string>
     */
    private function assistantNames(): array
    {
        if (null !== $this->assistantNames) {
            return $this->assistantNames;
        }

        $ids = array();

        foreach ($this->budgets() as $budget) {
            $assistantId = (int) $budget->assistant_id;

            if ($assistantId > 0) {
                $ids[$assistantId] = $assistantId;
            }
        }

        if (array() === $ids) {
            return $this->assistantNames = array();
        }

        return $this->assistantNames = Assistant::query()
            ->withoutGlobalScopes()
            ->whereIn('id', array_values($ids))
            ->pluck('title', 'id')
            ->map(static fn ($title): string => (string) $title)
            ->all();
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
}
