{{--
    Remote sites — Proposal 149 §42.

    Plain HTML with inline styles, as on the other reference pages: the panel's
    compiled CSS has no arbitrary Tailwind utilities.

    The page lists, tests, enables/disables and deletes. It does not create or
    edit: the console's wizard owns that, driven by each type's field schema in
    RemoteSiteTypes. A second authoring form here would be the same wizard twice.

    Nothing renders a credential. The Credentials column reports presence only —
    the store's uniform mask is deliberately not printed, because a mask next to
    a "show" affordance invites the question this page must answer with "never".
    Endpoints are shown as host + path: query and fragment are dropped, since a
    provider can put a secret there (the §30 webhook_url rule).
--}}
@php
    $sites = $this->sites();
    $typeLabels = $this->typeLabels();
@endphp

<x-filament-panels::page>
    @if ( '' !== $this->status )
        @php
            $tone = match ( $this->statusTone ) {
                'success' => 'background: rgba(34, 197, 94, 0.12); border: 1px solid rgba(34, 197, 94, 0.35);',
                'danger' => 'background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35);',
                default => 'background: rgba(59, 130, 246, 0.12); border: 1px solid rgba(59, 130, 246, 0.35);',
            };
        @endphp
        <div style="margin-bottom: 1rem; padding: 0.6rem 0.85rem; border-radius: 0.5rem; font-size: 0.85rem; {{ $tone }}">
            {{ $this->status }}
        </div>
    @endif

    <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
        <strong>{{ count( $sites ) }}</strong> connection(s) in this estate.
        Connections are created in the console's Remote sites wizard; this page
        tests, disables and removes what exists. Credentials are stored sealed and
        are never displayed or copied here.
    </p>

    @if ( array() === $sites )
        <p style="font-size: 0.85rem;">
            No connections in this estate yet. Create one in the console, then return
            here to verify it.
        </p>
    @else
        <x-admin.data-table
            caption="Remote site MCP connections in this estate"
            :columns="[
                ['label' => 'Connection'],
                ['label' => 'Endpoint'],
                ['label' => 'Credentials'],
                ['label' => 'Status'],
                ['label' => 'Last test'],
                ['label' => 'Actions'],
            ]"
        >
            @foreach ( $sites as $site )
                @php
                    $host = parse_url( (string) $site['url'], PHP_URL_HOST );
                    $path = parse_url( (string) $site['url'], PHP_URL_PATH );
                    $endpoint = '' !== (string) $host ? (string) $host : '(no URL)';
                    $endpoint .= is_string( $path ) ? rtrim( $path, '/' ) : '';
                    $typeLabel = $typeLabels[ (string) $site['connection_type'] ] ?? (string) $site['connection_type'];
                    $armed = (string) $site['id'] === $this->pendingDelete;
                    $status = (string) ( $site['last_test_status'] ?? '' );
                @endphp
                <tr>
                    <td>
                        <div>{{ (string) $site['name'] }}</div>
                        <code style="font-size: 0.75rem; opacity: 0.75;">{{ $typeLabel }} · {{ (string) $site['auth_type'] }}</code>
                    </td>
                    <td style="word-break: break-all;">
                        <code style="font-size: 0.75rem;">{{ $endpoint }}</code>
                    </td>
                    <td>
                        @if ( ! empty( $site['has_credentials'] ) )
                            stored
                        @else
                            <span style="opacity: 0.7;">none</span>
                        @endif
                    </td>
                    <td>
                        @if ( ! empty( $site['enabled'] ) )
                            <span style="padding: 0.1rem 0.45rem; border-radius: 999px; font-size: 0.75rem; background: rgba(34, 197, 94, 0.16);">enabled</span>
                        @else
                            <span style="padding: 0.1rem 0.45rem; border-radius: 999px; font-size: 0.75rem; background: rgba(127, 127, 127, 0.18);">disabled</span>
                        @endif
                    </td>
                    <td>
                        @if ( '' === $status )
                            <span style="opacity: 0.7;">never</span>
                        @else
                            <span style="padding: 0.1rem 0.45rem; border-radius: 999px; font-size: 0.75rem; {{ 'ok' === $status ? 'background: rgba(34, 197, 94, 0.16);' : 'background: rgba(239, 68, 68, 0.16);' }}">{{ $status }}</span>
                            @if ( ! empty( $site['last_tested_at'] ) )
                                <div style="font-size: 0.75rem; opacity: 0.75;">{{ (string) $site['last_tested_at'] }}</div>
                            @endif
                        @endif
                    </td>
                    <td style="white-space: nowrap;">
                        <button
                            type="button"
                            wire:click="testConnection('{{ (string) $site['id'] }}')"
                            wire:loading.attr="disabled"
                            style="font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: 0.375rem; border: 1px solid rgba(127, 127, 127, 0.4); cursor: pointer;"
                        >Test</button>

                        <button
                            type="button"
                            wire:click="toggleEnabled('{{ (string) $site['id'] }}')"
                            style="font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: 0.375rem; border: 1px solid rgba(127, 127, 127, 0.4); cursor: pointer;"
                        >{{ ! empty( $site['enabled'] ) ? 'Disable' : 'Enable' }}</button>

                        @if ( $armed )
                            <button
                                type="button"
                                wire:click="deleteConnection"
                                style="font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: 0.375rem; border: 1px solid rgba(239, 68, 68, 0.6); background: rgba(239, 68, 68, 0.12); cursor: pointer;"
                            >Confirm delete</button>
                            <button
                                type="button"
                                wire:click="cancelDelete"
                                style="font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: 0.375rem; border: 1px solid rgba(127, 127, 127, 0.4); cursor: pointer;"
                            >Cancel</button>
                        @else
                            <button
                                type="button"
                                wire:click="confirmDelete('{{ (string) $site['id'] }}')"
                                style="font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: 0.375rem; border: 1px solid rgba(127, 127, 127, 0.4); cursor: pointer;"
                            >Delete</button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-admin.data-table>
    @endif
</x-filament-panels::page>
