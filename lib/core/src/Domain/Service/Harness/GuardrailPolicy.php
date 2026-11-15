<?php

/**
 * Guardrail policy — whether a screened message should be blocked.
 *
 * `PromptInjectionDetector` answers "what is this message?". This answers the
 * separate question "what should happen next?", which depends on the
 * assistant's mode and strictness rather than on the message.
 *
 * The rules, and the reasoning behind them:
 *
 *   - `log` never blocks. A layer that is still being evaluated must not be
 *     able to break a working chat, and log mode is how an operator measures
 *     the false-positive rate before turning it on.
 *   - `block` blocks on any finding, including `off_topic`.
 *   - `warn` blocks only on the two security findings — a jailbreak or an
 *     injection is not something to warn about — and blocks `off_topic` only
 *     when the operator chose `high` strictness.
 *   - A safe message never blocks in any mode.
 *
 * Note the asymmetry: input-side warnings *block*, while the output guardrail's
 * `warn` mode *prefixes*. That is deliberate, and it follows from what the two
 * sides can still do. An unblocked hostile input reaches the model and spends
 * tokens; a flagged output has already been paid for, so annotating it keeps
 * the turn usable.
 *
 * Ported from `WP_MCP_AI_Guardrails::screen_message()` and
 * `build_block_message()`.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Harness;

final class GuardrailPolicy
{
    public const MODE_BLOCK = 'block';
    public const MODE_WARN = 'warn';
    public const MODE_LOG = 'log';

    /** Message keys a runtime maps to its own translated copy. */
    public const REASON_JAILBREAK = 'jailbreak';
    public const REASON_INJECTION = 'injection';
    public const REASON_OFF_TOPIC = 'off_topic';
    public const REASON_GENERIC = 'generic';

    /**
     * Whether an analysis result should be blocked.
     *
     * @param array  $analysis   Result from `PromptInjectionDetector::analyze()`.
     * @param string $mode       `block`, `warn` or `log`.
     * @param string $strictness `low`, `medium` or `high`.
     */
    public static function decide(array $analysis, string $mode, string $strictness): bool
    {
        $result = isset($analysis['result']) ? (string) $analysis['result'] : PromptInjectionDetector::RESULT_SAFE;

        if (PromptInjectionDetector::RESULT_SAFE === $result) {
            return false;
        }

        // Log mode never blocks, whatever the finding.
        if (self::MODE_LOG === $mode) {
            return false;
        }

        if (self::MODE_BLOCK === $mode) {
            return true;
        }

        if (
            PromptInjectionDetector::RESULT_JAILBREAK === $result
            || PromptInjectionDetector::RESULT_INJECTION === $result
        ) {
            return true;
        }

        return PromptInjectionDetector::STRICTNESS_HIGH === $strictness
            && PromptInjectionDetector::RESULT_OFF_TOPIC === $result;
    }

    /**
     * The message key for a blocked analysis.
     *
     * Returns the finding's own key rather than a generic one, so a caller that
     * chooses to show something reads the right reason.
     *
     * @param array $analysis Result from `PromptInjectionDetector::analyze()`.
     */
    public static function messageKey(array $analysis): string
    {
        $result = isset($analysis['result']) ? (string) $analysis['result'] : '';

        switch ($result) {
            case PromptInjectionDetector::RESULT_JAILBREAK:
                return self::REASON_JAILBREAK;
            case PromptInjectionDetector::RESULT_INJECTION:
                return self::REASON_INJECTION;
            case PromptInjectionDetector::RESULT_OFF_TOPIC:
                return self::REASON_OFF_TOPIC;
            default:
                return self::REASON_GENERIC;
        }
    }
}
