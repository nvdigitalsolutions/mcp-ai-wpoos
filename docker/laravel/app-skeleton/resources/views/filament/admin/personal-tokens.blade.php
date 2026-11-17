{{--
    Personal access tokens — Proposal 149 §26.

    The plaintext block is the one thing on this page that must be handled
    carefully: it is shown once, it is not in the audit trail, and dismissing it
    is the only way to remove it from the DOM. It is rendered in a bordered
    block that cannot be mistaken for ordinary page text.

    Plain HTML with inline styles, as on the other token pages.
--}}
@php
    $rows = $this->rows();
    $totals = $this->totals( $rows );
    $options = $this->expiryOptions();

    $labelStyle = 'display: block; font-size: 0.8rem; margin-bottom: 0.25rem;';
    $fieldStyle = 'width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;';
@endphp

<x-filament-panels::page>
    @if ( null !== $this->plaintext )
        <div style="margin-bottom: 1rem; padding: 0.85rem 1rem; border-radius: 0.5rem; border: 2px solid rgba(245, 158, 11, 0.6); background: rgba(245, 158, 11, 0.12);">
            <p style="font-size: 0.85rem; margin: 0 0 0.5rem; font-weight: 600;">
                Copy this token now — “{{ '' !== $this->plaintextFor ? $this->plaintextFor : 'token' }}”
            </p>
            <code style="display: block; padding: 0.6rem 0.75rem; border-radius: 0.375rem; background: rgba(0, 0, 0, 0.25); font-size: 0.8rem; word-break: break-all;">{{ $this->plaintext }}</code>
            <p style="font-size: 0.8rem; margin: 0.5rem 0 0.75rem;">
                It is stored hashed and cannot be shown again. Expires
                <strong>{{ '' !== $this->plaintextExpiresAt ? $this->plaintextExpiresAt : '—' }}</strong>.
            </p>
            <x-filament::button type="button" color="gray" size="sm" wire:click="dismissPlaintext">
                I have copied it
            </x-filament::button>
        </div>
    @endif

    @if ( '' !== $this->status )
        <div style="margin-bottom: 1rem; padding: 0.6rem 0.85rem; border-radius: 0.5rem; font-size: 0.85rem; background: rgba(59, 130, 246, 0.12); border: 1px solid rgba(59, 130, 246, 0.35);">
            {{ $this->status }}
        </div>
    @endif

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(127, 127, 127, 0.25);">
        <div style="flex: 1 1 16rem;">
            <label for="token-label" style="{{ $labelStyle }}">Label</label>
            <input
                id="token-label"
                type="text"
                wire:model="label"
                placeholder="e.g. reporting script"
                style="{{ $fieldStyle }}"
            >
        </div>

        <div style="flex: 0 1 10rem;">
            <label for="token-days" style="{{ $labelStyle }}">Expires in</label>
            <select id="token-days" wire:model="days" style="{{ $fieldStyle }}">
                @foreach ( $options as $value => $option )
                    <option value="{{ $value }}">{{ $option }}</option>
                @endforeach
            </select>
        </div>

        <div style="padding-bottom: 0.15rem;">
            <x-filament::button type="button" size="sm" wire:click="mint">
                Mint token
            </x-filament::button>
        </div>
    </div>

    <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
        <strong>{{ $totals['total'] }}</strong> token(s) on your account;
        <strong>{{ $totals['active'] }}</strong> active,
        <strong>{{ $totals['expired'] }}</strong> expired,
        <strong>{{ $totals['unused'] }}</strong> never used.
        A token carries the permissions your account has <em>now</em>, not the ones it had when the token was created.
    </p>

    @if ( array() === $rows )
        <p style="font-size: 0.85rem;">You have no personal tokens. Minting one creates a credential for API access that is separate from your browser session.</p>
    @else
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                    <th style="padding: 0.5rem 0.5rem 0.5rem 0;">Label</th>
                    <th style="padding: 0.5rem;">Status</th>
                    <th style="padding: 0.5rem;">Created</th>
                    <th style="padding: 0.5rem;">Last used</th>
                    <th style="padding: 0.5rem;">Expires</th>
                    <th style="padding: 0.5rem;">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach ( $rows as $row )
                    @php( $armed = (string) $row['id'] === $this->pendingRevoke )
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15); vertical-align: top;">
                        <td style="padding: 0.5rem 0.5rem 0.5rem 0;">
                            <div>{{ '' !== $row['label'] ? $row['label'] : 'token' }}</div>
                            <code style="font-size: 0.75rem; opacity: 0.75;">#{{ (int) $row['id'] }}</code>
                        </td>
                        <td style="padding: 0.5rem;">
                            @if ( 'active' === $row['status'] )
                                <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: #065f46; background: rgba(16, 185, 129, 0.15);">active</span>
                            @else
                                <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: #991b1b; background: rgba(239, 68, 68, 0.15);">expired</span>
                            @endif
                        </td>
                        <td style="padding: 0.5rem;">{{ '' !== $row['created_at'] ? $row['created_at'] : '—' }}</td>
                        <td style="padding: 0.5rem;">{{ '' !== $row['last_used_at'] ? $row['last_used_at'] : 'never' }}</td>
                        <td style="padding: 0.5rem;">{{ '' !== $row['expires_at'] ? $row['expires_at'] : '—' }}</td>
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
                                    wire:click="confirmRevoke('{{ $row['id'] }}')"
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
