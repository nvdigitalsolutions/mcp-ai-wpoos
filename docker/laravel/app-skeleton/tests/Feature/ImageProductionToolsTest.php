<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\ImageProductionInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\ApplyArtisticStyleTool;
use Nvoos\Core\Tool\ColorizeImageTool;
use Nvoos\Core\Tool\CompressImageTool;
use Nvoos\Core\Tool\EnhanceImageQualityTool;
use Nvoos\Core\Tool\GenerateResponsiveImagesTool;
use Nvoos\Core\Tool\GetImagesWithoutAltTool;
use Nvoos\Core\Tool\GetUnoptimisedImagesTool;
use Nvoos\Core\Tool\OptimiseImagesBatchTool;
use Nvoos\Core\Tool\OptimizeForWebTool;
use Nvoos\Core\Tool\UpscaleImageAiTool;
use Nvoos\Laravel\Adapter\MediaWorkerImageProduction;
use RuntimeException;
use Tests\TestCase;

/**
 * The image pipeline family reaches the media worker — Proposal 164 §164.6.
 *
 * Ten rows moved from "the WordPress toolkit has it" to "this runtime serves it"
 * by mapping each tool onto a worker route that already existed, plus three new
 * route arguments (`lossless`, `progressive`, and `position` on the transform
 * resize op).
 *
 * These tests pin the four things that can silently drift:
 *
 *  - **the capability gate** — a worker that does not declare a route must
 *    produce a named refusal, not a 404 mid-run;
 *  - **the request shape** — `method: lossless` has to reach the engine as
 *    `lossless: true`, and `color_mode: vibrant` as a prompt qualifier, or the
 *    tool becomes a lie that still returns success;
 *  - **the refusals** — JPEG + lossless, and a missing provider key, are refused
 *    rather than approximated;
 *  - **the audit** — format and size are separate reasons, so a caller can tell
 *    "wrong encoding" from "just too big".
 *
 * The worker itself is faked; its own bounds are exercised by
 * `addons/media-worker/src/routes/image.test.js`.
 */
final class ImageProductionToolsTest extends TestCase
{
    /**
     * A 1×1 transparent PNG — enough for the adapter, which never decodes it.
     */
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /**
     * The matrix a current worker publishes.
     */
    private const CAPABILITIES = array(
        'image_optimization' => true,
        'image_enhancement' => true,
        'image_upscaling' => true,
        'image_batch' => true,
        'image_smart_crop' => true,
        'image_batch_encoding' => true,
        'image_editing' => true,
    );

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/oos-imageprod-'.bin2hex(random_bytes(5));

        mkdir($this->workDir, 0775, true);

        file_put_contents($this->source(), base64_decode(self::PNG_B64, true));

        config([
            'oos.media_worker.url' => 'http://worker.test',
            'oos.media_worker.output_dir' => $this->workDir.'/out',
        ]);

        // The capability probe caches its report; a stale matrix from another
        // test would make `supports()` answer for the wrong worker.
        Cache::flush();

