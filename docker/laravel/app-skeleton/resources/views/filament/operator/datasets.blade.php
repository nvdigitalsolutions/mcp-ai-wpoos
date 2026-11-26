{{--
    Dataset browser — Proposal 149 §95.

    A catalogue with a search box, in the same shape as `/admin/tools`: plain
    HTML controls and inline styles, because the panel's compiled CSS has no
    arbitrary Tailwind utilities and a missing class here would silently collapse
    the table.

    Two tables, two jobs: what the runtime can ask for (derived from the tools),
    and which datasets are good starting points (derived from the tool that
    answers them).
--}}
@php
    $summary = $this->summary();
    $rows = $this->filtered();
    $recommended = $this->recommendations();
    $categories = $this->categories();
    $tokenConfigured = $this->tokenConfigured();

    $badge = static function ( string $readiness ): array {
        return match ( $readiness ) {
            'ready'       => array( '#065f46', 'rgba(16, 185, 129, 0.15)' ),
            'pure'        => array( '#1e40af', 'rgba(59, 130, 246, 0.15)' ),
            'delegated'   => array( '#5b21b6', 'rgba(139, 92, 246, 0.15)' ),
            'unverified'  => array( '#92400e', 'rgba(245, 158, 11, 0.15)' ),
            'quarantined' => array( '#991b1b', 'rgba(239, 68, 68, 0.15)' ),
            default       => array( '#374151', 'rgba(107, 114, 128, 0.15)' ),
        };
    };
@endphp

<x-filament-panels::page>
    @unless ($tokenConfigured)
        <x-filament::callout
            color="warning"
            icon="heroicon-o-exclamation-triangle"
            heading="No deployment token"
            description="Public datasets work without one. Gated datasets need a Hugging Face token, and tokens are per estate — set one in the estate's Settings → Providers → Hugging Face, or set HUGGINGFACE_API_KEY for the deployment."
        />
    @endunless

    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
        <div style="flex: 1 1 18rem;">
            <label for="dataset-search" style="display: block; font-size: 0.8rem; margin-bottom: 0.25rem;">Search</label>
            <input
                id="dataset-search"
                type="search"
                wire:model.live.debounce.400ms="search"
                placeholder="Slug, name, description or endpoint"
                style="width: 100%; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent;"
            >
        </div>
    </div>

    <p style="font-size: 0.85rem; margin-bottom: 1rem; opacity: 0.85;">
        {{ $summary['total'] }} tools: {{ $summary['api'] }} call <code>{{ $this->apiBase() }}</code>,
        {{ $summary['static'] }} answer from a built-in list. Endpoints are read from the tools
        themselves, so this page describes the routes that exist.
        Showing {{ count( $rows ) }} of {{ $summary['total'] }}.
    </p>

    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                <th style="padding: 0.5rem 0.5rem 0.5rem 0;">Tool</th>
                <th style="padding: 0.5rem;">Endpoint</th>
                <th style="padding: 0.5rem;">Readiness</th>
                <th style="padding: 0.5rem;">What it answers</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php
                    [ $badgeColor, $badgeBackground ] = $badge( (string) $row['readiness'] );
                @endphp
                <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                    <td style="padding: 0.5rem 0.5rem 0.5rem 0;">
                        <div>{{ $row['name'] }}</div>
                        <code style="font-size: 0.75rem; opacity: 0.75;">{{ $row['slug'] }}</code>
                    </td>
                    <td style="padding: 0.5rem;">
                        @if (null === $row['endpoint'])
                            <span style="opacity: 0.75;">built-in list</span>
                        @else
                            <code style="font-size: 0.8rem;">{{ $row['endpoint'] }}</code>
                        @endif
                    </td>
                    <td style="padding: 0.5rem;">
                        <span style="display: inline-block; padding: 0.1rem 0.5rem; border-radius: 999px; font-size: 0.75rem; color: {{ $badgeColor }}; background: {{ $badgeBackground }};">
                            {{ $row['readiness'] }}
                        </span>
                    </td>
                    <td style="padding: 0.5rem;">{{ $row['description'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="padding: 1rem 0; font-size: 0.85rem;">No tool matches that search.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <h2 style="font-size: 1rem; margin: 1.75rem 0 0.25rem;">Starting points</h2>

    <p style="font-size: 0.8rem; margin-bottom: 0.75rem; opacity: 0.85;">
        The curated list the runtime already answers from — the same rows
        <code>huggingface_recommended_datasets</code> returns.
    </p>

    @if (count( $categories ) > 1)
        <div style="margin-bottom: 0.75rem;">
            <label for="dataset-category" style="font-size: 0.8rem; margin-right: 0.5rem;">Category</label>
            <select
                id="dataset-category"
                wire:model.live="category"
                style="padding: 0.35rem 0.5rem; border: 1px solid rgba(127, 127, 127, 0.4); border-radius: 0.375rem;"
            >
                <option value="">All categories</option>
                @foreach ($categories as $option)
                    <option value="{{ $option }}" @selected( $category === $option )>{{ $option }}</option>
                @endforeach
            </select>
        </div>
    @endif

    <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="text-align: left; border-bottom: 1px solid rgba(127, 127, 127, 0.35);">
                <th style="padding: 0.5rem 0.5rem 0.5rem 0;">Dataset</th>
                <th style="padding: 0.5rem;">Category</th>
                <th style="padding: 0.5rem;">Rows</th>
                <th style="padding: 0.5rem;">Why it is here</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($recommended as $dataset)
                <tr style="border-bottom: 1px solid rgba(127, 127, 127, 0.15);">
                    <td style="padding: 0.5rem 0.5rem 0.5rem 0;"><code style="font-size: 0.8rem;">{{ $dataset['dataset'] }}</code></td>
                    <td style="padding: 0.5rem;">{{ $dataset['category'] }}</td>
                    <td style="padding: 0.5rem;">{{ number_format( $dataset['rows'] ) }}</td>
                    <td style="padding: 0.5rem;">{{ $dataset['description'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="padding: 1rem 0; font-size: 0.85rem;">No dataset in that category.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</x-filament-panels::page>
