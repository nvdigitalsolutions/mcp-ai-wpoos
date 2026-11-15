<?php

/**
 * Weighted posture scoring over applicable signals.
 *
 * Only applicable signals enter the numerator and the denominator. That is
 * the AWS Security Hub / CIS-CAT rule: a control that cannot be evaluated on
 * a runtime is "not applicable", not "failed". Without it a runtime would be
 * penalised for controls it has no primitive for.
 *
 * Grade bands are Mozilla HTTP Observatory's
 * (http-observatory/docs/scoring.md): 100 A+, 90 A, 85 A-, 80 B+, 70 B,
 * 65 B-, 60 C+, 50 C, 45 C-, 40 D+, 30 D, 25 D-, below 25 F.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Security;

use Nvoos\Core\Domain\ValueObject\PostureSignal;

final class PostureScorer
{
    /** Quick wins are the highest-weight failures the operator can fix now. */
    public const MAX_QUICK_WINS = 3;

    /**
     * Score floor → grade, highest first (Mozilla HTTP Observatory).
     */
    private const GRADES = array(
        array( 100, 'A+' ),
        array( 90, 'A' ),
        array( 85, 'A-' ),
        array( 80, 'B+' ),
        array( 70, 'B' ),
        array( 65, 'B-' ),
        array( 60, 'C+' ),
        array( 50, 'C' ),
        array( 45, 'C-' ),
        array( 40, 'D+' ),
        array( 30, 'D' ),
        array( 25, 'D-' ),
        array( 0, 'F' ),
    );

    /**
     * @param array<int, PostureSignal> $signals
     * @return array{
     *   score: int,
     *   grade: string,
     *   passed_weight: int,
     *   applicable_weight: int,
     *   signals: array<int, array<string, mixed>>,
     *   quick_wins: array<int, array<string, mixed>>,
     *   computed_at: string
     * }
     */
    public function score(array $signals): array
    {
        $applicable_weight = 0;
        $passed_weight = 0;
        $failing = array();

        foreach ($signals as $signal) {
            if (! $signal->applicable) {
                continue;
            }

            $applicable_weight += $signal->weight();

            if ($signal->passed) {
                $passed_weight += $signal->weight();
            } else {
                $failing[] = $signal;
            }
        }

        // No applicable control means nothing can fail. Returning 0 here would
        // read as "critically insecure" for a runtime with no evaluable surface.
        $score = $applicable_weight > 0
            ? (int) round(( $passed_weight / $applicable_weight ) * 100)
            : 100;

        usort(
            $failing,
            static fn (PostureSignal $a, PostureSignal $b): int => $b->weight() <=> $a->weight()
        );

        return array(
            'score'             => $score,
            'grade'             => self::gradeFor($score),
            'passed_weight'     => $passed_weight,
            'applicable_weight' => $applicable_weight,
            'signals'           => array_map(
                static fn (PostureSignal $signal): array => $signal->toArray(),
                $signals
            ),
            'quick_wins'        => array_map(
                static fn (PostureSignal $signal): array => $signal->toArray(),
                array_slice($failing, 0, self::MAX_QUICK_WINS)
            ),
            'computed_at'       => gmdate('c'),
        );
    }

    public static function gradeFor(int $score): string
    {
        foreach (self::GRADES as $band) {
            if ($score >= $band[0]) {
                return $band[1];
            }
        }

        return 'F';
    }
}
