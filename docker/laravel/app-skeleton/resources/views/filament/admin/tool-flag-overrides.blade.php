{{--
    Tool flag overrides — Proposal 149 §177, `/admin/tool-overrides`.

    The WordPress `orchestration` → `tools` view, minus `force_sync` (no
    asynchronous tool path exists here, so that toggle would be inert).

    Addition-only by construction: the checkbox list offers the eight flags the
    read-only profile already refuses on, and there is no remove control because
    there is no remove API.
--}}
<x-filament-panels::page>
    {{ $this->table }}

    <p style="font-size: 0.8rem; margin-top: 1rem; opacity: 0.85;">
        An override can only make a tool stricter. It never makes one runnable that was
        blocked: the read-only profile, the chat profile and the necessity gate all read the
        same resolved flag set, and that set only grows. A tool that declares no flags is
        already refused, so it cannot be overridden — adding a flag there would turn
        "unknown" into "known and safe". Tools are declared in code; the only thing this page
        stores is the extra restriction.
    </p>
</x-filament-panels::page>
