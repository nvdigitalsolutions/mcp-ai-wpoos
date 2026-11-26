{{--
    Compliance reports — Proposal 149 §34.

    This page IS the HTML report. The downloads serialise the same
    `ComplianceReport` the page renders, so there is no third renderer to keep in
    sync.

    Plain HTML with inline styles, as on the other operator pages.
--}}
@php
    $report = $this->report();
    $metadata = (array) ( $report['metadata'] ?? array() );
    $summary = (array) ( $report['summary'] ?? array() );
    $preview = $this->preview( $report );
    $totalAssets = (int) ( $summary['total_assets'] ?? 0 );
    $highest = (string) ( $summary['highest'] ?? '' );

    $badge = static function ( string $classification ): array {
        return match ( $classification ) {
            'restricted'   => array( '#991b1b', 'rgba(239, 68, 68, 0.15)' ),
            'confidential' => array( '#92400e', 'rgba(245, 158, 11, 0.15)' ),
            'internal'     => array( '#1e40af', 'rgba(59, 130, 246, 0.15)' ),
            default        => array( '#065f46', 'rgba(16, 185, 129, 0.15)' ),
        };
    };
@endphp

<x-filament-panels::page>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(127, 127, 127, 0.25);">
        <x-filament::button type="button" size="sm" wire:click="downloadCsv">
            Download CSV
        </x-filament::button>
        <x-filament::button type="button" color="gray" size="sm" wire:click="downloadJson">
            Download JSON
        </x-filament::button>
        <span style="font-size: 0.8rem; opacity: 0.8;">
            The CSV is sectioned (metadata, summary, assets, audit trail) like the WordPress export.
            PDF, DOCX and Excel are deliberately not offered: they need a rendering library this runtime
            does not ship, and the WordPress generator makes the same call.
        </span>
    </div>

    <h3 style="font-size: 0.95rem; margin: 0 0 0.5rem;">Summary</h3>

    <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
        <strong>{{ $totalAssets }}</strong> asset(s) in scope.
        Highest classification:
        @php( $highestColours = $badge( $highest ) )
        <strong style="color: {{ $highestColours[0] }};">
            {{ '' !== $highest ? $this->classificationLabel( $highest ) : 'none' }}
        </strong>.
        Every download records who exported the register; nothing is stored.
    </p>

    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
        @foreach ( $this->classifications() as $value => $label )
            <span style="display: inline-block; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.75rem; background: rgba(127, 127, 127, 0.12);">
                {{ $label }}: <strong>{{ (int) ( ( $summary['by_classification'] ?? array() )[ $value ] ?? 0 ) }}</strong>
            </span>
        @endforeach
    </div>

    <h3 style="font-size: 0.95rem; margin: 0 0 0.5rem;">Report metadata</h3>

    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-bottom: 1.25rem;">
        <tbody>
            @foreach ( $metadata as $key => $value )
                <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                    <th style="text-align: left; padding: 0.4rem 0.5rem 0.4rem 0; width: 12rem; vertical-align: top;">
                        <code style="font-size: 0.75rem;">{{ $key }}</code>
                    </th>
                    <td style="padding: 0.4rem 0.5rem;">{{ $value }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h3 style="font-size: 0.95rem; margin: 0 0 0.5rem;">Assets</h3>

    <p style="font-size: 0.8rem; margin-bottom: 0.75rem; opacity: 0.85;">
        Showing the first <strong>{{ $this->previewLimit() }}</strong> of
        <strong>{{ $totalAssets }}</strong>, highest classification first. The download carries every row.
    </p>

    @if ( array() === $preview )
        <p style="font-size: 0.85rem;">The register is empty, so there is nothing to report.</p>
    @else
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                    <th style="padding: 0.5rem 0.5rem 0.5rem 0;">Asset</th>
                    <th style="padding: 0.5rem;">Classification</th>
                    <th style="padding: 0.5rem;">Owner</th>
                    <th style="padding: 0.5rem;">Location</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $preview as $asset )
                    @php( $colours = $badge( (string) $asset['classification'] ) )
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15); vertical-align: top;">
                        <td style="padding: 0.5rem 0.5rem 0.5rem 0;">{{ $asset['name'] }}</td>
                        <td style="padding: 0.5rem;">
                            <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: {{ $colours[0] }}; background: {{ $colours[1] }};">
                                {{ $this->classificationLabel( (string) $asset['classification'] ) }}
                            </span>
                        </td>
                        <td style="padding: 0.5rem;">{{ $asset['owner'] }}</td>
                        <td style="padding: 0.5rem;"><code style="font-size: 0.75rem;">{{ $asset['location'] }}</code></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-filament-panels::page>
