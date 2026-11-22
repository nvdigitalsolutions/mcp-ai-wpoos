<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\ImageProcessingInterface;
use Nvoos\Core\Tool\ConvertImageFormatTool;
use Nvoos\Core\Tool\CropImageTool;
use Nvoos\Core\Tool\ResizeImageTool;
use Nvoos\Core\Tool\RotateImageTool;
use Nvoos\Laravel\Adapter\MediaWorkerClient;
use Nvoos\Laravel\Adapter\MediaWorkerImageProcessing;
use Tests\TestCase;

/**
 * The image tools reach the media worker — Proposal 149 §160.
 *
 * Four rows were `unavailable` because `ImageProcessingInterface` had no
 * non-WordPress implementation. The adapter now maps the domain contract onto
 * the sidecar's transform vocabulary, and these tests pin the two things that
 * can silently drift: the **operation mapping** (a `crop: true` resize must be
 * `fit: cover`, not `inside`) and the **output publication** (bytes come back
 * as base64; a path must exist afterwards).
 *
 * The multipart body is captured **inside** the fake: the adapter closes the
 * source stream as soon as the request is built, so a recorded request cannot
 * be re-read afterwards. The worker itself is faked; its own bounds are
 * exercised by `addons/media-worker/src/routes/image.transform.test.js`.
 */
final class ImageProcessingAdapterTest extends TestCase
{
    /**
     * A 1×1 transparent PNG — enough for the adapter, which never decodes it.
     */
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/oos-image-'.bin2hex(random_bytes(5));

        mkdir($this->workDir, 0775, true);

        file_put_contents($this->source(), base64_decode(self::PNG_B64, true));

