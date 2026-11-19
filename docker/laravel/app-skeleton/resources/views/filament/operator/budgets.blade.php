{{--
    Budgets — Proposal 154 slice B, `/ops/budgets`.

    The table is the surface; the callout is the one thing an operator must not
    have to read the rows to discover. Both read the same verdicts as the guard
    (`BudgetStore::verdict()`), so "exhausted" here means the runtime is refusing
    turns, not that a number looks high.
--}}
@php
    $exhausted = $this->exhaustedCount();
@endphp

<x-filament-panels::page>
    @if ($exhausted > 0)
        <x-filament::callout
            color="danger"
            icon="heroicon-o-exclamation-triangle"
            heading="{{ $exhausted }} budget(s) exhausted"
            description="Chat turns and workflow agents on those estates are refused with 429 budget_exceeded until the limit is raised, the window resets, or the budget is disabled."
        />
    @endif

    <p style="font-size: 0.85rem; margin-bottom: 1rem; opacity: 0.85;">
        A budget is a limit, not a tally: spend is the sum of recorded token cost over
        the window, so these figures and the cost pages are the same numbers. The check
        runs before the provider is called, so one turn can still overshoot by that
        turn's cost.
    </p>

    {{ $this->table }}
</x-filament-panels::page>
