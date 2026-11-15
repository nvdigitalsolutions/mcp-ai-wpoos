<?php

/**
 * Prompt-injection / jailbreak detector — shared message analysis.
 *
 * Ported from the WordPress harness helper
 * (`includes/harness/class-wp-mcp-ai-guardrails.php`) so both runtimes classify
 * a message with the same rules. The analysis is a set of ordered heuristics:
 *
 *  1. Jailbreak patterns — instruction override, system-prompt disclosure,
 *     role-play personas, developer mode, ChatML tokens, unrestricted-mode
 *     requests. A hit is critical.
 *  2. Injection patterns — template-variable, SQL-shaped, command, path
 *     traversal, null-byte, and context stuffing. A hit is high severity.
 *  3. Off-topic / diversion heuristics — only at `medium` or `high` strictness.
 *
 * Patterns are drawn from the OWASP Top 10 for LLM Applications (LLM01 Prompt
 * Injection) and the published jailbreak taxonomies.
 *
 * This is a heuristic screen, not a classifier: it is deliberately weighted to
 * catch blatant attempts without blocking ordinary prose.
 *
 * ## Licence
 *
 * @license MIT
 * @since   1.6.0
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Harness;

final class PromptInjectionDetector
{
    public const RESULT_SAFE = 'safe';
    public const RESULT_OFF_TOPIC = 'off_topic';
    public const RESULT_JAILBREAK = 'jailbreak';
    public const RESULT_INJECTION = 'injection';

    public const STRICTNESS_LOW = 'low';
    public const STRICTNESS_MEDIUM = 'medium';
    public const STRICTNESS_HIGH = 'high';

    /**
     * Analyse a message.
     *
     * @return array{
     *   result: string,
     *   family: string,
     *   matches: array<int, string>,
     *   confidence: float,
     *   severity: string
     * }
     */
    public static function analyze(string $message, string $strictness = self::STRICTNESS_MEDIUM): array
    {
        $strictness = in_array($strictness, array( self::STRICTNESS_LOW, self::STRICTNESS_MEDIUM, self::STRICTNESS_HIGH ), true)
            ? $strictness
            : self::STRICTNESS_MEDIUM;

        $analysis = array(
            'result'     => self::RESULT_SAFE,
            'family'     => '',
            'matches'    => array(),
            'confidence' => 0.0,
            'severity'   => 'none',
        );

        if ('' === trim($message)) {
            return $analysis;
        }

        $jailbreak = self::detectJailbreak($message);
        if (! empty($jailbreak)) {
            return array(
                'result'     => self::RESULT_JAILBREAK,
                'family'     => 'jailbreak',
                'matches'    => $jailbreak,
                'confidence' => 0.95,
                'severity'   => 'critical',
            );
        }

        $injection = self::detectInjection($message);
        if (! empty($injection)) {
            return array(
                'result'     => self::RESULT_INJECTION,
                'family'     => 'prompt_injection',
                'matches'    => $injection,
                'confidence' => 0.90,
                'severity'   => 'high',
            );
        }

        if (self::STRICTNESS_LOW !== $strictness) {
            $offTopic = self::detectOffTopicSignals($message, $strictness);
            if ($offTopic['flagged']) {
                return array(
                    'result'     => self::RESULT_OFF_TOPIC,
                    'family'     => 'diversion',
                    'matches'    => $offTopic['matches'],
                    'confidence' => $offTopic['confidence'],
                    'severity'   => self::STRICTNESS_HIGH === $strictness ? 'medium' : 'low',
                );
            }
        }

        return $analysis;
    }

    /**
     * Detect known jailbreak / role-override patterns.
     *
     * @return array<int, string> Matched pattern descriptions.
     */
    private static function detectJailbreak(string $message): array
    {
        $normalized = mb_strtolower($message, 'UTF-8');

        $patterns = array(
            '/(?:ignore|disregard|forget|skip)\s+(?:all\s+)?(?:previous|prior|above|earlier)\s+(?:instructions?|commands?|directives?|context|rules?|messages?|conversation|dialog)/i'
                => 'Instruction override: disregard previous instructions',
            '/(?:reveal|show|tell|print|output|display|dump|leak)\s+(?:your\s+)?(?:system\s+(?:prompt|message|instructions?)|original\s+instructions?|base\s+prompt|hidden\s+(?:prompt|instructions?)|secret\s+instructions?)/i'
                => 'System prompt disclosure attempt',
            '/(?:pretend|imagine|act|roleplay|pose)\s+(?:as|like)\s+(?:a\s+)?(?:DAN|developer|admin|root|god|hacker|unrestricted|unfiltered|uncensored|evil|dark|malicious)/i'
                => 'Role-play jailbreak (DAN/dev/admin persona)',
            '/(?:enable|activate|enter|switch\s+to)\s+(?:developer|dev|debug|admin|god|sudo|root|superuser|unrestricted)\s*mode/i'
                => 'Developer/debug mode activation',
            '/(?:new|updated|revised)\s+(?:instructions?|system\s+prompt|directives?)\s*(?::|is|are|as\s+follows)/i'
                => 'New instructions injection',
            '/(?:override|overwrite|replace|change)\s+(?:the\s+)?(?:system\s+(?:prompt|message|instructions?)|your\s+instructions?|your\s+programming)/i'
                => 'System prompt override',
            '/(?:<\|im_start\|>|<\|im_end\|>|<\|system\|>|<\|user\|>|<\|assistant\|>|\[SYSTEM\]|\[USER\]|\[ASSISTANT\]|### System:|### User:|### Assistant:)/i'
                => 'ChatML token injection',
            '/(?:do\s+anything\s+now|no\s+restrictions?|no\s+limits?|no\s+rules?|unrestricted\s+mode|remove\s+(?:all\s+)?restrictions?|remove\s+(?:all\s+)?limits?)/i'
                => 'Unrestricted mode request',
            '/you\s+are\s+now\s+(?:a\s+)?(?:different|new|unfiltered|uncensored|evil|dark)\s+(?:assistant|ai|bot|model|llm|agent|chatbot)/i'
                => 'Persona hijack attempt',
            '/(?:translate|encode|decode|base64|rot13|hex)\s+(?:the\s+)?(?:system\s+prompt|your\s+instructions?|your\s+prompt)/i'
                => 'System prompt extraction via encoding',
            '/(?:repeat|parrot|echo|recite|say\s+back)\s+(?:everything|your\s+prompt|the\s+above|the\s+system|the\s+beginning|from\s+the\s+start)/i'
                => 'Prompt leak via repetition',
            '/(?:simulate|emulate|mimic)\s+(?:GPT-4|Claude|Gemini|Llama|Mistral|another\s+ai|a\s+different\s+model)/i'
                => 'Model emulation jailbreak',
        );

        return self::matches($patterns, $normalized);
    }

    /**
     * Detect prompt-injection shapes.
     *
     * @return array<int, string> Matched pattern descriptions.
     */
    private static function detectInjection(string $message): array
    {
        $normalized = mb_strtolower($message, 'UTF-8');

        $patterns = array(
            '/\{\{.*\}\}|\$\{.*\}|<%.*%>/i'
                => 'Template variable injection',
            '/(?:\'|")\s+(?:or|and)\s+(?:\'|")\s*=\s*(?:\'|")/i'
                => 'SQL-style injection',
            '/(?:\|\&\&|\;)\s*(?:ls|cat|rm|wget|curl|bash|sh|cmd|powershell|python|perl|ruby|php)/i'
                => 'Command injection attempt',
            '/(?:\.\.\/|\.\.\\\)+/i'
                => 'Path traversal attempt',
            '/\x00/'
                => 'Null byte injection',
        );

        // Context stuffing: a long message that compresses well is mostly
        // repeated text. Only very obvious cases are flagged.
        if (strlen($normalized) > 500 && self::repetitionRatio($normalized) < 0.3) {
            $patterns['/.+/'] = 'Context stuffing / high repetition detected';
        }

        return self::matches($patterns, $normalized);
    }

    /**
     * Detect off-topic / diversion signals.
     *
     * @return array{flagged: bool, matches: array<int, string>, confidence: float}
     */
    private static function detectOffTopicSignals(string $message, string $strictness): array
    {
        $normalized = mb_strtolower($message, 'UTF-8');
        $matches = array();
        $score = 0.0;

        $topicShift = array(
            '/never\s+mind\s+(?:about\s+)?(?:that|this|the\s+above|my\s+question)\s*[,.]?\s*(?:instead|now|actually|rather)\s/i'
                => 'Topic shift with override language',
            '/forget\s+(?:about\s+)?(?:that|this|it)\s*(?:and|,)\s*(?:now|instead|actually|rather)\s/i'
                => 'Dismissal + new direction',
            '/that\s+(?:doesn\'?t|does\s+not)\s+matter\s*(?:anymore|now)\s/i'
                => 'Dismissal language',
            '/change\s+(?:of\s+)?(?:topic|subject|direction)\s*[:;]/i'
                => 'Explicit topic change request',
            '/actually\s*(?:,)?\s*(?:I|let\'?s|we)\s+(?:want|need|should|must|will)\s+(?:to\s+)?(?:talk|discuss|ask)\s+about\s+something\s+else/i'
                => 'Topic diversion with insistence',
        );

        foreach (self::matches($topicShift, $normalized) as $description) {
            $matches[] = $description;
            $score += 0.4;
        }

        if (self::STRICTNESS_HIGH === $strictness) {
            $unrelated = array(
                '/what\s+is\s+the\s+(?:meaning\s+of\s+life|weather|time|news|stock\s+price)/i'
                    => 'Generic unrelated query',
                '/tell\s+me\s+a\s+(?:joke|story|riddle|poem|fun\s+fact)/i'
                    => 'Entertainment diversion',
                '/write\s+(?:me\s+)?(?:a\s+)?(?:code|program|script|app|website|game)\s+(?:for|to|that)/i'
                    => 'Unrelated code generation request',
                '/translate\s+(?:this|the\s+following|to)\s/i'
                    => 'Translation diversion',
                '/who\s+(?:is|are|was|were)\s+(?:the\s+)?(?:president|prime\s+minister|ceo|leader)/i'
                    => 'General knowledge diversion',
            );

            foreach (self::matches($unrelated, $normalized) as $description) {
                $matches[] = $description;
                $score += 0.25;
            }
        }

        // A very short message without a question mark may be probing; on its
        // own this is low confidence, so it only nudges the score.
        if (str_word_count($normalized) <= 3 && false === strpos($normalized, '?')) {
            $score += 0.1;
        }

        $socialEngineering = array(
            '/(?:pretty\s+please|i\'?m\s+begging\s+you|i\s+(?:urgently|desperately|really)\s+need|this\s+is\s+(?:very|extremely)\s+important|you\s+(?:have|must|need)\s+to\s+(?:help|do\s+this))/i'
                => 'Social engineering language',
        );

        foreach (self::matches($socialEngineering, $normalized) as $description) {
            $matches[] = $description;
            $score += 0.2;
        }

        return array(
            'flagged'    => $score >= 0.5,
            'matches'    => $matches,
            'confidence' => min($score, 1.0),
        );
    }

    /**
     * Run a pattern => description table and collect the descriptions that hit.
     *
     * @param array<string, string> $patterns Pattern => description.
     * @return array<int, string>
     */
    private static function matches(array $patterns, string $subject): array
    {
        $matches = array();

        foreach ($patterns as $pattern => $description) {
            if (1 === @preg_match($pattern, $subject)) {
                $matches[] = $description;
            }
        }

        return $matches;
    }

    /**
     * Ratio of deflate-compressed length to original length.
     *
     * Lower means more repetition. Used as the context-stuffing heuristic.
     */
    private static function repetitionRatio(string $text): float
    {
        $original = strlen($text);

        if (0 === $original) {
            return 1.0;
        }

        return strlen(gzdeflate($text, 9)) / $original;
    }
}
