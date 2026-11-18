{{--
    The panel's one data-table contract — Proposal 149 §55.5, step 4.

    Eight tables across blueprints, paper-store and OKF were hand-rolled copies
    of the same six inline declarations, and each copy had drifted: no
    `<caption>` anywhere, `scope` on some tables and not others, no sticky
    header, and cell padding spelled out on every `<td>`. This component owns
    the shell so those declarations exist once.

    The contract is deliberately narrow.

    - `caption` is **required** and rendered visually hidden. A data table
      without an accessible name is a WCAG 1.3.1 failure, and all eight were
      missing one.
    - `columns` is a list of `array('label' => …)` or `array('srLabel' => …)`.
      The header row is rendered here, so `scope="col"` cannot be forgotten.
      `srLabel` is for the actions column, whose heading is empty on screen.
    - `rows` + `empty` move the empty state **into the table body**, where the
      header stays visible: an operator who filtered to nothing can still see
      what they filtered on. Pass the row count; the slot renders when it is
      non-zero.
    - The body is the slot. Cell markup stays with the page because a cell here
      holds buttons, `<code>` and nested blocks — a value renderer would have to
      own escaping, and `{!! !!}` inside a shared component is how an XSS
      foothold gets handed to every page at once.

    Deferred on purpose, not forgotten (§55.14 records the same list):

    - **`aria-sort` header buttons.** None of the three pages has an ordering
      input — blueprints is a code-shipped array, paper-store is file-backed,
      OKF is a parsed tree — so a sortable header would be a prop nothing
      passes and a path nothing tests. The component grows
      `sort`/`direction`/`sortAction` in the slice that gives a page an
      ordering path, and that slice's tests cover it.
    - **A footer slot.** Nothing here paginates; the "Showing N" totals belong
      to the filter block above the table, where they describe the filter.
    - **Numeric right-alignment.** Every count column on the three pages is
      left-aligned today. Alignment has to be applied to the body cells too,
      which the page owns, so adding it here alone would create exactly the
      drift this component exists to remove.

    Styles live in a `@once` block scoped by `[data-admin-data-table]`: one
    `<style>` per rendered page no matter how many tables, and no class names to
    keep in sync with a stylesheet the panel does not have. The sticky header
    needs **two** background declarations, not one: `--gray-50` is the light body
    colour and is not theme-scoped, so the header mirrors Filament's own
    `.fi-body:where(.dark, .dark *)` rule and switches to `--gray-950` under
    `.dark`. `border-collapse: separate` with `border-spacing: 0` keeps the
    header's rule from scrolling away with the collapsed border; the separator
    is drawn with an inset shadow instead.
--}}
@props([
    'caption',
    'columns' => array(),
    'rows' => null,
    'empty' => null,
    'maxHeight' => '70vh',
])

@php
    $columns = array_values( $columns );
    $columnCount = count( $columns );
    $showEmpty = null !== $rows && 0 === (int) $rows;
@endphp

@once
    <style>
        [data-admin-data-table] {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.85rem;
        }

        [data-admin-data-table] thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            padding: 0.5rem;
            text-align: left;
            font-weight: 600;
            background: var(--gray-50);
            box-shadow: inset 0 -1px 0 rgba(127, 127, 127, 0.35);
        }

        /*
         * `--gray-50` is NOT theme-scoped: it stays light when `.dark` is on, so
         * the header would have been a white band on a dark page. This mirrors
         * Filament's own body rule (`.fi-body:where(.dark, .dark *)`), which
         * switches to `--gray-950`. Measured in a browser, not inferred: with
         * `.dark` the body computed to gray-950 while the header stayed
         * gray-50 until this rule was added. `:where()` carries no specificity,
         * so the later declaration is what wins.
         */
        :where(.dark, .dark *) [data-admin-data-table] thead th {
            background: var(--gray-950);
        }

        [data-admin-data-table] tbody td {
            padding: 0.5rem;
            vertical-align: top;
            border-bottom: 1px solid rgba(127, 127, 127, 0.15);
        }

        [data-admin-data-table] tbody tr:last-child td {
            border-bottom: 0;
        }

        [data-admin-data-table] thead th:first-child,
        [data-admin-data-table] tbody td:first-child {
            padding-left: 0;
        }

        [data-admin-data-table] thead th:last-child,
        [data-admin-data-table] tbody td:last-child {
            padding-right: 0;
        }
    </style>
@endonce

<div @if ( null !== $maxHeight ) style="max-height: {{ $maxHeight }}; overflow: auto;" @endif>
    <table data-admin-data-table>
        <caption class="fi-sr-only">{{ $caption }}</caption>

        <thead>
            <tr>
                @foreach ( $columns as $column )
                    <th scope="col">
                        @if ( '' !== (string) ( $column['label'] ?? '' ) )
                            {{ $column['label'] }}
                        @elseif ( null !== ( $column['srLabel'] ?? null ) )
                            <span class="fi-sr-only">{{ $column['srLabel'] }}</span>
                        @endif
                    </th>
                @endforeach
            </tr>
        </thead>

        <tbody>
            @if ( $showEmpty )
                <tr>
                    <td colspan="{{ $columnCount }}" style="padding: 0.75rem 0; opacity: 0.8;">{{ $empty }}</td>
                </tr>
            @else
                {{ $slot }}
            @endif
        </tbody>
    </table>
</div>
