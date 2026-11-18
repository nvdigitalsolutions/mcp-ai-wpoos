{{--
    Profession catalogue — Proposal 149 §23.

    Plain HTML controls with inline styles, as on the tool catalogue: the
    panel's compiled CSS has no arbitrary Tailwind utilities, and a missing
    class here would break the one thing this page does (filter a list).
    Livewire owns the state; filtering and counting happen server-side in the
    page class so the header cannot claim more than is rendered.

    Read-only by design. There is no create/edit/delete control because the
    write path is WordPress-side and is not registered on this runtime.
--}}
@php
    $all = $this->catalogue();
    $rows = $this->rows();
    $totals = $this->totals( $rows );
    $categories = $this->categories();
    $version = $this->catalogueVersion();
@endphp

<x-filament-panels::page>
    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
        <div style="flex: 1 1 18rem;">
            <label for="profession-search" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Search</label>
            <input
                id="profession-search"
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Slug, title, category or description"
                style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
            >
        </div>

        <div style="flex: 0 1 14rem;">
            <label for="profession-category" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Category</label>
            <select
                id="profession-category"
                wire:model.live="category"
                style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
            >
                <option value="">All categories</option>
                @foreach ( $categories as $value => $label )
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div style="padding-bottom: 0.15rem;">
            <x-filament::button type="button" color="gray" size="sm" wire:click="clearFilters">
                Clear
            </x-filament::button>
        </div>
    </div>

    <p style="font-size: 0.85rem; margin-bottom: 0.75rem;">
        Showing <strong>{{ $totals['total'] }}</strong> of <strong>{{ count( $all ) }}</strong> profession(s),
        recommending <strong>{{ $totals['tools'] }}</strong> tool assignment(s) in total.
        Catalogue version <code style="font-size: 0.8rem;">{{ $version }}</code>.
        Read-only: professions are code-owned reference data, and the write path is not served by this runtime.
    </p>

    <x-admin.data-table
        caption="Professions in the catalogue"
        :columns="[
            ['label' => 'Profession'],
            ['label' => 'Category'],
            ['label' => 'Description'],
            ['label' => 'Tools'],
        ]"
        :rows="count( $rows )"
        empty="No profession matches that filter."
    >
        @foreach ( $rows as $row )
            <tr>
                <td>
                    <div>{{ $row['title'] }}</div>
                    <code style="font-size: 0.75rem; opacity: 0.75;">{{ $row['slug'] }}</code>
                </td>
                <td>
                    {{ $row['category'] }}
                    @if ( '' !== (string) $row['category_slug'] )
                        <div style="font-size: 0.7rem; opacity: 0.7;">{{ $row['category_slug'] }}</div>
                    @endif
                </td>
                <td>{{ $row['description'] }}</td>
                <td>{{ (int) $row['tools_count'] }}</td>
            </tr>
        @endforeach
    </x-admin.data-table>
</x-filament-panels::page>
