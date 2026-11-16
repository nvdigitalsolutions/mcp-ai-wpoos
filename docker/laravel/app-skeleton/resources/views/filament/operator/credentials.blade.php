<x-filament-panels::page>
    @if ($issuedToken)
        <x-filament::section>
            <x-slot name="heading">
                New credential for {{ $issuedFor }}
            </x-slot>

            <x-slot name="description">
                Copy it now: it is shown once and cannot be recovered. If you lose it, rotate again.
                @if ($issuedExpiresAt)
                    Expires {{ $issuedExpiresAt }}.
                @endif
            </x-slot>

            {{-- Inline styles, not utility classes: the panel's compiled CSS does
                 not include arbitrary Tailwind utilities, and a missing class
                 would silently collapse the one element that has to stay readable. --}}
            <pre style="white-space: pre-wrap; word-break: break-all; font-size: 0.85rem; line-height: 1.5; padding: 0.75rem; border-radius: 0.5rem; background: rgba(127, 127, 127, 0.12);">{{ $issuedToken }}</pre>

            <div style="margin-top: 0.75rem;">
                <x-filament::button type="button" color="gray" size="sm" wire:click="dismissIssued">
                    Hide credential
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
