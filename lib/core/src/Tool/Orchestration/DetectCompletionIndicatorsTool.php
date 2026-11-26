<?php

/**
 * Score text for semantic completion indicators.
 *
 * Port of `WP_MCP_AI_Pro_Tool_Detect_Completion_Indicators` (Proposal 164
 * section 164.78). This tool only **scores** text; it never decides to exit.
 * The decision belongs to `check_exit_conditions`, whose dual gate requires an
 * explicit signal as well — the current guidance is that a model's "done" is a
 * claim, not evidence, and a transcript pattern-match is a weak verifier on its
 * own.
 *
 * ## The WordPress pattern set is corrupted, and restored here
 *
 * Eight of the ten patterns cannot compile. A non-capturing group `(?:` was
 * mangled to `( ? ( :` in the source, so `preg_match()` fails with "missing
 * closing parenthesis" and the indicator never fires. The port restores the
 * intended groups; a test proves the corrupted form fails to compile while the
 * restored form matches the phrase it names.
 *
 * ## Pinned
 *
 * - **`completion_met` ignores the task-plan bonus.** WordPress computes it
 *   from the text score before adding a point for a 100%-complete plan, while
 *   `total_score` includes the point. The gate can therefore report `false`
 *   beside a score at or above the threshold. The port keeps that ordering
 *   because the plan reader is a separate slice; a host that binds one gets the
 *   same arithmetic.
 * - **`plan_id` without a plan reader leaves `task_completion` null**, which is
 *   the WordPress unavailable path.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Orchestration;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Tool\AbstractTool;

class DetectCompletionIndicatorsTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * Completion phrases, with the non-capturing groups WordPress lost.
     *
     * @var list<string>
     */
    private const PATTERNS = array(
        '/\b(done|finished|completed?|accomplished)\b/i',
        '/\b(all tasks? (?:are )?(?:complete|done|finished))\b/i',
        '/\b(successfully (?:completed|finished))\b/i',
        '/\b(nothing (?:more|else) to (?:do|add|fix))\b/i',
        '/\b(ready (?:for|to) (?:review|deploy|merge|release))\b/i',
        '/\b((?:fully?|completely) (?:implemented|working))\b/i',
        '/\b(no (?:more|further|additional) (?:changes|work|tasks?))\b/i',
        '/\b(objective achieved|goal (?:reached|met|accomplished))\b/i',
        '/\b(implementation complete)\b/i',
        '/\b(all requirements? (?:met|satisfied|fulfilled))\b/i',
    );

    public function __construct(ErrorFactoryInterface $e)
    {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'detect_completion_indicators';
    }

    public function getName(): string
    {
        return 'Detect Completion Indicators';
    }

    public function getDescription(): string
    {
        return 'Analyze text for semantic completion indicators. Returns a score indicating how many completion signals are detected. Used for intelligent exit detection in autonomous loops.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'text'      => array(
                    'type'        => 'string',
                    'description' => 'Text to analyze for completion indicators.',
                ),
                'plan_id'   => array(
                    'type'        => 'integer',
                    'description' => 'Optional task plan ID to check completion against. Ignored when the host has no task-plan reader.',
                ),
                'threshold' => array(
                    'type'        => 'integer',
                    'description' => 'Minimum indicators required (default: 2).',
                    'default'     => 2,
                ),
            ),
            'required'             => array('text'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'idempotent', 'cacheable', 'local-only');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $text = $arguments['text'] ?? null;

        if (null === $text || '' === $text) {
            return $this->errors->create('missing_text', 'Missing required argument: text.');
        }

        if (! is_string($text)) {
            return $this->errors->create('invalid_text', 'Text must be a string.');
        }

        $threshold   = $this->intParam($arguments, 'threshold', 2);
        $threshold   = $threshold > 0 ? $threshold : 2;
        $indicators  = $this->detect($text);
        $score       = count($indicators);
        $completion  = $score >= $threshold;

        return $this->success(
            sprintf('Detected %d completion indicator(s).', $score),
            array(
                'indicator_count'  => $score,
                'indicators_found' => $indicators,
                'total_score'      => $score,
                'threshold'        => $threshold,
                'completion_met'   => $completion,
                'task_completion'  => null,
                'recommendation'   => $this->recommendation($score, $threshold),
            )
        );
    }

    /**
     * @return list<array{pattern: string, match: string, context: string}>
     */
    private function detect(string $text): array
    {
        $matches = array();

        foreach (self::PATTERNS as $pattern) {
            if (1 !== preg_match($pattern, $text, $match)) {
                continue;
            }

            $matches[] = array(
                'pattern' => $pattern,
                'match'   => $match[0],
                'context' => $this->context($text, $match[0]),
            );
        }

        return $matches;
    }

    /**
     * Up to 50 characters before and 150 around the first occurrence.
     */
    private function context(string $text, string $matched): string
    {
        $position = strpos($text, $matched);

        if (false === $position) {
            return '';
        }

        $start = max(0, $position - 50);

        return trim(substr($text, $start, min(strlen($text) - $start, 150)));
    }

    private function recommendation(int $score, int $threshold): string
    {
        if ($score >= $threshold) {
            return sprintf(
                'INDICATORS_MET: %d completion indicators detected (threshold: %d). Verify work before exiting.',
                $score,
                $threshold
            );
        }

        return sprintf(
            'CONTINUE_WORKING: Only %d of %d indicators found. Need %d more completion signal(s).',
            $score,
            $threshold,
            $threshold - $score
        );
    }
}
