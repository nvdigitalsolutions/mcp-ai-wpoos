{{--
    OKF knowledge bundles — Proposal 149 §50.

    Plain HTML with inline styles, as on the other reference surfaces.

    Read-only. The WordPress bundle manager creates, replaces and deletes
    bundles; there is no OKF writer on this runtime, and the contract row says
    `writer: none` for that reason. Nothing here pretends to own a file.
--}}
@php
    $root = $this->root();
    $bundles = $this->bundles();
    $bundleNames = $this->bundleNames();
    $types = $this->types();
    $conceptList = $this->concepts();
    $detail = $this->conceptDetail();
    $searchResults = $this->searchResults();
@endphp

<x-filament-panels::page>
    <p style="font-size: 0.85rem; margin-bottom: 1rem;">
        Knowledge is read from <code style="font-size: 0.8rem;">{{ $root }}</code> and is
        <strong>shared by every estate</strong> — one directory per deployment, exactly as the
        console drawer reads it. Read-only: bundle writes are WordPress-side and are not served
        by this runtime.
    </p>

    {{-- ── Cross-bundle search ──────────────────────────────────────────── --}}
    <h3 style="margin: 0 0 0.5rem;">Search across bundles</h3>

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
        <div style="flex: 1 1 22rem;">
            <label for="okf-search" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Query</label>
            <input
                id="okf-search"
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Concept id, title, description or tag"
                style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
            >
        </div>

        <div style="padding-bottom: 0.15rem;">
            <x-filament::button type="button" color="gray" size="sm" wire:click="clearSearch">
                Clear
            </x-filament::button>
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
            caption="Concepts matching the cross-bundle search"
            :columns="[
                ['label' => 'Bundle'],
                ['label' => 'Concept'],
                ['label' => 'Type'],
                ['label' => 'Trust'],
            ]"
            :rows="count( $searchResults['results'] )"
            empty="No concept matches that query."
        >
            @foreach ( $searchResults['results'] as $result )
                <tr>
                    <td><code style="font-size: 0.8rem;">{{ $result['bundle'] }}</code></td>
                    <td>
                        <button
                            type="button"
                            wire:click="selectBundle({{ \Illuminate\Support\Js::from($result['bundle']) }}); openConcept({{ \Illuminate\Support\Js::from($result['concept_id']) }})"
                            style="padding: 0; border: 0; background: none; color: #2563eb; cursor: pointer; text-align: left;"
                        >
                            {{ $result['title'] !== '' ? $result['title'] : $result['concept_id'] }}
                        </button>
                        <div style="font-size: 0.75rem; opacity: 0.75;">{{ $result['concept_id'] }}</div>
                    </td>
                    <td>{{ '' === (string) $result['type'] ? '—' : $result['type'] }}</td>
                    <td>{{ $result['trust_tier'] }}</td>
                </tr>
            @endforeach
        </x-admin.data-table>
    @endif

    {{-- ── Bundles ──────────────────────────────────────────────────────── --}}
    <h3 style="margin: 1.5rem 0 0.5rem;">Bundles</h3>

    @if ( array() === $bundles )
        <p style="font-size: 0.85rem; opacity: 0.8; margin-bottom: 1.5rem;">
            No OKF bundle is present in that directory. A bundle is a subdirectory with a
            <code style="font-size: 0.8rem;">.md</code> concept tree.
        </p>
    @else
        <x-admin.data-table
            caption="OKF bundles in the configured directory"
            :columns="[
                ['label' => 'Bundle'],
                ['label' => 'Concepts'],
                ['label' => 'Stale'],
                ['label' => 'Deprecated'],
                ['label' => 'Trust tiers'],
                ['label' => 'Internal links'],
                ['srLabel' => 'Actions'],
            ]"
            :rows="count( $bundles )"
        >
            @foreach ( $bundles as $row )
                @php( $tiers = (array) $row['trust_tiers'] )
                <tr style="{{ $row['name'] === $bundle ? 'background: rgba(127, 127, 127, 0.08);' : '' }}">
                    <td>
                        <code style="font-size: 0.8rem;">{{ $row['name'] }}</code>
                        @if ( array() !== (array) $row['types'] )
                            <div style="font-size: 0.75rem; opacity: 0.75;">{{ implode( ', ', (array) $row['types'] ) }}</div>
                        @endif
                    </td>
                    <td>{{ (int) $row['concept_count'] }}</td>
                    <td>{{ (int) $row['stale_count'] }}</td>
                    <td>{{ (int) $row['deprecated_count'] }}</td>
                    <td>
                        <div style="font-size: 0.75rem;">
                            human: {{ (int) ( $tiers['human-reviewed'] ?? 0 ) }} ·
                            machine: {{ (int) ( $tiers['machine-confirmed'] ?? 0 ) }} ·
                            unverified: {{ (int) ( $tiers['unverified'] ?? 0 ) }}
                        </div>
                    </td>
                    <td>
                        {{ $row['conformant'] ? 'none broken' : (int) $row['issue_count'] . ' broken' }}
                    </td>
                    <td>
                        <x-filament::button
                            type="button"
                            color="{{ $row['name'] === $bundle ? 'primary' : 'gray' }}"
                            size="sm"
                            wire:click="selectBundle({{ \Illuminate\Support\Js::from($row['name']) }})"
                        >
                            {{ $row['name'] === $bundle ? 'Browsing' : 'Browse' }}
                        </x-filament::button>
                    </td>
                </tr>
            @endforeach
        </x-admin.data-table>
    @endif

    {{-- ── Concepts ─────────────────────────────────────────────────────── --}}
    @if ( '' !== $bundle )
        <h3 style="margin: 1.5rem 0 0.5rem;">Concepts in <code style="font-size: 0.9rem;">{{ $bundle }}</code></h3>

        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
            <div style="flex: 1 1 16rem;">
                <label for="okf-concept-search" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Search</label>
                <input
                    id="okf-concept-search"
                    type="search"
                    wire:model.live.debounce.400ms="conceptSearch"
                    placeholder="Id, title, description or tag"
                    style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
                >
            </div>

            <div style="flex: 0 1 11rem;">
                <label for="okf-type" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Type</label>
                <select id="okf-type" wire:model.live="type" style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;">
                    <option value="">Any type</option>
                    @foreach ( $types as $value )
                        <option value="{{ $value }}">{{ $value }}</option>
                    @endforeach
                </select>
            </div>

            <div style="flex: 0 1 10rem;">
                <label for="okf-status" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Status</label>
                <select id="okf-status" wire:model.live="status" style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;">
                    <option value="">Any status</option>
                    @foreach ( array( 'stable', 'draft', 'deprecated' ) as $value )
                        <option value="{{ $value }}">{{ $value }}</option>
                    @endforeach
                </select>
            </div>

            <div style="flex: 0 1 11rem;">
                <label for="okf-trust" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Trust tier</label>
                <select id="okf-trust" wire:model.live="trustTier" style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;">
                    <option value="">Any tier</option>
                    @foreach ( array( 'human-reviewed', 'machine-confirmed', 'unverified' ) as $value )
                        <option value="{{ $value }}">{{ $value }}</option>
                    @endforeach
                </select>
            </div>

            <div style="padding-bottom: 0.4rem; font-size: 0.8rem;">
                <label style="display: flex; gap: 0.35rem; align-items: center;">
                    <input type="checkbox" wire:model.live="includeStale">
                    Include stale
                </label>
            </div>

            <div style="padding-bottom: 0.15rem;">
                <x-filament::button type="button" color="gray" size="sm" wire:click="clearFilters">
                    Clear
                </x-filament::button>
            </div>
        </div>

        <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
            Showing <strong>{{ count( $conceptList['items'] ) }}</strong> of
            <strong>{{ $conceptList['total'] }}</strong> concept(s).
            @if ( $conceptList['truncated'] )
                <strong>The list is capped at {{ count( $conceptList['items'] ) }}</strong> —
                narrow the filters to see the rest.
            @endif
        </p>

        <x-admin.data-table
            caption="Concepts in the selected bundle"
            :columns="[
                ['label' => 'Concept'],
                ['label' => 'Type'],
                ['label' => 'Status'],
                ['label' => 'Trust'],
                ['label' => 'Stale'],
                ['label' => 'Tags'],
                ['srLabel' => 'Actions'],
            ]"
            :rows="count( $conceptList['items'] )"
            empty="No concept matches those filters."
        >
            @foreach ( $conceptList['items'] as $item )
                <tr>
                    <td>
                        <div>{{ $item['title'] !== '' ? $item['title'] : $item['concept_id'] }}</div>
                        <code style="font-size: 0.75rem; opacity: 0.75;">{{ $item['concept_id'] }}</code>
                    </td>
                    <td>{{ '' === (string) $item['type'] ? '—' : $item['type'] }}</td>
                    <td>{{ $item['status'] }}</td>
                    <td>{{ $item['trust_tier'] }}</td>
                    <td>{{ $item['stale'] ? 'yes' : 'no' }}</td>
                    <td>{{ is_array( $item['tags'] ) && array() !== $item['tags'] ? implode( ', ', $item['tags'] ) : '—' }}</td>
                    <td>
                        <x-filament::button type="button" color="gray" size="sm" wire:click="openConcept({{ \Illuminate\Support\Js::from($item['concept_id']) }})">
                            View
                        </x-filament::button>
                    </td>
                </tr>
            @endforeach
        </x-admin.data-table>
    @endif

    {{-- ── Concept detail ───────────────────────────────────────────────── --}}
    @if ( null !== $detail )
        <div style="margin-top: 1.5rem; padding: 1rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.3);">
            @if ( isset( $detail['error'] ) )
                <div style="display: flex; justify-content: space-between; gap: 1rem;">
                    <strong>That concept could not be read.</strong>
                    <x-filament::button type="button" color="gray" size="sm" wire:click="closeConcept">Close</x-filament::button>
                </div>
                <p style="font-size: 0.85rem; margin-top: 0.5rem;">{{ $detail['error'] }}</p>
            @else
                <div style="display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start;">
                    <div>
                        <h3 style="margin: 0 0 0.25rem;">
                            {{ $detail['frontmatter']['title'] ?? $detail['concept_id'] }}
                        </h3>
                        <code style="font-size: 0.8rem; opacity: 0.8;">{{ $bundle }} / {{ $detail['concept_id'] }}</code>
                        <div style="font-size: 0.8rem; margin-top: 0.25rem;">
                            Trust: <strong>{{ $detail['trust_tier'] }}</strong> ·
                            Stale: <strong>{{ $detail['stale'] ? 'yes' : 'no' }}</strong>
                        </div>
                    </div>
                    <x-filament::button type="button" color="gray" size="sm" wire:click="closeConcept">Close</x-filament::button>
                </div>

                @if ( array() !== $detail['frontmatter'] )
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 0.75rem;">
                        <tbody>
                            @foreach ( $detail['frontmatter'] as $key => $value )
                                <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15); vertical-align: top;">
                                    <th style="padding: 0.35rem 0.5rem 0.35rem 0; text-align: left; font-weight: 500; width: 12rem;">{{ $key }}</th>
                                    <td style="padding: 0.35rem 0;">
                                        <code style="font-size: 0.8rem;">{{ is_scalar( $value ) ? (string) $value : json_encode( $value ) }}</code>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @if ( array() !== $detail['links'] )
                    <div style="margin-top: 0.75rem; font-size: 0.85rem;">
                        <strong>Links:</strong>
                        {{ implode( ', ', $detail['links'] ) }}
                    </div>
                @endif

                <div style="margin-top: 0.75rem;">
                    <div style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.7; margin-bottom: 0.25rem;">Body</div>
                    <pre style="white-space: pre-wrap; font-size: 0.8rem; padding: 0.75rem; border-radius: 0.375rem; background: rgba(127, 127, 127, 0.08); margin: 0;">{{ $detail['body'] }}</pre>
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
