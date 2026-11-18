{{--
    Assistant MCP apps — Proposal 149 §43.

    Plain HTML with inline styles for everything outside the Filament schema: the
    panel's compiled CSS has no arbitrary Tailwind utilities.

    The token field lives inside the schema and is deliberately empty on load.
    Nothing on this page prints a mask, a tail or a value — a "stored" column
    would invite the question this surface answers with "write-only".
--}}
@php
    $assistants = $this->assistants();
    $max = $this->maxApps();
@endphp

<x-filament-panels::page>
    @if ( array() === $assistants )
        <p style="font-size: 0.85rem;">
            No assistants in this estate yet. Create one in <strong>Assistants</strong>
            first, then assign its MCP apps here.
        </p>
    @else
        <div style="margin-bottom: 1rem;">
            <label for="mcp-apps-assistant" style="display: block; font-size: 0.85rem; margin-bottom: 0.35rem;">
                Assistant
            </label>
            <select
                id="mcp-apps-assistant"
                wire:model.live="assistantId"
                style="min-width: 20rem; padding: 0.4rem 0.6rem; border-radius: 0.375rem; border: 1px solid rgba(127, 127, 127, 0.4); font-size: 0.85rem;"
            >
                <option value="">— Select an assistant —</option>
                @foreach ( $assistants as $assistant )
                    <option value="{{ $assistant['id'] }}">{{ $assistant['title'] }}</option>
                @endforeach
            </select>
        </div>

        @if ( '' === $this->assistantId )
            <p style="font-size: 0.85rem;">
                Choose an assistant to see and edit its MCP apps.
            </p>
        @else
            <p style="font-size: 0.85rem; margin-bottom: 1rem;">
                Up to <strong>{{ $max }}</strong> apps per assistant. The same list the
                console edits. Tokens are <strong>write-only</strong>: the field is empty on
                load, and leaving it blank keeps whatever is already sealed.
            </p>

            <p style="font-size: 0.8rem; opacity: 0.85; margin-bottom: 1rem; padding: 0.5rem 0.7rem; border-radius: 0.5rem; background: rgba(234, 179, 8, 0.12); border: 1px solid rgba(234, 179, 8, 0.35);">
                A stored token is carried by the connection reference, or by the server URL
                when the reference is blank. Changing either one makes this a different
                connection, so re-paste the token or it will not be carried over.
            </p>

            <form wire:submit="save">
                {{ $this->form }}

                <div style="margin-top: 1.5rem;">
                    <x-filament::button type="submit">
                        Save MCP apps
                    </x-filament::button>
                </div>
            </form>
        @endif
    @endif
</x-filament-panels::page>