        config([
            'oos.media_worker.url' => 'http://worker.test',
            'oos.media_worker.output_dir' => $this->workDir.'/out',
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/out/*') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($this->workDir.'/source.png');
        @rmdir($this->workDir.'/out');
        @rmdir($this->workDir);

        parent::tearDown();
    }

    public function test_the_contract_resolves_to_the_worker_adapter(): void
    {
        $this->assertInstanceOf(
            MediaWorkerImageProcessing::class,
            $this->app->make(ImageProcessingInterface::class)
        );
        $this->assertTrue($this->app->make(ImageProcessingInterface::class)->isAvailable());
    }

    public function test_resize_maps_to_a_cover_fit_and_publishes_the_output(): void
    {
        $this->fakeTransform($body, 40, 20);

        $result = $this->adapter()->resize($this->source(), 40, 20);

        $this->assertSame(40, $result['width']);
        $this->assertSame(20, $result['height']);
        $this->assertSame('image/png', $result['mime_type']);
        $this->assertFileExists($result['path']);
        $this->assertSame($result['bytes'], filesize($result['path']));

        $operations = $this->operations($body);

        $this->assertSame('cover', $operations[0]['fit']);
        $this->assertSame(40, $operations[0]['width']);
        $this->assertSame(20, $operations[0]['height']);
        $this->assertStringContainsString('http://worker.test/api/image/transform', (string) $body['url']);
    }

    public function test_resize_without_crop_uses_inside(): void
    {
        $this->fakeTransform($body, 40, 20);

        $this->adapter()->resize($this->source(), 40, 20, ['crop' => false]);

        $this->assertSame('inside', $this->operations($body)[0]['fit']);
    }

    public function test_crop_maps_the_region(): void
    {
        $this->fakeTransform($body, 10, 10);

        $this->adapter()->crop($this->source(), 5, 6, 10, 10);

        $this->assertSame(
            array('type' => 'crop', 'left' => 5, 'top' => 6, 'width' => 10, 'height' => 10),
            $this->operations($body)[0]
        );
    }

    public function test_rotate_passes_the_angle_and_background(): void
    {
        $this->fakeTransform($body, 20, 20);

        $this->adapter()->rotate($this->source(), 90.0, '#000000');

        $operation = $this->operations($body)[0];

        $this->assertSame('rotate', $operation['type']);
        $this->assertSame(90.0, (float) $operation['angle']);
        $this->assertSame('#000000', $operation['background']);
    }

    public function test_convert_maps_jpg_to_jpeg_and_rejects_an_unknown_format(): void
    {
        $this->fakeTransform($body, 1, 1, 'jpeg');

        $this->adapter()->convert($this->source(), 'jpg', 75);

        $this->assertSame('jpeg', $this->part($body, 'format'));
        $this->assertSame('75', $this->part($body, 'quality'));
        $this->assertSame(array(), $this->operations($body));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported target format: avif');

        $this->adapter()->convert($this->source(), 'avif');
    }

    public function test_get_info_reads_metadata_through_the_worker(): void
    {
        Http::fake(array(
            'worker.test/api/image/info' => Http::response(array(
                'success' => true,
                'width' => 640,
                'height' => 480,
                'format' => 'png',
                'mime_type' => 'image/png',
                'bytes' => 1234,
            )),
        ));

        $info = $this->adapter()->getInfo($this->source());

        $this->assertSame(
            array('width' => 640, 'height' => 480, 'mime_type' => 'image/png', 'bytes' => 1234, 'format' => 'png'),
            $info
        );
        $this->assertSame(array('png', 'jpeg', 'webp', 'gif'), $this->adapter()->getSupportedFormats());
    }

    public function test_an_unconfigured_worker_reports_unavailable_and_throws(): void
    {
        config(['oos.media_worker.url' => '']);

        $adapter = $this->adapter();

        $this->assertFalse($adapter->isAvailable());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The media worker is not configured.');

        $adapter->resize($this->source(), 10, 10);
    }

    public function test_a_worker_error_becomes_an_exception(): void
    {
        Http::fake(array(
            'worker.test/*' => Http::response(array('error' => 'crop region out of bounds'), 400),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Media worker error: crop region out of bounds');

        $this->adapter()->crop($this->source(), 999999, 0, 10, 10);
    }

    public function test_a_missing_source_file_is_refused_before_the_call(): void
    {
        Http::fake();

        try {
            $this->adapter()->resize($this->workDir.'/nope.png', 10, 10);
            $this->fail('A missing source must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Image file not found', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /**
     * The four tools, through the container, with the worker faked.
     *
     * @dataProvider imageTools
     *
     * @param array<string, mixed> $arguments
     */
    public function test_the_image_tools_run_end_to_end(string $class, array $arguments): void
    {
        $this->fakeTransform($body, 40, 20);

        $arguments['image_url'] = $this->source();

        $result = $this->app->make($class)->execute($arguments);

        $this->assertTrue($result['success']);
        $this->assertSame(40, $result['data']['width']);
        $this->assertSame(20, $result['data']['height']);
    }

    /**
     * @return array<string, array{0: class-string, 1: array<string, mixed>}>
     */
    public static function imageTools(): array
    {
        return array(
            'resize' => array(ResizeImageTool::class, array(
                'image_url' => 'placeholder.png',
                'width' => 40,
                'height' => 20,
            )),
            'crop' => array(CropImageTool::class, array(
                'image_url' => 'placeholder.png',
                'x' => 0,
                'y' => 0,
                'width' => 40,
                'height' => 20,
            )),
            'rotate' => array(RotateImageTool::class, array(
                'image_url' => 'placeholder.png',
                'angle' => 90,
            )),
            'convert' => array(ConvertImageFormatTool::class, array(
                'image_url' => 'placeholder.png',
                'format' => 'webp',
            )),
        );
    }

    private function adapter(): ImageProcessingInterface
    {
        return new MediaWorkerImageProcessing(new MediaWorkerClient());
    }

    private function source(): string
    {
        return $this->workDir.'/source.png';
    }

    /**
     * Fake the transform endpoint and capture the multipart parts.
     *
     * `Request::data()` returns the multipart parts for a multipart request, so
     * the assertion reads names and string contents directly — no body parsing,
     * and no dependence on a stream the adapter has already closed.
     *
     * @param array<string, mixed>|null $captured Receives `url` and `parts`.
     */
    private function fakeTransform(?array &$captured, int $width, int $height, string $format = 'png'): void
    {
        $captured = null;

        Http::fake(function (ClientRequest $request) use (&$captured, $width, $height, $format) {
            $captured = array(
                'url' => $request->url(),
                'parts' => $request->data(),
            );

            return Http::response(array(
                'success' => true,
                'width' => $width,
                'height' => $height,
                'format' => $format,
                'mime_type' => 'image/'.$format,
                'bytes' => strlen((string) base64_decode(self::PNG_B64, true)),
                'b64' => self::PNG_B64,
            ));
        });
    }

    /**
     * @param array<string, mixed>|null $captured
     * @return list<array<string, mixed>>
     */
    private function operations(?array $captured): array
    {
        $decoded = json_decode((string) $this->part($captured, 'operations'), true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Read one multipart field from the captured request.
     *
     * @param array<string, mixed>|null $captured
     */
    private function part(?array $captured, string $name): ?string
    {
        foreach ((array) ($captured['parts'] ?? array()) as $part) {
            if (is_array($part) && $name === ($part['name'] ?? null)) {
                return is_resource($part['contents'] ?? null)
                    ? null
                    : (string) ($part['contents'] ?? '');
            }
        }

        return null;
    }
}
