<?php

/**
 * LLM sanitizer — the model-facing copy of a tool result.
 *
 * A tool implements this when its result is safe to *act on* but wasteful to
 * *read*: base64 payloads, provider echoes, verbose metadata. The orchestrator
 * asks for the sanitized copy when it builds the tool message for the model; the
 * tool's own result is untouched.
 *
 * Ported from WordPress's `WP_MCP_AI_Tool_LLM_Sanitizer_Interface`, minus the
 * per-tool ad-hoc wording. The contract is deliberately one method so the reader
 * lives in exactly one place (Proposal 161 §5–§6).
 *
 * Implementations must be **pure**: same result in, same copy out, no reads of
 * request state. The orchestrator may call this once per tool call per
 * iteration, and a non-deterministic copy would make replays disagree.
 *
 * @package Nvoos\Core
 * @since   2.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Optional declaration: how this tool's result should look to the model.
 */
interface LlmSanitizerInterface
{
    /**
     * Return the model-facing copy of a tool result.
     *
     * Keep what the model needs to reason with — status, ids, URLs, the fields
     * it will pass to the next tool — and drop what only costs tokens: encoded
     * binary payloads, provider envelopes that duplicate the processed values,
     * and verbose metadata.
     *
     * Returning the input unchanged is a valid implementation; it means "no
     * tool-specific trimming, use the runtime's default".
     *
     * @param mixed $result Raw tool result.
     * @return mixed The copy that may be placed in the model's context.
     */
    public function sanitizeForLlm(mixed $result): mixed;
}
