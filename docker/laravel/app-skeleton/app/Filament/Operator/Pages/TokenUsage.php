<?php

namespace App\Filament\Operator\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Reports\TokenUsageReport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Token usage by user — Proposal 149 §82, `/ops/token-usage`.
 *
 * The WordPress token manager's `per_user` view, which had no console
 * equivalent. It only became worth building once §81 gave the table a writer
 * and §80 gave it a long-term store: before that the page would have rendered
 * zeros on every real deployment.
 *
 * What is deliberately absent is in `TokenUsageReport`'s docblock — the tier
 * column, cached tokens, and a per-site view. The last one is now possible:
 * Proposal 152 gave the table an estate column and the report a required
 * `$tenantId`. The page asks for `null` (every estate) because an operator
 * surface must see the whole deployment; an estate filter is the obvious next
 * step, not a missing capability.
 */
class TokenUsage extends Page
{
    protected static ?string $slug = 'token-usage';

    protected static ?string $navigationLabel = 'Token usage';

    protected static ?string $title = 'Token usage';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    // With the operational read surfaces: queue (10), diagnostics (12), then
    // this, before the audit/asset pages.
    protected static ?int $navigationSort = 13;

    protected string $view = 'filament.operator.token-usage';

    /** Whole days; one of `TokenUsageReport::PERIODS`. */
    public int $period = TokenUsageReport::DEFAULT_PERIOD;

    /**
     * Memoised for the request: the blade calls `rows()` and `totals()`, and
     * `totals()` reads the rows.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $rows = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    /**
     * Keep the select coherent: an out-of-range value has no option to render,
     * so it is normalised as soon as it arrives rather than only inside the
     * report query.
     */
    public function updatedPeriod(): void
    {
        $this->period = app(TokenUsageReport::class)->normalisePeriod($this->period);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        // `null` = every estate. Only an operator page may ask for that
        // (Proposal 152 §3 D5).
        return $this->rows ??= app(TokenUsageReport::class)->byUser($this->period, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function totals(): array
    {
        return app(TokenUsageReport::class)->totals($this->rows());
    }

    public function windowLabel(): string
    {
        $start = app(TokenUsageReport::class)->windowStart($this->period);

        return $start->toDateString().' — '.now()->toDateString();
    }

    /**
     * @return list<int>
     */
    public function periods(): array
    {
        return TokenUsageReport::PERIODS;
    }
}
