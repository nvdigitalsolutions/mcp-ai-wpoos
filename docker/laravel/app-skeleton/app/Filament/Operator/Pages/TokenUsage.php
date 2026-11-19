<?php

namespace App\Filament\Operator\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Reports\TokenUsageReport;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Token usage by user — Proposal 149 §82, `/ops/token-usage`.
 *
 * The WordPress token manager's `per_user` view, which had no console
 * equivalent. It only became worth building once §81 gave the table a writer
 * and §80 gave it a long-term store: before that the page would have rendered
 * zeros on every real deployment.
 *
 * What is deliberately absent is in `TokenUsageReport`'s docblock — the tier
 * column and cached tokens. The per-site view arrived in §87: Proposal 152 gave
 * the table an estate column, so this page now carries the WordPress `per_site`
 * view as its scope control, and the `per_user` table is what that scope filters.
 *
 * One page rather than two because both read the same rows over the same window:
 * a second page would duplicate the period control and the totals row to say the
 * same thing about a narrower set of rows. The WordPress views are separate
 * because its admin is tabbed; §83 already collapsed three analysis sub-tabs into
 * one page for the same reason.
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
     * The estate the user table is scoped to; empty means every estate.
     *
     * URL-bound so a support link can name the estate it is about. A string
     * rather than `?string` because an empty query parameter is a real value a
     * browser sends (`?estate=`), and normalising that to "every estate" is a
     * read-time concern, not a nullable one.
     *
     * This is a **filter, not an authorization**: the page is operator-only and
     * an operator may see every estate, so the value never grants anything. It
     * reaches the query as a parameter, so a crafted value can only fail to
     * match.
     */
    #[Url]
    public string $estate = '';

    /**
     * Memoised for the request: the blade calls `rows()` and `totals()`, and
     * `totals()` reads the rows.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $rows = null;

    /**
     * Memoised for the request; the blade calls `estates()` twice (the table and
     * the select's options).
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $estates = null;

    /**
     * Memoised for the request; only computed for a selected estate.
     *
     * @var list<array<string, mixed>>|null
     */
    private ?array $models = null;

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
        // `null` = every estate; a selected estate narrows both tables. Only an
        // operator page may ask for the unscoped read (Proposal 152 §3 D5).
        return $this->rows ??= app(TokenUsageReport::class)->byUser($this->period, $this->estateScope());
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
     * Every estate with usage in the window.
     *
     * Computed even when the page is scoped, because the select needs the
     * options; the table itself is only rendered unscoped.
     *
     * @return list<array<string, mixed>>
     */
    public function estates(): array
    {
        return $this->estates ??= app(TokenUsageReport::class)->byEstate($this->period);
    }

    /**
     * The select's options: "All estates" plus every estate with usage.
     *
     * An estate named by the URL that has no usage in this window is added back,
     * so the control shows what the page is scoped to rather than silently
     * falling back to "All estates" — a scope the page did not apply is worse
     * than an empty table.
     *
     * @return array<string, string> value => label
     */
    public function estateOptions(): array
    {
        $options = array('' => 'All estates');
        $seen = array();

        foreach ($this->estates() as $estate) {
            $tenantId = (string) $estate['tenant_id'];
            $options[$tenantId] = (string) $estate['name'];
            $seen[] = $tenantId;
        }

        $scope = $this->estateScope();

        if (null !== $scope && ! in_array($scope, $seen, true)) {
            $options[$scope] = app(TokenUsageReport::class)->estateName($scope);
        }

        return $options;
    }

    /**
     * The model breakdown for the selected estate.
     *
     * Empty while unscoped on purpose: the estate table is already the overview,
     * and a platform-wide by-model table would be a third table saying less than
     * the two above it.
     *
     * @return list<array<string, mixed>>
     */
    public function models(): array
    {
        $scope = $this->estateScope();

        if (null === $scope) {
            return array();
        }

        return $this->models ??= app(TokenUsageReport::class)->byModel($this->period, $scope);
    }

    /**
     * The estate the page is scoped to, or `null` for every estate.
     */
    public function estateScope(): ?string
    {
        $estate = trim($this->estate);

        return '' === $estate ? null : $estate;
    }

    /**
     * The scope's human label, for the section heading and the empty state.
     */
    public function scopeLabel(): string
    {
        $scope = $this->estateScope();

        if (null === $scope) {
            return 'All estates';
        }

        return app(TokenUsageReport::class)->estateName($scope);
    }

    /**
     * Whether the page is showing one estate rather than all of them.
     */
    public function isScoped(): bool
    {
        return null !== $this->estateScope();
    }

    /**
     * @return list<int>
     */
    public function periods(): array
    {
        return TokenUsageReport::PERIODS;
    }
}
