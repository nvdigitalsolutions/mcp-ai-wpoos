{{--
    Run inspector — Proposal 149 §18.

    Inline styles rather than Tailwind utilities: the panel's compiled CSS does
    not include arbitrary utilities, and a missing class would collapse the one
    element that has to stay readable (the per-node events payload).
--}}
@php
    $blocks = [
        'Input' => $run->input,
        'Output' => $run->output,
        'Events' => $run->events,
    ];
@endphp

<div style="display: flex; flex-direction: column; gap: 1rem;">
    <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; font-size: 0.85rem;">
        <div><strong>Status:</strong> {{ $run->status }}</div>
        <div><strong>Source:</strong> {{ $run->source ?? 'manual' }}</div>
        <div><strong>Started:</strong> {{ $run->started_at ?? '—' }}</div>
        <div><strong>Finished:</strong> {{ $run->finished_at ?? '—' }}</div>
        <div><strong>Duration:</strong> {{ null === $run->duration ? '—' : $run->duration.'s' }}</div>
        <div><strong>Tool calls:</strong> {{ (int) $run->tool_calls }}</div>
    </div>

    @if (filled($run->error))
        <div style="padding: 0.75rem; border-radius: 0.5rem; background: rgba(220, 38, 38, 0.12); font-size: 0.85rem;">
            <strong>Error</strong>
            <pre style="white-space: pre-wrap; word-break: break-word; margin: 0.5rem 0 0; font-size: 0.8rem;">{{ $run->error }}</pre>
        </div>
    @endif

    @foreach ($blocks as $label => $raw)
        @php
            $decoded = \App\Support\WorkflowRuns::decode(is_string($raw) ? $raw : null);
            $pretty = null === $decoded
                ? (is_string($raw) && '' !== $raw ? $raw : '—')
                : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        @endphp

        <div>
            <strong style="font-size: 0.85rem;">{{ $label }}</strong>
            <pre style="white-space: pre-wrap; word-break: break-word; font-size: 0.8rem; line-height: 1.5; padding: 0.75rem; border-radius: 0.5rem; background: rgba(127, 127, 127, 0.12); margin: 0.5rem 0 0;">{{ $pretty }}</pre>
        </div>
    @endforeach
</div>
