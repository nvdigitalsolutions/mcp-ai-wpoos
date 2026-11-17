{{--
    Approval inspector — Proposal 149 §41.

    Inline styles rather than Tailwind utilities: the panel's compiled CSS does
    not include arbitrary utilities, and a missing class would collapse the
    element that has to stay readable (the tool arguments being approved).

    Everything is escaped. `arguments` is the payload a tool would run with, so it
    is the most sensitive field on the row and the one that must be shown exactly
    as queued — a re-encoded or prettified *value* would misrepresent it, so only
    the formatting is added.
--}}
@php
    $arguments = json_encode(
        $approval->arguments,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if (false === $arguments || '[]' === $arguments) {
        $arguments = '—';
    }
@endphp

<div style="display: flex; flex-direction: column; gap: 0.75rem;">
    <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; font-size: 0.85rem;">
        <div><strong>Status:</strong> {{ $approval->status }}</div>
        <div><strong>Tool:</strong> {{ $approval->tool }}</div>
        <div><strong>Requested by:</strong> {{ $approval->requester_id ?? '—' }}</div>
        <div><strong>Assistant:</strong> {{ $approval->assistant_id ?? '—' }}</div>
        <div><strong>Session:</strong> {{ $approval->session_id ?: '—' }}</div>
        <div><strong>Queued:</strong> {{ $approval->created_at ?: '—' }}</div>
        <div><strong>Expires:</strong> {{ $approval->expires_at ?: 'never' }}</div>
        <div><strong>Resolved:</strong> {{ $approval->resolved_at ?: '—' }}</div>
    </div>

    @if ($approval->is_expired)
        <div style="padding: 0.75rem; border-radius: 0.5rem; background: rgba(220, 38, 38, 0.12); font-size: 0.85rem;">
            <strong>Past its window.</strong> Nobody has decided this request yet.
        </div>
    @endif

    @if (filled($approval->reason))
        <div>
            <strong style="font-size: 0.85rem;">Why it was queued</strong>
            <div style="padding: 0.75rem; border-radius: 0.5rem; background: rgba(127, 127, 127, 0.12); margin: 0.5rem 0 0; font-size: 0.85rem;">
                {{ $approval->reason }}
            </div>
        </div>
    @endif

    <div>
        <strong style="font-size: 0.85rem;">Arguments</strong>
        <pre style="white-space: pre-wrap; word-break: break-word; font-size: 0.8rem; line-height: 1.5; padding: 0.75rem; border-radius: 0.5rem; background: rgba(127, 127, 127, 0.12); margin: 0.5rem 0 0;">{{ $arguments }}</pre>
    </div>

    @if (filled($approval->resolution_note) || null !== $approval->resolved_by)
        <div style="font-size: 0.85rem;">
            <strong>Resolution</strong>
            <div>{{ $approval->resolution_note ?: '—' }}</div>
            <div>by {{ $approval->resolved_by ?? '—' }}</div>
        </div>
    @endif
</div>
