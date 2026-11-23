{{--
    Tool quotas — Proposal 149 §176, `/admin/tool-quotas`.

    The WordPress `token_manager` → `per_tool` view. Writable, because a per-tool
    cost ceiling is the estate's own control rather than a boundary set on it.

    The limit is enforced per user, so the badge answers "is the busiest caller
    near the ceiling"; the estate total beside it answers "what is this tool
    costing us". They are different questions and are deliberately not merged.
--}}
<x-filament-panels::page>
    {{ $this->table }}

    <p style="font-size: 0.8rem; margin-top: 1rem; opacity: 0.85;">
        A tool's cost is estimated from the size of its result (characters ÷ 4), counted
        per user and per UTC day. It is a proxy: a tool body makes no provider call, so
        what it costs the loop is the payload it puts back into the context on the next
        turn. The check runs before the tool executes, so one call can still overshoot by
        its own result size. A tool with no row, a disabled row and a limit of zero are
        all unmetered.
    </p>
</x-filament-panels::page>
