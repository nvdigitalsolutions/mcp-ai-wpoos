<?php

/**
 * Self-Refine — the bounded generate → critique → revise loop.
 *
 * Implements Self-Refine (Madaan et al. 2023) and the verbal-reflection portion
 * of Reflexion (Shinn et al. 2023) as one synchronous loop:
 *
 *   1. The generator produces an initial candidate.
 *   2. The critic scores it and returns `accept`, `revise` or `reject`.
 *   3. An accepted verdict — or empty feedback, since nothing actionable came
 *      back — stops the loop.
 *   4. Otherwise the generator revises, given the previous answer and the
 *      critique.
 *   5. Repeat until the iteration cap or the cost ceiling is reached.
 *
 * Both callables are plain callables, so the loop is provider-agnostic and
 * testable without an HTTP roundtrip.
 *
 * Two caps exist on purpose. The profile asks for an iteration count and
 * `HarnessProfile` already clamps it, but a caller can pass `$opts` directly, so
 * the loop clamps again: `MAX_REFINE_ITERATIONS` holds regardless of what the
 * profile says. Ported from
 * `includes/harness/class-wp-mcp-ai-self-refine-loop.php`.
 *
 * @package Nvoos\Core
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Harness;

final class SelfRefineLoop
{
    public const DEFAULT_MAX_ITERATIONS = 2;

    public const STOPPED_ACCEPTED = 'accepted';
    public const STOPPED_REJECTED = 'rejected';
    public const STOPPED_MAX_ITERS = 'max_iters';
    public const STOPPED_COST_CEILING = 'cost_ceiling';

    /** The only verdicts the loop understands; anything else degrades to `revise`. */
    private const VERDICTS = array( 'accept', 'revise', 'reject' );

    /**
     * Run the loop.
     *
     * @param string   $task      The task being solved.
     * @param callable $generator `function(string $task, ?string $previous, ?string $critique): mixed`
     *                            Called once for the draft and once per revision.
     * @param callable $critic    `function(string $task, string $candidate): mixed`
     *                            An array with `verdict` and `feedback`, or a bare
     *                            feedback string. A platform error object aborts.
     * @param array    $opts      `max_iters` (int), `cost_per_iter` (float),
     *                            `cost_ceiling` (float), `on_iteration`
     *                            (callable(array): void — invoked once per
     *                            critique, the trace-capture seam).
     * @return array{answer: string, iterations: int, verdicts: array<int, string>, feedback: array<int, string>, stopped_reason: string, estimated_cost_usd: float}
     *
     * @throws SelfRefineException When the task is empty, the ceiling is
     *                             exceeded before the draft, or a callable
     *                             returns a platform error.
     */
    public static function run(string $task, callable $generator, callable $critic, array $opts = array()): array
    {
        if ('' === trim($task)) {
            throw new SelfRefineException('empty_task', 'Self-Refine requires a non-empty task.');
        }

        $maxIterations = self::clampIterations($opts['max_iters'] ?? self::DEFAULT_MAX_ITERATIONS);
        $costPerIteration = isset($opts['cost_per_iter']) ? (float) $opts['cost_per_iter'] : 0.0;
        $costCeiling = isset($opts['cost_ceiling'])
            ? (float) $opts['cost_ceiling']
            : HarnessProfile::DEFAULT_COST_CEILING_USD;
        $onIteration = $opts['on_iteration'] ?? null;

        $verdicts = array();
        $feedback = array();
        $cost = 0.0;

        // The draft is paid for before it is generated: a ceiling that cannot
        // afford one call must fail rather than spend it.
        $cost += $costPerIteration;
        if ($costCeiling > 0 && $cost > $costCeiling) {
            throw new SelfRefineException('cost_exceeded', 'Self-Refine aborted: estimated cost exceeds ceiling.');
        }

        $candidate = self::generate($generator, $task, null, null);
        $stoppedReason = self::STOPPED_MAX_ITERS;

        for ($i = 1; $i <= $maxIterations; ++$i) {
            $verdict = self::sanitizeVerdict(self::critique($critic, $task, $candidate));

            $verdicts[] = $verdict['verdict'];
            $feedback[] = $verdict['feedback'];

            if (is_callable($onIteration)) {
                $onIteration(
                    array(
                        'iteration' => $i,
                        'verdict' => $verdict['verdict'],
                        'feedback' => $verdict['feedback'],
                        'candidate_length' => strlen($candidate),
                    )
                );
            }

            if ('accept' === $verdict['verdict'] || '' === trim($verdict['feedback'])) {
                $stoppedReason = self::STOPPED_ACCEPTED;
                break;
            }

            if ('reject' === $verdict['verdict']) {
                $stoppedReason = self::STOPPED_REJECTED;
                break;
            }

            if ($i === $maxIterations) {
                $stoppedReason = self::STOPPED_MAX_ITERS;
                break;
            }

            $cost += $costPerIteration;
            if ($costCeiling > 0 && $cost > $costCeiling) {
                $stoppedReason = self::STOPPED_COST_CEILING;
                break;
            }

            $candidate = self::generate($generator, $task, $candidate, $verdict['feedback']);
        }

        return array(
            'answer' => $candidate,
            'iterations' => count($verdicts),
            'verdicts' => $verdicts,
            'feedback' => $feedback,
            'stopped_reason' => $stoppedReason,
            'estimated_cost_usd' => round($cost, 6),
        );
    }

    /**
     * Clamp an iteration request to `1..MAX_REFINE_ITERATIONS`.
     *
     * Public because the profile clamp and the loop clamp have to agree, and a
     * caller that resolves the cap itself must be able to ask.
     */
    public static function clampIterations(mixed $value): int
    {
        $value = (int) $value;

        if ($value < 1) {
            return 1;
        }

        if ($value > HarnessProfile::MAX_REFINE_ITERATIONS) {
            return HarnessProfile::MAX_REFINE_ITERATIONS;
        }

        return $value;
    }

    /**
     * Coerce a critic response to `array{verdict: string, feedback: string}`.
     *
     * An unknown or missing verdict becomes `revise`, matching the WordPress
     * behaviour: an unusable critique must not be read as approval.
     */
    public static function sanitizeVerdict(mixed $raw): array
    {
        $verdict = 'revise';
        $feedback = '';

        if (is_array($raw)) {
            if (isset($raw['verdict'])) {
                // Byte-wise lowercasing then the WordPress key charset, so a
                // non-ASCII verdict degrades to `revise` on both runtimes.
                $candidate = (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $raw['verdict']));

                if (in_array($candidate, self::VERDICTS, true)) {
                    $verdict = $candidate;
                }
            }

            if (isset($raw['feedback'])) {
                $feedback = (string) $raw['feedback'];
            }
        } elseif (is_string($raw)) {
            $feedback = $raw;
        }

        return array(
            'verdict' => $verdict,
            'feedback' => $feedback,
        );
    }

    /**
     * Call the generator, translating a platform error into an abort.
     */
    private static function generate(callable $generator, string $task, ?string $previous, ?string $critique): string
    {
        $result = $generator($task, $previous, $critique);

        if (self::isPlatformError($result)) {
            throw new SelfRefineException('generator_failed', self::errorMessage($result), $result);
        }

        return (string) $result;
    }

    /**
     * Call the critic, translating a platform error into an abort.
     */
    private static function critique(callable $critic, string $task, string $candidate): mixed
    {
        $result = $critic($task, $candidate);

        if (self::isPlatformError($result)) {
            throw new SelfRefineException('critic_failed', self::errorMessage($result), $result);
        }

        return $result;
    }

    /**
     * Duck-typed on purpose: core must not import `WP_Error`. Any error type
     * exposing `get_error_message()` — `WP_Error`, the Laravel equivalent, a
     * test double — is recognised.
     */
    private static function isPlatformError(mixed $value): bool
    {
        return is_object($value) && method_exists($value, 'get_error_message');
    }

    private static function errorMessage(object $error): string
    {
        $message = $error->get_error_message();

        return is_string($message) ? $message : '';
    }
}
