<?php

namespace App\Support\Import;

use Nvoos\Core\Domain\Contract\TranscriptStoreInterface;

/**
 * Conversation import — Proposal 149 §37.
 *
 * ## A bounded subset, and why
 *
 * The WordPress subsystem is fifteen classes: five adapters (ChatGPT, Claude,
 * Gemini, OpenAI JSONL, ShareGPT), a zip archive reader, a CCT writer, a deleter,
 * a media handler, a memory miner, a privacy filter and an async queue. That is a
 * project, not a parity slice.
 *
 * This port accepts **pasted JSON** in two shapes and imports it synchronously:
 *
 * | Shape | Accepted |
 * |---|---|
 * | ShareGPT (`[{conversations: [{from, value}]}]`) | yes |
 * | Generic (`[{session_id, messages: [{role, content}]}]`) | yes |
 * | zip archives | no — an archive reader needs entry-name validation and a decompression limit |
 * | JSONL streaming | no — a line stream wants a temp file, which a paste avoids |
 * | Claude / Gemini / OpenAI-JSONL adapters | no — each is its own shape |
 *
 * **Paste rather than upload on purpose.** An upload path needs a temporary file
 * store, a MIME gate and a size check before the file is even readable; a paste is
 * one code path with no filesystem surface to secure. The caps below are enforced
 * on the string, before anything touches storage.
 *
 * ## Idempotency is free
 *
 * `TranscriptStoreInterface::save()` upserts by `session_id`, so a re-import
 * updates the same conversation instead of duplicating it. The session key is
 * derived deterministically — the export's own id when it has one, otherwise a
 * hash of the first message — so the same file always lands on the same rows.
 *
 * ## Nothing is trusted
 *
 * Imported text is user-supplied. Each message is coerced to a string, invalid
 * UTF-8 is scrubbed rather than stored, and roles are mapped from a closed set
 * instead of passed through.
 */
final class ConversationImport
{
    /** Largest body accepted, in bytes. */
    public const MAX_BYTES = 2_097_152;

    /** Most conversations accepted in one import. */
    public const MAX_CONVERSATIONS = 500;

    /** Most messages kept per conversation; the rest are dropped and reported. */
    public const MAX_MESSAGES = 200;

    /** Prefix that marks a transcript as imported rather than chatted. */
    public const SESSION_PREFIX = 'import:';

    /**
     * ShareGPT speaker → role. Anything else becomes `user`, so an unknown label
     * cannot invent a role the runtime does not understand.
     *
     * @var array<string, string>
     */
    private const SHAREGPT_ROLES = array(
        'human' => 'user',
        'user' => 'user',
        'gpt' => 'assistant',
        'assistant' => 'assistant',
        'system' => 'system',
        'tool' => 'tool',
    );

    /**
     * Parse a body without writing anything.
     *
     * Returns either a fatal reason or the normalised conversations, so a caller
     * can show a preview before committing.
     *
     * @return array{fatal: string, conversations: list<array{session_id: string, messages: list<array{role: string, content: string}>, truncated: int}>, errors: list<string>}
     */
    public static function parse(string $body): array
    {
        $result = array(
            'fatal' => '',
            'conversations' => array(),
            'errors' => array(),
        );

        $body = trim($body);

        if ('' === $body) {
            $result['fatal'] = 'Paste an export body first.';

            return $result;
        }

        if (strlen($body) > self::MAX_BYTES) {
            $result['fatal'] = sprintf(
                'The body is larger than %s bytes. Split the export or import it in parts.',
                number_format(self::MAX_BYTES)
            );

            return $result;
        }

        $decoded = json_decode($body, true);

        if (JSON_ERROR_NONE !== json_last_error()) {
            $result['fatal'] = 'That is not valid JSON: '.json_last_error_msg().'.';

            return $result;
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            $result['fatal'] = 'Expected a JSON array of conversations at the top level.';

            return $result;
        }

        if (count($decoded) > self::MAX_CONVERSATIONS) {
            $result['fatal'] = sprintf(
                'That export has %d conversations; the limit is %d per import.',
                count($decoded),
                self::MAX_CONVERSATIONS
            );

            return $result;
        }

        foreach ($decoded as $index => $entry) {
            if (! is_array($entry)) {
                $result['errors'][] = sprintf('Conversation %d is not an object; skipped.', (int) $index);

                continue;
            }

            $parsed = self::conversation($entry, (int) $index);

            if ('' !== $parsed['error']) {
                $result['errors'][] = $parsed['error'];

                continue;
            }

            if (array() === $parsed['messages']) {
                $result['errors'][] = sprintf('Conversation %d has no usable messages; skipped.', (int) $index);

                continue;
            }

            $result['conversations'][] = array(
                'session_id' => $parsed['session_id'],
                'messages' => $parsed['messages'],
                'truncated' => $parsed['truncated'],
            );
        }

        return $result;
    }

