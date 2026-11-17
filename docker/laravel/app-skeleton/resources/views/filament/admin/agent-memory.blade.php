{{--
    Agent memory — Proposal 149 §40.

    Read-only by construction: the table has no mutating action, so this view
    deliberately renders nothing but the table.
--}}
<x-filament-panels::page>
    {{ $this->table }}
</x-filament-panels::page>
