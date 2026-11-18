{{--
    Paper Store and record schemas — Proposal 149 §52.

    Read-only. The console owns record CRUD, and Wave 3's manifest engine
    replaces schema-driven record rendering, so a create/edit form here would be
    a writer that is immediately superseded.
--}}
@php
    $collections = $this->collections();
    $records = $this->records();
    $searchResults = $this->searchResults();
    $detail = $this->detail;
    $entities = $this->entities();
@endphp

<x-filament-panels::page>
    <p style="font-size: 0.85rem; margin-bottom: 1rem;">
        The Paper Store is one flat-file library read by both runtimes — a record written on
        WordPress is readable here and vice versa — so it is <strong>shared by every estate</strong>,
        like the OKF knowledge bundles. Records are curated knowledge (research, prompts, workflows,
        brand kits), not estate-owned data.
        <strong>Read-only:</strong> creating, editing and deleting are console actions.
    </p>

    {{-- ── Cross-collection search ──────────────────────────────────────── --}}
    <h3 style="margin: 0 0 0.5rem;">Search across collections</h3>

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
        <div style="flex: 1 1 22rem;">
            <label for="paper-search" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Query</label>
            <input
                id="paper-search"
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Title or description"
                style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
            >
        </div>

        <div style="padding-bottom: 0.15rem;">
            <x-filament::button type="button" color="gray" size="sm" wire:click="clearSearch">Clear</x-filament::button>
        </div>
    </div>

    @if ( '' !== $searchResults['query'] )
        <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
            <strong>{{ $searchResults['total'] }}</strong> result(s) for
            <code style="font-size: 0.8rem;">{{ $searchResults['query'] }}</code>.
            @if ( $searchResults['truncated'] )
                Showing the first {{ count( $searchResults['results'] ) }}.
            @endif
        </p>

        <x-admin.data-table
            caption="Records matching the cross-collection search"
            :columns="[
                ['label' => 'Collection'],
                ['label' => 'Record'],
                ['label' => 'Status'],
                ['label' => 'Updated'],
            ]"
            :rows="count( $searchResults['results'] )"
            empty="No record matches that query."
        >
            @foreach ( $searchResults['results'] as $result )
                <tr>
                    <td><code style="font-size: 0.8rem;">{{ $result['collection'] }}</code></td>
                    <td>
                        <button
                            type="button"
                            wire:click="selectCollection({{ \Illuminate\Support\Js::from($result['collection']) }}); openRecord({{ \Illuminate\Support\Js::from($result['id']) }})"
                            style="padding: 0; border: 0; background: none; color: #2563eb; cursor: pointer; text-align: left;"
                        >
                            {{ $result['title'] }}
                        </button>
                        <div style="font-size: 0.75rem; opacity: 0.75;">{{ $result['id'] }}</div>
                    </td>
                    <td>{{ $result['status'] }}</td>
                    <td><code style="font-size: 0.8rem;">{{ $result['updated_at'] }}</code></td>
                </tr>
            @endforeach
        </x-admin.data-table>
    @endif

    {{-- ── Collections ──────────────────────────────────────────────────── --}}
    <h3 style="margin: 1.5rem 0 0.5rem;">Collections</h3>

    @if ( array() === $collections )
        <p style="font-size: 0.85rem; opacity: 0.8; margin-bottom: 1.5rem;">
            The Paper Store is empty. Records are written by the console and by the assistant
            tools that use it.
        </p>
    @else
        <x-admin.data-table
            caption="Paper Store collections in this estate"
            :columns="[
                ['label' => 'Collection'],
                ['label' => 'Records'],
                ['srLabel' => 'Actions'],
            ]"
            :rows="count( $collections )"
        >
            @foreach ( $collections as $row )
                <tr style="{{ $row['name'] === $collection ? 'background: rgba(127, 127, 127, 0.08);' : '' }}">
                    <td><code style="font-size: 0.8rem;">{{ $row['name'] }}</code></td>
                    <td>{{ (int) $row['total'] }}</td>
                    <td>
                        <x-filament::button
                            type="button"
                            color="{{ $row['name'] === $collection ? 'primary' : 'gray' }}"
                            size="sm"
                            wire:click="selectCollection({{ \Illuminate\Support\Js::from($row['name']) }})"
                        >
                            {{ $row['name'] === $collection ? 'Browsing' : 'Browse' }}
                        </x-filament::button>
                    </td>
                </tr>
            @endforeach
        </x-admin.data-table>
    @endif

    {{-- ── Records ──────────────────────────────────────────────────────── --}}
    @if ( '' !== $collection )
        <h3 style="margin: 1.5rem 0 0.5rem;">Records in <code style="font-size: 0.9rem;">{{ $collection }}</code></h3>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
            <div style="flex: 0 1 12rem;">
                <label for="paper-status" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Status</label>
                <select id="paper-status" wire:model.live="status" style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;">
                    <option value="">Any status</option>
                    @foreach ( array( 'published', 'draft', 'archived' ) as $value )
                        <option value="{{ $value }}">{{ $value }}</option>
                    @endforeach
                </select>
            </div>

            <div style="flex: 0 1 12rem;">
                <label for="paper-tag" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Tag</label>
                <input
                    id="paper-tag"
                    type="text"
                    wire:model.live.debounce.400ms="tag"
                    placeholder="Exact tag"
                    style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
                >
            </div>

            <div style="padding-bottom: 0.15rem;">
                <x-filament::button type="button" color="gray" size="sm" wire:click="clearFilters">Clear</x-filament::button>
            </div>
        </div>

        <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
            Showing <strong>{{ count( $records['items'] ) }}</strong> of
            <strong>{{ $records['total'] }}</strong> record(s).
            @if ( $records['truncated'] )
                <strong>The list is capped</strong> — narrow the filters to see the rest.
            @endif
        </p>

        <x-admin.data-table
            caption="Records in the selected collection"
            :columns="[
                ['label' => 'Record'],
                ['label' => 'Status'],
                ['label' => 'Type'],
                ['label' => 'Tags'],
                ['label' => 'Updated'],
                ['srLabel' => 'Actions'],
            ]"
            :rows="count( $records['items'] )"
            empty="No record matches those filters."
        >
            @foreach ( $records['items'] as $item )
                <tr>
                    <td>
                        <div>{{ $item['title'] }}</div>
                        <code style="font-size: 0.75rem; opacity: 0.75;">{{ $item['id'] }}</code>
                    </td>
                    <td>{{ $item['status'] }}</td>
                    <td>{{ '' === (string) ($item['type'] ?? '') ? '—' : $item['type'] }}</td>
                    <td>{{ array() === (array) ($item['tags'] ?? array()) ? '—' : implode( ', ', (array) $item['tags'] ) }}</td>
                    <td><code style="font-size: 0.8rem;">{{ $item['updated_at'] ?? '' }}</code></td>
                    <td>
                        <x-filament::button type="button" color="gray" size="sm" wire:click="openRecord({{ \Illuminate\Support\Js::from($item['id']) }})">
                            View
                        </x-filament::button>
                    </td>
                </tr>
            @endforeach
        </x-admin.data-table>
    @endif

    {{-- ── Record detail ────────────────────────────────────────────────── --}}
    @if ( null !== $detail )
        <div style="margin-top: 1.5rem; padding: 1rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.3);">
            @if ( isset( $detail['error'] ) )
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <strong>{{ $detail['error'] }}</strong>
                    <x-filament::button type="button" color="gray" size="sm" wire:click="closeRecord">Close</x-filament::button>
                </div>
            @else
                <div style="display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start;">
                    <div>
                        <h3 style="margin: 0 0 0.25rem;">{{ $detail['title'] }}</h3>
                        <code style="font-size: 0.8rem; opacity: 0.8;">{{ $detail['collection'] }} / {{ $detail['id'] }}</code>
                        <div style="font-size: 0.8rem; margin-top: 0.25rem;">
                            {{ $detail['status'] }}
                            @if ( '' !== $detail['type'] ) · {{ $detail['type'] }} @endif
                            @if ( array() !== $detail['tags'] ) · {{ implode( ', ', $detail['tags'] ) }} @endif
                        </div>
                    </div>
                    <x-filament::button type="button" color="gray" size="sm" wire:click="closeRecord">Close</x-filament::button>
                </div>

                @if ( '' !== $detail['description'] )
                    <p style="font-size: 0.85rem; margin-top: 0.5rem;">{{ $detail['description'] }}</p>
                @endif

                @if ( array() !== $detail['meta'] )
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 0.75rem;">
                        <tbody>
                            @foreach ( $detail['meta'] as $key => $value )
                                <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15); vertical-align: top;">
                                    <th style="padding: 0.35rem 0.5rem 0.35rem 0; text-align: left; font-weight: 500; width: 12rem;"><code style="font-size: 0.8rem;">{{ $key }}</code></th>
                                    <td style="padding: 0.35rem 0;"><code style="font-size: 0.8rem;">{{ is_scalar( $value ) || null === $value ? (string) $value : json_encode( $value ) }}</code></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                <div style="margin-top: 0.75rem;">
                    <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.7; margin-bottom: 0.25rem;">Body</div>
                    <pre style="white-space: pre-wrap; font-size: 0.8rem; padding: 0.75rem; border-radius: 0.375rem; background: rgba(127, 127, 127, 0.08); margin: 0;">{{ is_scalar( $detail['body'] ) || null === $detail['body'] ? (string) $detail['body'] : json_encode( $detail['body'] ) }}</pre>
                </div>

                <div style="margin-top: 0.5rem; font-size: 0.75rem; opacity: 0.75;">
                    Created <code>{{ $detail['created_at'] }}</code> · updated <code>{{ $detail['updated_at'] }}</code>
                </div>
            @endif
        </div>
    @endif

    {{-- ── Record schemas ───────────────────────────────────────────────── --}}
    <h3 style="margin: 1.75rem 0 0.5rem;">Record schemas</h3>

    <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
        The entities <code style="font-size: 0.8rem;">/api/v1/pro/records/schema</code> advertises —
        the console's Records page reads the same catalogue. Content entities carry no meta columns on
        either runtime, so <code style="font-size: 0.8rem;">columns</code> is empty by design.
    </p>

    <x-admin.data-table
        caption="Record schemas advertised by the Pro records API"
        :columns="[
            ['label' => 'Entity'],
            ['label' => 'Group'],
            ['label' => 'Post type'],
            ['label' => 'REST base'],
            ['label' => 'Columns'],
        ]"
        :rows="count( $entities )"
    >
        @foreach ( $entities as $entity )
            <tr>
                <td>{{ $entity['icon'] }} {{ $entity['label'] }}</td>
                <td>{{ $entity['group'] }}</td>
                <td><code style="font-size: 0.8rem;">{{ $entity['postType'] }}</code></td>
                <td><code style="font-size: 0.8rem;">/{{ $entity['restBase'] }}</code></td>
                <td>{{ array() === (array) $entity['columns'] ? 'none' : implode( ', ', (array) $entity['columns'] ) }}</td>
            </tr>
        @endforeach
    </x-admin.data-table>
</x-filament-panels::page>
