{{--
    Transcript inspector — Proposal 149 §39.

    Inline styles rather than Tailwind utilities: the panel's compiled CSS does
    not include arbitrary utilities, and a missing class would collapse the one
    element that has to stay readable (the conversation itself).

    Message text is rendered in `<pre>` and always escaped. An assistant turn may
    contain markdown or HTML; showing it as literal text is the honest choice for
    a diagnostic surface, and rendering it would make a stored message an
    injection vector. Nothing here is editable — retention and deletion belong to
    the store and the console.
--}}
@php
    $messages = $transcript->decodedMessages();
    $limit = 4000;

    $tints = [
        'user' => 'rgba(59, 130, 246, 0.12)',
        'assistant' => 'rgba(16, 185, 129, 0.12)',
        'system' => 'rgba(245, 158, 11, 0.14)',
        'tool' => 'rgba(127, 127, 127, 0.14)',
    ];
@endphp

<div style="display: flex; flex-direction: column; gap: 0.75rem; max-height: 60vh; overflow-y: auto;">
    <div style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; font-size: 0.85rem;">
        <div><strong>Assistant:</strong> {{ $transcript->assistant_id ?: '—' }}</div>
        <div><strong>Turns:</strong> {{ count($messages) }}</div>
        <div><strong>Started:</strong> {{ $transcript->created_at ?? '—' }}</div>
        <div><strong>Last turn:</strong> {{ $transcript->updated_at ?? '—' }}</div>
    </div>

    @forelse ($messages as $index => $message)
        @php
            $role = is_array($message) ? (string) ($message['role'] ?? 'unknown') : 'unknown';
            $text = \App\Support\Transcripts\TranscriptPreview::text(
                is_array($message) ? ($message['content'] ?? '') : ''
            );
            $truncated = mb_strlen($text) > $limit;
            $shown = $truncated ? mb_substr($text, 0, $limit)."\n… (truncated)" : $text;
            $tint = $tints[$role] ?? 'rgba(127, 127, 127, 0.12)';
        @endphp

        <div style="padding: 0.75rem; border-radius: 0.5rem; background: {{ $tint }};">
            <div style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.75;">
                {{ $role }} · #{{ $index + 1 }}
            </div>
            <pre style="white-space: pre-wrap; word-break: break-word; margin: 0.5rem 0 0; font-size: 0.8rem; line-height: 1.5;">{{ '' === $shown ? '—' : $shown }}</pre>
        </div>
    @empty
        <div style="font-size: 0.85rem;">This session has no stored messages.</div>
    @endforelse
</div>
