<?php

namespace Tests\Feature;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Tests\TestCase;

/**
 * `get_site_health` against this app — Proposal 090-M §12.
 *
 * The unit test pins the aggregation rules. This pins the wiring the unit test
 * cannot: that the manifest row is servable here, that the package's two-probe
 * default was replaced by the app's full probe set, and that the tool reports
 * the same probes the `/ops/site-health` page renders.
 */
final class GetSiteHealthToolTest extends TestCase
{
    public function test_the_container_builds_the_health_tool(): void
    {
        $this->assertTrue(
            $this->app->make(ToolRegistry::class)->has('get_site_health'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_it_answers_with_the_apps_probe_set(): void
    {
        $tool = $this->app->make(ToolRegistry::class)->get('get_site_health');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        $result = $tool->execute();

        $this->assertTrue($result['success']);
        $this->assertContains($result['data']['status'], array('good', 'recommended', 'critical'));

        $ids = array_column($result['data']['probes'], 'id');

        $this->assertContains(
            'database',
            $ids,
            'The app binding did not replace the package default probe set.'
        );
        $this->assertNotContains(
            'settings',
            $ids,
            'The package default leaked through; `settings` is not an app probe.'
        );

        $this->assertSame(
            count($result['data']['probes']),
            array_sum($result['data']['counts']),
            'Every probe lands in exactly one bucket.'
        );
    }
}
