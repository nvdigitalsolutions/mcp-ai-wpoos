<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\VideoCompositionInterface;
use Nvoos\Core\Tool\AddWatermarkToVideoTool;
use Nvoos\Core\Tool\CreateVideoFromImagesTool;
use Nvoos\Core\Tool\MergeVideosTool;
use Nvoos\Laravel\Adapter\MediaWorkerVideoComposition;
use RuntimeException;
use Tests\TestCase;

/**
 * Two inputs, one endpoint — Proposal 164 §164.19.
 *
 * `/api/video/compose` takes an ordered `files` array because a watermark needs
 * the video *and* the mark. These tests pin the three things that go wrong when
 * that is done carelessly:
 *
 *  - the order is carried in the field name, not arrival order (a swapped pair
 *    overlays the video onto the mark);
 *  - the mark's pixel size comes from the video's width, so the route probes
 *    before it builds the graph;
 *  - the answered filename is re-validated before it becomes a URL —
 *    `MediaWorkerRemoteName` is the rule both video adapters share.
 *
 * The graph itself is proven against real ffmpeg separately; these are the
 * contract assertions.
 */
final class VideoCompositionAdapterTest extends TestCase
{
    private const CAPABILITIES = array(
        'video_processing' => true,
    );

    private const STUB_BYTES = 'STUB-COMPOSED-BYTES';

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/oos-compose-'.bin2hex(random_bytes(5));

        mkdir($this->workDir, 0775, true);

        file_put_contents($this->video(), 'fake mp4 bytes');
        file_put_contents($this->mark(), 'fake png bytes');

        config(array(
            'oos.media_worker.url' => 'http://worker.test',
            'oos.media_worker.output_dir' => $this->workDir.'/out',
        ));

