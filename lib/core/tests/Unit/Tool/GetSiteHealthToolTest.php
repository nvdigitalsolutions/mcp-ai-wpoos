<?php

/**
 * The host health tool.
 *
 * The statuses are the whole contract: a host reports `good`, `recommended` or
 * `critical`, and the tool must not soften any of them. The two properties worth
 * pinning are that `critical` wins, and that a status the vocabulary does not
 * know is not counted as a pass.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HealthReportInterface;
use Nvoos\Core\Tool\GetSiteHealthTool;
use PHPUnit\Framework\TestCase;

final class GetSiteHealthToolTest extends TestCase
{
    public function test_it_reports_good_when_every_probe_passes(): void
    {
        $result = $this->tool(
            array(
                $this->probe('database', HealthReportInterface::GOOD),
                $this->probe('cache', HealthReportInterface::GOOD),
            )
        )->execute();

        $this->assertTrue($result['success']);
        $this->assertSame(HealthReportInterface::GOOD, $result['data']['status']);
        $this->assertSame(
            array('good' => 2, 'recommended' => 0, 'critical' => 0),
            $result['data']['counts']
        );
        $this->assertCount(2, $result['data']['probes']);
        $this->assertSame('Site Health: good (0 critical, 0 recommended, 2 good).', $result['message']);
    }

    public function test_a_critical_probe_makes_the_whole_report_critical(): void
    {
        $result = $this->tool(
            array(
                $this->probe('database', HealthReportInterface::GOOD),
                $this->probe('queue', HealthReportInterface::CRITICAL),
                $this->probe('cache', HealthReportInterface::RECOMMENDED),
            )
        )->execute();

        $this->assertSame(HealthReportInterface::CRITICAL, $result['data']['status']);
        $this->assertSame(1, $result['data']['counts']['critical']);
        $this->assertSame(1, $result['data']['counts']['recommended']);
    }

    public function test_a_recommendation_downgrades_without_failing(): void
    {
        $result = $this->tool(
            array(
                $this->probe('database', HealthReportInterface::GOOD),
                $this->probe('security', HealthReportInterface::RECOMMENDED),
            )
        )->execute();

        $this->assertSame(HealthReportInterface::RECOMMENDED, $result['data']['status']);
    }

    public function test_a_status_outside_the_vocabulary_is_not_counted_as_a_pass(): void
    {
        $result = $this->tool(array($this->probe('odd', 'warn')))->execute();

        $this->assertSame(
            HealthReportInterface::RECOMMENDED,
            $result['data']['status'],
            'An unknown status must not produce a good report.'
        );
        $this->assertSame(1, $result['data']['counts']['recommended']);

        // The probe itself is passed through unchanged, so a reader can see the
        // value the host actually reported.
        $this->assertSame('warn', $result['data']['probes'][0]['status']);
    }

    public function test_a_probe_without_a_status_is_not_a_pass(): void
    {
        $probe = $this->probe('truncated', HealthReportInterface::GOOD);
        unset($probe['status']);

        $result = $this->tool(array($probe))->execute();

        $this->assertSame(HealthReportInterface::RECOMMENDED, $result['data']['status']);
    }

    public function test_an_empty_report_is_good(): void
    {
        $result = $this->tool(array())->execute();

        $this->assertSame(HealthReportInterface::GOOD, $result['data']['status']);
        $this->assertSame(array(), $result['data']['probes']);
    }

    /**
     * @param list<array<string, string>> $probes
     */
    private function tool(array $probes): GetSiteHealthTool
    {
        $health = $this->createMock(HealthReportInterface::class);
        $health->method('probes')->willReturn($probes);

        return new GetSiteHealthTool($this->createMock(ErrorFactoryInterface::class), $health);
    }

    /**
     * @return array{id: string, label: string, status: string, summary: string, detail: string}
     */
    private function probe(string $id, string $status): array
    {
        return array(
            'id'      => $id,
            'label'   => ucfirst($id),
            'status'  => $status,
            'summary' => $id . ' summary.',
            'detail'  => $id . ' detail.',
        );
    }
}
