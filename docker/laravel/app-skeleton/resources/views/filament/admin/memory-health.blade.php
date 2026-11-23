{{--
    Agent-memory health — Proposal 149 §182.

    Read-only. Grades the store rather than browsing it: `/admin/memory` shows what
    an assistant stored; this shows whether the store is healthy.

    The empty state is a callout, not a green verdict. WordPress's memory view uses
    a percentage of a limit that this runtime cannot observe across requests; the
    portable half is Pro's retention stats, and "no data" is not "healthy".

    Plain HTML with inline styles, as on the other ported pages.
--}}
@php
    $summary = $this->summary();
    $verdict = $this->verdict();
    $window = $this->dormancyDays();

    $tone = match ($verdict) {
        'critical' => ['#b91c1c', 'rgba(220, 38, 38, 0.10)'],
        'warn' => ['#92400e', 'rgba(245, 158, 11, 0.14)'],
        'ok' => ['#166534', 'rgba(34, 197, 94, 0.12)'],
        default => ['#1e40af', 'rgba(59, 130, 246, 0.12)'],
    };

    $explanation = match ($verdict) {
        'critical' => 'Most of this store is either expired or untouched past the dormancy window. Expired rows can never be recalled; dormant ones are retrieved only by a deliberate search.',
        'warn' => 'Some rows have expired and await a sweep, or a majority of live rows are past the dormancy window.',
        'ok' => 'Live rows dominate and nothing has expired.',
        default => 'There are no memories in this estate, so there is nothing to grade — this is not a clean bill of health.',
    };
@endphp

<x-filament-panels::page>
    <div style="padding: 1rem 1.15rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem; background: {{ $tone[1] }}; margin-bottom: 1.25rem;">
        <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.75;">Verdict</div>
        <div style="font-size: 1.5rem; font-weight: 700; color: {{ $tone[0] }}; line-height: 1.2;">{{ $this->verdictLabel() }}</div>
        <p style="font-size: 0.85rem; margin: 0.35rem 0 0;">{{ $explanation }}</p>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem;">
        @foreach ([
            ['Memories', number_format($summary['total']), 'in this estate'],
            ['Live', number_format($summary['live']), 'still retrievable'],
            ['Dormant', $summary['live'] > 0 ? $summary['dormant_pct'].'%' : '—', number_format($summary['dormant']).' untouched for '.$window.' days'],
            ['Expired', $summary['total'] > 0 ? $summary['expired_pct'].'%' : '—', number_format($summary['expired']).' awaiting a sweep'],
            ['Expiring ≤ '.$this->expiringSoonDays().' days', number_format($summary['expiring_soon']), 'worth renewing'],
            ['Content', $this->humanBytes($summary['bytes']), 'in memory bodies'],
        ] as [$label, $value, $note])
            <div style="padding: 0.9rem 1rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem;">
                <div style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.75;">{{ $label }}</div>
                <div style="font-size: 1.45rem; font-weight: 600; line-height: 1.2; font-variant-numeric: tabular-nums; margin: 0.15rem 0;">{{ $value }}</div>
                <div style="font-size: 0.75rem; opacity: 0.7;">{{ $note }}</div>
            </div>
        @endforeach
    </div>

    <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; font-size: 0.85rem; padding: 0.9rem 1rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem; margin-bottom: 1.25rem;">
        <div>
            <strong>Growth ({{ $this->growthDays() }} days)</strong>
            <div style="opacity: 0.85;">{{ $this->growthLabel($summary) }}</div>
        </div>
        <div>
            <strong>Oldest memory</strong>
            <div style="opacity: 0.85;">{{ $this->humanDate($summary['oldest']) }}</div>
        </div>
        <div>
            <strong>Dormancy window</strong>
            <div style="opacity: 0.85;">{{ $window }} days · <a href="{{ url('/admin/settings') }}" style="text-decoration: underline;">change under Settings → Orchestration</a></div>
        </div>
    </div>

    @if ([] !== $summary['agents'])
        <x-admin.data-table
            caption="The five agents holding the most memories in this estate"
            :columns="[['label' => 'Agent'], ['label' => 'Memories']]"
            :rows="count($summary['agents'])"
            :empty="'No agent has written a memory.'"
        >
            @foreach ($summary['agents'] as $agent)
                <tr>
                    <td style="overflow-wrap: anywhere;">{{ $agent['agent_id'] }}</td>
                    <td style="font-variant-numeric: tabular-nums; white-space: nowrap;">{{ number_format($agent['total']) }}</td>
                </tr>
            @endforeach
        </x-admin.data-table>
    @endif

    <p style="font-size: 0.8rem; opacity: 0.75; margin-top: 1.25rem; padding-top: 0.75rem; border-top: 1px solid rgba(127, 127, 127, 0.25);">
        This estate only. Expired rows are hidden from every retrieval path but nothing
        sweeps them, which is why the count is reported rather than ignored. The
        WordPress view's headline — PHP process memory against a percentage of
        <code>memory_limit</code> — is not reproduced: an FPM request would be measuring
        the page that reports it, and this runtime keeps no cross-request gauge. Retention
        days and per-user caps are not shown because nothing here enforces them.
    </p>
</x-filament-panels::page>
