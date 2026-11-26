<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\ToolQuota;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\TenantContext;
use App\Support\ToolCatalogue;
use App\Support\ToolQuota\ToolQuotaStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use UnitEnum;

/**
 * Per-tool daily quotas — Proposal 149 §176, `/admin/tool-quotas`.
 *
 * The `token_manager` → `per_tool` view: a daily token ceiling per tool, with the
 * day's consumption beside it. **Writable here**, unlike spend limits, because
 * the quota is a cost control the estate runs for itself rather than a boundary
 * someone else sets on it — the same reasoning that makes chat profiles a user
 * preference and budgets an operator limit.
 *
 * **Why a token count and not a dollar figure.** A tool body makes no provider
 * call, so there is no invoice line to attribute to it. What a tool costs the
 * loop is the payload it puts back into the context on the next turn, which is
 * why the counter is `strlen / 4` over the result — the ratio WordPress's
 * `estimate_tokens()` uses, kept so the two runtimes report comparable numbers.
 *
 * **The badge is per user, not per estate.** The limit is enforced against one
 * caller, so the honest question is "is the busiest user near their ceiling".
 * An estate total beside it answers "what is this tool costing us", which is a
 * different question and is shown as its own column rather than folded into the
 * badge.
 *
 * **A tool with no row is unmetered.** Adding a row is the act of metering;
 * absence, a disabled row and a zero limit all refuse nothing.
 */
