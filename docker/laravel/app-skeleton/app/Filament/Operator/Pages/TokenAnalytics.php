<?php

namespace App\Filament\Operator\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Reports\TokenAnalyticsReport;
use App\Support\Reports\TokenUsageReport;
use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Token analytics — Proposal 149 §83, `/ops/token-analytics`.
 *
 * The WordPress token manager's `analytics` view, whose three sub-tabs
 * (`trends`, `patterns`, `anomalies`) are three sections of one page here:
 * sub-tabs inside a sub-tab is the density §62 decision 1 rejected, and all
 * three read the same daily series.
 *
 * The page renders no chart library. A bar per day with a scaled height is
 * enough to see a spike, keeps the page dependency-free, and — unlike a canvas
 * — can be asserted in a test.
 */
class TokenAnalytics extends Page
{
    protected static ?string $slug = 'token-analytics';

    protected static ?string $navigationLabel = 'Token analytics';

    protected static ?string $title = 'Token analytics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    // Immediately after Token usage (13).
    protected static string|UnitEnum|null $navigationGroup = OperatorNavigationGroup::Spend;
    protected static ?int $navigationSort = 21;

    protected string $view = 'filament.operator.token-analytics';

    /** Whole days; one of `TokenUsageReport::PERIODS`. */
    public int $period = TokenUsageReport::DEFAULT_PERIOD;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    public function updatedPeriod(): void
    {
        $this->period = app(TokenAnalyticsReport::class)->normalise($this->period);
    }

    /**
     * @return array<string, array{events: int, tokens: int, cost: float}>
     */
    public function series(): array
    {
        // `null` = every estate (Proposal 152 §3 D5).
        return app(TokenAnalyticsReport::class)->series($this->period, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function trend(): array
    {
        return app(TokenAnalyticsReport::class)->trend($this->period, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function patterns(): array
    {
        return app(TokenAnalyticsReport::class)->patterns($this->period, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function anomalies(): array
    {
        return app(TokenAnalyticsReport::class)->anomalies($this->period, null);
    }

    public function windowLabel(): string
    {
        return app(TokenAnalyticsReport::class)->windowStart($this->period)->toDateString()
            .' — '.now()->toDateString();
    }

    public function hasData(): bool
    {
        foreach ($this->series() as $day) {
            if ((int) $day['tokens'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    public function periods(): array
    {
        return TokenUsageReport::PERIODS;
    }
}
