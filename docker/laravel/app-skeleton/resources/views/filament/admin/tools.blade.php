{{--
    Tool catalogue — Proposal 149 §22.

    Plain HTML controls with inline styles: the panel's compiled CSS has no
    arbitrary Tailwind utilities, and a missing class here would break the one
    thing this page does (filter a long list). Livewire owns the state; the
    filtering itself happens server-side in the page class.
--}}
@php
    $rows = $this->rows();
    $summary = $this->summary( $rows );
    $options = $this->readinessOptions();

    $badge = static function ( string $readiness ): array {
        return match ( $readiness ) {
            'ready'       => array( '#065f46', 'rgba(16, 185, 129, 0.15)' ),
            'pure'        => array( '#1e40af', 'rgba(59, 130, 246, 0.15)' ),
            'delegated'   => array( '#5b21b6', 'rgba(139, 92, 246, 0.15)' ),
            'unverified'  => array( '#92400e', 'rgba(245, 158, 11, 0.15)' ),
            'quarantined' => array( '#991b1b', 'rgba(239, 68, 68, 0.15)' ),
            'unavailable' => array( '#374151', 'rgba(107, 114, 128, 0.15)' ),
            default       => array( '#374151', 'rgba(107, 114, 128, 0.15)' ),
        };
    };
@endphp

<x-filament-panels::page>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
        <div style="flex: 1 1 18rem;">
            <label for="tool-search" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Search</label>
            <input
                id="tool-search"
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Slug, name or description"
                style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
            >
        </div>

        <div style="flex: 0 1 12rem;">
            <label for="tool-readiness" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Readiness</label>
            <select
                id="tool-readiness"
                wire:model.live="readiness"
                style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
            >
                <option value="">All classes</option>
                @foreach ( $options as $value => $label )
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

    <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
        Showing <strong>{{ $summary['total'] }}</strong> tool(s);
        <strong>{{ $summary['servable'] }}</strong> servable by this runtime.
        Read-only: the catalogue is identical on the console, and nothing here writes to it.
    </p>

    @if ( array() === $rows )
        <p style="font-size: 0.85rem;">No tool matches that filter.</p>
    @else
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                    <th style="padding: 0.5rem 0.5rem 0.5rem 0;">Tool</th>
                    <th style="padding: 0.5rem;">Readiness</th>
                    <th style="padding: 0.5rem;">Capability</th>
                    <th style="padding: 0.5rem;">Description</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $rows as $row )
                    @php( $colours = $badge( (string) $row['readiness'] ) )
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15); vertical-align: top;">
                        <td style="padding: 0.5rem 0.5rem 0.5rem 0;">
                            <div>{{ $row['name'] }}</div>
                            <code style="font-size: 0.75rem; opacity: 0.75;">{{ $row['slug'] }}</code>
                        </td>
                        <td style="padding: 0.5rem;">
                            <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: {{ $colours[0] }}; background: {{ $colours[1] }};">
                                {{ $row['readiness'] }}
                            </span>
                            @unless ( $this->isServable( (string) $row['readiness'] ) )
                                <div style="font-size: 0.7rem; opacity: 0.7; margin-top: 0.15rem;">not served</div>
                            @endunless
                        </td>
                        <td style="padding: 0.5rem;">
                            <code style="font-size: 0.75rem;">{{ $row['required_capability'] }}</code>
                        </td>
                        <td style="padding: 0.5rem;">{{ $row['description'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-filament-panels::page>
