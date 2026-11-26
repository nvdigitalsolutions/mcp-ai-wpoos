{{--
    Runtime health — Proposal 149 §31.

    Plain HTML with inline styles, as on the other operator pages.

    No action of any kind: the probes run on every load and nothing is saved.
    A "re-check" button would be a place where the page briefly shows something
    other than the truth.
--}}
@php
    $probes = $this->probes();
    $totals = $this->totals( $probes );
    $overall = $this->overall( $probes );

    $badge = static function ( string $status ): array {
        return match ( $status ) {
            'good'        => array( '#065f46', 'rgba(16, 185, 129, 0.15)' ),
            'recommended' => array( '#92400e', 'rgba(245, 158, 11, 0.15)' ),
            'critical'    => array( '#991b1b', 'rgba(239, 68, 68, 0.15)' ),
            default       => array( '#374151', 'rgba(107, 114, 128, 0.15)' ),
        };
    };
@endphp

<x-filament-panels::page>
    @php( $overallColours = $badge( $overall ) )
    <div style="margin-bottom: 1rem; padding: 0.75rem 1rem; border-radius: 0.5rem; background: {{ $overallColours[1] }}; border: 1px solid {{ $overallColours[0] }}33;">
        <strong style="color: {{ $overallColours[0] }};">{{ ucfirst( $overall ) }}</strong>
        — <strong>{{ $totals['good'] }}</strong> good,
        <strong>{{ $totals['recommended'] }}</strong> recommended,
        <strong>{{ $totals['critical'] }}</strong> critical,
        of <strong>{{ $totals['total'] }}</strong> probe(s).
        Re-run on every load; nothing on this page is stored.
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                <th style="padding: 0.5rem 0.5rem 0.5rem 0;">Probe</th>
                <th style="padding: 0.5rem;">Status</th>
                <th style="padding: 0.5rem;">Observed</th>
                <th style="padding: 0.5rem;">What it means</th>
            </tr>
        </thead>
        <tbody>
            @foreach ( $probes as $probe )
                @php( $colours = $badge( (string) $probe['status'] ) )
                <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15); vertical-align: top;">
                    <td style="padding: 0.5rem 0.5rem 0.5rem 0;">
                        <div>{{ $probe['label'] }}</div>
                        <code style="font-size: 0.75rem; opacity: 0.75;">{{ $probe['id'] }}</code>
                    </td>
                    <td style="padding: 0.5rem;">
                        <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: {{ $colours[0] }}; background: {{ $colours[1] }};">
                            {{ $probe['status'] }}
                        </span>
                    </td>
                    <td style="padding: 0.5rem;"><code style="font-size: 0.75rem;">{{ $probe['summary'] }}</code></td>
                    <td style="padding: 0.5rem;">{{ $probe['detail'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-filament-panels::page>
