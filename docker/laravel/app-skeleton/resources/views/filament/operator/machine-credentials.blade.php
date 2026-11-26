<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            What revocation does
        </x-slot>

        <x-slot name="description">
            A machine credential belongs to an assistant, not to a person, so the operator
            who issued it is the operator who revokes it. Deleting it is the whole
            revocation: Sanctum resolves the token on every request, so the next call fails.
            Personal tokens stay with their owner under Connections, where the audit trail
            can name the owner as the actor.
        </x-slot>
    </x-filament::section>

    {{ $this->table }}
</x-filament-panels::page>
