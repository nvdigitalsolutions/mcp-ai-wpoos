<?php

/**
 * Read a JSON object out of a model's answer.
 *
 * Several tools ask for a structure rather than prose, and models answer with a
 * structure *and* wrapping: a fenced block, a sentence of preamble, or both. The
 * failure mode this guards against is subtler than a parse error — a tool that
 * silently treats unreadable output as empty success tells a caller "nothing
 * found" when the truth is "I could not read the answer".
 *
 * So `decode()` returns null rather than an empty array, and every caller turns
 * that into an explicit refusal that carries the beginning of what arrived.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Provider;

final class JsonPayload
{
    /**
     * Decode the first object in a model answer.
     *
     * The fence and brace handling is deliberate: both are present in real
     * answers far more often than not, and asking a model not to use them works
     * until it does not.
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $text): ?array
    {
        $candidate = \trim($text);

        if ('' === $candidate) {
            return null;
        }

        $fenced = \preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $candidate, $matches);

        if (1 === $fenced) {
            $candidate = \trim($matches[1]);
        }

        $start = \strpos($candidate, '{');
        $end   = \strrpos($candidate, '}');

        if (false !== $start && false !== $end && $end > $start) {
            $candidate = \substr($candidate, $start, $end - $start + 1);
        }

        $decoded = \json_decode($candidate, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Coerce a value to one of a closed set, or the fallback.
     *
     * @param list<string> $allowed
     */
    public static function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        $candidate = is_string($value) ? \strtolower(\trim($value)) : '';

        return \in_array($candidate, $allowed, true) ? $candidate : $fallback;
    }

    /**
     * A list of trimmed, non-empty strings.
     *
     * @return list<string>
     */
    public static function stringList(mixed $value, int $limit = 50): array
    {
        $items = array();

        foreach (\is_array($value) ? $value : array() as $item) {
            if (! \is_string($item)) {
                continue;
            }

            $item = \trim($item);

            if ('' === $item) {
                continue;
            }

            $items[] = \mb_substr($item, 0, 500);

            if (\count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * A probability clamped to 0..1, or the fallback.
     */
    public static function confidence(mixed $value, float $fallback = 0.5): float
    {
        if (! \is_numeric($value)) {
            return $fallback;
        }

        return \max(0.0, \min(1.0, (float) $value));
    }
}
