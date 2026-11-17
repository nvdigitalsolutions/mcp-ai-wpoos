<?php

namespace App\Support\Transcripts;

/**
 * The one rule for a transcript list-row preview — Proposal 149 §39.
 *
 * Two presentations show a session's first user message: the console list
 * (`TranscriptsController::index`) and the `/admin` table. Extracting the rule is
 * what stops them from disagreeing about what "preview" means — the read form of
 * 149 R1.
 *
 * Content is not always a string. A multimodal turn stores the content-parts
 * shape (`[{"type": "text", "text": "…"}]`), and the rule this replaced cast that
 * to `(string)`, which raises "Array to string conversion" and previews a session
 * as the word "Array". Parts are reduced to their text instead.
 */
final class TranscriptPreview
{
    /** Characters kept in a list row. */
    public const LIMIT = 100;

    /**
     * The first user message's text, truncated.
     *
     * An empty or text-less user turn is skipped rather than ending the search:
     * a session can open with an image-only turn, and the useful preview is the
     * next turn with words in it.
     *
     * @param  array<int, mixed>  $messages
     */
    public static function firstUserMessage(array $messages, int $limit = self::LIMIT): string
    {
        foreach ($messages as $message) {
            if (! is_array($message) || 'user' !== ($message['role'] ?? '')) {
                continue;
            }

            $text = self::text($message['content'] ?? '');

            if ('' !== $text) {
                return mb_substr($text, 0, max(0, $limit));
            }
        }

        return '';
    }

    /**
     * Reduce message content to plain text.
     *
     * A string is already text. An array is content parts, and only parts that
     * carry text contribute — an image part adds nothing rather than a
     * placeholder, because "[image]" would be a preview claiming something the
     * text does not say.
     */
    public static function text(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if (! is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $part) {
            if (is_string($part) && '' !== $part) {
                $parts[] = $part;

                continue;
            }

            if (is_array($part) && is_string($part['text'] ?? null) && '' !== $part['text']) {
                $parts[] = $part['text'];
            }
        }

        return implode(' ', $parts);
    }
}