        Cache::flush();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/out/*') ?: array() as $file) {
            @unlink($file);
        }

        @unlink($this->video());
        @unlink($this->mark());
        @rmdir($this->workDir.'/out');
        @rmdir($this->workDir);

        parent::tearDown();
    }

    // ── Contract ─────────────────────────────────────────────────────────────

    public function test_the_contract_resolves_to_the_composition_adapter(): void
    {
        $adapter = $this->app->make(VideoCompositionInterface::class);

        $this->assertInstanceOf(MediaWorkerVideoComposition::class, $adapter);
        $this->assertTrue($adapter->isAvailable());
        $this->assertSame(array('watermark', 'merge', 'images'), $adapter::operations());
    }

    // ── The two-input contract ───────────────────────────────────────────────

    public function test_the_video_and_the_mark_are_sent_as_ordered_parts(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(AddWatermarkToVideoTool::class)->execute(array(
            'video_url' => $this->video(),
            'watermark_url' => $this->mark(),
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('/api/video/compose', $this->path($captured));

        $names = $this->filePartNames($captured);

        $this->assertSame(array('files[0]', 'files[1]'), $names, 'the order is carried in the field name');
        $this->assertSame('watermark', $this->field($captured, 'operation'));
    }

    public function test_the_mark_is_sized_from_the_video_width(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $this->app->make(AddWatermarkToVideoTool::class)->execute(array(
            'video_url' => $this->video(),
            'watermark_url' => $this->mark(),
            'scale' => 0.25,
            'save_to_media' => false,
        ));

        // The fraction travels; the route resolves it against the probed width.
        $this->assertSame('0.25', $this->field($captured, 'scale'));
    }

    public function test_the_position_and_opacity_are_forwarded(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $this->app->make(AddWatermarkToVideoTool::class)->execute(array(
            'video_url' => $this->video(),
            'watermark_url' => $this->mark(),
            'position' => 'top-left',
            'opacity' => 0.4,
            'save_to_media' => false,
        ));

        $this->assertSame('top-left', $this->field($captured, 'position'));
        $this->assertSame('0.4', $this->field($captured, 'opacity'));
    }

    public function test_the_default_placement_is_the_bottom_right_corner(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $this->app->make(AddWatermarkToVideoTool::class)->execute(array(
            'video_url' => $this->video(),
            'watermark_url' => $this->mark(),
            'save_to_media' => false,
        ));

        $this->assertSame('bottom-right', $this->field($captured, 'position'));
        $this->assertSame('0.7', $this->field($captured, 'opacity'));
    }

    // ── Refusals ─────────────────────────────────────────────────────────────

    public function test_an_unknown_position_is_refused_before_a_request(): void
    {
        Http::fake();

        $result = $this->app->make(AddWatermarkToVideoTool::class)->execute(array(
            'video_url' => $this->video(),
            'watermark_url' => $this->mark(),
            'position' => 'middle',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('Unknown position: middle', $result->getMessage());
        $this->assertStringContainsString('bottom-right', $result->getMessage());

        Http::assertNothingSent();
    }

    public function test_a_missing_mark_is_refused_rather_than_defaulted(): void
    {
        Http::fake();

        $result = $this->app->make(AddWatermarkToVideoTool::class)->execute(array(
            'video_url' => $this->video(),
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('watermark_url or watermark_file_id is required', $result->getMessage());

        Http::assertNothingSent();
    }

    public function test_a_host_without_ffmpeg_refuses_by_name(): void
    {
        $captured = null;
        $downloads = array();

        $this->fakeWorker($captured, array('video_processing' => false));

        $result = $this->app->make(AddWatermarkToVideoTool::class)->execute(array(
            'video_url' => $this->video(),
            'watermark_url' => $this->mark(),
            'save_to_media' => false,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('ffmpeg', $result->getMessage());
    }

    public function test_an_output_name_the_host_will_not_fetch_is_refused(): void
    {
        $json = array('Content-Type' => 'application/json');

        $health = Http::response(array('status' => 'ok', 'capabilities' => self::CAPABILITIES), 200, $json);
        $compose = Http::response(array(
            'success' => true,
            'operation' => 'watermark',
            'output_size' => 100,
            // A worker must not be able to steer this host's URL path.
            'output_file' => '../../etc/passwd',
        ), 200, $json);

        Http::fake(function (ClientRequest $request) use ($health, $compose) {
            return str_contains($request->url(), '/api/health') ? $health : $compose;
        });

        $result = $this->app->make(AddWatermarkToVideoTool::class)->execute(array(
            'video_url' => $this->video(),
            'watermark_url' => $this->mark(),
            'save_to_media' => false,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('will not fetch', $result->getMessage());
    }

    // ── The size delta ───────────────────────────────────────────────────────

    public function test_the_reported_saving_is_measured_against_the_base_input(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(AddWatermarkToVideoTool::class)->execute(array(
            'video_url' => $this->video(),
            'watermark_url' => $this->mark(),
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertSame(\strlen('fake mp4 bytes'), $result['data']['original_bytes']);
        $this->assertSame(\strlen(self::STUB_BYTES), $result['data']['bytes']);
    }

    // ── Merge ────────────────────────────────────────────────────────────────

    public function test_a_merge_sends_the_clips_in_order(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(MergeVideosTool::class)->execute(array(
            'video_urls' => array($this->video(), $this->mark()),
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('merge', $this->field($captured, 'operation'));
        $this->assertSame(array('files[0]', 'files[1]'), $this->filePartNames($captured));

        // Nothing is re-encoded, and the result says so rather than leaving the
        // caller to guess whether the quality changed.
        $this->assertFalse($result['data']['reencoded']);
        $this->assertSame(2, $result['data']['source_count']);
    }

    public function test_a_merge_does_not_carry_watermark_fields(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $this->app->make(MergeVideosTool::class)->execute(array(
            'video_urls' => array($this->video(), $this->mark()),
            'save_to_media' => false,
        ));

        $this->assertNull($this->field($captured, 'position'), 'a merge has no position');
        $this->assertNull($this->field($captured, 'opacity'));
    }

    public function test_a_single_clip_is_not_a_merge(): void
    {
        Http::fake();

        $result = $this->app->make(MergeVideosTool::class)->execute(array(
            'video_urls' => array($this->video()),
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('at least two videos', $result->getMessage());

        Http::assertNothingSent();
    }

    public function test_a_fade_transition_is_refused_with_the_reason(): void
    {
        Http::fake();

        $result = $this->app->make(MergeVideosTool::class)->execute(array(
            'video_urls' => array($this->video(), $this->mark()),
            'transition' => 'fade',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('not implemented', $result->getMessage());
        $this->assertStringContainsString('xfade', $result->getMessage());

        Http::assertNothingSent();
    }

    public function test_a_worker_refusal_for_mismatched_clips_is_a_caller_error(): void
    {
        $this->fakeWorkerRefusing('clip 2 differs from clip 1: frame rate (25.00 vs 30.00)');

        $result = $this->app->make(MergeVideosTool::class)->execute(array(
            'video_urls' => array($this->video(), $this->mark()),
            'save_to_media' => false,
        ));

        // A 400 from the route is the caller's to fix, so it must not be
        // reported as a broken worker (500).
        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('clip 2 differs from clip 1', $result->getMessage());
    }

    // ── Slideshow ────────────────────────────────────────────────────────────

    public function test_a_slideshow_sends_the_frame_and_the_per_image_duration(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(CreateVideoFromImagesTool::class)->execute(array(
            'image_urls' => array($this->video(), $this->mark()),
            'resolution' => '720p',
            'duration_per_image' => 2.5,
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('images', $this->field($captured, 'operation'));
        $this->assertSame('1280', $this->field($captured, 'width'));
        $this->assertSame('720', $this->field($captured, 'height'));
        $this->assertSame('2.5', $this->field($captured, 'duration'));
        $this->assertSame('720p', $result['data']['resolution']);
    }

    public function test_one_image_is_enough_for_a_slideshow(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(CreateVideoFromImagesTool::class)->execute(array(
            'image_urls' => array($this->video()),
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], 'a single still is a valid one-frame slideshow');
        $this->assertSame(array('files[0]'), $this->filePartNames($captured));
        $this->assertSame('1920', $this->field($captured, 'width'), '1080p is the default frame');
    }

    public function test_a_slideshow_reports_the_total_duration(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(CreateVideoFromImagesTool::class)->execute(array(
            'image_urls' => array($this->video(), $this->mark()),
            'duration_per_image' => 3,
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertSame(2, $result['data']['source_count']);
        $this->assertSame(6.0, $result['data']['duration'], 'two images at three seconds each');
    }

    public function test_a_slideshow_fade_is_refused_with_the_reason(): void
    {
        Http::fake();

        $result = $this->app->make(CreateVideoFromImagesTool::class)->execute(array(
            'image_urls' => array($this->video()),
            'transition' => 'fade',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('not implemented', $result->getMessage());
        $this->assertStringContainsString('xfade', $result->getMessage());

        Http::assertNothingSent();
    }

    public function test_an_unknown_slideshow_resolution_is_refused_with_the_supported_ones(): void
    {
        Http::fake();

        $result = $this->app->make(CreateVideoFromImagesTool::class)->execute(array(
            'image_urls' => array($this->video()),
            'resolution' => '8k',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('Unknown resolution: 8k', $result->getMessage());
        $this->assertStringContainsString('1080p', $result->getMessage());

        Http::assertNothingSent();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function video(): string
    {
        return $this->workDir.'/source.mp4';
    }

    private function mark(): string
    {
        return $this->workDir.'/mark.png';
    }

    /**
     * Fake health, `/compose` and the download route.
     *
     * @param array{url: string, data: mixed}|null $captured Receives the compose request.
     * @param array<string, bool>                  $capabilities
     */
    private function fakeWorker(?array &$captured, array $capabilities = self::CAPABILITIES): void
    {
        $captured = null;

        $json = array('Content-Type' => 'application/json');

        $health = Http::response(array('status' => 'ok', 'capabilities' => $capabilities), 200, $json);

        $compose = Http::response(array(
            'success' => true,
            'operation' => 'watermark',
            'position' => 'bottom-right',
            'output_size' => \strlen(self::STUB_BYTES),
            'output_file' => 'processed_1700000000.mp4',
            'source_count' => 2,
            'reencoded' => false,
            'duration' => 6.0,
        ), 200, $json);

        $bytes = Http::response(self::STUB_BYTES, 200, array('Content-Type' => 'video/mp4'));

        Http::fake(function (ClientRequest $request) use (&$captured, $health, $compose, $bytes) {
            $url = $request->url();

            if (str_contains($url, '/api/health')) {
                return $health;
            }

            if (str_contains($url, '/api/video/download/')) {
                return $bytes;
            }

            $captured = array('url' => $url, 'data' => $request->data());

            return $compose;
        });
    }

