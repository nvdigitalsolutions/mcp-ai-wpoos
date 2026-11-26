<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\ValueObject\MediaWorkerCapabilityReport;
use Nvoos\Laravel\Adapter\MediaWorkerCapabilityProbe;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The worker capability probe — Proposal 164 §164.4.2.
 *
 * The probe answers one question (`what can this worker do?`) for every reader,
 * so the properties worth pinning are the ones that would make two readers
 * disagree:
 *
 *  - a configured worker that did not answer is `unknown`, not `unavailable`;
 *  - one probe serves every caller inside the TTL;
 *  - a probe never fails the page it is reported on.
 *
 * The matrix fixtures use the worker's own capability keys
 * (`addons/media-worker/src/index.js`), so a rename on the worker fails here
 * rather than silently turning a domain into `unavailable`.
 */
final class MediaWorkerCapabilityProbeTest extends TestCase
{
    use RefreshDatabase;
    /**
     * The keys the declared domains read, all true.
     *
     * @return array<string, bool>
     */
    private function fullMatrix(): array
    {
        return array(
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
            // The `social` domain reads the two read-side keys, while
            // `social_publishing` reads the write-side one. Declaring only the
            // write key leaves `social` unavailable — which is what happened
            // when the domain was split in two.
            'social_accounts' => true,
            'social_composition' => true,
            'social_publishing' => true,
        );
    }

    private function configureWorker(): void
    {
        config(array(
            'oos.media_worker.url' => 'http://worker.test',
            'oos.media_worker.token' => 'site-token',
        ));
    }

    public function test_an_unconfigured_host_reports_unconfigured_without_a_request(): void
    {
        config(array('oos.media_worker.url' => ''));
        Http::fake();

        $report = $this->probe()->report();

        $this->assertFalse($report->isConfigured());
        $this->assertSame(MediaWorkerCapabilityReport::TIER_UNAVAILABLE, $report->tier('documents'));

        Http::assertNothingSent();
    }

    public function test_a_health_matrix_becomes_domain_tiers(): void
    {
        $this->configureWorker();

        Http::fake(array(
            'worker.test/api/health' => Http::response(array('status' => 'ok', 'version' => '3.4.0', 'uptime' => 12.5)),
            'worker.test/api/health/full' => Http::response(array(
                'status' => 'ok',
                'version' => '3.4.0',
                'capabilities' => $this->fullMatrix(),
            )),
        ));

        $report = $this->probe()->report();

        $this->assertTrue($report->isAvailable());
        $this->assertSame('3.4.0', $report->version());

        foreach ($report->domains() as $domain => $tier) {
            $this->assertSame(MediaWorkerCapabilityReport::TIER_WORKER, $tier, "{$domain} should be worker-served.");
        }

        // The auth contract is the worker's, shared with WordPress.
        Http::assertSent(static fn ($request): bool => 'site-token' === ($request->header('X-Site-Token')[0] ?? null));
    }

    public function test_a_worker_without_ffmpeg_reports_video_unavailable_and_says_why(): void
    {
        $this->configureWorker();

        Http::fake(array(
            'worker.test/api/health' => Http::response(array('status' => 'ok', 'version' => '3.4.0')),
            'worker.test/api/health/full' => Http::response(array(
                'status' => 'ok',
                'capabilities' => array('video_processing' => false) + $this->fullMatrix(),
            )),
        ));

        $report = $this->probe()->report();

        $this->assertSame(MediaWorkerCapabilityReport::TIER_WORKER, $report->tier('documents'));
        $this->assertSame(MediaWorkerCapabilityReport::TIER_UNAVAILABLE, $report->tier('video'));
        $this->assertSame(array('video_processing'), $report->missing('video'));
    }

    public function test_a_worker_that_answers_an_error_is_unknown_not_unavailable(): void
    {
        $this->configureWorker();

        Http::fake(array(
            'worker.test/api/health' => Http::response(array('status' => 'ok')),
            'worker.test/api/health/full' => Http::response(array('error' => 'Internal server error'), 500),
        ));

        $report = $this->probe()->report();

        $this->assertTrue($report->isConfigured());
        $this->assertFalse($report->isAvailable());
        $this->assertSame(MediaWorkerCapabilityReport::TIER_UNKNOWN, $report->tier('documents'));
    }

