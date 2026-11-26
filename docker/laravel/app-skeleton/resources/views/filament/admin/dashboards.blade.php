{{--
    Dashboards — Proposal 149 §113.

    Plain HTML with inline styles, as on the other ported pages: the panel's
    compiled CSS has no arbitrary utilities, so a Tailwind class here would
    silently do nothing.

    Four devices, each a decision rather than decoration:

      - **a dashboard this runtime cannot feed is absent, not greyed.** The
        console greys them because on WordPress they are one toggle away; here
        there is no toggle, and a disabled control promises an enabled state the
        reader can reach. Only `servable()` dashboards are rendered at all.
      - **the picker appears once there is a choice.** One dashboard needs no
        switcher; the console takes the same line (`dashboards.length > 1`).
      - **the list always renders, even when empty.** The shared table component
        puts the empty state in the body so the column headings survive a filter
        that matches nothing (§55.5) — hiding the whole section on empty would
        undo exactly that.
      - **every bar carries its label and its value as text**, so nothing is
        encoded by colour alone (WCAG 1.4.1).
--}}
@php
    $dashboards = $this->dashboards();
    $config = $this->config();
    $data = $this->data();
    $selected = null;

    foreach ($dashboards as $candidate) {
        if ($candidate['id'] === $this->dashboard) {
            $selected = $candidate;
        }
    }

    $selected = $selected ?? ($dashboards[0] ?? null);
@endphp

