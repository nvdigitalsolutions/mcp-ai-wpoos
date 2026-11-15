<?php

/**
 * Output guardrail — validate a model response before it reaches a sink.
 *
 * The input side of the safety layer asks whether a message should be allowed
 * to reach the model. This is the mirror question, and the more consequential
 * one: the model has already generated (and billed) the text, and anything the
 * guardrail misses is what a user or a downstream tool will act on.
 *
 * Three checks, matching the OWASP risks they answer:
 *
 *   1. Sensitive information leakage — email, card-shaped digits, SSNs, API
 *      keys, JWTs, private keys, connection strings, and (at higher strictness)
 *      IPv4, long hex runs, phone numbers and credential assignments.
 *      OWASP LLM02:2025.
 *   2. Unsafe content — a deliberately narrow keyword heuristic, run only at
 *      `high` strictness. OWASP LLM05:2025.
 *   3. Injection echoes — the model regurgitating an injection payload rather
 *      than resisting it. OWASP LLM07:2025.
 *
 * The result is *structured*: this class reports findings and the content a
 * runtime should deliver. It does not build translated error text and it does
 * not log, because both are runtime concerns — WordPress needs `__()` and an
 * options table; another runtime needs neither.
 *
 * **Detection is not redaction.** `PiiFilter` redacts; this detects. The
 * pattern tables differ on purpose: redaction must not mangle legitimate text,
 * so it covers the common cases and nothing else, while detection is tuned to
 * over-report, because a false positive costs a warning prefix and a false
 * negative leaks a credential.
 *
 * Ported from `includes/harness/class-wp-mcp-ai-output-guardrail.php`.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Harness;

final class OutputGuardrail
{
    public const RESULT_SAFE = 'safe';
    public const RESULT_SENSITIVE = 'sensitive';
    public const RESULT_UNSAFE = 'unsafe';
    public const RESULT_INJECTION_ECHO = 'injection_echo';

    public const MODE_BLOCK = 'block';
    public const MODE_WARN = 'warn';
    public const MODE_LOG = 'log';

    public const STRICTNESS_LOW = 'low';
    public const STRICTNESS_MEDIUM = 'medium';
    public const STRICTNESS_HIGH = 'high';

    /**
     * How many injection markers must appear before an echo is reported.
     *
     * One marker is ordinary in a conversation *about* prompt injection, so a
     * single hit is not evidence. Two is the smallest count that has held up.
     */
    public const MIN_ECHO_MARKERS = 2;

    /** Prefix prepended in `warn` mode. Not translated: it carries no prose. */
    public const WARN_PREFIX = '[⚠️ %s] ';

    /**
     * Strictness levels, in the order the tiers widen.
     *
     * @var array<int, string>
     */
    private const STRICTNESS_LEVELS = array( self::STRICTNESS_LOW, self::STRICTNESS_MEDIUM, self::STRICTNESS_HIGH );

    /**
     * Modes, in the order they escalate.
     *
     * @var array<int, string>
     */
    private const MODES = array( self::MODE_LOG, self::MODE_WARN, self::MODE_BLOCK );

    /**
     * Markers of a regurgitated injection payload. Matched case-insensitively
     * as substrings; `MIN_ECHO_MARKERS` of them must appear.
     *
     * @var array<int, string>
     */
    private const INJECTION_MARKERS = array(
        'ignore previous instructions',
        'ignore all previous',
        'do not follow the above',
        'disregard prior',
        'you are now dan',
        'developer mode override',
        'system prompt override',
    );

    /**
     * Evaluate a response against an `output_guard` profile block.
     *
     * Never throws: a guardrail that throws costs the chat turn, which is a
     * worse outcome than the finding it failed to make.
     *
     * @param string $content Response text.
     * @param array  $config  The profile's `output_guard` block: `enabled`,
     *                        `mode`, `strictness`. Missing keys take their
     *                        documented defaults.
     *
     * @return array{
     *   enabled: bool,
     *   safe: bool,
     *   blocked: bool,
     *   findings: array<int, string>,
     *   mode: string,
     *   strictness: string,
     *   content: string
     * } `content` is what the runtime should deliver; it is unchanged when the
     *   response is safe or the mode is `log`, prefixed in `warn` mode, and
     *   meaningless when `blocked` is true (the runtime builds the error).
     */
    public static function evaluate(string $content, array $config): array
    {
        $config = self::normaliseConfig($config);

        if (!$config['enabled']) {
            return self::result(false, $content, array(), $config);
        }

        $findings = array();

        if ('' !== $content) {
            if (self::hasSensitiveInfo($content, $config['strictness'])) {
                $findings[] = self::RESULT_SENSITIVE;
            }

            // The unsafe check is a keyword heuristic, so it only runs where the
            // operator asked for the strictest posture.
            if (self::STRICTNESS_HIGH === $config['strictness'] && self::hasUnsafeContent($content)) {
                $findings[] = self::RESULT_UNSAFE;
            }

            if (self::hasInjectionEcho($content)) {
                $findings[] = self::RESULT_INJECTION_ECHO;
            }
        }

        if (array() === $findings) {
            return self::result(true, $content, array(), $config);
        }

        $blocked = self::MODE_BLOCK === $config['mode'];

        $delivered = self::MODE_WARN === $config['mode']
            ? sprintf(self::WARN_PREFIX, implode(', ', $findings)) . $content
            : $content;

        return self::result(true, $delivered, $findings, $config, $blocked);
    }

    /**
     * Whether the response leaks something that looks like a credential or PII.
     *
     * @param string $content    Response text.
     * @param string $strictness `low`, `medium` or `high`.
     */
    public static function hasSensitiveInfo(string $content, string $strictness = self::STRICTNESS_MEDIUM): bool
    {
        foreach (self::sensitivePatterns($strictness) as $pattern) {
            if (1 === preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the response matches the narrow unsafe-content heuristic.
     *
     * This is a keyword filter, not a classifier. It exists so an operator can
     * opt into a cheap last line at `high` strictness; a real deployment is
     * expected to put a moderation model behind the score filter instead.
     */
    public static function hasUnsafeContent(string $content): bool
    {
        $patterns = array(
            '/\b(?:how to (?:make|build|create) (?:a bomb|explosives|weapons))\b/i',
            '/\b(?:self[- ]?harm|suicide|kill yourself)\b/i',
            '/\b(?:child\s*(?:abuse|exploitation|pornography))\b/i',
        );

        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the response echoes an injection payload.
     *
     * Counts markers rather than matching one, so a response that merely
     * *discusses* injection is not flagged.
     */
    public static function hasInjectionEcho(string $content): bool
    {
        $haystack = strtolower($content);
        $hits = 0;

        foreach (self::INJECTION_MARKERS as $marker) {
            if (false !== strpos($haystack, $marker)) {
                ++$hits;

                if ($hits >= self::MIN_ECHO_MARKERS) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The sensitive-information patterns for a strictness level.
     *
     * Public so a runtime or a test can enumerate exactly what will be checked
     * — and so the compile check can assert every one of them is a valid regex.
     *
     * @return array<int, string>
     */
    public static function sensitivePatterns(string $strictness): array
    {
        $strictness = self::normaliseStrictness($strictness);

        // Every level.
        $patterns = array(
            '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', // Email.
            '/\b(?:\d[ -]*?){13,16}\b/', // Credit-card-shaped digits.
            '/\b\d{3}-\d{2}-\d{4}\b/', // US SSN.
            '/\bsk-[A-Za-z0-9]{20,}\b/', // OpenAI keys.
            '/\bAIza[0-9A-Za-z\-_]{35}\b/', // Google API keys.
            '/\bAKIA[0-9A-Z]{16}\b/', // AWS access keys.
            '/\beyJ[A-Za-z0-9\-_]+\.eyJ[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+/', // JWT tokens.
            '/-----BEGIN (?:RSA |EC |DSA |OPENSSH )?PRIVATE KEY-----/', // Private keys.
            '/\b(?:mongodb|mysql|postgres|redis):\/\/[^\s]+/', // Connection strings.
        );

        if (self::STRICTNESS_LOW === $strictness) {
            return $patterns;
        }

        // Medium and high.
        $patterns[] = '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/'; // IP addresses.
        $patterns[] = '/\b(?:0x)?[A-Fa-f0-9]{32,}\b/'; // Hashes / long hex tokens.

        if (self::STRICTNESS_HIGH !== $strictness) {
            return $patterns;
        }

        // High only.
        $patterns[] = '/\b(?:\+?\d{1,3}[-.]?)?\(?\d{3}\)?[-.]?\d{3}[-.]?\d{4}\b/'; // Phone numbers.
        $patterns[] = '/\b(?:password|secret|token|key|credential)\s*[:=]\s*\S+/i'; // Credential assignments.

        return $patterns;
    }

    /**
     * Normalise an `output_guard` block to the contracted shape.
     *
     * @return array{enabled: bool, mode: string, strictness: string}
     */
    public static function normaliseConfig(array $config): array
    {
        return array(
            'enabled' => !empty($config['enabled']),
            'mode' => isset($config['mode']) && in_array((string) $config['mode'], self::MODES, true)
                ? (string) $config['mode']
                : self::MODE_WARN,
            'strictness' => self::normaliseStrictness($config['strictness'] ?? self::STRICTNESS_MEDIUM),
        );
    }

    /**
     * @param array{enabled: bool, mode: string, strictness: string} $config
     * @return array{enabled: bool, safe: bool, blocked: bool, findings: array<int, string>, mode: string, strictness: string, content: string}
     */
    private static function result(bool $enabled, string $content, array $findings, array $config, bool $blocked = false): array
    {
        return array(
            'enabled' => $enabled,
            'safe' => array() === $findings,
            'blocked' => $blocked,
            'findings' => $findings,
            'mode' => $config['mode'],
            'strictness' => $config['strictness'],
            'content' => $content,
        );
    }

    private static function normaliseStrictness(mixed $strictness): string
    {
        $strictness = (string) $strictness;

        return in_array($strictness, self::STRICTNESS_LEVELS, true) ? $strictness : self::STRICTNESS_MEDIUM;
    }
}