class ToolQuotas extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'tool-quotas';

    protected static ?string $navigationLabel = 'Tool quotas';

    protected static ?string $title = 'Tool quotas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Knowledge;

    // Directly under the catalogue it constrains.
    protected static ?int $navigationSort = 11;

    protected string $view = 'filament.admin.tool-quotas';

    /**
     * `verdict()` per record, memoised for the request: the table reads state,
     * used and totals from four columns and would otherwise run four queries per
     * row.
     *
     * @var array<int, array{state: string, limit: int, busiest: int, total: int, users: int, percent: float|null}>
     */
    private array $verdicts = array();

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    public function table(Table $table): Table
    {
        return $table
            // Estate-scoped by the model's global scope, not by a filter here.
            ->query(ToolQuota::query())
            ->defaultSort('tool_slug')
            ->columns([
                TextColumn::make('tool_slug')
                    ->label('Tool')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ToolQuota $record): string => $this->toolName($record->tool_slug))
                    ->formatStateUsing(static fn (string $state): string => $state)
                    ->fontFamily('mono'),
                TextColumn::make('daily_tokens')
                    ->label('Daily limit')
                    ->getStateUsing(fn (ToolQuota $record): string => $this->limitLabel($record))
                    ->badge()
                    ->color(fn (ToolQuota $record): string => (int) $record->daily_tokens > 0 ? 'gray' : 'warning'),
                TextColumn::make('used')
                    ->label('Busiest user')
                    ->badge()
                    ->getStateUsing(fn (ToolQuota $record): string => $this->usedLabel($record))
                    ->color(fn (ToolQuota $record): string => $this->stateColour($record)),
                TextColumn::make('estate_total')
                    ->label('Estate today')
                    ->getStateUsing(fn (ToolQuota $record): string => $this->formatTokens($this->verdictFor($record)['total']))
                    ->tooltip(fn (ToolQuota $record): string => $this->usersTooltip($record)),
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
                    ->modalHeading(fn (ToolQuota $record): string => 'Quota: '.$record->tool_slug)
                    ->modalDescription('The tool identifies the quota and cannot change here. Delete the row and create another to move it.')
                    ->fillForm(fn (ToolQuota $record): array => array(
                        'daily_tokens' => (int) $record->daily_tokens,
                        'enabled' => (bool) $record->enabled,
                    ))
                    ->schema($this->quotaSchema(withIdentity: false))
                    ->action(fn (ToolQuota $record, array $data) => $this->save($record, $data)),
                Action::make('delete')
                    ->label('Delete')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedTrash)
                    ->requiresConfirmation()
                    ->modalDescription('Removes the quota. The tool becomes unmetered; the day\'s usage counters stay where they are.')
                    ->action(fn (ToolQuota $record) => app(ToolQuotaStore::class)->remove((int) $record->getKey())),
            ])
            ->emptyStateHeading('No tool quotas')
            ->emptyStateDescription('A quota caps one user\'s estimated tokens per tool per day. Until one is set, tools are unmetered and only counted.')
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
     * The fields shared by create and edit.
     *
     * `withIdentity` is false on edit: the tool slug is the key, and letting it
     * change would silently move a limit onto a different tool while leaving the
     * old one unmetered.
     *
     * @return array<int, mixed>
     */
    private function quotaSchema(bool $withIdentity): array
    {
        $fields = array();

        if ($withIdentity) {
            $fields[] = Select::make('tool_slug')
                ->label('Tool')
                ->options(fn (): array => $this->toolOptions())
                ->searchable()
                ->required()
                ->helperText('Only registered tools are listed. A tool that is not registered cannot run, so a quota for it would meter nothing.');
        }

        $fields[] = TextInput::make('daily_tokens')
            ->label('Daily limit (estimated tokens)')
            ->numeric()
            ->minValue(0)
            ->default(0)
            ->required()
            ->helperText('Estimated from the size of each tool result (characters ÷ 4), per user, per UTC day. 0 means no limit.');

        $fields[] = Toggle::make('enabled')
            ->label('Meter this tool')
            ->default(true)
            ->helperText('Off keeps the row and its counter but refuses nothing.');

        return $fields;
    }

    private function createAction(): Action
    {
        return Action::make('create')
            ->label('Add quota')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Add a tool quota')
            ->modalDescription('Metering a tool is opt-in. An estate with no quota rows is never refused.')
            ->schema($this->quotaSchema(withIdentity: true))
            ->action(fn (array $data) => $this->store($data));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function store(array $data): void
    {
        $slug = trim((string) ($data['tool_slug'] ?? ''));

        if ('' === $slug) {
            return;
        }

        app(ToolQuotaStore::class)->put(
            $slug,
            (int) ($data['daily_tokens'] ?? 0),
            (bool) ($data['enabled'] ?? true)
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save(ToolQuota $record, array $data): void
    {
        app(ToolQuotaStore::class)->put(
            (string) $record->tool_slug,
            (int) ($data['daily_tokens'] ?? 0),
            (bool) ($data['enabled'] ?? true)
        );
    }

    // ── Presentation helpers ────────────────────────────────────

    /**
     * @return array{state: string, limit: int, busiest: int, total: int, users: int, percent: float|null}
     */
    private function verdictFor(ToolQuota $record): array
    {
        $key = (int) $record->getKey();

        if (! isset($this->verdicts[$key])) {
            $this->verdicts[$key] = app(ToolQuotaStore::class)->verdict($record);
        }

        return $this->verdicts[$key];
    }

    /**
     * Tool names by slug, resolved once per request.
     *
     * @var array<string, string>|null
     */
    private ?array $toolNames = null;

    private function toolName(string $slug): string
    {
        if (null === $this->toolNames) {
            $this->toolNames = array();

            foreach (ToolCatalogue::rows(app(ToolRegistry::class), app(ToolReadiness::class)) as $row) {
                $this->toolNames[(string) $row['slug']] = (string) $row['name'];
            }
        }

        return $this->toolNames[$slug] ?? 'not registered on this runtime';
    }

    /**
     * The live registry, for the create form's selector.
     *
     * Read from the registry rather than from existing rows so a new quota can
     * only name a tool that can actually run.
     *
     * @return array<string, string>
     */
    private function toolOptions(): array
    {
        $options = array();

        foreach (ToolCatalogue::rows(app(ToolRegistry::class), app(ToolReadiness::class)) as $row) {
            $slug = (string) $row['slug'];
            $options[$slug] = $slug.' — '.$row['name'];
        }

        asort($options);

        return $options;
    }

    private function limitLabel(ToolQuota $record): string
    {
        $limit = (int) $record->daily_tokens;

        return $limit > 0 ? $this->formatTokens($limit) : 'unlimited';
    }

    private function usedLabel(ToolQuota $record): string
    {
        $verdict = $this->verdictFor($record);

        if (ToolQuotaStore::STATE_DISABLED === $verdict['state']) {
            return 'off';
        }

        if (ToolQuotaStore::STATE_UNLIMITED === $verdict['state']) {
            return $this->formatTokens($verdict['busiest']).' (no limit)';
        }

        return $this->formatTokens($verdict['busiest']).' · '.number_format((float) $verdict['percent'], 0).'%';
    }

    private function stateColour(ToolQuota $record): string
    {
        return match ($this->verdictFor($record)['state']) {
            ToolQuotaStore::STATE_EXHAUSTED => 'danger',
            ToolQuotaStore::STATE_WARN => 'warning',
            ToolQuotaStore::STATE_DISABLED => 'gray',
            ToolQuotaStore::STATE_UNLIMITED => 'info',
            default => 'success',
        };
    }

    private function usersTooltip(ToolQuota $record): string
    {
        $verdict = $this->verdictFor($record);

        return sprintf(
            '%s estimated tokens across %d user%s today (UTC).',
            $this->formatTokens($verdict['total']),
            $verdict['users'],
            1 === $verdict['users'] ? '' : 's'
        );
    }

    private function formatTokens(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            return number_format($tokens / 1_000_000, 1).'M';
        }

        if ($tokens >= 1_000) {
            return number_format($tokens / 1_000, 1).'k';
        }

        return (string) $tokens;
    }

    /**
     * The estate label, for the page's context line.
     */
    public function estateLabel(): string
    {
        return (string) TenantContext::current();
    }
}
