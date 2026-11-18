{{--
    Toolkit catalogue — Proposal 149 Wave 3.

    Inline styles only: the panel's compiled CSS has no arbitrary Tailwind
    utilities, so a utility class here would silently collapse the layout.

    One card per licensed manifest. The card links to the toolkit's settings
    screen; the detail page owns the screen nav. An unlicensed toolkit never
    reaches this list, which is why there is no "lock" state to render.
--}}
@php
    $toolkits = $this->toolkits();
@endphp

<x-filament-panels::page>
    <p style="margin: 0 0 1rem; opacity: 0.75;">
        Toolkits this estate is licensed for. Each screen below renders from the
        toolkit's manifest — settings, entity records and dashboard cards — so a
        new toolkit is a manifest, not a page.
    </p>

    @if ( array() === $toolkits )
        <div style="padding: 1.5rem; border: 1px solid rgba(127, 127, 127, 0.3); border-radius: 0.75rem;">
            <p style="margin: 0; font-weight: 600;">No toolkits are licensed for this estate.</p>
            <p style="margin: 0.5rem 0 0; opacity: 0.75;">
                Manifests are discovered from the runtime's toolkit directory and filtered by
                the estate's entitlement. An empty list means neither condition is met — not a
                loading state.
            </p>
        </div>
    @else
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(18rem, 1fr)); gap: 1rem;">
            @foreach ( $toolkits as $toolkit )
                @php
                    // A manifest may be console-only (no panel screen). Link
                    // only where a screen exists; a dead link would read as a
                    // broken toolkit rather than a deliberate omission.
                    $firstScreen = (string) ( ( $toolkit['screens'][0] ?? '' ) );
                    $href = '' !== $firstScreen
                        ? $this->toolkitUrl( (string) $toolkit['slug'], $firstScreen )
                        : '';
                @endphp

                <div style="padding: 1rem; border: 1px solid rgba(127, 127, 127, 0.3); border-radius: 0.75rem;">
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span aria-hidden="true">{{ $toolkit['icon'] }}</span>
                        @if ( '' !== $href )
                            <a href="{{ $href }}" style="font-weight: 700; color: inherit;">{{ $toolkit['name'] }}</a>
                        @else
                            <strong>{{ $toolkit['name'] }}</strong>
                        @endif
                    </div>

                    <p style="margin: 0.5rem 0 0; opacity: 0.75;">{{ $toolkit['description'] }}</p>

                    <p style="margin: 0.75rem 0 0; font-size: 0.8rem; opacity: 0.6;">
                        v{{ $toolkit['version'] }}
                        · {{ count( (array) $toolkit['entities'] ) }} {{ 1 === count( (array) $toolkit['entities'] ) ? 'entity' : 'entities' }}
                        · {{ array() === (array) $toolkit['screens'] ? 'console only' : implode( ', ', (array) $toolkit['screens'] ) }}
                    </p>
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
