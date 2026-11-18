{{--
    One toolkit, rendered from its manifest — Proposal 149 Wave 3.

    Three branches, one per routed screen. Everything on them is derived:
    fields and tabs from `settingTabs()`, columns from the entity manifest,
    cards from `dashboard()`. No family name appears in this file, which is
    the acceptance test for the engine.

    Inline styles only — the panel's compiled CSS has no arbitrary utilities.
--}}
@php
    $summary  = $this->summary();
    $entities = (array) ( $summary['entities'] ?? array() );
    $screens  = $this->screens();

    $toneColour = static function ( string $tone ): string {
        return match ( $tone ) {
            'success' => '#065f46',
            'danger'  => '#991b1b',
            default   => '#1e40af',
        };
    };

    /** Cell text for a manifest column: booleans as words, arrays as JSON. */
    $cell = static function ( array $record, string $key ): string {
        $value = $record['data'][ $key ] ?? null;

        if ( null === $value || '' === $value ) {
            return '—';
        }

        if ( \is_bool( $value ) ) {
            return $value ? 'Yes' : 'No';
        }

        if ( \is_array( $value ) ) {
            return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }

        return (string) $value;
    };

    /** A dashboard figure: integers stay integral, fractions get two places. */
    $figure = static function ( mixed $value ): string {
        if ( ! is_numeric( $value ) ) {
            return (string) $value;
        }

        $number = 0 + $value;

        return floor( $number ) === (float) $number
            ? number_format( $number )
            : number_format( $number, 2 );
    };
@endphp

