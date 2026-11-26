{{--
    Information-asset register — Proposal 149 §33.

    Plain HTML with inline styles, as on the other operator pages.

    No create, edit or delete control: the register is derived from what the
    runtime contains, so there is nothing here to edit. An editable row would
    immediately stop describing reality.
--}}
@php
    $all = $this->register();
    $assets = $this->assets();
    $stats = $this->statistics( $assets );
    $classifications = $this->classifications();

    $badge = static function ( string $classification ): array {
        return match ( $classification ) {
            'restricted'   => array( '#991b1b', 'rgba(239, 68, 68, 0.15)' ),
            'confidential' => array( '#92400e', 'rgba(245, 158, 11, 0.15)' ),
            'internal'     => array( '#1e40af', 'rgba(59, 130, 246, 0.15)' ),
            default        => array( '#065f46', 'rgba(16, 185, 129, 0.15)' ),
        };
    };

    $labelStyle = 'display: block; font-size: 0.8rem; margin-bottom: 0.25rem;';
    $fieldStyle = 'width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;';
@endphp

<x-filament-panels::page>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
        <div style="flex: 1 1 18rem;">
            <label for="asset-search" style="{{ $labelStyle }}">Search</label>
            <input
                id="asset-search"
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Name, type, owner or location"
                style="{{ $fieldStyle }}"
            >
        </div>

        <div style="flex: 0 1 12rem;">
            <label for="asset-classification" style="{{ $labelStyle }}">Classification</label>
            <select id="asset-classification" wire:model.live="classification" style="{{ $fieldStyle }}">
                <option value="">All levels</option>
                @foreach ( $classifications as $value => $label )
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div style="padding-bottom: 0.15rem;">
            <x-filament::button type="button" color="gray" size="sm" wire:click="clearFilters">
                Clear
            </x-filament::button>
        </div>
    </div>

    <p style="font-size: 0.85rem; margin-bottom: 0.5rem;">
        Showing <strong>{{ $stats['total'] }}</strong> of <strong>{{ count( $all ) }}</strong> asset(s).
        Highest classification in this view:
        @php( $highestColours = $badge( $stats['highest'] ) )
        <strong style="color: {{ $highestColours[0] }};">{{ '' !== $stats['highest'] ? $this->classificationLabel( $stats['highest'] ) : 'none' }}</strong>.
    </p>

    <p style="font-size: 0.8rem; margin-bottom: 1rem; opacity: 0.85;">
        Derived on every load from what this deployment actually contains — tables and row counts,
        configured credentials (presence only), registered providers, shipped packages and contracts.
        Nothing is stored, and no credential value is recorded or displayed.
    </p>

    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem;">
        @foreach ( $classifications as $value => $label )
            <span style="display: inline-block; padding: 0.15rem 0.6rem; border-radius: 999px; font-size: 0.75rem; background: rgba(127, 127, 127, 0.12);">
                {{ $label }}: <strong>{{ (int) ( $stats['by_classification'][ $value ] ?? 0 ) }}</strong>
            </span>
        @endforeach
    </div>

    @if ( array() === $assets )
        <p style="font-size: 0.85rem;">No asset matches that filter.</p>
    @else
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                    <th style="padding: 0.5rem 0.5rem 0.5rem 0;">Asset</th>
                    <th style="padding: 0.5rem;">Classification</th>
                    <th style="padding: 0.5rem;">Type</th>
                    <th style="padding: 0.5rem;">Owner</th>
                    <th style="padding: 0.5rem;">Location</th>
                    <th style="padding: 0.5rem;">Detail</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $assets as $asset )
                    @php( $colours = $badge( (string) $asset['classification'] ) )
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15); vertical-align: top;">
                        <td style="padding: 0.5rem 0.5rem 0.5rem 0;">
                            <div>{{ $asset['name'] }}</div>
                            <code style="font-size: 0.75rem; opacity: 0.75;">{{ $asset['id'] }}</code>
                        </td>
                        <td style="padding: 0.5rem;">
                            <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: {{ $colours[0] }}; background: {{ $colours[1] }};">
                                {{ $this->classificationLabel( (string) $asset['classification'] ) }}
                            </span>
                        </td>
                        <td style="padding: 0.5rem;">{{ $this->typeLabel( (string) $asset['type'] ) }}</td>
                        <td style="padding: 0.5rem;">{{ $asset['owner'] }}</td>
                        <td style="padding: 0.5rem;"><code style="font-size: 0.75rem;">{{ $asset['location'] }}</code></td>
                        <td style="padding: 0.5rem;">{{ $asset['description'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-filament-panels::page>
