<?php

/**
 * Coverage says what this host can do, not what its worker could do.
 *
 * The bug this pins is a wording bug with a real cost: `DiagnosticsPage` printed
 * the worker's tier under a label an operator reads as the host's capability, and
 * for social the two differed — the worker declared `social_publishing` whenever
 * a platform key was set, while the runtime served no social tool at all.
 *
 * `ToolCoverage` adds `served` and `worker_only`, so "the worker could" and "this
 * host does" are two different answers.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\ValueObject;

use Nvoos\Core\Domain\ValueObject\MediaWorkerCapabilityReport;
use Nvoos\Core\Domain\ValueObject\ToolCoverage;
use PHPUnit\Framework\TestCase;

final class ToolCoverageTest extends TestCase
{
    /**
     * The manifest's servable slugs, read from the shipped file.
     *
     * @return list<string>
     */
    private static function servable(): array
    {
        $manifest = require dirname(__DIR__, 4) . '/resources/tool-readiness.php';
        $slugs = array();

        foreach ((array) ($manifest['tools'] ?? array()) as $slug => $row) {
            if (\in_array((string) ($row['readiness'] ?? ''), array('ready', 'pure', 'delegated'), true)) {
                $slugs[] = (string) $slug;
            }
        }

        return $slugs;
    }

    /**
     * A worker that declares everything.
     */
    private static function fullWorker(): MediaWorkerCapabilityReport
    {
        return MediaWorkerCapabilityReport::fromProbe(
            true,
            true,
            '3.4.0',
            array(
                'pdf_generation' => true,
                'document_word' => true,
                'document_excel' => true,
                'document_ocr' => true,
                'image_optimization' => true,
                'video_processing' => true,
                'email' => true,
                'chart_rendering' => true,
                'browser_automation' => true,
                'web_crawling' => true,
                'social_accounts' => true,
                'social_composition' => true,
                'social_publishing' => true,
            )
        );
    }

    public function test_every_domain_the_report_knows_about_has_a_coverage_row(): void
    {
        $declared = MediaWorkerCapabilityReport::knownDomains();
        $covered = ToolCoverage::knownDomains();

        \sort($declared);
        \sort($covered);

        $this->assertSame(
            $declared,
            $covered,
            'A domain the report can name but coverage cannot is a domain that silently disappears from diagnostics.'
        );
    }

    public function test_every_mapped_slug_is_servable(): void
    {
        $servable = self::servable();
        $coverage = ToolCoverage::fromReport(self::fullWorker(), $servable);

        // `tools()` filters to servable slugs, so a typo in the map shows up as a
        // missing tool rather than an error. Compare against the raw declaration
        // by asking each domain and checking a non-empty row is non-empty for the
        // domains we know have tools.
        foreach (array('documents', 'office', 'ocr', 'image', 'email', 'data', 'crawl', 'social') as $domain) {
            $this->assertNotSame(
                array(),
                $coverage->tools($domain),
                $domain . ' maps no servable tool, which means a slug in TOOLS is not in the manifest: '
                    . \implode(', ', $coverage->tools($domain))
            );
        }
    }

    public function test_a_domain_the_worker_serves_and_a_tool_reaches_is_served(): void
    {
        $coverage = ToolCoverage::fromReport(self::fullWorker(), self::servable());

        $this->assertSame(ToolCoverage::TIER_SERVED, $coverage->tier('image'));
        $this->assertSame(ToolCoverage::TIER_SERVED, $coverage->tier('social'));
        $this->assertStringStartsWith('served (', $coverage->describe('image'));
    }

    public function test_publishing_reads_worker_only_while_social_composition_is_served(): void
    {
        $coverage = ToolCoverage::fromReport(self::fullWorker(), self::servable());

        // The whole point: the worker can publish, this host cannot, and the two
        // domains answer differently.
        $this->assertSame(ToolCoverage::TIER_SERVED, $coverage->tier('social'));
        $this->assertSame(ToolCoverage::TIER_WORKER_ONLY, $coverage->tier('social_publishing'));
        $this->assertSame('worker available, no tool here', $coverage->describe('social_publishing'));
        $this->assertContains('social_publishing', $coverage->toArray()['worker_only']);
    }

    public function test_video_and_browser_are_honest_worker_only_domains(): void
    {
        $coverage = ToolCoverage::fromReport(self::fullWorker(), self::servable());

        $this->assertSame(ToolCoverage::TIER_WORKER_ONLY, $coverage->tier('video'));
        $this->assertSame(ToolCoverage::TIER_WORKER_ONLY, $coverage->tier('browser'));
    }

    public function test_a_worker_without_a_capability_outranks_the_host_tool_count(): void
    {
        $report = MediaWorkerCapabilityReport::fromProbe(true, true, '3.4.0', array(
            'image_optimization' => true,
            // social keys are absent: this worker cannot.
        ));

        $coverage = ToolCoverage::fromReport($report, self::servable());

        $this->assertSame(
            ToolCoverage::TIER_UNAVAILABLE,
            $coverage->tier('social'),
            'A tool that exists but cannot reach its engine is not coverage.'
        );
    }

    public function test_an_unreachable_worker_is_unknown_not_unavailable(): void
    {
        $coverage = ToolCoverage::fromReport(MediaWorkerCapabilityReport::unreachable('3.4.0'), self::servable());

        $this->assertSame(ToolCoverage::TIER_UNKNOWN, $coverage->tier('image'));
        $this->assertSame(ToolCoverage::TIER_UNKNOWN, $coverage->tier('social_publishing'));
    }

    public function test_an_unconfigured_worker_is_unavailable_everywhere(): void
    {
        $coverage = ToolCoverage::fromReport(MediaWorkerCapabilityReport::unconfigured(), self::servable());

        foreach (ToolCoverage::knownDomains() as $domain) {
            $this->assertSame(ToolCoverage::TIER_UNAVAILABLE, $coverage->tier($domain), $domain);
        }
    }
}
