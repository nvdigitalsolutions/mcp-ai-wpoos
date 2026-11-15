<?php

/**
 * Reasoning trace — the canonical schema and the self-consistency primitive.
 *
 * The trace is a plain associative array rather than an object graph, so a
 * runtime can persist it as one JSON document and an LLM can fill it in
 * directly when prompted with a plan-then-solve cue:
 *
 *   assumptions → constraints → plan → intermediate_results → verification → answer
 *
 * Ported from `includes/harness/class-wp-mcp-ai-reasoning-trace.php`. Storage
 * stays with the runtime (WordPress: post meta), because where a trace lives is
 * a platform concern and the schema is not.
 *
 * `selfConsistencyVote()` is the cheapest test-time-compute lever: sample N
 * answers, normalise trivial formatting differences, take the mode, and report
 * the agreement ratio (Wang et al. 2022, Self-Consistency).
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Harness;

use Nvoos\Core\Support\Text;

final class ReasoningTrace
{
    public const SCHEMA_VERSION = '1.0';

    /** Cap on any list field, so adversarial input cannot grow a trace without bound. */
    public const MAX_LIST_ITEMS = 50;

    /**
     * List fields, in the order the schema documents them.
     *
     * @var array<int, string>
     */
    private const LIST_KEYS = array(
        'assumptions',
        'constraints',
        'plan',
        'intermediate_results',
        'verification',
    );

    /**
     * Build a fresh, empty trace.
     *
     * @param int|null $now Clock override for tests.
     * @return array<string, mixed>
     */
    public static function newTrace(?int $now = null): array
    {
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'task_class' => 'general',
            'assumptions' => array(),
            'constraints' => array(),
            'plan' => array(),
            'intermediate_results' => array(),
            'verification' => array(),
            'answer' => '',
            'created_at' => $now ?? time(),
        );
    }

    /**
     * Sanitise a trace: unknown keys dropped, list fields coerced to capped
     * string lists, invalid input degrading to a fresh trace.
     *
     * @param mixed $raw JSON string, array, or anything else.
     * @return array<string, mixed>
     */
    public static function sanitize(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : array();
        }

        if (! is_array($raw)) {
            $raw = array();
        }

        $trace = self::newTrace();

        $trace['schema_version'] = isset($raw['schema_version'])
            ? Text::singleLine((string) $raw['schema_version'])
            : self::SCHEMA_VERSION;

        if (isset($raw['task_class'])) {
            $taskClass = Text::sanitizeKey((string) $raw['task_class']);

            if ('' !== $taskClass) {
                $trace['task_class'] = $taskClass;
            }
        }

        foreach (self::LIST_KEYS as $key) {
            $trace[ $key ] = self::coerceStringList($raw[ $key ] ?? array());
        }

        if (isset($raw['answer'])) {
            $trace['answer'] = (string) $raw['answer'];
        }

        if (isset($raw['created_at'])) {
            $created = (int) $raw['created_at'];

            if ($created > 0) {
                $trace['created_at'] = $created;
            }
        }

        return $trace;
    }

    /**
     * Majority vote across candidate answers.
     *
     * Candidates are normalised (whitespace and case) before counting so trivial
     * formatting differences do not fragment the vote. Ties go to the first
     * candidate seen — `arsort()` is stable since PHP 8.0, so key order holds.
     *
     * @param array<int, mixed> $candidates Candidate answers.
     * @return array{answer: string, agreement: float, votes: array<string, int>, total: int}
     */
    public static function selfConsistencyVote(array $candidates): array
    {
        $counts = array();
        $display = array();

        foreach ($candidates as $candidate) {
            $candidate = (string) $candidate;
            $key = strtolower(trim((string) preg_replace('/\s+/', ' ', $candidate)));

            if ('' === $key) {
                continue;
            }

            if (! isset($counts[ $key ])) {
                $counts[ $key ] = 0;
                $display[ $key ] = $candidate;
            }

            ++$counts[ $key ];
        }

        $total = array_sum($counts);

        if (0 === $total) {
            return array( 'answer' => '', 'agreement' => 0.0, 'votes' => array(), 'total' => 0 );
        }

        arsort($counts);

        $winnerKey = (string) key($counts);
        $winnerVotes = (int) $counts[ $winnerKey ];

        $votes = array();
        foreach ($counts as $key => $count) {
            $votes[ $display[ $key ] ] = (int) $count;
        }

        return array(
            'answer' => $display[ $winnerKey ],
            'agreement' => round($winnerVotes / $total, 4),
            'votes' => $votes,
            'total' => $total,
        );
    }

    /**
     * Coerce a value to a list of trimmed non-empty strings, capped.
     *
     * Arrays become JSON so a structured plan step survives as text rather than
     * being silently dropped.
     *
     * @param mixed $value Raw value.
     * @return array<int, string>
     */
    private static function coerceStringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = array( $value );
        }

        if (! is_array($value)) {
            return array();
        }

        $out = array();

        foreach ($value as $item) {
            if (is_array($item)) {
                $encoded = json_encode($item);
                $item = false === $encoded ? '' : $encoded;
            }

            $item = trim((string) $item);

            if ('' !== $item) {
                $out[] = $item;
            }

            if (count($out) >= self::MAX_LIST_ITEMS) {
                break;
            }
        }

        return $out;
    }
}
