{{--
    Media library — Proposal 149 §109.

    Read-only: upload and alt editing stay on the console, where the write path
    already exists and already audits. Plain HTML with inline styles, as on the
    other ported pages — the panel's compiled CSS has no arbitrary utilities.

    One honesty device: WordPress reports 100% alt coverage when there are no
    images to measure. That is a true number saying something false, so it is
    labelled rather than printed as a success.
--}}
@php
    $summary = $this->summary();
    $library = $this->library();
    $measured = $this->altIsMeasured();
@endphp

<x-filament-panels::page>
    @unless ($measured)
        <x-filament::callout
            color="info"
            icon="heroicon-o-information-circle"
            heading="No images to measure"
            description="Alt coverage and next-gen coverage are shown as 100% only because there is nothing to divide by — the same reading WordPress gives an empty library. They are not a clean bill of health."
        />
    @endunless

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem;">
        @foreach ([
            ['Files', number_format($summary['total']), 'in this estate'],
            ['Images', number_format($summary['image_count']), 'the alt figures are about these'],
            ['Alt coverage', $measured ? $summary['alt_pct'].'%' : '—', $measured ? number_format($summary['with_alt']).' with alt text' : 'nothing to measure'],
            ['Storage', $this->humanBytes($summary['storage']), 'across every file'],
            ['Next-gen', $measured ? $summary['nextgen_pct'].'%' : '—', 'WebP or AVIF'],
        ] as [$label, $value, $note])
            <div style="padding: 0.9rem 1rem; border: 1px solid rgba(127, 127, 127, 0.25); border-radius: 0.5rem;">
                <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.75;">{{ $label }}</div>
                <div style="font-size: 1.5rem; font-weight: 600; line-height: 1.2; font-variant-numeric: tabular-nums; margin: 0.15rem 0;">{{ $value }}</div>
                <div style="font-size: 0.75rem; opacity: 0.7;">{{ $note }}</div>
            </div>
        @endforeach
    </div>

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(127, 127, 127, 0.25);">
        <label for="media-search" style="font-weight: 600;">Search</label>
        <input
            id="media-search"
            type="search"
            wire:model.live.debounce.400ms="search"
            placeholder="filename"
            style="padding: 0.35rem 0.5rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem; min-width: 14rem;"
        />

        <label for="media-type" style="font-weight: 600; margin-left: 0.5rem;">Type</label>
        <select
            id="media-type"
            wire:model.live="type"
            style="padding: 0.35rem 0.5rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
        >
            @foreach ($this->types() as $value => $label)
                <option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <span style="font-size: 0.8rem; opacity: 0.85; margin-left: auto;">
            {{ number_format($library['total']) }} matching {{ 1 === $library['total'] ? 'file' : 'files' }}
        </span>
    </div>

    @if ([] === $library['rows'])
        <p style="font-size: 0.9rem; opacity: 0.8;">
            Nothing in this estate's library matches that filter.
        </p>
    @else
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                    <th style="padding: 0.5rem 0.5rem 0.5rem 0;">File</th>
                    <th style="padding: 0.5rem;">Type</th>
                    <th style="padding: 0.5rem;">Size</th>
                    <th style="padding: 0.5rem;">Alt text</th>
                    <th style="padding: 0.5rem;">Added</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($library['rows'] as $row)
                    <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                        <td style="padding: 0.5rem 0.5rem 0.5rem 0; overflow-wrap: anywhere;">{{ $row['filename'] }}</td>
                        <td style="padding: 0.5rem; opacity: 0.85;">{{ '' !== $row['mime_type'] ? $row['mime_type'] : '—' }}</td>
                        <td style="padding: 0.5rem; font-variant-numeric: tabular-nums; white-space: nowrap;">{{ $this->humanBytes($row['size_bytes']) }}</td>
                        <td style="padding: 0.5rem;">
                            @if ('' === $row['alt_text'])
                                <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.7rem; color: #92400e; background: rgba(245, 158, 11, 0.15);">missing</span>
                            @else
                                <span style="opacity: 0.9;">{{ $row['alt_text'] }}</span>
                            @endif
                        </td>
                        <td style="padding: 0.5rem; font-size: 0.8rem; opacity: 0.7; white-space: nowrap;">
                            {{ '' !== $row['created_at'] ? substr($row['created_at'], 0, 10) : '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($library['pages'] > 1)
            <div style="display: flex; gap: 0.5rem; align-items: center; margin-top: 1rem; font-size: 0.85rem;">
                <button
                    type="button"
                    wire:click="goToPage({{ $library['page'] - 1 }})"
                    @disabled($library['page'] <= 1)
                    style="padding: 0.3rem 0.7rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
                >Previous</button>

                <span style="opacity: 0.8;">
                    Page {{ $library['page'] }} of {{ $library['pages'] }}
                </span>

                <button
                    type="button"
                    wire:click="goToPage({{ $library['page'] + 1 }})"
                    @disabled($library['page'] >= $library['pages'])
                    style="padding: 0.3rem 0.7rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
                >Next</button>
            </div>
        @endif
    @endif

    <p style="font-size: 0.8rem; opacity: 0.75; margin-top: 1.25rem; padding-top: 0.75rem; border-top: 1px solid rgba(127, 127, 127, 0.25);">
        This estate's files only. Uploading and editing alt text stay on the console,
        where the write path already audits; this page reads. Files written before the
        library carried an estate are attributed to <code>default</code>, so a library
        that predates it may show a different count here than the console's global one.
    </p>
</x-filament-panels::page>
