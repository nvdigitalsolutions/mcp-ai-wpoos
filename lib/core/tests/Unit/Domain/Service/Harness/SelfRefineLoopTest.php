<?php

/**
 * Tests for the self-refine loop.
 *
 * The load-bearing properties are the two caps and the stop conditions: a
 * caller cannot buy more than `MAX_REFINE_ITERATIONS` passes, an unaffordable
 * draft fails before it is generated, empty feedback counts as acceptance
 * (nothing actionable came back), `reject` stops without revising, and a
 * platform error from either callable aborts instead of being stringified into
 * an answer.
 *
 * @package Nvoos\Core\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Harness;

use Nvoos\Core\Domain\Service\Harness\HarnessProfile;
use Nvoos\Core\Domain\Service\Harness\SelfRefineException;
use Nvoos\Core\Domain\Service\Harness\SelfRefineLoop;
use PHPUnit\Framework\TestCase;

final class SelfRefineLoopTest extends TestCase
{
    /**
     * A generator that always returns the same text, counting its calls.
     */
    private function countingGenerator(string $answer, ?int &$calls = null): callable
    {
        $calls = 0;

        return static function () use ($answer, &$calls): string {
            ++$calls;

            return $answer;
        };
    }

    public function test_an_empty_task_is_refused_before_anything_runs(): void
    {
        $generator = $this->countingGenerator('x', $calls);

        try {
            SelfRefineLoop::run('   ', $generator, static fn (): array => array());
            $this->fail('Expected a SelfRefineException.');
        } catch (SelfRefineException $e) {
            $this->assertSame('empty_task', $e->reason);
        }

        $this->assertSame(0, $calls, 'The generator must not run for an empty task.');
    }

    public function test_an_accepting_critic_stops_after_one_pass(): void
    {
        $generator = $this->countingGenerator('draft', $calls);

        $result = SelfRefineLoop::run(
            'Write a haiku.',
            $generator,
            static fn (): array => array( 'verdict' => 'accept', 'feedback' => 'Good.' )
        );

        $this->assertSame(1, $result['iterations']);
        $this->assertSame('accepted', $result['stopped_reason']);
        $this->assertSame(array( 'accept' ), $result['verdicts']);
        $this->assertSame('draft', $result['answer']);
        $this->assertSame(1, $calls);
    }

    public function test_empty_feedback_counts_as_acceptance(): void
    {
        $generator = $this->countingGenerator('draft', $calls);

        $result = SelfRefineLoop::run(
            'Write a haiku.',
            $generator,
            static fn (): array => array( 'verdict' => 'revise', 'feedback' => '   ' )
        );

        $this->assertSame('accepted', $result['stopped_reason']);
        $this->assertSame(1, $calls, 'Nothing actionable means nothing to revise.');
    }

    public function test_reject_stops_without_revising(): void
    {
        $generator = $this->countingGenerator('draft', $calls);

        $result = SelfRefineLoop::run(
            'Write a haiku.',
            $generator,
            static fn (): array => array( 'verdict' => 'reject', 'feedback' => 'Harmful request.' )
        );

        $this->assertSame('rejected', $result['stopped_reason']);
        $this->assertSame(1, $calls);
    }

    public function test_the_loop_revises_until_the_cap(): void
    {
        $generator = $this->countingGenerator('draft', $calls);

        $result = SelfRefineLoop::run(
            'Write a haiku.',
            $generator,
            static fn (): array => array( 'verdict' => 'revise', 'feedback' => 'Tighten line two.' ),
            array( 'max_iters' => 3 )
        );

        $this->assertSame(3, $result['iterations']);
        $this->assertSame('max_iters', $result['stopped_reason']);
        $this->assertSame(3, $calls, 'One draft plus two revisions.');
        $this->assertSame(array( 'revise', 'revise', 'revise' ), $result['verdicts']);
    }

    /**
     * The profile clamps at the same value, but a caller can pass `$opts`
     * directly, so the loop has to hold the ceiling on its own.
     */
    public function test_the_iteration_cap_holds_even_when_the_caller_asks_for_more(): void
    {
        $generator = $this->countingGenerator('draft', $calls);

        $result = SelfRefineLoop::run(
            'Write a haiku.',
            $generator,
            static fn (): array => array( 'verdict' => 'revise', 'feedback' => 'Again.' ),
            array( 'max_iters' => 999 )
        );

        $this->assertSame(HarnessProfile::MAX_REFINE_ITERATIONS, $result['iterations']);
    }

    public function test_a_zero_or_negative_cap_still_runs_once(): void
    {
        $generator = $this->countingGenerator('draft', $calls);

        $result = SelfRefineLoop::run(
            'Write a haiku.',
            $generator,
            static fn (): array => array( 'verdict' => 'revise', 'feedback' => 'Again.' ),
            array( 'max_iters' => -4 )
        );

        $this->assertSame(1, $result['iterations']);
    }

    public function test_an_unaffordable_draft_fails_before_generating(): void
    {
        $generator = $this->countingGenerator('draft', $calls);

        try {
            SelfRefineLoop::run(
                'Write a haiku.',
                $generator,
                static fn (): array => array( 'verdict' => 'accept', 'feedback' => '' ),
                array( 'cost_per_iter' => 0.5, 'cost_ceiling' => 0.25 )
            );
            $this->fail('Expected a SelfRefineException.');
        } catch (SelfRefineException $e) {
            $this->assertSame('cost_exceeded', $e->reason);
        }

        $this->assertSame(0, $calls, 'An unaffordable draft must not be generated.');
    }

    public function test_the_cost_ceiling_stops_the_loop_with_a_reason(): void
    {
        $generator = $this->countingGenerator('draft', $calls);

        $result = SelfRefineLoop::run(
            'Write a haiku.',
            $generator,
            static fn (): array => array( 'verdict' => 'revise', 'feedback' => 'Again.' ),
            array( 'max_iters' => 4, 'cost_per_iter' => 0.4, 'cost_ceiling' => 1.0 )
        );

        // 0.4 for the draft, 0.8 before the first revision, then 1.2 > 1.0 — and
        // the charge for the pass that never ran is still counted, which is the
        // WordPress behaviour this ports.
        $this->assertSame('cost_ceiling', $result['stopped_reason']);
        $this->assertSame(2, $result['iterations']);
        $this->assertSame(1.2, $result['estimated_cost_usd']);
        $this->assertSame(2, $calls);
    }

    public function test_a_zero_ceiling_disables_the_budget_check(): void
    {
        $generator = $this->countingGenerator('draft', $calls);

        $result = SelfRefineLoop::run(
            'Write a haiku.',
            $generator,
            static fn (): array => array( 'verdict' => 'revise', 'feedback' => 'Again.' ),
            array( 'max_iters' => 2, 'cost_per_iter' => 100.0, 'cost_ceiling' => 0 )
        );

        $this->assertSame('max_iters', $result['stopped_reason']);
        $this->assertSame(200.0, $result['estimated_cost_usd']);
    }

    public function test_each_pass_is_reported_to_the_trace_seam(): void
    {
        $traced = array();

        SelfRefineLoop::run(
            'Write a haiku.',
            $this->countingGenerator('draft'),
            static fn (): array => array( 'verdict' => 'revise', 'feedback' => 'Tighten line two.' ),
            array(
                'max_iters' => 2,
                'on_iteration' => static function (array $iteration) use (&$traced): void {
                    $traced[] = $iteration;
                },
            )
        );

        $this->assertCount(2, $traced);
        $this->assertSame(1, $traced[0]['iteration']);
        $this->assertSame('revise', $traced[0]['verdict']);
        $this->assertSame('Tighten line two.', $traced[0]['feedback']);
        $this->assertSame(5, $traced[0]['candidate_length']);
    }

    public function test_a_generator_error_aborts_with_the_original_object(): void
    {
        $error = new class () {
            public function get_error_message(): string
            {
                return 'provider exploded';
            }
        };

        try {
            SelfRefineLoop::run(
                'Write a haiku.',
                static fn () => $error,
                static fn (): array => array( 'verdict' => 'accept', 'feedback' => '' )
            );
            $this->fail('Expected a SelfRefineException.');
        } catch (SelfRefineException $e) {
            $this->assertSame('generator_failed', $e->reason);
            $this->assertSame('provider exploded', $e->getMessage());
            $this->assertSame($error, $e->getPlatformError(), 'The caller must get its own error back.');
        }
    }

    public function test_a_critic_error_aborts_with_the_original_object(): void
    {
        $error = new class () {
            public function get_error_message(): string
            {
                return 'critic exploded';
            }
        };

        try {
            SelfRefineLoop::run(
                'Write a haiku.',
                $this->countingGenerator('draft'),
                static fn () => $error
            );
            $this->fail('Expected a SelfRefineException.');
        } catch (SelfRefineException $e) {
            $this->assertSame('critic_failed', $e->reason);
            $this->assertSame($error, $e->getPlatformError());
        }
    }

    /**
     * @dataProvider verdicts
     */
    public function test_a_verdict_is_normalised_to_the_contract(mixed $raw, string $verdict, string $feedback): void
    {
        $result = SelfRefineLoop::sanitizeVerdict($raw);

        $this->assertSame($verdict, $result['verdict']);
        $this->assertSame($feedback, $result['feedback']);
    }

    /**
     * @return array<string, array{0: mixed, 1: string, 2: string}>
     */
    public static function verdicts(): array
    {
        return array(
            'canonical accept' => array( array( 'verdict' => 'accept', 'feedback' => 'ok' ), 'accept', 'ok' ),
            'case and spacing' => array( array( 'verdict' => '  ACCEPT ', 'feedback' => 'ok' ), 'accept', 'ok' ),
            'unknown verdict' => array( array( 'verdict' => 'maybe', 'feedback' => 'hmm' ), 'revise', 'hmm' ),
            'missing verdict' => array( array( 'feedback' => 'hmm' ), 'revise', 'hmm' ),
            'a bare string is feedback' => array( 'needs work', 'revise', 'needs work' ),
            'junk is feedback-less revise' => array( 42, 'revise', '' ),
            'missing feedback' => array( array( 'verdict' => 'reject' ), 'reject', '' ),
        );
    }
}
