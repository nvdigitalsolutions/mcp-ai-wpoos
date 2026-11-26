<x-filament-panels::page>
    {{-- Inline spacing, not Tailwind utilities: the panel's compiled CSS does not
         include arbitrary utilities, and a missing class here would collapse the
         gap between the form and its save button. --}}
    <form wire:submit="save">
        {{ $this->form }}

        <div style="margin-top: 1.5rem;">
            <x-filament::button type="submit">
                Save settings
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