    /**
     * A worker that refuses the request, which the route does with a 400.
     */
    private function fakeWorkerRefusing(string $error, int $status = 400): void
    {
        $json = array('Content-Type' => 'application/json');

        $health = Http::response(array('status' => 'ok', 'capabilities' => self::CAPABILITIES), 200, $json);
        $refusal = Http::response(array('error' => $error), $status, $json);

        Http::fake(static function (ClientRequest $request) use ($health, $refusal) {
            return str_contains($request->url(), '/api/health') ? $health : $refusal;
        });
    }

    /**
     * @param array{url: string, data: mixed}|null $captured
     */
    private function path(?array $captured): string
    {
        $path = parse_url((string) ($captured['url'] ?? ''), PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    /**
     * @param array{url: string, data: mixed}|null $captured
     */
    private function field(?array $captured, string $name): ?string
    {
        foreach ((array) ($captured['data'] ?? array()) as $value) {
            if (is_array($value) && array_key_exists('name', $value) && (string) $value['name'] === $name) {
                $contents = $value['contents'] ?? null;

                return is_scalar($contents) ? (string) $contents : null;
            }
        }

        return null;
    }

    /**
     * The multipart parts that carry files, in the order they were sent.
     *
     * @param array{url: string, data: mixed}|null $captured
     *
     * @return list<string>
     */
    private function filePartNames(?array $captured): array
    {
        $names = array();

        foreach ((array) ($captured['data'] ?? array()) as $value) {
            if (! is_array($value) || ! isset($value['name'])) {
                continue;
            }

            $name = (string) $value['name'];

            if (str_starts_with($name, 'files[')) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
