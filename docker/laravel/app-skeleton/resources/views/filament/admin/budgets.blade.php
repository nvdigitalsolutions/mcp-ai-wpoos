{{--
    Spend and limits — Proposal 154 slice B, `/admin/budgets`.

    Read-only by construction: the table has no mutating action. The callouts are
    the point of the page — an estate finds out it is burning its limit before the
    limit refuses a chat turn, not after.
--}}
@php
    $exhausted = $this->exhaustedCount();
    $warning = $this->warnCount();
@endphp

<x-filament-panels::page>
    @if ($exhausted > 0)
        <x-filament::callout
            color="danger"
            icon="heroicon-o-exclamation-triangle"
            heading="Over budget"
            description="Chat and workflow agents are being refused on this estate until the limit is raised or the window resets. Contact the platform operator; limits are not editable here."
        />
    @elseif ($warning > 0)
        <x-filament::callout
            color="warning"
            icon="heroicon-o-clock"
            heading="Approaching a limit"
            description="This estate is past its warning threshold. Spend resets at midnight for a daily limit and on the 1st for a monthly one."
        />
    @endif

    {{ $this->table }}

    <p style="font-size: 0.8rem; margin-top: 1rem; opacity: 0.85;">
        Spend is the sum of recorded token cost over the window, so these figures match
        the cost reports. Limits are set by the platform operator. The check runs before
        a provider is called, so one turn can still overshoot by that turn's cost.
    </p>
</x-filament-panels::page>
