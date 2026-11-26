{{--
    Metrics — Proposal 155, `/ops/metrics` (§99).

    One table: the vocabulary, the figure, and — where this runtime cannot produce
    the figure — the reason. Plain HTML with inline styles, as on the other
    operator pages: the panel's compiled CSS has no arbitrary utilities.
--}}
@php
    $report = $this->report();
    $rows = $this->rows();
    $counts = $this->counts();
    $traces = $this->traces();
    $hasTraces = $this->hasTraces();

    $badge = static function ( string $state ): array {
        return match ( $state ) {
            'derived'    => array( '#065f46', 'rgba(16, 185, 129, 0.15)' ),
            'partial'    => array( '#92400e', 'rgba(245, 158, 11, 0.15)' ),
            default      => array( '#374151', 'rgba(107, 114, 128, 0.15)' ),
        };
    };
@endphp

<x-filament-panels::page>
    @unless ($hasTraces)
        <x-filament::callout
            color="info"
            icon="heroicon-o-information-circle"
            heading="No trace records in this window"
            description="The trace-derived rows are blank rather than zero, because “nothing recorded” and “nothing ran” are different facts. Trace capture is per assistant (trace_capture.enabled) and keeps a run count, not a time window. Token figures below come from the token table and are unaffected."
        />
    @endunless

    @if ((bool) ($traces['truncated'] ?? false))
        <x-filament::callout
            color="warning"
            icon="heroicon-o-exclamation-triangle"
            heading="Trace read was capped"
            description="The window holds more than {{ number_format( $this->scanLimit() ) }} trace records, so the newest {{ number_format( $this->scanLimit() ) }} were read. Trace-derived figures describe that slice, not the whole window."
        />
    @endif

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(127, 127, 127, 0.25);">
        <label for="metrics-period" style="font-weight: 600;">Period</label>

        <select
            id="metrics-period"
            wire:model.live="period"
            style="padding: 0.35rem 0.5rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
        >
            @foreach ($this->periods() as $days)
                <option value="{{ $days }}" @selected($period === $days)>Last {{ $days }} days</option>
            @endforeach
        </select>

        <span style="font-size: 0.8rem; opacity: 0.85;">{{ $this->windowLabel() }}</span>
    </div>

    <p style="font-size: 0.85rem; margin-bottom: 1rem; opacity: 0.85;">
        {{ $counts['total'] }} definitions: {{ $counts['derived'] }} derived, {{ $counts['partial'] }} partial,
        {{ $counts['unproduced'] }} not produced. A dash means this runtime cannot produce the figure —
        the note says what would. No value is ever inferred.
    </p>

    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                <th style="padding: 0.5rem 0.5rem 0.5rem 0;">Metric</th>
                <th style="padding: 0.5rem;">Value</th>
                <th style="padding: 0.5rem;">Unit</th>
                <th style="padding: 0.5rem;">Direction</th>
                <th style="padding: 0.5rem;">State</th>
                <th style="padding: 0.5rem;">Source</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                @php
                    [ $badgeColor, $badgeBackground ] = $badge( (string) $row['state'] );
                @endphp
                <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                    <td style="padding: 0.5rem 0.5rem 0.5rem 0;">
                        <div>{{ $row['label'] }}</div>
                        <code style="font-size: 0.75rem; opacity: 0.75;">{{ $row['id'] }}</code>
                        <div style="font-size: 0.75rem; opacity: 0.75; margin-top: 0.15rem;">{{ $row['note'] }}</div>
                    </td>
                    <td style="padding: 0.5rem; font-variant-numeric: tabular-nums; white-space: nowrap;">
                        {{ $this->formatValue( $row ) }}
                    </td>
                    <td style="padding: 0.5rem;">{{ $row['unit'] }}</td>
                    <td style="padding: 0.5rem;">{{ $row['direction'] }}</td>
                    <td style="padding: 0.5rem;">
                        <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: {{ $badgeColor }}; background: {{ $badgeBackground }};">
                            {{ $row['state'] }}
                        </span>
                    </td>
                    <td style="padding: 0.5rem;"><code style="font-size: 0.75rem;">{{ $row['source'] }}</code></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p style="font-size: 0.8rem; margin-top: 1rem; opacity: 0.85;">
        {{ number_format( (int) ( $traces['tools'] ?? 0 ) ) }} tool executions and
        {{ number_format( (int) ( $traces['runs'] ?? 0 ) ) }} run records were read from
        {{ number_format( (int) ( $traces['records'] ?? 0 ) ) }} trace records in this window.
        Values are cross-estate: this is the operator's view. An estate's own spend is on its Spend page.
    </p>
</x-filament-panels::page>
