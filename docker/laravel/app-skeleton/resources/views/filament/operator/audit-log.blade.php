{{--
    Admin audit trail — Proposal 149 §25.

    Plain HTML with inline styles, as on the other log pages: the panel's
    compiled CSS has no arbitrary Tailwind utilities.

    No action of any kind. The table is append-only and the contract declares
    `writer: none`; a retry/delete control here would be the one thing that
    turns evidence into a mutable table.
--}}
@php
    $rows = $this->rows();
    $totals = $this->totals( $rows );
    $panels = $this->panelOptions();
    $estates = $this->estateOptions();
    $limit = $this->limit();

    $selectStyle = 'width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;';
    $labelStyle = 'display: block; font-size: 0.8rem; margin-bottom: 0.25rem;';
@endphp

<x-filament-panels::page>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
        <div style="flex: 1 1 16rem;">
            <label for="audit-search" style="{{ $labelStyle }}">Search</label>
            <input
                id="audit-search"
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Path, resource, action or record id"
                style="{{ $selectStyle }}"
            >
        </div>

        <div style="flex: 0 1 10rem;">
            <label for="audit-panel" style="{{ $labelStyle }}">Panel</label>
            <select id="audit-panel" wire:model.live="panel" style="{{ $selectStyle }}">
                <option value="">All panels</option>
                @foreach ( $panels as $value => $label )
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div style="flex: 0 1 12rem;">
            <label for="audit-estate" style="{{ $labelStyle }}">Estate</label>
            <select id="audit-estate" wire:model.live="estate" style="{{ $selectStyle }}">
                <option value="">All estates</option>
                @foreach ( $estates as $value => $label )
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div style="flex: 0 1 10rem;">
            <label for="audit-kind" style="{{ $labelStyle }}">Kind</label>
            <select id="audit-kind" wire:model.live="kind" style="{{ $selectStyle }}">
                <option value="">Both</option>
                <option value="record">Record change</option>
                <option value="request">Request</option>
            </select>
        </div>

        <div style="flex: 0 1 9rem;">
            <label for="audit-status" style="{{ $labelStyle }}">Outcome</label>
            <select id="audit-status" wire:model.live="status" style="{{ $selectStyle }}">
                <option value="">Any</option>
                <option value="ok">Accepted</option>
                <option value="refused">Refused</option>
            </select>
        </div>

        <div style="padding-bottom: 0.15rem;">
            <x-filament::button type="button" color="gray" size="sm" wire:click="clearFilters">
                Clear
            </x-filament::button>
        </div>
    </div>

    <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
        Showing <strong>{{ $totals['total'] }}</strong> of the newest {{ $limit }} row(s);
        <strong>{{ $totals['record'] }}</strong> record change(s),
        <strong>{{ $totals['refused'] }}</strong> refused,
        across <strong>{{ $totals['estates'] }}</strong> estate value(s).
        Append-only: nothing on this page writes.
    </p>

    @if ( array() === $rows )
        <p style="font-size: 0.85rem;">No audit row matches that filter.</p>
    @else
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                    <th style="padding: 0.5rem 0.5rem 0.5rem 0;">When</th>
                    <th style="padding: 0.5rem;">Actor</th>
                    <th style="padding: 0.5rem;">Panel</th>
                    <th style="padding: 0.5rem;">Estate</th>
                    <th style="padding: 0.5rem;">What</th>
                    <th style="padding: 0.5rem;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $rows as $row )
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15); vertical-align: top;">
                        <td style="padding: 0.5rem 0.5rem 0.5rem 0; white-space: nowrap;">{{ '' !== $row['at'] ? $row['at'] : '—' }}</td>
                        <td style="padding: 0.5rem;">{{ $row['actor'] }}</td>
                        <td style="padding: 0.5rem;">{{ $row['panel'] }}</td>
                        <td style="padding: 0.5rem;">{{ '' !== $row['estate'] ? $row['estate'] : 'operator' }}</td>
                        <td style="padding: 0.5rem;">
                            <code style="font-size: 0.75rem;">{{ $row['label'] }}</code>
                            @if ( $row['has_diff'] )
                                <div style="font-size: 0.7rem; opacity: 0.7;">diff recorded</div>
                            @endif
                        </td>
                        <td style="padding: 0.5rem;">
                            @if ( (int) $row['status'] >= 400 )
                                <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: #991b1b; background: rgba(239, 68, 68, 0.15);">{{ (int) $row['status'] }}</span>
                            @else
                                <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: #065f46; background: rgba(16, 185, 129, 0.15);">{{ (int) $row['status'] }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-filament-panels::page>
