{{--
    Assistant blueprints — Proposal 149 §53.

    The catalogue is code-shipped reference data; only the installed flags are
    estate-scoped. Install writes through `BlueprintInstaller`, the same service
    the console's HTTP route uses, and records an audit row because Livewire
    actions are skipped by the request-level audit.
--}}
@php
    $catalogue = $this->catalogue();
    $toolkits = $this->toolkits();
    $installed = $this->installedCount();
    $notice = $this->notice;
    $detail = $this->detail;
@endphp

<x-filament-panels::page>
    <p style="font-size: 0.85rem; margin-bottom: 1rem;">
        Blueprints are authored with the Pro toolkits and ship with the runtime, so the catalogue is
        the same on every estate. <strong>Installing creates a published assistant in this estate</strong>;
        reinstalling updates that assistant in place instead of duplicating it. The installed count
        below is this estate's.
    </p>

    @if ( null !== $notice )
        <div style="margin-bottom: 1rem; padding: 0.75rem 1rem; border-radius: 0.5rem; font-size: 0.85rem;
            background: {{ 'ok' === $notice['tone'] ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)' }};
            border: 1px solid {{ 'ok' === $notice['tone'] ? '#065f46' : '#991b1b' }}33;">
            {{ $notice['message'] }}
        </div>
    @endif

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
        <div style="flex: 1 1 20rem;">
            <label for="blueprint-search" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Search</label>
            <input
                id="blueprint-search"
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Name, description or toolkit"
                style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
            >
        </div>

        <div style="flex: 0 1 16rem;">
            <label for="blueprint-toolkit" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Toolkit</label>
            <select
                id="blueprint-toolkit"
                wire:model.live="toolkit"
                style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
            >
                <option value="">All toolkits</option>
                @foreach ( $toolkits as $slug => $label )
                    <option value="{{ $slug }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div style="padding-bottom: 0.15rem;">
            <x-filament::button type="button" color="gray" size="sm" wire:click="clearFilters">Clear</x-filament::button>
        </div>
    </div>

    <p style="font-size: 0.85rem; margin-bottom: 1rem;">
        Showing <strong>{{ $catalogue['total'] }}</strong> blueprint(s);
        <strong>{{ $installed }}</strong> installed in this estate.
    </p>

    @if ( array() === $catalogue['toolkits'] )
        <p style="font-size: 0.85rem; opacity: 0.8;">No blueprint matches that filter.</p>
    @else
        @foreach ( $catalogue['toolkits'] as $group )
            <h3 style="margin: 1.25rem 0 0.5rem;">
                {{ $group['label'] }}
                <span style="font-size: 0.75rem; font-weight: 400; opacity: 0.7;"><code>{{ $group['slug'] }}</code></span>
            </h3>

            <x-admin.data-table
                :caption="'Blueprints in the '.$group['label'].' toolkit'"
                :columns="[
                    ['label' => 'Blueprint'],
                    ['label' => 'Tools'],
                    ['label' => 'Status'],
                    ['srLabel' => 'Actions'],
                ]"
                :rows="count( $group['blueprints'] )"
            >
                @foreach ( $group['blueprints'] as $blueprint )
                    <tr>
                        <td>
                            <div>{{ $blueprint['name'] }}</div>
                            <div style="font-size: 0.8rem; opacity: 0.8;">{{ $blueprint['description'] }}</div>
                            <code style="font-size: 0.7rem; opacity: 0.7;">{{ $blueprint['slug'] }}</code>
                        </td>
                        <td>{{ (int) $blueprint['tool_count'] }}</td>
                        <td style="white-space: nowrap;">
                            @if ( $blueprint['installed'] )
                                <span style="font-size: 0.75rem; color: #065f46;">installed · #{{ (int) $blueprint['assistant_id'] }}</span>
                            @else
                                <span style="font-size: 0.75rem; opacity: 0.7;">not installed</span>
                            @endif
                        </td>
                        <td style="white-space: nowrap;">
                            <x-filament::button
                                type="button"
                                color="gray"
                                size="sm"
                                wire:click="open({{ \Illuminate\Support\Js::from($group['slug']) }}, {{ \Illuminate\Support\Js::from($blueprint['slug']) }})"
                            >
                                View
                            </x-filament::button>

                            @if ( $blueprint['installed'] )
                                <x-filament::button
                                    type="button"
                                    color="warning"
                                    size="sm"
                                    wire:click="install({{ \Illuminate\Support\Js::from($group['slug']) }}, {{ \Illuminate\Support\Js::from($blueprint['slug']) }}, true)"
                                    wire:confirm="Reinstall overwrites the blueprint's keys on that assistant. Anything you added to it is kept. Continue?"
                                >
                                    Reinstall
                                </x-filament::button>
                            @else
                                <x-filament::button
                                    type="button"
                                    color="primary"
                                    size="sm"
                                    wire:click="install({{ \Illuminate\Support\Js::from($group['slug']) }}, {{ \Illuminate\Support\Js::from($blueprint['slug']) }})"
                                >
                                    Install
                                </x-filament::button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-admin.data-table>
        @endforeach
    @endif

    {{-- ── Detail ───────────────────────────────────────────────────────── --}}
    @if ( null !== $detail )
        @php( $info = $detail['detail'] )
        <div style="margin-top: 1.5rem; padding: 1rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.3);">
            <div style="display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start;">
                <div>
                    <h3 style="margin: 0 0 0.25rem;">{{ $info['name'] }}</h3>
                    <code style="font-size: 0.8rem; opacity: 0.8;">{{ $detail['toolkit'] }} / {{ $detail['slug'] }}</code>
                    @if ( '' !== (string) $info['description'] )
                        <p style="font-size: 0.85rem; margin: 0.35rem 0 0;">{{ $info['description'] }}</p>
                    @endif
                </div>
                <x-filament::button type="button" color="gray" size="sm" wire:click="close">Close</x-filament::button>
            </div>

            @if ( is_array( $info['defaults'] ) && array() !== $info['defaults'] )
                <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 0.75rem;">
                    <tbody>
                        @foreach ( $info['defaults'] as $key => $value )
                            <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15); vertical-align: top;">
                                <th style="padding: 0.35rem 0.5rem 0.35rem 0; text-align: left; font-weight: 500; width: 12rem;"><code style="font-size: 0.8rem;">{{ $key }}</code></th>
                                <td style="padding: 0.35rem 0;"><code style="font-size: 0.8rem;">{{ is_scalar( $value ) ? (string) $value : json_encode( $value ) }}</code></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ( array() !== (array) $info['tools'] )
                <div style="margin-top: 0.75rem; font-size: 0.85rem;">
                    <strong>Tools:</strong>
                    {{ implode( ', ', (array) $info['tools'] ) }}
                </div>
            @endif

            @if ( '' !== (string) $info['instructions'] )
                <div style="margin-top: 0.75rem;">
                    <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.7; margin-bottom: 0.25rem;">Instructions</div>
                    <pre style="white-space: pre-wrap; font-size: 0.8rem; padding: 0.75rem; border-radius: 0.375rem; background: rgba(127, 127, 127, 0.08); margin: 0;">{{ $info['instructions'] }}</pre>
                </div>
            @endif

            <div style="margin-top: 0.75rem;">
                @if ( (int) $detail['installed_id'] > 0 )
                    <x-filament::button
                        type="button"
                        color="warning"
                        size="sm"
                        wire:click="install({{ \Illuminate\Support\Js::from($detail['toolkit']) }}, {{ \Illuminate\Support\Js::from($detail['slug']) }}, true)"
                        wire:confirm="Reinstall overwrites the blueprint's keys on that assistant. Anything you added to it is kept. Continue?"
                    >
                        Reinstall assistant #{{ (int) $detail['installed_id'] }}
                    </x-filament::button>
                @else
                    <x-filament::button
                        type="button"
                        color="primary"
                        size="sm"
                        wire:click="install({{ \Illuminate\Support\Js::from($detail['toolkit']) }}, {{ \Illuminate\Support\Js::from($detail['slug']) }})"
                    >
                        Install as an assistant
                    </x-filament::button>
                @endif
            </div>
        </div>
    @endif
</x-filament-panels::page>
