<x-filament-panels::page>
    @php
        $overdue = $this->overdueCount();
    @endphp

    @if ($overdue > 0)
        <x-filament::callout
            color="danger"
            icon="heroicon-o-exclamation-triangle"
            heading="{{ $overdue }} enabled schedule(s) overdue"
            description="Their next run time has passed and nothing advanced it, which means the scheduler tick is not running. Check the scheduler container role runs php artisan schedule:work, then use Run due now to catch up."
        />
    @endif

    {{ $this->table }}
</x-filament-panels::page>