<x-filament-panels::page>
    @if (null === $selected)
        {{-- Unreachable while `eca` is servable, and the honest answer if the
             catalogue ever loses its last store: say so rather than draw an
             empty chart. --}}
        <x-filament::callout
            color="info"
            icon="heroicon-o-information-circle"
            heading="No dashboards on this runtime"
            description="This deployment does not host any of the data stores a dashboard reads. Nothing here is switched off — the storage itself is not present."
        />
    @else
        @if (count($dashboards) > 1)
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 1rem;">
                @foreach ($dashboards as $candidate)
                    <button
                        type="button"
                        aria-pressed="{{ $candidate['id'] === $selected['id'] ? 'true' : 'false' }}"
                        wire:click="$set('dashboard', '{{ $candidate['id'] }}')"
                        style="padding: 0.4rem 0.75rem; border-radius: 999px; border: 1px solid rgba(127, 127, 127, 0.4); background: {{ $candidate['id'] === $selected['id'] ? 'rgba(127, 127, 127, 0.18)' : 'transparent' }}; font-weight: 600; cursor: pointer;"
                    >
                        {{ $candidate['icon'] }} {{ $candidate['label'] }}
                    </button>
                @endforeach
            </div>
        @endif

        @if (null === $config)
            {{-- The store exists here; the estate's toolkit is off. That is a
                 disabled state the reader can fix, which is precisely why it is
                 shown rather than hidden. --}}
            <x-filament::callout
                color="warning"
                icon="heroicon-o-lock-closed"
                heading="{{ $selected['label'] }} is switched off for this estate"
                description="Its toolkit is disabled, so there is nothing to gather yet. Nothing is lost while it is off — the records are the same rows, read the same way."
            />

            @if ('' !== $selected['flag'])
                <p style="font-size: 0.85rem; opacity: 0.8; margin-top: 0.75rem;">
                    Enable <code>{{ $selected['flag'] }}</code> under
                    <a href="{{ $this->settingsUrl() }}" style="text-decoration: underline;">Settings → Tools &amp; Features</a>,
                    then reload this page.
                </p>
            @endif
        @elseif (null === $data)
            <x-filament::callout
                color="danger"
                icon="heroicon-o-exclamation-triangle"
                heading="This dashboard could not be read"
                description="Its store exists on this runtime but the query failed. That is a fault, not an empty estate — check the diagnostics page before concluding there is no data."
            />
        @else
            <p style="opacity: 0.8; margin-bottom: 1.25rem;">{{ $config['description'] }}</p>

            @if ([] !== ($config['filters'] ?? []))
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: end; margin-bottom: 1.25rem; padding-bottom: 1.25rem; border-bottom: 1px solid rgba(127, 127, 127, 0.25);">
                    @foreach ($config['filters'] as $filter)
                        <label style="display: flex; flex-direction: column; gap: 0.25rem; font-weight: 600; font-size: 0.85rem;">
                            {{ $filter['label'] }}

                            @if ('select' === $filter['type'] && $this->bound($filter['key']))
                                <select
                                    wire:model.live="{{ $filter['key'] }}"
                                    style="padding: 0.35rem 0.5rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem; font-weight: 400;"
                                >
                                    @foreach ($filter['options'] as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            @else
                                {{-- Either a filter this page has no control for
                                     (a `member` picker, which no servable
                                     dashboard declares) or a select whose key has
                                     no bound property. Saying so beats rendering
                                     a control that silently matches nothing. --}}
                                <span style="font-weight: 400; opacity: 0.7;">
                                    Not filterable on this page yet
                                </span>
                            @endif
                        </label>
                    @endforeach

                    <span style="font-size: 0.8rem; opacity: 0.6;">Filters apply as you change them.</span>
                </div>
            @endif

            @if ([] !== ($config['metrics'] ?? []))
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 0.75rem; margin-bottom: 1.5rem;">
                    @foreach ($config['metrics'] as $metric)
                        <div style="padding: 0.75rem 0.9rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem;">
                            <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.75;">
                                {{ $metric['label'] }}
                            </div>
                            <div style="font-size: 1.5rem; font-weight: 600; line-height: 1.2; font-variant-numeric: tabular-nums; margin-top: 0.15rem;">
                                {{ $this->format($data[$metric['key']] ?? null, $metric['format'] ?? 'number') }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @foreach (($config['charts'] ?? []) as $chart)
                @php $bars = $this->bars($this->chartData($chart['key'])); @endphp

                <section style="margin-bottom: 1.75rem;" aria-label="{{ $chart['title'] }}">
                    <div style="font-weight: 600; margin-bottom: 0.5rem;">{{ $chart['title'] }}</div>

                    @if ([] === $bars)
                        <p style="font-size: 0.85rem; opacity: 0.7; margin: 0;">
                            Nothing to plot for the current filters.
                        </p>
                    @else
                        <ul style="list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.3rem;">
                            @foreach ($bars as $bar)
                                <li style="display: grid; grid-template-columns: minmax(6rem, 12rem) 1fr auto; gap: 0.6rem; align-items: center; font-size: 0.85rem;">
                                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $bar['label'] }}</span>
                                    <span
                                        aria-hidden="true"
                                        style="height: 0.6rem; border-radius: 0.2rem; background: rgba(127, 127, 127, 0.18); overflow: hidden;"
                                    >
                                        <span style="display: block; height: 100%; width: {{ $bar['width'] }}%; background: rgba(37, 99, 235, 0.75);"></span>
                                    </span>
                                    <span style="font-variant-numeric: tabular-nums; opacity: 0.85;">{{ $bar['display'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach

            @foreach (($config['lists'] ?? []) as $list)
                @php $rows = $this->rows($list['key']); @endphp

                <section style="margin-bottom: 1.75rem;" aria-label="{{ $list['title'] }}">
                    <div style="font-weight: 600; margin-bottom: 0.5rem;">{{ $list['title'] }}</div>

                    <x-admin.data-table
                        :caption="$list['title']"
                        :columns="array_map(
                            static fn (array $column): array => ['label' => $column['label']],
                            $list['columns']
                        )"
                        :rows="count($rows)"
                        empty="No activity matches the current filters."
                    >
                        @foreach ($rows as $row)
                            <tr>
                                @foreach ($list['columns'] as $column)
                                    @php $value = $row[$column['key']] ?? null; @endphp

                                    <td>
                                        @if (isset($column['format']))
                                            <span style="font-variant-numeric: tabular-nums;">
                                                {{ $this->format($value, $column['format']) }}
                                            </span>
                                        @elseif (null === $value || '' === (string) $value)
                                            — 
                                        @else
                                            {{ $value }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </x-admin.data-table>
                </section>
            @endforeach
        @endif
    @endif
</x-filament-panels::page>
