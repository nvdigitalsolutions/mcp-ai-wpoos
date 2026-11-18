{{--
    Security snapshots — Proposal 149 §51.

    A projection over `nvoos_security_snapshots`, never a live scan: the posture
    report, the header preview and the self-test are checks the console runs on
    demand. Read-only — creating and restoring are console writes.
--}}
@php
    $rows = $this->snapshots();
    $total = $this->total();
    $detail = $this->detail;
@endphp

<x-filament-panels::page>
    <p style="font-size: 0.85rem; margin-bottom: 1rem;">
        A snapshot records this estate's settings at a point in time. Sensitive values are
        replaced with a mask when the snapshot is written and are skipped on restore, so a
        snapshot can never bring a credential back.
        <strong>Read-only:</strong> creating and restoring are console actions; the posture
        report and self-test are live checks and also live in the console.
    </p>

    <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
        Showing <strong>{{ count( $rows ) }}</strong> of <strong>{{ $total }}</strong> snapshot(s), newest first.
    </p>

    @if ( array() === $rows )
        <p style="font-size: 0.85rem; opacity: 0.8;">
            No snapshot has been saved for this estate yet.
        </p>
    @else
        <x-admin.data-table
            caption="Security posture snapshots recorded for this estate"
            :columns="[
                ['label' => 'Label'],
                ['label' => 'Recorded'],
                ['label' => 'Settings recorded'],
                ['srLabel' => 'Actions'],
            ]"
        >
            @foreach ( $rows as $row )
                <tr>
                    <td>
                        {{ $row['label'] }}
                        <div style="font-size: 0.7rem; opacity: 0.7;"><code>{{ $row['snapshot_id'] }}</code></div>
                    </td>
                    <td><code style="font-size: 0.8rem;">{{ $row['created_at'] }}</code></td>
                    <td>{{ (int) $row['keys'] }}</td>
                    <td>
                        <x-filament::button
                            type="button"
                            color="gray"
                            size="sm"
                            wire:click="open({{ \Illuminate\Support\Js::from($row['snapshot_id']) }})"
                        >
                            View
                        </x-filament::button>
                    </td>
                </tr>
            @endforeach
        </x-admin.data-table>
    @endif

    @if ( null !== $detail )
        <div style="margin-top: 1.5rem; padding: 1rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.3);">
            @if ( isset( $detail['error'] ) )
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <strong>{{ $detail['error'] }}</strong>
                    <x-filament::button type="button" color="gray" size="sm" wire:click="close">Close</x-filament::button>
                </div>
            @else
                <div style="display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start;">
                    <div>
                        <h3 style="margin: 0 0 0.25rem;">{{ $detail['label'] }}</h3>
                        <code style="font-size: 0.8rem; opacity: 0.8;">{{ $detail['created_at'] }}</code>
                        <div style="font-size: 0.8rem; margin-top: 0.25rem;">
                            {{ count( $detail['rows'] ) }} setting(s) recorded,
                            {{ (int) $detail['masked_count'] }} masked.
                        </div>
                    </div>
                    <x-filament::button type="button" color="gray" size="sm" wire:click="close">Close</x-filament::button>
                </div>

                @if ( array() === $detail['rows'] )
                    <p style="font-size: 0.85rem; margin-top: 0.75rem; opacity: 0.8;">This snapshot recorded no settings.</p>
                @else
                    {{--
                        A definition table, not a data table: the setting name is a
                        row header, so a two-column `<thead>` repeated what each
                        row already said. `scope="row"` is the correct label here.
                    --}}
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 0.75rem;">
                        <tbody>
                            @foreach ( $detail['rows'] as $row )
                                <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15); vertical-align: top;">
                                    <th scope="row" style="padding: 0.4rem 0.5rem 0.4rem 0; width: 18rem; text-align: left; font-weight: 500;">
                                        <code style="font-size: 0.8rem;">{{ $row['key'] }}</code>
                                    </th>
                                    <td style="padding: 0.4rem 0.5rem;">
                                        <code style="font-size: 0.8rem;">{{ $row['value'] }}</code>
                                        @if ( $row['masked'] )
                                            <span style="font-size: 0.7rem; opacity: 0.7;">masked — not restorable</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endif
        </div>
    @endif
</x-filament-panels::page>