        $this->fakeStores(array());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/out/*') ?: array() as $file) {
            @unlink($file);
        }

        @unlink($this->workDir.'/source.png');
        @rmdir($this->workDir.'/out');
        @rmdir($this->workDir);

        parent::tearDown();
    }

    public function test_the_contract_resolves_to_the_worker_adapter(): void
    {
        $adapter = $this->app->make(ImageProductionInterface::class);

        $this->assertInstanceOf(MediaWorkerImageProduction::class, $adapter);
        $this->assertTrue($adapter->isAvailable());
    }

    public function test_supports_reads_the_capability_matrix_not_the_wire(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured);

        $adapter = $this->app->make(ImageProductionInterface::class);

        $this->assertTrue($adapter->supports('optimize'));
        $this->assertTrue($adapter->supports('enhance'));
        $this->assertTrue($adapter->supports('upscale'));
        $this->assertTrue($adapter->supports('batch'));
        $this->assertTrue($adapter->supports('resize_smart'));
        $this->assertTrue($adapter->supports('edit'));

        // An operation nobody declared is not offered.
        $this->assertFalse($adapter->supports('teleport'));
    }

    public function test_a_worker_without_image_editing_refuses_rather_than_styling_locally(): void
    {
        $captured = null;
        $this->fakeWorker(array_merge(self::CAPABILITIES, array('image_editing' => false)), $captured);

        $result = $this->app->make(ColorizeImageTool::class)->execute(array(
            'image_url' => $this->source(),
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('No AI image provider is configured', $result->getMessage());
    }

    public function test_compress_sends_the_encoder_arguments_and_stores_the_output(): void
    {
        $store = $this->fakeStores(array());
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured);

        $result = $this->app->make(CompressImageTool::class)->execute(array(
            'image_url' => $this->source(),
            'quality' => 70,
            'method' => 'standard',
            'format' => 'webp',
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('webp', $this->part($captured, 'format'));
        $this->assertSame('70', $this->part($captured, 'quality'));
        $this->assertSame('/api/image/batch', $this->path($captured));
        $this->assertCount(1, $store->writes);
        $this->assertSame(701, $result['data']['media'][0]['attachment_id']);
    }

    public function test_compress_refuses_lossless_without_an_explicit_format(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured);

        $result = $this->app->make(CompressImageTool::class)->execute(array(
            'image_url' => $this->source(),
            'method' => 'lossless',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('needs an explicit output format', $result->getMessage());
    }

    public function test_compress_refuses_lossless_for_jpeg(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured);

        $result = $this->app->make(CompressImageTool::class)->execute(array(
            'image_url' => $this->source(),
            'method' => 'lossless',
            'format' => 'jpeg',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('JPEG has no lossless mode', $result->getMessage());
    }

    public function test_compress_sends_lossless_as_the_engine_flag(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured);

        $result = $this->app->make(CompressImageTool::class)->execute(array(
            'image_url' => $this->source(),
            'method' => 'lossless',
            'format' => 'webp',
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('true', $this->part($captured, 'lossless'));
        $this->assertSame('lossless', $result['data']['method']);
    }

    public function test_compress_steps_quality_down_to_meet_a_size_target(): void
    {
        // First pass 4000 bytes, second 900 bytes: the 2 KB target is met on the
        // second pass, so the tool must have sent exactly two requests.
        $sizes = array(4000, 900);
        $sent = array();

        $json = array('Content-Type' => 'application/json');
        $health = Http::response(array('status' => 'ok', 'capabilities' => self::CAPABILITIES), 200, $json);
        $first = Http::response($this->batchBody(array(array(4000))), 200, $json);
        $second = Http::response($this->batchBody(array(array(900))), 200, $json);

        Http::fake(function (ClientRequest $request) use ($health, $first, $second, &$sent) {
            $url = $request->url();

            if (! str_contains($url, '/api/image/')) {
                // `/api/health` and `/api/health/full` are the capability probe,
                // not an encode. Counting them would shift every index.
                return $health;
            }

            $sent[] = array('url' => $url, 'parts' => $request->data());

            return 1 === count($sent) ? $first : $second;
        });

        $result = $this->app->make(CompressImageTool::class)->execute(array(
            'image_url' => $this->source(),
            'quality' => 85,
            'max_size_kb' => 2,
            'format' => 'webp',
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertCount(2, $sent);
        $this->assertSame('85', $this->part($sent[0], 'quality'));
        $this->assertSame('80', $this->part($sent[1], 'quality'));
        $this->assertSame(1, $result['data']['passes']);
    }

    public function test_compress_reports_an_unreachable_size_target(): void
    {
        $json = array('Content-Type' => 'application/json');
        $health = Http::response(array('status' => 'ok', 'capabilities' => self::CAPABILITIES), 200, $json);
        $big = Http::response($this->batchBody(array(array(50000))), 200, $json);

        Http::fake(function (ClientRequest $request) use ($health, $big) {
            return str_contains($request->url(), '/api/health/full') ? $health : $big;
        });

        $result = $this->app->make(CompressImageTool::class)->execute(array(
            'image_url' => $this->source(),
            'max_size_kb' => 1,
            'format' => 'webp',
            'save_to_media' => false,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('Could not reach 1 KB', $result->getMessage());
    }

    public function test_optimize_for_web_auto_means_webp_and_caps_the_width(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured);

        $result = $this->app->make(OptimizeForWebTool::class)->execute(array(
            'image_url' => $this->source(),
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('webp', $this->part($captured, 'format'));
        $this->assertSame('2560', $this->part($captured, 'width'));
        $this->assertSame('stripped', $result['data']['metadata']);
    }

    public function test_optimize_for_web_sends_progressive_only_for_jpeg(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured);

        $result = $this->app->make(OptimizeForWebTool::class)->execute(array(
            'image_url' => $this->source(),
            'target_format' => 'jpg',
            'progressive' => false,
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('false', $this->part($captured, 'progressive'));
        $this->assertFalse($result['data']['progressive']);
    }

    public function test_enhance_maps_auto_onto_three_engine_operations(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured);

        $result = $this->app->make(EnhanceImageQualityTool::class)->execute(array(
            'image_url' => $this->source(),
            'strength' => 0.5,
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('/api/image/enhance', $this->path($captured));
        $this->assertSame('0.5', $this->part($captured, 'strength'));
        $this->assertSame('1.00', $this->part($captured, 'sharpen'));
        $this->assertSame('1.6', $this->part($captured, 'contrast'));
        $this->assertSame('1.5', $this->part($captured, 'saturation'));
        $this->assertSame(array('sharpness', 'contrast', 'color'), $result['data']['requested']);
    }

    public function test_enhance_honours_an_explicit_list_and_floors_zero_strength(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured);

        $result = $this->app->make(EnhanceImageQualityTool::class)->execute(array(
            'image_url' => $this->source(),
            'enhancements' => array('denoise'),
            'strength' => 0,
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('true', $this->part($captured, 'denoise'));
        $this->assertNull($this->part($captured, 'contrast'), 'an unrequested enhancement is not sent');
        $this->assertSame('false', $this->part($captured, 'sharpen'), 'sharpening defaults to off');
        $this->assertSame(0.05, $result['data']['strength']);
    }

    public function test_upscale_reports_the_kernel_it_actually_used(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured);

        $result = $this->app->make(UpscaleImageAiTool::class)->execute(array(
            'image_url' => $this->source(),
            'scale_factor' => 4,
            'kernel' => 'cubic',
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('/api/image/upscale', $this->path($captured));
        $this->assertSame('4', $this->part($captured, 'factor'));
        $this->assertSame('cubic', $this->part($captured, 'kernel'));
        $this->assertSame(4, $result['data']['factor']);
        $this->assertSame('lanczos3', $result['data']['method']);
    }

    public function test_upscale_refuses_a_factor_outside_the_engine_allowlist(): void
    {
        $result = $this->app->make(UpscaleImageAiTool::class)->execute(array(
            'image_url' => $this->source(),
            'scale_factor' => 3,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('must be 2, 4 or 8', $result->getMessage());
    }

    public function test_a_batch_dry_run_is_the_default_and_sends_nothing(): void
    {
        Http::fake();

        $result = $this->app->make(OptimiseImagesBatchTool::class)->execute(array(
            'file_ids' => array(5, 6),
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame(2, $result['data']['would_process']);
        $this->assertSame('webp', $result['data']['target_format']);
        Http::assertNothingSent();
    }

    public function test_a_batch_apply_sends_every_file_in_one_call(): void
    {
        $store = $this->fakeStores(array());
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured, 2);

        $result = $this->app->make(OptimiseImagesBatchTool::class)->execute(array(
            'file_ids' => array(5, 6),
            'dry_run' => false,
            'quality' => 80,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['dry_run']);
        $this->assertSame(2, $result['data']['processed']);
        $this->assertSame('80', $this->part($captured, 'quality'));
        $this->assertSame('webp', $this->part($captured, 'format'));
        $this->assertCount(2, $store->writes);
        $this->assertCount(2, $result['data']['media']);
    }

    public function test_a_batch_names_a_corrupt_file_beside_the_results(): void
    {
        $json = array('Content-Type' => 'application/json');
        $health = Http::response(array('status' => 'ok', 'capabilities' => self::CAPABILITIES), 200, $json);
        $partial = Http::response(array(
            'success' => true,
            'processed' => 1,
            'total_bytes_saved' => 100,
            'results' => array($this->batchRow(0)),
            'errors' => array(array('filename' => 'broken.png', 'error' => 'Input buffer contains unsupported image format')),
        ), 200, $json);

        Http::fake(function (ClientRequest $request) use ($health, $partial) {
            return str_contains($request->url(), '/api/health/full') ? $health : $partial;
        });

        $result = $this->app->make(OptimiseImagesBatchTool::class)->execute(array(
            'file_ids' => array(5, 6),
            'dry_run' => false,
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['processed']);
        $this->assertSame(1, $result['data']['failed']);
        $this->assertSame('broken.png', $result['data']['errors'][0]['filename']);
    }

    public function test_responsive_images_skip_widths_above_the_source(): void
    {
        $encoded = 0;

        $json = array('Content-Type' => 'application/json');
        $health = Http::response(array('status' => 'ok', 'capabilities' => self::CAPABILITIES), 200, $json);
        $info = Http::response(array(
            'success' => true,
            'width' => 800,
            'height' => 600,
            'format' => 'png',
            'mime_type' => 'image/png',
            'bytes' => 1234,
        ), 200, $json);
        $variant = Http::response($this->batchBody(array(array(500))), 200, $json);

        Http::fake(function (ClientRequest $request) use ($health, $info, $variant, &$encoded) {
            $url = $request->url();

            if (str_contains($url, '/api/image/info')) {
                return $info;
            }

            if (! str_contains($url, '/api/image/')) {
                // The liveness and capability probes are not encodes.
                return $health;
            }

            ++$encoded;

            return $variant;
        });

        $result = $this->app->make(GenerateResponsiveImagesTool::class)->execute(array(
            'image_url' => $this->source(),
            'sizes' => array(320, 640, 1200),
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame(800, $result['data']['source_width']);

        // 320 and 640 are encoded; 1200 is skipped as an upscale.
        $this->assertSame(2, $encoded);
        $this->assertCount(2, $result['data']['variants']);
        $this->assertCount(1, $result['data']['skipped']);
        $this->assertSame(1200, $result['data']['skipped'][0]['width']);
        $this->assertStringContainsString('upscale', $result['data']['skipped'][0]['reason']);
    }

    public function test_colorize_turns_a_mode_into_a_prompt_qualifier(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured);

        $result = $this->app->make(ColorizeImageTool::class)->execute(array(
            'image_url' => $this->source(),
            'color_mode' => 'vibrant',
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('/api/image/edit', $this->path($captured));
        $this->assertSame('colorize', $this->part($captured, 'operation'));
        $this->assertStringContainsString('vivid', (string) $this->part($captured, 'prompt'));
        $this->assertSame('gemini', $result['data']['provider']);
    }

    public function test_colorize_auto_sends_no_prompt_so_the_provider_default_applies(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured);

        $result = $this->app->make(ColorizeImageTool::class)->execute(array(
            'image_url' => $this->source(),
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertNull($this->part($captured, 'prompt'));
        $this->assertSame('auto', $result['data']['mode']);
    }

    public function test_artistic_style_maps_strength_onto_an_intensity_phrase(): void
    {
        $captured = null;
        $this->fakeWorker(self::CAPABILITIES, $captured);

        $result = $this->app->make(ApplyArtisticStyleTool::class)->execute(array(
            'image_url' => $this->source(),
            'style' => 'ukiyo-e',
            'strength' => 0.9,
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('style_transfer', $this->part($captured, 'operation'));
        $this->assertSame('ukiyo-e', $this->part($captured, 'style'));
        $this->assertStringContainsString('strongly', (string) $this->part($captured, 'prompt'));
        $this->assertSame('strong', $result['data']['intensity']);
    }

    public function test_artistic_style_refuses_an_unknown_preset(): void
    {
        $result = $this->app->make(ApplyArtisticStyleTool::class)->execute(array(
            'image_url' => $this->source(),
            'style' => 'banksy',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('Unknown style preset', $result->getMessage());
    }

    public function test_the_unoptimised_audit_names_format_and_size_reasons_separately(): void
    {
        $this->fakeStores(array(
            array('id' => 1, 'filename' => 'hero.jpg', 'mime' => 'image/jpeg', 'bytes' => 100000),
            array('id' => 2, 'filename' => 'thumb.webp', 'mime' => 'image/webp', 'bytes' => 4000),
            array('id' => 3, 'filename' => 'banner.webp', 'mime' => 'image/webp', 'bytes' => 900000),
        ));

        $result = $this->app->make(GetUnoptimisedImagesTool::class)->execute(array(
            'min_bytes' => 500000,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        $byId = array_column($result['data']['images'], null, 'file_id');

        // JPEG: format reason only. Large WebP: size reason only.
        $this->assertCount(1, $byId[1]['reasons']);
        $this->assertStringContainsString('not WebP or AVIF', $byId[1]['reasons'][0]);

        $this->assertCount(1, $byId[3]['reasons']);
        $this->assertStringContainsString('byte floor', $byId[3]['reasons'][0]);

        // The small WebP is already fine and must not be listed.
        $this->assertArrayNotHasKey(2, $byId);
        $this->assertSame(3, $result['data']['scanned']);
        $this->assertSame('optimise_images_batch', $result['data']['next_step']);
    }

    public function test_the_alt_audit_lists_only_images_with_blank_alt_text(): void
    {
        $this->fakeStores(array(
            array('id' => 1, 'filename' => 'hero.jpg', 'mime' => 'image/jpeg', 'bytes' => 100, 'alt' => 'A red boat'),
            array('id' => 2, 'filename' => 'banner.png', 'mime' => 'image/png', 'bytes' => 100),
            array('id' => 3, 'filename' => 'blank.png', 'mime' => 'image/png', 'bytes' => 100, 'alt' => '   '),
        ));

        $result = $this->app->make(GetImagesWithoutAltTool::class)->execute(array());

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame(array(2, 3), array_column($result['data']['images'], 'file_id'));
        $this->assertSame('generate_image_alt_text', $result['data']['next_step']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function source(): string
    {
        return $this->workDir.'/source.png';
    }

    /**
     * Fake the worker's health matrix plus every image route the tools use.
     *
     * The responses are built **before** `Http::fake()` on purpose. A response
     * constructed inside the stub callback is re-entering the factory while a
     * request is being dispatched, and Laravel's stub recorder does not survive
     * that — the caller silently receives an empty body, which reads as "the
     * worker declared nothing".
     *
     * `$captured` is by reference: the adapter closes the source stream as soon
     * as the request is built, so the parts have to be copied inside the stub.
     *
     * @param array<string, bool>                                                              $capabilities
     * @param array{url: string, parts: array<int, array{name: string, contents: string}>}|null $captured
     */
    private function fakeWorker(array $capabilities, ?array &$captured, int $batchSize = 1): void
    {
        $captured = null;

        $json = array('Content-Type' => 'application/json');

        $health = Http::response(array(
            'status' => 'ok',
            'version' => '3.4.0',
            'capabilities' => $capabilities,
        ), 200, $json);

        $batch = Http::response($this->batchBody(array_fill(0, $batchSize, array(500))), 200, $json);

        $enhance = Http::response(array(
            'success' => true,
            'original_size' => 1000,
            'optimized_size' => 900,
            'savings_bytes' => 100,
            'savings_percent' => '10.0%',
            'format' => 'png',
            'width' => 64,
            'height' => 48,
            'enhancements' => array('sharpen:0.95', 'contrast:1.30', 'saturation:1.25'),
            'b64' => self::PNG_B64,
        ), 200, $json);

        $upscale = Http::response(array(
            'success' => true,
            'original_size' => 1000,
            'optimized_size' => 4000,
            'savings_bytes' => -3000,
            'savings_percent' => '-300.0%',
            'format' => 'png',
            'width' => 256,
            'height' => 192,
            'factor' => 4,
            'kernel' => 'lanczos3',
            'upscale_method' => 'lanczos3',
            'b64' => self::PNG_B64,
        ), 200, $json);

        $edit = Http::response(array(
            'success' => true,
            'provider' => 'gemini',
            'model' => 'gemini-3.1-flash-image',
            'operation' => 'colorize',
            'style' => null,
            'original_size' => 1000,
            'optimized_size' => 1200,
            'savings_bytes' => -200,
            'savings_percent' => '-20.0%',
            'format' => 'png',
            'width' => 64,
            'height' => 48,
            'b64' => self::PNG_B64,
        ), 200, $json);

        $info = Http::response(array(
            'success' => true,
            'width' => 800,
            'height' => 600,
            'format' => 'png',
            'mime_type' => 'image/png',
            'bytes' => 1234,
        ), 200, $json);

        $missing = Http::response(array('error' => 'unexpected route'), 404, $json);

        Http::fake(function (ClientRequest $request) use (&$captured, $health, $batch, $enhance, $upscale, $edit, $info, $missing) {
            $url = $request->url();

            if (str_contains($url, '/api/health/full')) {
                return $health;
            }

            $captured = array('url' => $url, 'parts' => $request->data());

            return match (true) {
                str_contains($url, '/api/image/batch') => $batch,
                str_contains($url, '/api/image/enhance') => $enhance,
                str_contains($url, '/api/image/upscale') => $upscale,
                str_contains($url, '/api/image/edit') => $edit,
                str_contains($url, '/api/image/info') => $info,
                default => $missing,
            };
        });
    }

