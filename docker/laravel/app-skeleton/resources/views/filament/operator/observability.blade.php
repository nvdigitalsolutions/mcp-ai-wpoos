{{--
    Observability — Proposal 149 §84.

    Recent harness runs and the export gauge. Read-only, plain HTML with inline
    styles, as on the other operator pages.
--}}
@php
    $captureEnabled = $this->captureEnabled();
    $runs = $this->runs();
    $export = $this->exportState();

    $statusColors = [
        'completed' => ['#065f46', 'rgba(16, 185, 129, 0.15)'],
        'aborted' => ['#92400e', 'rgba(245, 158, 11, 0.15)'],
        'incomplete' => ['#991b1b', 'rgba(239, 68, 68, 0.15)'],
    ];
@endphp

<x-filament-panels::page>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(127, 127, 127, 0.25);">
        <span style="font-weight: 600;">Run capture</span>
        <span style="padding: 0.1rem 0.45rem; border-radius: 999px; font-size: 0.75rem; color: {{ $captureEnabled ? '#065f46' : '#991b1b' }}; background: {{ $captureEnabled ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)' }};">
            {{ $captureEnabled ? 'enabled' : 'disabled' }}
        </span>

        <span style="margin-left: 1rem; font-weight: 600;">OTLP export</span>
        <span style="opacity: 0.8;">
            {{ $export['status'] }}@if ($export['configured']) &middot; watermark {{ number_format($export['watermark']) }}@endif
        </span>

        @if ($export['backing_off'] && null !== $export['retry_after'])
            <span style="padding: 0.1rem 0.45rem; border-radius: 999px; font-size: 0.75rem; color: #92400e; background: rgba(245, 158, 11, 0.15);">
                backing off &middot; retry after {{ $export['retry_after']->toDateTimeString() }}
            </span>
        @endif
    </div>

    @if (! $captureEnabled)
        <p style="opacity: 0.8; margin-bottom: 1rem;">
            Trace capture is off (<code>oos.security.trace_capture</code>), so no runs are recorded.
            Turning it on starts writing one row per trace record; per-assistant
            <code>trace_capture.enabled</code> still gates each assistant.
        </p>
    @elseif ([] === $runs)
        <p style="opacity: 0.8; margin-bottom: 1rem;">
            No runs recorded yet. Capture is on, but an assistant opts in with
            <code>trace_capture.enabled</code> in its harness profile — a runtime with the store wired
            and the profile off writes nothing.
        </p>
    @else
        <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                    <th style="padding: 0.4rem 0.5rem;">Started</th>
                    <th style="padding: 0.4rem 0.5rem;">Estate</th>
                    <th style="padding: 0.4rem 0.5rem;">Assistant</th>
                    <th style="padding: 0.4rem 0.5rem;">Status</th>
                    <th style="padding: 0.4rem 0.5rem;">Run</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Calls</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Duration</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Cost (USD)</th>
                    <th style="padding: 0.4rem 0.5rem; text-align: right;">Records</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($runs as $run)
                    @php
                        [$foreground, $background] = $statusColors[$run['status']] ?? ['#374151', 'rgba(127, 127, 127, 0.15)'];
                    @endphp
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                        <td style="padding: 0.4rem 0.5rem;">{{ $run['started_at'] }}</td>
                        <td style="padding: 0.4rem 0.5rem;">{{ $run['estate'] }}</td>
                        <td style="padding: 0.4rem 0.5rem;">{{ $run['assistant'] }}</td>
                        <td style="padding: 0.4rem 0.5rem;">
                            <span style="padding: 0.1rem 0.45rem; border-radius: 999px; font-size: 0.75rem; color: {{ $foreground }}; background: {{ $background }};">{{ $run['status'] }}</span>
                        </td>
                        <td style="padding: 0.4rem 0.5rem; font-family: ui-monospace, monospace; font-size: 0.8rem; opacity: 0.75;">{{ $run['run_id'] }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ null === $run['provider_calls'] ? '—' : number_format($run['provider_calls']) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ null === $run['duration_ms'] ? '—' : number_format($run['duration_ms']).' ms' }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ null === $run['cost_usd'] ? '—' : number_format($run['cost_usd'], 4) }}</td>
                        <td style="padding: 0.4rem 0.5rem; text-align: right;">{{ number_format($run['records']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p style="margin-top: 0.75rem; opacity: 0.7; font-size: 0.85rem;">
            The newest {{ \App\Support\Observability\TraceRuns::LIMIT }} runs, one row per chat turn.
            <strong>Incomplete</strong> means the run has no <code>end</code> record — it died, or its
            records were pruned mid-run. Records carry identifiers and timings, never message text.
            The estate is read from the assistant that produced the run, not from the request, so a
            run cannot be attributed to an estate its assistant does not belong to.
        </p>
    @endif

    <p style="margin-top: 1rem; font-size: 0.85rem; opacity: 0.75;">
        Aggregate probes live on
        <a href="{{ \App\Filament\Operator\Pages\DiagnosticsPage::getUrl() }}" style="text-decoration: underline;">Diagnostics</a>;
        spend over time lives on
        <a href="{{ \App\Filament\Operator\Pages\TokenAnalytics::getUrl() }}" style="text-decoration: underline;">Token analytics</a>.
    </p>
</x-filament-panels::page>
