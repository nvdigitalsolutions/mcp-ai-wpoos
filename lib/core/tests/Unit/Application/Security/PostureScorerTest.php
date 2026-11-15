<?php

/**
 * Tests for PostureEvaluator + PostureScorer.
 *
 * The load-bearing properties: a not-applicable control never enters the
 * denominator, a missing evaluation is a visible failure (not a free pass),
 * and the grade bands match the documented Observatory table.
 *
 * @package Nvoos\Core\Tests
 * @since   1.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Security;

use Nvoos\Core\Application\Security\PostureEvaluator;
use Nvoos\Core\Application\Security\PostureScorer;
use Nvoos\Core\Domain\Security\SecuritySignalCatalogue;
use Nvoos\Core\Domain\Security\SecuritySignalDefinition;
use Nvoos\Core\Domain\ValueObject\PostureSignal;
use PHPUnit\Framework\TestCase;

final class PostureScorerTest extends TestCase
{
    private function signal(
        string $id,
        int $weight,
        bool $passed,
        bool $applicable = true
    ): PostureSignal {
        return new PostureSignal(
            definition: new SecuritySignalDefinition(
                id: $id,
                label: $id,
                weight: $weight,
                subtab: 'network',
                anchor: '',
                runtimes: array( SecuritySignalCatalogue::RUNTIME_WORDPRESS ),
            ),
            passed: $passed,
            applicable: $applicable,
            detail: '',
        );
    }

    public function test_a_clean_posture_is_100_and_a_plus(): void
    {
        $report = ( new PostureScorer() )->score(array(
            $this->signal('a', 10, true),
            $this->signal('b', 5, true),
        ));

        $this->assertSame(100, $report['score']);
        $this->assertSame('A+', $report['grade']);
        $this->assertSame(15, $report['passed_weight']);
        $this->assertSame(15, $report['applicable_weight']);
        $this->assertSame(array(), $report['quick_wins']);
    }

    public function test_the_score_is_normalised_over_applicable_weight(): void
    {
        $report = ( new PostureScorer() )->score(array(
            $this->signal('a', 10, true),
            $this->signal('b', 5, true),
            $this->signal('c', 5, false),
        ));

        $this->assertSame(75, $report['score']);
        $this->assertSame('B', $report['grade']);
    }

    public function test_not_applicable_controls_leave_the_denominator(): void
    {
        $report = ( new PostureScorer() )->score(array(
            $this->signal('a', 10, true),
            $this->signal('b', 10, false),
            $this->signal('wordpress_only', 50, false, false),
        ));

        // Without the N/A exclusion this would be 10/70 = 14 (F).
        $this->assertSame(50, $report['score']);
        $this->assertSame('C', $report['grade']);
        $this->assertSame(20, $report['applicable_weight']);
    }

    public function test_no_applicable_controls_reads_as_clean_not_critical(): void
    {
        $report = ( new PostureScorer() )->score(array(
            $this->signal('x', 10, false, false),
        ));

        $this->assertSame(100, $report['score']);
        $this->assertSame(0, $report['applicable_weight']);
    }

    public function test_quick_wins_are_the_three_heaviest_failures(): void
    {
        $report = ( new PostureScorer() )->score(array(
            $this->signal('light', 1, false),
            $this->signal('heavy', 9, false),
            $this->signal('medium', 5, false),
            $this->signal('other', 7, false),
            $this->signal('not_applicable', 50, false, false),
            $this->signal('passing', 10, true),
        ));

        $ids = array_column($report['quick_wins'], 'id');

        $this->assertSame(array( 'heavy', 'other', 'medium' ), $ids);
        $this->assertLessThanOrEqual(PostureScorer::MAX_QUICK_WINS, count($ids));
    }

    public function test_grade_bands_match_the_documented_table(): void
    {
        $scorer = new PostureScorer();

        $expected = array(
            100 => 'A+',
            99  => 'A',
            90  => 'A',
            89  => 'A-',
            85  => 'A-',
            84  => 'B+',
            80  => 'B+',
            79  => 'B',
            70  => 'B',
            69  => 'B-',
            65  => 'B-',
            64  => 'C+',
            60  => 'C+',
            59  => 'C',
            50  => 'C',
            49  => 'C-',
            45  => 'C-',
            44  => 'D+',
            40  => 'D+',
            39  => 'D',
            30  => 'D',
            29  => 'D-',
            25  => 'D-',
            24  => 'F',
            0   => 'F',
        );

        foreach ($expected as $score => $grade) {
            $this->assertSame($grade, PostureScorer::gradeFor($score), "score {$score}");
        }
    }

    public function test_the_evaluator_marks_runtime_specific_controls(): void
    {
        $signals = PostureEvaluator::build(
            SecuritySignalCatalogue::RUNTIME_LARAVEL,
            array( 'https_active' => array( 'passed' => true, 'detail' => 'ok' ) )
        );

        $by_id = array();
        foreach ($signals as $signal) {
            $by_id[ $signal->definition->id ] = $signal;
        }

        $this->assertTrue($by_id['https_active']->applicable);
        $this->assertTrue($by_id['https_active']->passed);
        $this->assertSame(PostureSignal::STATUS_PASS, $by_id['https_active']->status());

        $this->assertFalse($by_id['root_key_set']->applicable);
        $this->assertFalse($by_id['root_key_set']->passed);
        $this->assertSame(PostureSignal::STATUS_NOT_APPLICABLE, $by_id['root_key_set']->status());
        $this->assertSame(SecuritySignalCatalogue::NOT_APPLICABLE_DETAIL, $by_id['root_key_set']->detail);
    }

    public function test_an_applicable_but_unevaluated_control_fails_loudly(): void
    {
        $signals = PostureEvaluator::build(SecuritySignalCatalogue::RUNTIME_LARAVEL, array());

        $hsts = null;
        foreach ($signals as $signal) {
            if ('hsts_enabled' === $signal->definition->id) {
                $hsts = $signal;
                break;
            }
        }

        $this->assertNotNull($hsts);
        $this->assertTrue($hsts->applicable);
        $this->assertFalse($hsts->passed);
        $this->assertSame(PostureEvaluator::UNEVALUATED_DETAIL, $hsts->detail);
    }

    public function test_a_runtime_label_override_wins(): void
    {
        $signals = PostureEvaluator::build(
            SecuritySignalCatalogue::RUNTIME_WORDPRESS,
            array( 'https_active' => array( 'passed' => true, 'label' => 'HTTPS (übersetzt)' ) )
        );

        foreach ($signals as $signal) {
            if ('https_active' === $signal->definition->id) {
                $this->assertSame('HTTPS (übersetzt)', $signal->label());
                $this->assertSame('HTTPS (übersetzt)', $signal->toArray()['label']);
                return;
            }
        }

        $this->fail('https_active missing from the evaluation');
    }

    public function test_the_payload_keeps_the_console_shape(): void
    {
        $report = ( new PostureScorer() )->score(
            PostureEvaluator::build(SecuritySignalCatalogue::RUNTIME_LARAVEL, array())
        );

        foreach (array( 'score', 'grade', 'passed_weight', 'applicable_weight', 'signals', 'quick_wins', 'computed_at' ) as $key) {
            $this->assertArrayHasKey($key, $report);
        }

        $signal = $report['signals'][0];

        foreach (array( 'id', 'label', 'weight', 'passed', 'applicable', 'status', 'detail', 'subtab', 'anchor', 'reference' ) as $key) {
            $this->assertArrayHasKey($key, $signal);
        }
    }
}