<x-filament-panels::page>
    <div style="display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; align-items: flex-start; margin-bottom: 1rem;">
        <div>
            <h2 style="margin: 0; font-size: 1.25rem; font-weight: 700;">
                <span aria-hidden="true">{{ $summary['icon'] }}</span>
                {{ $summary['name'] }}
            </h2>
            <p style="margin: 0.25rem 0 0; opacity: 0.75;">{{ $summary['description'] }}</p>
        </div>

        @if ( '' !== $status )
            <p
                role="status"
                style="margin: 0; padding: 0.5rem 0.75rem; border-radius: 0.5rem; color: {{ $toneColour( $statusTone ) }}; background: rgba(127, 127, 127, 0.1);"
            >
                {{ $status }}
            </p>
        @endif
    </div>

    <nav aria-label="Toolkit screens" style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.25rem;">
        @foreach ( $screens as $candidate )
            <a
                href="{{ $this->screenUrl( $candidate ) }}"
                @if ( $candidate === $screen ) aria-current="page" @endif
                style="padding: 0.25rem 0; font-size: 0.9rem; text-decoration: none; color: inherit; border-bottom: 2px solid {{ $candidate === $screen ? 'currentColor' : 'transparent' }}; {{ $candidate === $screen ? 'font-weight: 600;' : 'opacity: 0.7;' }}"
            >
                {{ ucfirst( $candidate ) }}
            </a>
        @endforeach
    </nav>

    @if ( 'settings' === $screen )
        {{-- Fields, descriptions and tabs all come from the manifest. Secrets
             hydrate empty and write only when typed — see ToolkitPage::component(). --}}
        <form wire:submit="save">
            {{ $this->form }}

            <div style="margin-top: 1.5rem;">
                <x-filament::button type="submit">
                    Save settings
                </x-filament::button>
            </div>
        </form>
    @endif

    @if ( 'dashboard' === $screen )
        @php
            $cards = $this->cards();
        @endphp

        @if ( array() === $cards )
            <p style="opacity: 0.75;">This toolkit's manifest declares no dashboard cards.</p>
        @else
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); gap: 1rem;">
                @foreach ( $cards as $card )
                    <div style="padding: 1rem; border: 1px solid rgba(127, 127, 127, 0.3); border-radius: 0.75rem;">
                        <p style="margin: 0; font-size: 0.8rem; opacity: 0.7;">{{ $card['label'] }}</p>
                        <p style="margin: 0.25rem 0 0; font-size: 1.5rem; font-weight: 700;">
                            {{ $figure( $card['value'] ) }}
                        </p>
                        <p style="margin: 0.25rem 0 0; font-size: 0.75rem; opacity: 0.5;">
                            {{ $card['aggregate'] }}
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    @if ( 'entities' === $screen )
        @php
            $config  = $this->entityConfig();
            $columns = (array) ( $config['columns'] ?? array() );
            $listing = $this->records();
            $records = (array) $listing['records'];
            $total   = (int) $listing['total'];
            $perPage = (int) $listing['per_page'];
            $page    = (int) $listing['page'];
            $first   = array() === $records ? 0 : ( ( $page - 1 ) * $perPage ) + 1;
            $last    = array() === $records ? 0 : $first + count( $records ) - 1;

            $tableColumns = array_map(
                static fn ( array $column ): array => array( 'label' => (string) $column['label'] ),
                $columns
            );
            $tableColumns[] = array( 'srLabel' => 'Actions' );
        @endphp

        @if ( count( $entities ) > 1 )
            <nav aria-label="Entities" style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem;">
                @foreach ( $entities as $candidate )
                    <a
                        href="{{ $this->entityUrl( (string) $candidate['key'] ) }}"
                        @if ( $candidate['key'] === $entity ) aria-current="page" @endif
                        style="padding: 0.35rem 0.75rem; border-radius: 999px; text-decoration: none; color: inherit; border: 1px solid rgba(127, 127, 127, 0.35); {{ $candidate['key'] === $entity ? 'font-weight: 600; background: rgba(127, 127, 127, 0.15);' : '' }}"
                    >
                        {{ $candidate['icon'] }} {{ $candidate['label'] }}
                    </a>
                @endforeach
            </nav>
        @endif

        {{-- GET form: the search is server-side, so the page reports what the
             engine actually matched rather than filtering a fetched payload.
             The action carries no query string, so the hidden entity survives. --}}
        <form method="get" action="{{ $this->screenUrl( 'entities' ) }}" style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
            <input type="hidden" name="entity" value="{{ $entity }}">

            <div style="flex: 1 1 18rem;">
                <label for="toolkit-entity-search" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Search</label>
                <input
                    id="toolkit-entity-search"
                    type="search"
                    name="search"
                    value="{{ $search }}"
                    placeholder="Search {{ strtolower( (string) ( $config['label'] ?? 'records' ) ) }}"
                    style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
                >
            </div>

            <x-filament::button type="submit">
                Search
            </x-filament::button>

            @if ( '' !== $search )
                <a
                    href="{{ $this->entityUrl( $entity ) }}"
                    style="padding: 0.5rem 0.75rem; font-size: 0.85rem;"
                >
                    Clear
                </a>
            @endif
        </form>

        <p style="margin: 0 0 0.5rem; font-size: 0.85rem; opacity: 0.7;">
            @if ( 0 === $total )
                No {{ strtolower( (string) ( $config['label'] ?? 'records' ) ) }} in this estate.
            @else
                Showing {{ $first }}–{{ $last }} of {{ number_format( $total ) }}.
            @endif
        </p>

        <x-admin.data-table
            :caption="(string) ( $config['label'] ?? 'Toolkit records' ) . ' in this estate'"
            :columns="$tableColumns"
            :rows="count( $records )"
        >
            @if ( array() === $records )
                <tr>
                    <td colspan="{{ count( $tableColumns ) }}" style="padding: 1rem; opacity: 0.7;">
                        No records match this filter.
                    </td>
                </tr>
            @else
                @foreach ( $records as $record )
                    <tr wire:key="toolkit-record-{{ $record['id'] }}">
                        @foreach ( $columns as $column )
                            <td style="padding: 0.5rem 0.75rem;">{{ $cell( $record, (string) $column['key'] ) }}</td>
                        @endforeach

                        <td style="padding: 0.5rem 0.75rem; white-space: nowrap;">
                            @if ( $pendingDelete === $record['id'] )
                                <button
                                    type="button"
                                    wire:click="deleteRecord"
                                    style="padding: 0.3rem 0.6rem; border-radius: 0.4rem; border: 1px solid #991b1b; color: #991b1b; background: transparent; cursor: pointer;"
                                >
                                    Confirm delete
                                </button>
                                <button
                                    type="button"
                                    wire:click="cancelDelete"
                                    style="padding: 0.3rem 0.6rem; margin-left: 0.35rem; border-radius: 0.4rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent; cursor: pointer;"
                                >
                                    Cancel
                                </button>
                            @else
                                <button
                                    type="button"
                                    wire:click="armDelete({{ \Illuminate\Support\Js::from( $record['id'] ) }})"
                                    style="padding: 0.3rem 0.6rem; border-radius: 0.4rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent; cursor: pointer;"
                                >
                                    Delete
                                </button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            @endif
        </x-admin.data-table>

        @if ( $total > $perPage )
            <div style="display: flex; gap: 0.75rem; align-items: center; margin-top: 1rem;">
                @if ( $page > 1 )
                    <a href="{{ $this->entityUrl( $entity, array_filter( array( 'search' => $search, 'page' => $page - 1 ) ) ) }}">
                        ← Previous
                    </a>
                @endif

                <span style="font-size: 0.85rem; opacity: 0.7;">
                    Page {{ $page }} of {{ (int) ceil( $total / $perPage ) }}
                </span>

                @if ( $last < $total )
                    <a href="{{ $this->entityUrl( $entity, array_filter( array( 'search' => $search, 'page' => $page + 1 ) ) ) }}">
                        Next →
                    </a>
                @endif
            </div>
        @endif
    @endif
</x-filament-panels::page>
