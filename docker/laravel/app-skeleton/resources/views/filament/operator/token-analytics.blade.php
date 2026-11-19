{{--
    Token analytics — Proposal 149 §83.

    Trends, patterns and anomalies over one daily series. Plain HTML with
    inline styles, as on the other operator pages; the bars are divs so the
    page carries no chart dependency and the output is assertable.
--}}
@php
    $series = $this->series();
    $trend = $this->trend();
    $patterns = $this->patterns();
    $anomalies = $this->anomalies();
    $hasData = $this->hasData();

    $maxTokens = 0;
    foreach ($series as $day) {
        $maxTokens = max($maxTokens, (int) $day['tokens']);
    }

    $directionLabels = [
        'rising' => 'Rising',
        'falling' => 'Falling',
        'stable' => 'Stable',
        'insufficient' => 'Not enough data',
    ];

    $severityColors = [
        'high' => ['#991b1b', 'rgba(239, 68, 68, 0.15)'],
        'medium' => ['#92400e', 'rgba(245, 158, 11, 0.15)'],
        'low' => ['#1e40af', 'rgba(59, 130, 246, 0.15)'],
    ];
@endphp

<x-filament-panels::page>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(127, 127, 127, 0.25);">
        <label for="token-analytics-period" style="font-weight: 600;">Period</label>

        <select
            id="token-analytics-period"
            wire:model.live="period"
            style="padding: 0.35rem 0.5rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
        >
            @foreach ($this->periods() as $days)
                <option value="{{ $days }}" @selected($period === $days)>Last {{ $days }} days</option>
            @endforeach
        </select>

        <span style="opacity: 0.7;">{{ $this->windowLabel() }}</span>
    </div>

    @if (! $hasData)
        <p style="opacity: 0.8;">
            No token usage recorded in this window. Usage is written when a chat turn completes
            (Proposal 149 §81), so a deployment with no chats yet legitimately has nothing to analyse.
        </p>
    @else
        {{-- Trend --}}
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 0.75rem; margin-bottom: 1.5rem;">
            @foreach ([
                ['Trend', $directionLabels[$trend['direction']] ?? $trend['direction']],
                ['Confidence', number_format((float) $trend['confidence'], 1).'%'],
                ['Average daily tokens', number_format((float) $trend['average_daily'])],
                ['Projected (7d)', number_format((float) $trend['projected_7d'])],
            ] as $card)
                <div style="padding: 0.75rem 0.9rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.65;">{{ $card[0] }}</div>
                    <div style="font-size: 1.35rem; font-weight: 600; margin-top: 0.2rem;">{{ $card[1] }}</div>
                </div>
            @endforeach
        </div>

        @if ('insufficient' === $trend['direction'])
            <p style="opacity: 0.75; margin-bottom: 1.5rem;">
                A trend needs at least {{ \App\Support\Reports\TokenAnalyticsReport::MIN_TREND_DAYS }} days with usage;
                the projection is the current run rate (mean × 7), not a forecast.
            </p>
        @endif

        {{-- Daily bars --}}
        <div style="margin-bottom: 1.75rem;">
            <div style="font-weight: 600; margin-bottom: 0.5rem;">Daily tokens</div>

            <div style="display: flex; align-items: flex-end; gap: 2px; height: 120px; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                @foreach ($series as $date => $day)
                    @php
                        $tokens = (int) $day['tokens'];
                        $height = $maxTokens > 0 ? max(2, (int) round($tokens / $maxTokens * 116)) : 2;
                    @endphp
                    <div
                        title="{{ $date }}: {{ number_format($tokens) }} tokens"
                        style="flex: 1 1 0; height: {{ $height }}px; background: {{ $tokens > 0 ? 'rgba(37, 99, 235, 0.75)' : 'rgba(127, 127, 127, 0.25)' }}; border-radius: 1px 1px 0 0;"
                    ></div>
                @endforeach
            </div>

            <div style="display: flex; justify-content: space-between; font-size: 0.75rem; opacity: 0.6; margin-top: 0.25rem;">
                <span>{{ array_key_first($series) }}</span>
                <span>{{ array_key_last($series) }}</span>
            </div>
        </div>

        {{-- Patterns --}}
        <div style="margin-bottom: 1.75rem;">
            <div style="font-weight: 600; margin-bottom: 0.5rem;">Patterns</div>

            <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                <tbody>
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                        <th style="text-align: left; padding: 0.4rem 0.5rem; width: 12rem;">Peak hours</th>
                        <td style="padding: 0.4rem 0.5rem;">
                            {{ [] === $patterns['peak_hours'] ? '—' : implode(', ', array_map(static fn (int $h): string => sprintf('%02d:00', $h), $patterns['peak_hours'])) }}
                        </td>
                    </tr>
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                        <th style="text-align: left; padding: 0.4rem 0.5rem;">Peak days</th>
                        <td style="padding: 0.4rem 0.5rem;">{{ [] === $patterns['peak_days'] ? '—' : implode(', ', $patterns['peak_days']) }}</td>
                    </tr>
                    <tr>
                        <th style="text-align: left; padding: 0.4rem 0.5rem;">Usage pattern</th>
                        <td style="padding: 0.4rem 0.5rem;">
                            @switch($patterns['usage_type'])
                                @case('consistent') Consistent (predictable usage) @break
                                @case('sporadic') Sporadic (moderate variation) @break
                                @default Bursty (high variation)
                            @endswitch
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Anomalies --}}
        <div>
            <div style="font-weight: 600; margin-bottom: 0.5rem;">Anomalies</div>

            @if (null !== $anomalies['reason'])
                <p style="opacity: 0.75;">Nothing computed: {{ $anomalies['reason'] }}.</p>
            @elseif ([] === $anomalies['points'])
                <p style="opacity: 0.75;">No days above the anomaly threshold (z ≥ {{ \App\Support\Reports\TokenAnalyticsReport::ANOMALY_Z }}) in this window.</p>
            @else
                <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                            <th style="padding: 0.4rem 0.5rem;">Date</th>
                            <th style="padding: 0.4rem 0.5rem; text-align: right;">Tokens</th>
                            <th style="padding: 0.4rem 0.5rem; text-align: right;">Expected</th>
                            <th style="padding: 0.4rem 0.5rem; text-align: right;">Z-score</th>
                            <th style="padding: 0.4rem 0.5rem;">Severity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($anomalies['points'] as $point)
                            @php
                                [$foreground, $background] = $severityColors[$point['severity']] ?? ['#374151', 'rgba(127, 127, 127, 0.15)'];
                            @endphp
                            <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                                <td style="padding: 0.4rem 0.5rem;">{{ $point['date'] }}</td>
                                <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($point['tokens']) }}</td>
                                <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($point['expected']) }}</td>
                                <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($point['z'], 2) }}</td>
                                <td style="padding: 0.4rem 0.5rem;">
                                    <span style="padding: 0.1rem 0.45rem; border-radius: 999px; font-size: 0.75rem; color: {{ $foreground }}; background: {{ $background }};">{{ $point['severity'] }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <p style="margin-top: 0.75rem; opacity: 0.7; font-size: 0.85rem;">
                    Only spikes are listed. A quiet day is not a cost event; a flat window has no σ to score against.
                </p>
            @endif
        </div>
    @endif
</x-filament-panels::page>
