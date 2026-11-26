<x-filament-panels::page>
    {{-- Read-only by construction: the table has no mutating action, so this view
         deliberately renders nothing but the table. --}}
    {{ $this->table }}
</x-filament-panels::page>
