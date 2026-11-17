{{--
    Estate credentials — Proposal 149 §24.

    Plain HTML with inline styles, as on the other reference pages: the panel's
    compiled CSS has no arbitrary Tailwind utilities.

    Read-and-revoke only. There is no issue/rotate control here because that is
    the operator flow (/ops/credentials); this page is the estate-scoped subset
    the contract declares.

    Revoking is two-step. The armed row's button becomes "Confirm revoke" and
    only that second click deletes; there is no undo, because revocation *is*
    deletion and Sanctum re-reads the table on every request.
--}}
@php
    $overview = $this->overview();
    $stats = $overview['stats'];
    $credentials = $overview['credentials'];
@endphp

<x-filament-panels::page>
    @if ( '' !== $this->status )
        <div style="margin-bottom: 1rem; padding: 0.6rem 0.85rem; border-radius: 0.5rem; font-size: 0.85rem; background: rgba(59, 130, 246, 0.12); border: 1px solid rgba(59, 130, 246, 0.35);">
            {{ $this->status }}
        </div>
    @endif

    <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
        <strong>{{ $stats['total'] }}</strong> credential(s) across
        <strong>{{ $stats['assistants'] }}</strong> assistant(s);
        <strong>{{ $stats['active'] }}</strong> active,
        <strong>{{ $stats['revoked'] }}</strong> expired.
        Scoped to this estate only. The token itself is never shown, because it is
        never read.
    </p>

    @if ( array() === $credentials )
        <p style="font-size: 0.85rem;">
            This estate's assistants hold no credentials. Credentials are issued by NV Digital
            support; this page lists and revokes them.
        </p>
    @else
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                    <th style="padding: 0.5rem 0.5rem 0.5rem 0;">Assistant</th>
                    <th style="padding: 0.5rem;">Status</th>
                    <th style="padding: 0.5rem;">Issued by</th>
                    <th style="padding: 0.5rem;">Created</th>
                    <th style="padding: 0.5rem;">Expires</th>
                    <th style="padding: 0.5rem;">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $credentials as $credential )
                    @php( $armed = (string) $credential['id'] === $this->pendingRevoke )
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15); vertical-align: top;">
                        <td style="padding: 0.5rem 0.5rem 0.5rem 0;">
                            <div>{{ '' !== (string) $credential['assistant_title'] ? $credential['assistant_title'] : 'Assistant #'.(int) $credential['assistant_id'] }}</div>
                            <code style="font-size: 0.75rem; opacity: 0.75;">#{{ (int) $credential['assistant_id'] }} · credential {{ $credential['id'] }}</code>
                        </td>
                        <td style="padding: 0.5rem;">
                            @if ( 'active' === (string) $credential['status'] )
                                <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: #065f46; background: rgba(16, 185, 129, 0.15);">active</span>
                            @else
                                <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: #991b1b; background: rgba(239, 68, 68, 0.15);">expired</span>
                            @endif
                        </td>
                        <td style="padding: 0.5rem;">{{ '' !== (string) $credential['created_by_name'] ? $credential['created_by_name'] : 'user #'.(int) $credential['created_by'] }}</td>
                        <td style="padding: 0.5rem;">{{ '' !== (string) $credential['created_at'] ? $credential['created_at'] : '—' }}</td>
                        <td style="padding: 0.5rem;">{{ '' !== (string) $credential['expires_at'] ? $credential['expires_at'] : 'never' }}</td>
                        <td style="padding: 0.5rem;">
                            @if ( $armed )
                                <x-filament::button type="button" color="danger" size="sm" wire:click="revoke">
                                    Confirm revoke
                                </x-filament::button>
                                <x-filament::button type="button" color="gray" size="sm" wire:click="cancelRevoke" style="margin-top: 0.35rem;">
                                    Cancel
                                </x-filament::button>
                            @else
                                <x-filament::button
                                    type="button"
                                    color="gray"
                                    size="sm"
                                    wire:click="confirmRevoke('{{ $credential['id'] }}', {{ (int) $credential['assistant_id'] }})"
                                >
                                    Revoke
                                </x-filament::button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-filament-panels::page>