    /**
     * Parse and write in one step.
     *
     * Each conversation is saved through the transcript store, which upserts by
     * session id — so a re-import corrects rather than duplicates.
     *
     * @return array{fatal: string, imported: int, failed: int, messages: int, truncated: int, errors: list<string>}
     */
    public static function import(string $body, TranscriptStoreInterface $store): array
    {
        $parsed = self::parse($body);

        $report = array(
            'fatal' => $parsed['fatal'],
            'imported' => 0,
            'failed' => 0,
            'messages' => 0,
            'truncated' => 0,
            'errors' => $parsed['errors'],
        );

        if ('' !== $parsed['fatal']) {
            return $report;
        }

        foreach ($parsed['conversations'] as $conversation) {
            $saved = $store->save(array(
                'session_id' => $conversation['session_id'],
                'messages' => $conversation['messages'],
                'metadata' => array(
                    'source' => 'conversation-import',
                    'imported_at' => gmdate('c'),
                ),
            ));

            if (empty($saved['success'])) {
                ++$report['failed'];
                $report['errors'][] = sprintf(
                    '%s: %s',
                    $conversation['session_id'],
                    (string) ($saved['error'] ?? 'save failed')
                );

                continue;
            }

            ++$report['imported'];
            $report['messages'] += count($conversation['messages']);
            $report['truncated'] += $conversation['truncated'];
        }

        return $report;
    }

    /**
     * Normalise one conversation from either accepted shape.
     *
     * @param array<string, mixed> $entry
     * @return array{session_id: string, messages: list<array{role: string, content: string}>, truncated: int, error: string}
     */
    private static function conversation(array $entry, int $index): array
    {
        $messages = array();
        $truncated = 0;

        // ShareGPT: `conversations` is a list of `{from, value}`.
        if (isset($entry['conversations']) && is_array($entry['conversations'])) {
            foreach ($entry['conversations'] as $turn) {
                if (! is_array($turn)) {
                    continue;
                }

                $messages[] = array(
                    'role' => self::sharegptRole((string) ($turn['from'] ?? '')),
                    'content' => self::text($turn['value'] ?? ''),
                );
            }
        } elseif (isset($entry['messages']) && is_array($entry['messages'])) {
            // Generic: `messages` is a list of `{role, content}`.
            foreach ($entry['messages'] as $message) {
                if (! is_array($message)) {
                    continue;
                }

                $messages[] = array(
                    'role' => self::role((string) ($message['role'] ?? '')),
                    'content' => self::text($message['content'] ?? ''),
                );
            }
        } else {
            return array(
                'session_id' => '',
                'messages' => array(),
                'truncated' => 0,
                'error' => sprintf(
                    'Conversation %d has neither a `conversations` nor a `messages` list; skipped.',
                    $index
                ),
            );
        }

        // Drop turns with no text rather than storing empty bubbles.
        $messages = array_values(array_filter(
            $messages,
            static fn (array $message): bool => '' !== $message['content']
        ));

        if (count($messages) > self::MAX_MESSAGES) {
            $truncated = count($messages) - self::MAX_MESSAGES;
            $messages = array_slice($messages, 0, self::MAX_MESSAGES);
        }

        return array(
            'session_id' => self::sessionId($entry, $messages, $index),
            'messages' => $messages,
            'truncated' => $truncated,
            'error' => '',
        );
    }

    /**
     * A deterministic session key, so the same export always lands on the same
     * transcript row.
     *
     * @param array<string, mixed>                  $entry
     * @param list<array{role: string, content: string}> $messages
     */
    private static function sessionId(array $entry, array $messages, int $index): string
    {
        $id = trim((string) ($entry['id'] ?? $entry['session_id'] ?? ''));

        if ('' !== $id) {
            // Keep the caller's id readable but bounded, and strip separators so
            // it cannot collide with a chatted session key.
            $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $id) ?? '';

            if ('' !== $clean) {
                return self::SESSION_PREFIX.substr($clean, 0, 80);
            }
        }

        $seed = (string) ($messages[0]['content'] ?? '');

        return self::SESSION_PREFIX.$index.'-'.substr(sha1($seed.(string) $index), 0, 16);
    }

    private static function sharegptRole(string $from): string
    {
        return self::SHAREGPT_ROLES[strtolower(trim($from))] ?? 'user';
    }

    private static function role(string $role): string
    {
        $role = strtolower(trim($role));

        return in_array($role, array('user', 'assistant', 'system', 'tool'), true) ? $role : 'user';
    }

    /**
     * Coerce a value to safe text.
     *
     * JSON can carry arrays or numbers where a string is expected, and a
     * multipart content list has to be flattened. The UTF-8 scrub is
     * **defence-in-depth for a byte-level source** (a file or stream reader): a
     * pasted JSON body cannot carry invalid UTF-8, because `json_decode` rejects
     * the whole document first. Keep the scrub for the day an upload path lands —
     * one bad byte would otherwise fail the whole `json_encode` on write.
     */
    private static function text(mixed $value): string
    {
        if (is_array($value)) {
            // A multipart content list: keep the text parts, drop the rest.
            $parts = array();

            foreach ($value as $part) {
                if (is_array($part) && is_string($part['text'] ?? null)) {
                    $parts[] = $part['text'];
                }
            }

            $value = implode("\n", $parts);
        }

        if (! is_string($value)) {
            $value = is_scalar($value) ? (string) $value : '';
        }

        $value = trim($value);

        if ('' === $value) {
            return '';
        }

        // Scrub invalid UTF-8 rather than let json_encode fail on write.
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return $value;
    }
}
