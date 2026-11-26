<?php

namespace App\Filament\Operator\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Metrics\MetricCatalogue;
use App\Support\Metrics\MetricsReport;
use App\Support\Metrics\TraceMetrics;
use App\Support\Reports\TokenUsageReport;
use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The metric catalogue — Proposal 155 §4 D6 and D7, `/ops/metrics` (§99).
 *
 * The surface `measurement` was waiting for since §45. It shows **the
 * vocabulary and one windowed figure per metric**, and names the metrics this
 * runtime cannot produce. It is not a second observability page: per-run detail
 * stays on `/ops/observability`, and the cost breakdowns stay on their own pages.
 *
 * Read-only: browsing metrics never writes, and there is no export (D7 — the
 * WordPress page exports a buffer this runtime does not have; derived values are
 * a different question, answered by `/ops/reports`).
 *
 * Two honesty devices, both deliberate:
 *
 *  - a metric with no producer renders its **reason**, never a zero;
 *  - when the window holds no trace records at all, the trace-derived rows are
 *    blank rather than zero — capture is per assistant and opt-in, so "nothing
 *    recorded" and "nothing ran" are different facts.
 */
class Metrics extends Page
{
    protected static ?string $slug = 'metrics';

    protected static ?string $navigationLabel = 'Metrics';

    protected static ?string $title = 'Metrics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    // After budgets (17) and datasets (18): the reference surfaces that describe
    // what the runtime measures and may spend.
    protected static string|UnitEnum|null $navigationGroup = OperatorNavigationGroup::Insight;
    protected static ?int $navigationSort = 16;

    protected string $view = 'filament.operator.metrics';

    /** Whole days; one of `TokenUsageReport::PERIODS`, the cost pages' own set. */
    public int $period = TokenUsageReport::DEFAULT_PERIOD;

    /**
     * Memoised for the request: the blade reads it several times.
     *
     * @var array<string, mixed>|null
     */
    private ?array $report = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    /**
     * Keep the select coherent with the report's own window.
     */
    public function updatedPeriod(): void
    {
        $this->period = app(TokenUsageReport::class)->normalisePeriod($this->period);
        $this->report = null;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return $this->report ??= app(MetricsReport::class)->report($this->period);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return (array) $this->report()['rows'];
    }

    /**
     * @return array{total: int, derived: int, partial: int, unproduced: int}
     */
    public function counts(): array
    {
        return (array) $this->report()['counts'];
    }

    public function windowLabel(): string
    {
        $window = (array) $this->report()['window'];

        return substr((string) $window['start'], 0, 10).' — '.substr((string) $window['end'], 0, 10);
    }

    /**
     * @return array<string, mixed>
     */
    public function traces(): array
    {
        return (array) $this->report()['traces'];
    }

    /**
     * Whether the window holds any trace records at all.
     *
     * Drives the callout that explains why the trace-derived rows are blank.
     */
    public function hasTraces(): bool
    {
        return ((int) ($this->traces()['records'] ?? 0)) > 0;
    }

    /**
     * @return list<int>
     */
    public function periods(): array
    {
        return TokenUsageReport::PERIODS;
    }

    /**
     * A value in its metric's unit, or an em dash when nothing produced it.
     *
     * @param array<string, mixed> $row
     */
    public function formatValue(array $row): string
    {
        $value = $row['value'] ?? null;

        if (null === $value) {
            return '—';
        }

        $value = (float) $value;

        return match ((string) $row['unit']) {
            'usd' => '$'.number_format($value, 2),
            'ms' => number_format($value, 1).' ms',
            'ratio' => number_format($value * 100, 1).'%',
            default => number_format($value, 0),
        };
    }

    /**
     * The badge colour for a state.
     */
    public function stateColour(string $state): string
    {
        return match ($state) {
            MetricCatalogue::STATE_DERIVED => 'success',
            MetricCatalogue::STATE_PARTIAL => 'warning',
            default => 'gray',
        };
    }

    /**
     * The record ceiling, for the truncation callout.
     */
    public function scanLimit(): int
    {
        return TraceMetrics::SCAN_LIMIT;
    }
}
