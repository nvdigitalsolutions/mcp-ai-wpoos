{{--
    Security snapshots, operator-wide — Proposal 149 §171.

    A projection over `nvoos_security_snapshots` across every estate, never a
    live scan: the posture report, the header preview and the self-test are
    checks the console runs on demand, and the posture chart is Wave 6. Plain
    HTML with inline styles, as on the other log pages: the panel's compiled CSS
    has no arbitrary Tailwind utilities.

    Read-only. Creating and restoring a snapshot are console writes.
--}}
@php
    $rows = $this->rows();
    $total = $this->total();
    $estates = $this->estates();
    $limit = $this->limit();
    $detail = $this->detail;
@endphp

<x-filament-panels::page>
    <p style="font-size: 0.85rem; margin-bottom: 1rem;">
        A snapshot records <strong>one estate's settings</strong> at a point in time, so this page is
        cross-estate by design: the estate is a column, and the only way to see one estate alone is to
        read `/admin/security` from inside it. Sensitive values are masked when the snapshot is written
        and masked again on render.
        <strong>Read-only:</strong> creating and restoring are console actions; the posture report,
        header preview and self-test are live checks that also live in the console.
    </p>

    @if ( array() !== $estates )
        <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
            @foreach ( $estates as $estate => $count )
                <span style="display: inline-block; margin-right: 0.5rem; padding: 0.1rem 0.5rem; border-radius: 999px; background: rgba(127, 127, 127, 0.15); font-size: 0.75rem;">
                    {{ $estate }}: {{ $count }}
                </span>
            @endforeach
        </p>
    @endif

    <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
        Showing <strong>{{ count( $rows ) }}</strong> of <strong>{{ $total }}</strong> snapshot(s),
        newest first, across <strong>{{ count( $estates ) }}</strong> estate(s).
    </p>

    @if ( array() === $rows )
        <p style="font-size: 0.85rem;">No estate has saved a snapshot yet.</p>
    @else
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                    <th style="padding: 0.5rem 0.5rem 0.5rem 0;">Estate</th>
                    <th style="padding: 0.5rem;">Label</th>
                    <th style="padding: 0.5rem;">Recorded</th>
                    <th style="padding: 0.5rem;">Settings</th>
                    <th style="padding: 0.5rem;"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $rows as $row )
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15); vertical-align: top;">
                        <td style="padding: 0.5rem 0.5rem 0.5rem 0;">
                            <code style="font-size: 0.8rem;">{{ $row['estate'] }}</code>
                        </td>
                        <td style="padding: 0.5rem;">
                            {{ $row['label'] }}
                            <div style="font-size: 0.7rem; opacity: 0.7;"><code>{{ $row['snapshot_id'] }}</code></div>
                        </td>
                        <td style="padding: 0.5rem; white-space: nowrap;"><code style="font-size: 0.8rem;">{{ $row['created_at'] }}</code></td>
                        <td style="padding: 0.5rem;">{{ (int) $row['keys'] }}</td>
                        <td style="padding: 0.5rem;">
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
            </tbody>
        </table>
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
                        <div style="font-size: 0.8rem;">
                            Estate <code style="font-size: 0.8rem;">{{ $detail['estate'] }}</code>
                            · <code style="font-size: 0.8rem; opacity: 0.8;">{{ $detail['created_at'] }}</code>
                        </div>
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
                        row header, so a two-column `<thead>` would repeat what each
                        row already says. `scope="row"` is the correct label here.
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
