<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            What the allowlist decides
        </x-slot>

        <x-slot name="description">
            An allowlisted tool still needs the assistant to assign it, and a tool whose
            annotations say it destroys data or changes state is still routed to a person
            for approval. Allowlisting permits; it never makes a call unattended.
        </x-slot>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