    /**
     * A `/api/image/batch` envelope, one row per entry.
     *
     * @param list<list<int>> $rows Each entry is `array{0: int}` — the output size.
     * @return array<string, mixed>
     */
    private function batchBody(array $rows): array
    {
        $results = array();
        $saved = 0;

        foreach ($rows as $index => $sizes) {
            $row = $this->batchRow($index, (int) ($sizes[0] ?? 500));
            $saved += $row['original_size'] - $row['optimized_size'];
            $results[] = $row;
        }

        return array(
            'success' => true,
            'processed' => count($results),
            'total_bytes_saved' => $saved,
            'results' => $results,
            'errors' => array(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function batchRow(int $index, int $size = 500): array
    {
        $original = max($size, 1000);

        return array(
            'filename' => 'fixture-'.$index.'.png',
            'format' => 'webp',
            'width' => 64,
            'height' => 48,
            'original_size' => $original,
            'optimized_size' => $size,
            'savings_percent' => number_format((($original - $size) / $original) * 100, 1).'%',
            'b64' => self::PNG_B64,
        );
    }

    /**
     * @param array{url: string, parts: array<int, array{name: string, contents: string}>}|null $captured
     */
    private function path(?array $captured): string
    {
        $path = parse_url((string) ($captured['url'] ?? ''), PHP_URL_PATH);

        return is_string($path) ? $path : '';
    }

    /**
     * One multipart part's value, or null when it was not sent.
     *
     * `Request::data()` returns a list of `{name, contents}`, not a map.
     *
     * @param array{url: string, parts: array<int, array{name: string, contents: string}>}|null $captured
     */
    private function part(?array $captured, string $name): ?string
    {
        foreach ($captured['parts'] ?? array() as $part) {
            if ($name === ($part['name'] ?? '')) {
                $contents = $part['contents'] ?? null;

                return is_scalar($contents) ? (string) $contents : null;
            }
        }

        return null;
    }

    /**
     * Recording file stores, so no probe reaches the real registry.
     *
     * @param list<array<string, mixed>> $rows Registry rows the audit tools list.
     */
    private function fakeStores(array $rows): object
    {
        $path = $this->source();

        $visibility = new class implements FileVisibilityStoreInterface {
            /** @var list<array<string, mixed>> */
            public array $writes = array();

            public function storeWithVisibility(
                string $localPath,
                string $filename,
                string $mimeType,
                int $userId,
                string $visibility,
                ?string $tenantId = null,
            ): StoredFile {
                $bytes = is_file($localPath) ? (string) file_get_contents($localPath) : '';

                $this->writes[] = array(
                    'name' => $filename,
                    'mime' => $mimeType,
                    'existed' => is_file($localPath),
                    'visibility' => $visibility,
                );

                return new StoredFile(
                    id: 700 + count($this->writes),
                    filename: $filename.'.webp',
                    mimeType: $mimeType,
                    sizeBytes: strlen($bytes),
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                    publicUrl: '/storage/default/public/out.webp',
                    visibility: $visibility,
                    slug: $filename,
                );
            }

            public function supportsVisibility(string $visibility): bool
            {
                return in_array($visibility, array('public', 'private'), true);
            }
        };

        $files = new class($path, $rows) implements FileStoreInterface {
            /**
             * @param list<array<string, mixed>> $rows
             */
            public function __construct(private readonly string $path, private readonly array $rows)
            {
            }

            public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                return new StoredFile(
                    id: 1,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: is_file($localPath) ? (int) filesize($localPath) : 1,
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                );
            }

            public function getPath(int $fileId): ?string
            {
                return $this->path;
            }

            public function getMetadata(int $fileId): ?StoredFile
            {
                return new StoredFile(
                    id: $fileId,
                    filename: 'stored.png',
                    mimeType: 'image/png',
                    sizeBytes: is_file($this->path) ? (int) filesize($this->path) : 1,
                    localPath: $this->path,
                    ownerId: 7,
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                );
            }

            public function userCanAccess(int $fileId, int $userId): bool
            {
                return true;
            }

            public function delete(int $fileId): void
            {
            }

            public function findByMetadata(array $criteria, int $limit = 50): array
            {
                $matches = array();

                foreach ($this->rows as $row) {
                    $matches[] = new StoredFile(
                        id: (int) $row['id'],
                        filename: (string) $row['filename'],
                        mimeType: (string) $row['mime'],
                        sizeBytes: (int) $row['bytes'],
                        localPath: $this->path,
                        ownerId: 7,
                        createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                        metadata: isset($row['alt']) ? array('alt_text' => (string) $row['alt']) : array(),
                    );
                }

                return $matches;
            }
        };

        $this->app->instance(FileVisibilityStoreInterface::class, $visibility);
        $this->app->instance(FileStoreInterface::class, $files);

        return $visibility;
    }
}
