{{--
    Memory inspector — Proposal 149 §40.

    Inline styles rather than Tailwind utilities: the panel's compiled CSS does
    not include arbitrary utilities, and a missing class would collapse the
    element that has to stay readable (the stored content).

    Content is escaped and rendered as literal text. Memory can quote a
    conversation or a document; rendering it as HTML would make a stored string an
    injection vector, and nothing here is editable.
--}}
@php
    $limit = 8000;
    $content = (string) $memory->content;
    $truncated = mb_strlen($content) > $limit;
    $shown = $truncated ? mb_substr($content, 0, $limit)."\n… (truncated)" : $content;
    $tags = $memory->tags;
@endphp

<div style="display: flex; flex-direction: column; gap: 0.75rem;">
    <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; font-size: 0.85rem;">
        <div><strong>Agent:</strong> {{ $memory->agent_id ?: '—' }}</div>
        <div><strong>Tier:</strong> {{ $memory->tier }}</div>
        <div><strong>Importance:</strong> {{ number_format((float) $memory->importance, 2) }}</div>
        <div><strong>Wing:</strong> {{ $memory->wing ?: '—' }}</div>
        <div><strong>Room:</strong> {{ $memory->room ?: '—' }}</div>
        <div><strong>Expires:</strong> {{ $memory->expires_at ?: 'never' }}</div>
        <div><strong>Created:</strong> {{ $memory->created_at ?: '—' }}</div>
        <div><strong>Last changed:</strong> {{ $memory->updated_at ?: '—' }}</div>
    </div>

    @if ($memory->is_expired)
        <div style="padding: 0.75rem; border-radius: 0.5rem; background: rgba(220, 38, 38, 0.12); font-size: 0.85rem;">
            <strong>Expired.</strong> Recall filters this row out, so an assistant no longer sees it.
        </div>
    @endif

    @if ([] !== $tags)
        <div style="font-size: 0.85rem;">
            <strong>Tags:</strong> {{ implode(', ', $tags) }}
        </div>
    @endif

    <div>
        <strong style="font-size: 0.85rem;">Content</strong>
        <pre style="white-space: pre-wrap; word-break: break-word; font-size: 0.8rem; line-height: 1.5; padding: 0.75rem; border-radius: 0.5rem; background: rgba(127, 127, 127, 0.12); margin: 0.5rem 0 0;">{{ '' === $shown ? '—' : $shown }}</pre>
    </div>
</div>
