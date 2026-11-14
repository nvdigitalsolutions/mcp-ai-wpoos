<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Application\Security\PostureScorer;
use Nvoos\Core\Domain\Security\SecuritySignalCatalogue;
use Tests\TestCase;

/**
 * Security posture — the shared signal catalogue contract.
 *
 * The WordPress and Laravel runtimes must report the same check vocabulary so
 * the console renders one list on either backend. What differs is `applicable`:
 * a control the runtime has no primitive for is reported as not applicable and
 * is excluded from both sides of the score ratio.
 */
class SecurityPostureTest extends TestCase
{
    use RefreshDatabase;

    private function posture(): array
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/security/posture')
            ->assertOk()
            ->json();
    }

    /** @param array<int, array<string, mixed>> $signals */
    private function find(array $signals, string $id): array
    {
        foreach ($signals as $signal) {
            if ($signal['id'] === $id) {
                return $signal;
            }
        }

        $this->fail("Signal {$id} is missing from the posture report.");
    }

    public function test_the_report_covers_the_whole_catalogue(): void
    {
        $report = $this->posture();

        $this->assertSame(
            count(SecuritySignalCatalogue::all()),
            count($report['signals'])
        );

        // Every catalogue entry keeps its display metadata, so the console can
        // group and deep-link the card on both runtimes.
        foreach ($report['signals'] as $signal) {
            $this->assertNotEmpty($signal['label']);
            $this->assertIsInt($signal['weight']);
            $this->assertArrayHasKey('anchor', $signal);
            $this->assertArrayHasKey('reference', $signal);
        }
    }

    public function test_wordpress_only_controls_are_not_applicable_and_never_fail(): void
    {
        $report = $this->posture();

        // The nine WordPress-only controls must all be reported as N/A rather
        // than as failures — otherwise this runtime is penalised for controls
        // it cannot possibly satisfy.
        foreach (SecuritySignalCatalogue::onlyOn(SecuritySignalCatalogue::RUNTIME_WORDPRESS) as $definition) {
            $signal = $this->find($report['signals'], $definition->id);

            $this->assertFalse($signal['applicable'], "{$definition->id} must not be applicable.");
            $this->assertSame('not_applicable', $signal['status']);
            $this->assertFalse($signal['passed']);
            $this->assertSame(SecuritySignalCatalogue::NOT_APPLICABLE_DETAIL, $signal['detail']);
        }

        $quickWinIds = array_column($report['quick_wins'], 'id');
        foreach (SecuritySignalCatalogue::onlyOn(SecuritySignalCatalogue::RUNTIME_WORDPRESS) as $definition) {
            $this->assertNotContains($definition->id, $quickWinIds);
        }
    }

    public function test_the_laravel_only_controls_are_applicable_here(): void
    {
        $report = $this->posture();

        foreach (SecuritySignalCatalogue::onlyOn(SecuritySignalCatalogue::RUNTIME_LARAVEL) as $definition) {
            $signal = $this->find($report['signals'], $definition->id);

            $this->assertTrue($signal['applicable'], "{$definition->id} is a Laravel primitive.");
            $this->assertContains($signal['status'], ['pass', 'fail']);
        }
    }

    public function test_cors_is_reported_as_restricted_under_the_default_policy(): void
    {
        // config/cors.php defaults to no cross-origin access: the console is
        // served same-origin through the proxy.
        $report = $this->posture();

        $this->assertSame('pass', $this->find($report['signals'], 'cors_restricted')['status']);
    }

    public function test_cors_is_reported_as_a_finding_when_every_origin_is_allowed(): void
    {
        config(['cors.allowed_origins' => ['*']]);

        $report = $this->posture();

        $signal = $this->find($report['signals'], 'cors_restricted');

        $this->assertSame('fail', $signal['status']);
        $this->assertTrue($signal['applicable']);
    }

    public function test_the_score_uses_applicable_weight_only(): void
    {
        $report = $this->posture();

        $applicable = array_values(array_filter(
            $report['signals'],
            static fn (array $signal): bool => $signal['applicable']
        ));

        $expected = (int) round(
            array_sum(array_column(
                array_values(array_filter($applicable, static fn (array $s): bool => $s['passed'])),
                'weight'
            )) / array_sum(array_column($applicable, 'weight')) * 100
        );

        $this->assertSame($expected, $report['score']);
        $this->assertSame(PostureScorer::gradeFor($expected), $report['grade']);
    }

    /**
     * @dataProvider gradeBands
     */
    public function test_grades_follow_the_mozilla_observatory_bands(int $score, string $grade): void
    {
        $this->assertSame($grade, PostureScorer::gradeFor($score));
    }

    public static function gradeBands(): array
    {
        return [
            'perfect' => [100, 'A+'],
            'a floor' => [90, 'A'],
            'a minus' => [85, 'A-'],
            'b plus' => [80, 'B+'],
            'b floor' => [70, 'B'],
            'b minus' => [65, 'B-'],
            'c plus' => [60, 'C+'],
            'c floor' => [50, 'C'],
            'c minus' => [45, 'C-'],
            'd plus' => [40, 'D+'],
            'd floor' => [30, 'D'],
            'd minus' => [25, 'D-'],
            'f' => [24, 'F'],
            'zero' => [0, 'F'],
        ];
    }
}
