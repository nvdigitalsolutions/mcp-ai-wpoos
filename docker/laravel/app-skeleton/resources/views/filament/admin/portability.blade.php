{{--
    Assistant export / import — Proposal 149 §54.

    Both halves call `App\Services\AssistantPortability`, the same service the
    console's export/import routes use. The estate comes from the runtime, never
    from the bundle, so a pasted document cannot import into another estate.

    No `@js()` anywhere: panel attributes use `{{ Js::from(...) }}` echoes when
    they need an argument (see AdminPanelBladeTest). This page needs none — every
    control is a plain `wire:click` or a model binding.
--}}
@php
    $assistants = $this->assistants();
    $selected = array_map( 'intval', $this->selected );
    $notice = $this->notice;
    $report = $this->report;
@endphp

<x-filament-panels::page>
    <p style="font-size: 0.85rem; margin-bottom: 1rem;">
        Bundles are scoped to <strong>this estate</strong>: an export contains only this estate's
        assistants, and an import writes into this estate whatever the bundle claims. The canonical
        <code>nvoos-assistant</code> v1 document round-trips with WordPress.
    </p>

    @if ( null !== $notice )
        <div style="margin-bottom: 1rem; padding: 0.75rem 1rem; border-radius: 0.5rem; font-size: 0.85rem;
            background: {{ 'ok' === $notice['tone'] ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)' }};
            border: 1px solid {{ 'ok' === $notice['tone'] ? '#065f46' : '#991b1b' }}33;">
            {{ $notice['message'] }}
        </div>
    @endif

    {{-- ── Export ────────────────────────────────────────────────────────── --}}
    <h3 style="margin: 0 0 0.35rem;">Export</h3>
    <p style="font-size: 0.85rem; margin: 0 0 0.75rem; opacity: 0.85;">
        Tick assistants to export those; tick none and <strong>Export</strong> produces the whole estate.
        The bundle renders below for you to copy — nothing is stored on the server.
    </p>

    @if ( array() === $assistants )
        <p style="font-size: 0.85rem; opacity: 0.8; margin-bottom: 1rem;">
            This estate has no assistants yet.
        </p>
    @else
        <x-admin.data-table
            caption="Assistants that can be exported from this estate"
            :columns="[
                ['srLabel' => 'Include'],
                ['label' => 'Assistant'],
                ['label' => 'Status'],
                ['label' => 'Default'],
            ]"
        >
            @foreach ( $assistants as $assistant )
                <tr>
                    <td>
                        <input
                            type="checkbox"
                            wire:model.live="selected"
                            value="{{ $assistant['id'] }}"
                            aria-label="Include {{ $assistant['title'] }}"
                        >
                    </td>
                    <td>
                        <div>{{ $assistant['title'] }}</div>
                        <code style="font-size: 0.75rem; opacity: 0.7;">{{ $assistant['slug'] }}</code>
                    </td>
                    <td style="white-space: nowrap;">{{ $assistant['status'] }}</td>
                    <td style="white-space: nowrap;">{{ $assistant['is_default'] ? 'yes' : '—' }}</td>
                </tr>
            @endforeach
        </x-admin.data-table>
    @endif

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem;">
        <x-filament::button type="button" color="primary" size="sm" wire:click="export">Export</x-filament::button>

        @if ( [] !== $selected )
            <span style="font-size: 0.8rem; opacity: 0.8;">{{ count( $selected ) }} selected</span>
        @endif

        @if ( '' !== $this->bundle )
            <x-filament::button type="button" color="gray" size="sm" wire:click="clearBundle">Clear bundle</x-filament::button>
        @endif
    </div>

    @if ( '' !== $this->bundle )
        <div style="margin-bottom: 1.5rem;">
            <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.7; margin-bottom: 0.25rem;">Bundle</div>
            <textarea
                readonly
                rows="14"
                aria-label="Exported bundle"
                style="width: 100%; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem;
                    padding: 0.75rem; border-radius: 0.375rem; border: 1px solid rgba(127, 127, 127, 0.35);
                    background: rgba(127, 127, 127, 0.08);"
            >{{ $this->bundle }}</textarea>
        </div>
    @endif

    {{-- ── Import ────────────────────────────────────────────────────────── --}}
    <h3 style="margin: 1.5rem 0 0.35rem;">Import</h3>
    <p style="font-size: 0.85rem; margin: 0 0 0.75rem; opacity: 0.85;">
        Conflict mode decides what happens when an assistant with the same slug or title already
        exists <em>in this estate</em>: <strong>skip</strong> leaves it, <strong>overwrite</strong>
        replaces it, <strong>duplicate</strong> creates a second one with a suffixed slug.
        Preview writes nothing.
    </p>

    <div style="margin-bottom: 0.75rem;">
        <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.7; margin-bottom: 0.25rem;">Bundle JSON</div>
        <textarea
            rows="10"
            wire:model.blur="importJson"
            placeholder='{"format":"nvoos-assistant","format_version":1,"assistants":[…]}'
            aria-label="Bundle JSON to import"
            style="width: 100%; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.75rem;
                padding: 0.75rem; border-radius: 0.375rem; border: 1px solid rgba(127, 127, 127, 0.35);
                background: transparent;"
        ></textarea>
    </div>

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
        <div style="flex: 0 1 12rem;">
            <label for="portability-mode" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Conflict mode</label>
            <select
                id="portability-mode"
                wire:model.live="mode"
                style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
            >
                <option value="skip">Skip existing</option>
                <option value="overwrite">Overwrite existing</option>
                <option value="duplicate">Duplicate</option>
            </select>
        </div>

        <div style="padding-bottom: 0.4rem; font-size: 0.8rem;">
            <label style="display: flex; gap: 0.35rem; align-items: center;">
                <input type="checkbox" wire:model.live="dryRun">
                Preview only
            </label>
        </div>

        <div style="padding-bottom: 0.15rem; display: flex; gap: 0.5rem;">
            <x-filament::button type="button" color="gray" size="sm" wire:click="preview">Preview</x-filament::button>

            <x-filament::button
                type="button"
                color="danger"
                size="sm"
                :disabled="$this->dryRun"
                wire:click="import"
                wire:confirm="Import writes to this estate now. Overwrite replaces existing assistants that share a slug or title. Continue?"
            >
                Import
            </x-filament::button>

            <x-filament::button type="button" color="gray" size="sm" wire:click="resetImport">Reset</x-filament::button>
        </div>
    </div>

    @if ( $this->dryRun )
        <p style="font-size: 0.8rem; opacity: 0.8; margin-bottom: 1rem;">
            <strong>Import</strong> is disabled while <strong>Preview only</strong> is ticked — that is
            what makes it safe to paste a bundle and read the report first.
        </p>
    @endif

    @if ( null !== $report )
        <x-admin.data-table
            caption="Assistants in the previewed or imported bundle"
            :columns="[
                ['label' => 'Title'],
                ['label' => 'Action'],
                ['label' => 'Assistant'],
            ]"
        >
            @foreach ( $report['assistants'] as $row )
                <tr>
                    <td>{{ $row['title'] }}</td>
                    <td style="white-space: nowrap;">{{ $row['action'] }}</td>
                    <td style="white-space: nowrap;">
                        {{ null !== ( $row['id'] ?? null ) ? '#' . (int) $row['id'] : '—' }}
                    </td>
                </tr>
            @endforeach
        </x-admin.data-table>
    @endif
</x-filament-panels::page>