    public function test_an_unreachable_worker_is_unknown_and_does_not_throw(): void
    {
        $this->configureWorker();

        Http::fake(static function (): void {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        });

        $report = $this->probe()->report();

        $this->assertSame(MediaWorkerCapabilityReport::TIER_UNKNOWN, $report->tier('documents'));
    }

    public function test_one_probe_serves_every_reader_inside_the_ttl(): void
    {
        $this->configureWorker();

        Http::fake(array(
            'worker.test/api/health' => Http::response(array('status' => 'ok', 'version' => '3.4.0')),
            'worker.test/api/health/full' => Http::response(array('capabilities' => $this->fullMatrix())),
        ));

        $probe = $this->probe();

        $probe->report();
        $probe->report();
        $probe->toArray();

        // One `health` plus one `health/full`, then cache hits.
        Http::assertSentCount(2);
    }

    public function test_a_fresh_probe_bypasses_the_cache(): void
    {
        $this->configureWorker();

        // A mutable matrix, because `Http::fake()` merges stubs: calling it a
        // second time to "change the answer" would leave the first stub
        // matching. The closure reads the current matrix on every request.
        $matrix = $this->fullMatrix();

        Http::fake(static function ($request) use (&$matrix) {
            if (str_ends_with($request->url(), '/api/health/full')) {
                return Http::response(array('capabilities' => $matrix));
            }

            return Http::response(array('status' => 'ok', 'version' => '3.4.0'));
        });

        $probe = $this->probe();

        $this->assertSame(MediaWorkerCapabilityReport::TIER_WORKER, $probe->report()->tier('video'));

        // The worker loses ffmpeg between probes — the refresh must see it.
        $matrix['video_processing'] = false;

        $this->assertSame(
            MediaWorkerCapabilityReport::TIER_UNAVAILABLE,
            $probe->report(true)->tier('video')
        );
    }

    public function test_diagnostics_reports_the_domain_tiers(): void
    {
        $this->configureWorker();
        $this->actingAsOperator();

        Http::fake(array(
            'worker.test/api/health' => Http::response(array('status' => 'ok', 'version' => '3.4.0')),
            'worker.test/api/health/full' => Http::response(array(
                'capabilities' => array('document_ocr' => false) + $this->fullMatrix(),
            )),
        ));

        $response = $this->getJson('/api/v1/diagnostics/overview')->assertOk();

        $response
            ->assertJsonPath('data.media_worker.configured', true)
            ->assertJsonPath('data.media_worker.available', true)
            ->assertJsonPath('data.media_worker.domains.documents', MediaWorkerCapabilityReport::TIER_WORKER)
            ->assertJsonPath('data.media_worker.domains.ocr', MediaWorkerCapabilityReport::TIER_UNAVAILABLE)
            ->assertJsonPath('data.media_worker.missing.ocr.0', 'document_ocr');
    }

    public function test_diagnostics_says_not_configured_rather_than_failing(): void
    {
        config(array('oos.media_worker.url' => ''));
        Http::fake();
        $this->actingAsOperator();

        $this->getJson('/api/v1/diagnostics/overview')
            ->assertOk()
            ->assertJsonPath('data.media_worker.configured', false)
            ->assertJsonPath('data.media_worker.available', false)
            ->assertJsonPath('data.media_worker.domains.documents', MediaWorkerCapabilityReport::TIER_UNAVAILABLE);
    }

    private function probe(): MediaWorkerCapabilityProbe
    {
        return $this->app->make(MediaWorkerCapabilityProbe::class);
    }

    /**
     * The diagnostics route is operator-only; Sanctum only starts a session for
     * requests that look first-party, so the referer matters.
     */
    private function actingAsOperator(): void
    {
        config(array('sanctum.stateful' => array('localhost', '127.0.0.1')));
        $this->withHeader('Referer', 'http://localhost');

        Sanctum::actingAs(User::factory()->admin()->create());
    }
}
