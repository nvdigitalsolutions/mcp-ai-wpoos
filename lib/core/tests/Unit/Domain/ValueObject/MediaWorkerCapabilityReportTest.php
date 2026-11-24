<?php

/**
 * `MediaWorkerCapabilityReport`.
 *
 * The report exists so a tier is answered before a request is spent, and so a
 * probe that failed is not confused with a worker that cannot do the job. Those
 * are the two properties these tests pin:
 *
 *  - a configured worker that did not answer is `unknown`, never `unavailable`;
 *  - a worker that answered and named a missing binary is `unavailable`, and
 *    `missing()` names the binary.
 *
 * The capability keys are the worker's own, so the fixtures use the strings in
 * `addons/media-worker/src/index.js` rather than invented ones.
 *
 * @package Nvoos\Core\Tests
 * @since   2.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\ValueObject;

use Nvoos\Core\Domain\ValueObject\MediaWorkerCapabilityReport;
use PHPUnit\Framework\TestCase;

final class MediaWorkerCapabilityReportTest extends TestCase
{
    /**
     * The matrix a fully-equipped worker answers with, trimmed to the keys the
     * declared domains read.
     *
     * @return array<string, bool>
     */
    private function fullMatrix(array $overrides = array()): array
    {
        return $overrides + array(
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
            'social_publishing' => true,
        );
    }

    public function test_an_unconfigured_host_is_unavailable_everywhere(): void
    {
        $report = MediaWorkerCapabilityReport::unconfigured();

        $this->assertFalse($report->isConfigured());
        $this->assertFalse($report->isAvailable());
        $this->assertNull($report->version());

        foreach (MediaWorkerCapabilityReport::knownDomains() as $domain) {
            $this->assertSame(
                MediaWorkerCapabilityReport::TIER_UNAVAILABLE,
                $report->tier($domain),
                "{$domain} must be unavailable when no worker is configured."
            );
            $this->assertSame(array(), $report->missing($domain), 'Nothing is known to be missing.');
        }
    }

    public function test_a_configured_worker_that_did_not_answer_is_unknown_not_unavailable(): void
    {
        $report = MediaWorkerCapabilityReport::unreachable();

        $this->assertTrue($report->isConfigured());
        $this->assertFalse($report->isAvailable());

        foreach (MediaWorkerCapabilityReport::knownDomains() as $domain) {
            $this->assertSame(MediaWorkerCapabilityReport::TIER_UNKNOWN, $report->tier($domain));
        }
    }

    public function test_a_worker_that_declares_everything_serves_every_domain(): void
    {
        $report = MediaWorkerCapabilityReport::fromProbe(true, true, '3.4.0', $this->fullMatrix());

        $this->assertTrue($report->isAvailable());
        $this->assertSame('3.4.0', $report->version());

        foreach ($report->domains() as $domain => $tier) {
            $this->assertSame(MediaWorkerCapabilityReport::TIER_WORKER, $tier, "{$domain} should be worker-served.");
        }
    }

    public function test_a_worker_without_ffmpeg_names_video_rather_than_failing_later(): void
    {
        $report = MediaWorkerCapabilityReport::fromProbe(
            true,
            true,
            null,
            $this->fullMatrix(array('video_processing' => false))
        );

        $this->assertSame(MediaWorkerCapabilityReport::TIER_UNAVAILABLE, $report->tier('video'));
        $this->assertSame(array('video_processing'), $report->missing('video'));

        // Everything else still works: one missing binary is not a dead worker.
        $this->assertSame(MediaWorkerCapabilityReport::TIER_WORKER, $report->tier('documents'));
        $this->assertSame(MediaWorkerCapabilityReport::TIER_WORKER, $report->tier('image'));
    }

    public function test_office_needs_both_writers(): void
    {
        $report = MediaWorkerCapabilityReport::fromProbe(
            true,
            true,
            null,
            $this->fullMatrix(array('document_excel' => false))
        );

        $this->assertSame(MediaWorkerCapabilityReport::TIER_UNAVAILABLE, $report->tier('office'));
        $this->assertSame(array('document_excel'), $report->missing('office'));
    }

    public function test_an_undeclared_domain_is_unknown_not_worker(): void
    {
        $report = MediaWorkerCapabilityReport::fromProbe(true, true, null, $this->fullMatrix());

        $this->assertSame(MediaWorkerCapabilityReport::TIER_UNKNOWN, $report->tier('quantum'));
        $this->assertSame(array(), $report->missing('quantum'));
    }

    public function test_absence_is_not_a_claim_of_support(): void
    {
        $report = MediaWorkerCapabilityReport::fromProbe(true, true, null, array('pdf_generation' => true));

        $this->assertTrue($report->supports('pdf_generation'));
        $this->assertFalse($report->supports('ffmpeg_that_nobody_declared'));
    }

    public function test_a_matrix_with_odd_values_is_normalised(): void
    {
        $report = MediaWorkerCapabilityReport::fromProbe(
            true,
            true,
            null,
            array(
                'pdf_generation' => 1,
                'video_processing' => 'yes',
                'document_ocr' => 0,
                '' => true,
            )
        );

        $this->assertSame(
            array(
                'pdf_generation' => true,
                'video_processing' => true,
                'document_ocr' => false,
            ),
            $report->capabilities(),
            'Keys are strings, values are booleans, and an empty key is dropped.'
        );
    }

    public function test_the_serialisable_shape_carries_the_tiers_and_the_gaps(): void
    {
        $report = MediaWorkerCapabilityReport::fromProbe(
            true,
            true,
            '3.4.0',
            $this->fullMatrix(array('document_ocr' => false))
        );

        $data = $report->toArray();

        $this->assertSame(true, $data['configured']);
        $this->assertSame(true, $data['available']);
        $this->assertSame('3.4.0', $data['version']);
        $this->assertSame(MediaWorkerCapabilityReport::TIER_UNAVAILABLE, $data['domains']['ocr']);
        $this->assertSame(array('ocr' => array('document_ocr')), $data['missing']);
        $this->assertArrayHasKey('capabilities', $data);
    }

    public function test_the_domain_list_is_declared_data(): void
    {
        $this->assertSame(
            array('documents', 'office', 'ocr', 'image', 'video', 'email', 'data', 'browser', 'crawl', 'social'),
            MediaWorkerCapabilityReport::knownDomains()
        );
    }
}
