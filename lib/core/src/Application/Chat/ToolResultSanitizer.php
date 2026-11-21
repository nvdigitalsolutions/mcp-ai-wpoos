<?php

/**
 * Tool result sanitizer — the single reader for the LLM-facing copy.
 *
 * One place decides what the model sees of a tool result, and two paths share
 * it: the non-streaming loop and the SSE loop. Before this class they had
 * separate logic and had already drifted — the streaming path encoded the raw
 * result, so a nested payload reached the model there and not on the other path.
 *
 * Two layers, in order:
 *
 *  1. **The tool's declaration.** A tool implementing `LlmSanitizerInterface`
 *     owns the shape of its copy. Its output is still passed through the encoded
 *     payload drop below, because "no base64 reaches the model" is a runtime
 *     guarantee, not something a declaration can waive.
 *  2. **The runtime backstop.** Unambiguous encoded fields are dropped at any
 *     depth, and (only for tools that do not declare) oversized strings are
 *     truncated. A declaration says *what* to strip; the backstop catches the
 *     tool that forgot to say anything.
 *
 * The tool's own result is never mutated: arrays are rebuilt, not edited.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Chat;

use Nvoos\Core\Domain\Contract\LlmSanitizerInterface;

final class ToolResultSanitizer
{
    /**
     * Keys that only ever hold encoded binary. Dropped at any depth.
     *
     * @var list<string>
     */
    private const ENCODED_KEYS = array(
        'b64_json',
        'b64_image',
        'inline_data',
        'inlineData',
        'audio_base64',
        'image_base64',
    );

    /**
     * Keys the pre-2.0 sanitizer dropped at the top level. Kept for the generic
     * path only: nested `data` is often the real payload, and a declaring tool
     * may mean something specific by it.
     *
     * @var list<string>
     */
    private const LEGACY_TOP_LEVEL_KEYS = array(
        'base64',
        'data',
    );

    /** Strings longer than this are truncated on the generic path. */
    private const MAX_STRING_BYTES = 10000;

    /** How much of a truncated string is kept. */
    private const KEEP_BYTES = 200;

    /** Recursion guard for pathologically nested results. */
    private const MAX_DEPTH = 8;

    /**
     * @param mixed       $result Raw tool result.
     * @param object|null $tool   The tool that produced it, when known.
     */
    public function sanitize(mixed $result, ?object $tool = null): mixed
    {
        if ($tool instanceof LlmSanitizerInterface) {
            return $this->scrub($tool->sanitizeForLlm($result), false);
        }

        return $this->scrub($result, true);
    }

    /**
     * @param mixed $value     Candidate.
     * @param bool  $truncate  Whether oversized strings are shortened.
     * @param int   $depth     Current depth.
     * @param int   $topDepth  Depth that counts as "top level" for the legacy keys.
     */
    private function scrub(mixed $value, bool $truncate, int $depth = 0, int $topDepth = 0): mixed
    {
        if (! \is_array($value)) {
            if ($truncate && \is_string($value) && \strlen($value) > self::MAX_STRING_BYTES) {
                return \substr($value, 0, self::KEEP_BYTES)
                    . '…[truncated ' . \strlen($value) . ' bytes]';
            }

            return $value;
        }

        if ($depth >= self::MAX_DEPTH) {
            return $value;
        }

        $clean = array();

        foreach ($value as $key => $item) {
            if (\is_string($key) && $this->isDropped($key, $depth === $topDepth)) {
                continue;
            }

            $clean[ $key ] = $this->scrub($item, $truncate, $depth + 1, $topDepth);
        }

        return $clean;
    }

    private function isDropped(string $key, bool $atTopLevel): bool
    {
        if (\in_array($key, self::ENCODED_KEYS, true)) {
            return true;
        }

        return $atTopLevel && \in_array($key, self::LEGACY_TOP_LEVEL_KEYS, true);
    }
}
