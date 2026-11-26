<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\VideoFrameExtractionInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\ExtractVideoFramesTool;
use Nvoos\Laravel\Adapter\MediaWorkerVideoFrames;
use RuntimeException;
use Tests\TestCase;

/**
 * Many files out of one video — Proposal 164 §164.25.
 *
 * `/process` answers `output_files` on the worker's disk and keeps them for five
 * minutes. These tests pin what goes wrong when that is treated like the
 * single-output path:
 *
 *  - a frame name is nested (`frames_<ts>/frame_000.jpg`), so the download URL
 *    must be validated per segment rather than by a single-filename regex;
 *  - every frame is a second request, so the count has to be bounded before the
 *    loop and not after it;
 *  - the three modes are alternatives, so two of them arriving together is a
 *    caller error rather than a precedence the adapter invents.
 */
final class VideoFramesAdapterTest extends TestCase
{
    private const CAPABILITIES = array(
        'video_processing' => true,
    );

    private const STUB_BYTES = 'STUB-JPEG-BYTES';

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/oos-frames-'.bin2hex(random_bytes(5));

        mkdir($this->workDir, 0775, true);

        file_put_contents($this->source(), 'fake mp4 bytes');

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

        @unlink($this->source());
        @rmdir($this->workDir.'/out');
        @rmdir($this->workDir);

        parent::tearDown();
    }

    // ── Contract ─────────────────────────────────────────────────────────────

    public function test_the_contract_resolves_to_the_frames_adapter(): void
    {
        $adapter = $this->app->make(VideoFrameExtractionInterface::class);

        $this->assertInstanceOf(MediaWorkerVideoFrames::class, $adapter);
        $this->assertTrue($adapter->isAvailable());
    }

    // ── The three modes ──────────────────────────────────────────────────────

    public function test_timestamps_are_sent_in_the_callers_order(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(ExtractVideoFramesTool::class)->execute(array(
            'video_url' => $this->source(),
            'timestamps' => array(1.5, 4, 0.25),
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame('extract_frames', $this->field($captured, 'operation'));
        $this->assertSame('1.5,4,0.25', $this->field($captured, 'timestamps'));
        $this->assertSame('timestamps', $result['data']['mode']);
    }

    public function test_a_frame_count_and_width_are_forwarded(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(ExtractVideoFramesTool::class)->execute(array(
            'video_url' => $this->source(),
            'frame_count' => 5,
            'width' => 640,
            'quality' => 5,
        ));

        $this->assertIsArray($result);
        $this->assertSame('5', $this->field($captured, 'count'));
        $this->assertSame('640', $this->field($captured, 'width'));
        $this->assertSame('5', $this->field($captured, 'quality'));
        $this->assertSame('even', $result['data']['mode']);
    }

    public function test_the_default_is_ten_evenly_spread_frames(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $this->app->make(ExtractVideoFramesTool::class)->execute(array(
            'video_url' => $this->source(),
        ));

        $this->assertSame('10', $this->field($captured, 'count'));
        $this->assertSame('2', $this->field($captured, 'quality'));
        $this->assertNull($this->field($captured, 'timestamps'));
    }

    public function test_an_interval_is_its_own_mode(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(ExtractVideoFramesTool::class)->execute(array(
            'video_url' => $this->source(),
            'interval' => 2.5,
        ));

        $this->assertIsArray($result);
        $this->assertSame('2.5', $this->field($captured, 'interval'));
        $this->assertNull($this->field($captured, 'timestamps'), 'an interval is not a timestamp list');
        $this->assertNull($this->field($captured, 'count'), 'the route expands the interval itself');
        $this->assertSame('interval', $result['data']['mode']);
    }

    // ── The payload ──────────────────────────────────────────────────────────

    public function test_every_answered_frame_is_downloaded_and_written(): void
    {
        $captured = null;
        $downloads = array();
        $this->fakeWorker($captured, $downloads);

        $result = $this->app->make(ExtractVideoFramesTool::class)->execute(array(
            'video_url' => $this->source(),
            'timestamps' => array(1, 2),
        ));

        $this->assertIsArray($result);
        $this->assertSame(2, $result['data']['frame_count']);
        $this->assertSame(10.5, $result['data']['source_duration']);
        $this->assertCount(2, $result['data']['frames']);

        foreach ($result['data']['frames'] as $frame) {
            $this->assertFileExists((string) $frame['path']);
            $this->assertSame(\strlen(self::STUB_BYTES), (int) $frame['bytes']);
            $this->assertArrayNotHasKey('media', $frame, 'a scratch frame is not stored');
        }

        // Nested names, fetched one at a time.
        $this->assertCount(2, $downloads);
        $this->assertStringContainsString('frames_1700000000/frame_000.jpg', $downloads[0]);
        $this->assertStringContainsString('frames_1700000000/frame_001.jpg', $downloads[1]);
    }

    public function test_frames_are_attached_only_when_asked_for(): void
    {
        $captured = null;
        $downloads = array();
        $this->fakeWorker($captured, $downloads);
        $this->fakeVisibilityStore();

        $result = $this->app->make(ExtractVideoFramesTool::class)->execute(array(
            'video_url' => $this->source(),
            'frame_count' => 2,
            'save_to_media' => true,
        ));

        $this->assertIsArray($result);
        $this->assertCount(2, $result['data']['frames']);

        foreach ($result['data']['frames'] as $frame) {
            $this->assertArrayHasKey('media', $frame);
            $this->assertNotEmpty($frame['media'][0]['attachment_id']);
            $this->assertSame('image/jpeg', $frame['media'][0]['mime_type']);
        }
    }

    // ── Refusals ─────────────────────────────────────────────────────────────

    public function test_two_modes_together_are_refused_before_a_request(): void
    {
        Http::fake();

        $result = $this->app->make(ExtractVideoFramesTool::class)->execute(array(
            'video_url' => $this->source(),
            'timestamps' => array(1, 2),
            'interval' => 5,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('not both', $result->getMessage());

        Http::assertNothingSent();
    }

    public function test_a_frame_count_above_the_ceiling_is_refused(): void
    {
        Http::fake();

        $result = $this->app->make(ExtractVideoFramesTool::class)->execute(array(
            'video_url' => $this->source(),
            'frame_count' => 21,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('frame_count must be between 1 and 20', $result->getMessage());

        Http::assertNothingSent();
    }

    public function test_a_missing_source_is_refused(): void
    {
        Http::fake();

        $result = $this->app->make(ExtractVideoFramesTool::class)->execute(array(
            'frame_count' => 3,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('video_url or file_id is required', $result->getMessage());

        Http::assertNothingSent();
    }

    public function test_a_frame_name_the_host_will_not_fetch_is_refused(): void
    {
        $json = array('Content-Type' => 'application/json');

        $health = Http::response(array('status' => 'ok', 'capabilities' => self::CAPABILITIES), 200, $json);
        $process = Http::response(array(
            'success' => true,
            'operation' => 'extract_frames',
            'frame_count' => 1,
            'source_duration' => 10.0,
            // A worker must not be able to steer this host's URL path — and the
            // names here are nested, so a single-segment check would not see it.
            'output_files' => array('../../etc/passwd'),
        ), 200, $json);

        Http::fake(static function (ClientRequest $request) use ($health, $process) {
            return str_contains($request->url(), '/api/health') ? $health : $process;
        });

        $result = $this->app->make(ExtractVideoFramesTool::class)->execute(array(
            'video_url' => $this->source(),
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('will not fetch', $result->getMessage());
    }

    public function test_a_worker_refusal_is_a_caller_error(): void
    {
        $this->fakeWorkerRefusing('quality must be a number between 1 and 31.');

        $result = $this->app->make(ExtractVideoFramesTool::class)->execute(array(
            'video_url' => $this->source(),
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('quality must be a number', $result->getMessage());
    }

    public function test_a_host_without_ffmpeg_refuses_by_name(): void
    {
        $captured = null;
        $this->fakeWorker($captured, $downloads, array('video_processing' => false));

        $result = $this->app->make(ExtractVideoFramesTool::class)->execute(array(
            'video_url' => $this->source(),
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('ffmpeg', $result->getMessage());
        $this->assertNull($captured, 'no upload is spent before the refusal');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function source(): string
    {
        return $this->workDir.'/source.mp4';
    }

    /**
     * Fake health, `/process` and the download route.
     *
     * @param array{url: string, data: mixed}|null $captured  Receives the process request.
     * @param list<string>|null                    $downloads Receives each downloaded path.
     * @param array<string, bool>                  $capabilities
     */
    private function fakeWorker(
        ?array &$captured,
        ?array &$downloads = null,
        array $capabilities = self::CAPABILITIES,
    ): void {
        $captured = null;
        $downloads = array();

        $json = array('Content-Type' => 'application/json');

        $health = Http::response(array('status' => 'ok', 'capabilities' => $capabilities), 200, $json);

        $process = Http::response(array(
            'success' => true,
            'operation' => 'extract_frames',
            'frame_count' => 2,
            'source_duration' => 10.5,
            'output_files' => array(
                'frames_1700000000/frame_000.jpg',
                'frames_1700000000/frame_001.jpg',
            ),
        ), 200, $json);

        $bytes = Http::response(self::STUB_BYTES, 200, array('Content-Type' => 'image/jpeg'));

        Http::fake(function (ClientRequest $request) use (&$captured, &$downloads, $health, $process, $bytes) {
            $url = $request->url();

            if (str_contains($url, '/api/health')) {
                return $health;
            }

            if (str_contains($url, '/api/video/download/')) {
                $downloads[] = $url;

                return $bytes;
            }

            $captured = array('url' => $url, 'data' => $request->data());

            return $process;
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
     * A recording visibility store, so nothing reaches the real registry.
     */
    private function fakeVisibilityStore(): void
    {
        $store = new class implements FileVisibilityStoreInterface {
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
                $this->writes[] = array('name' => $filename, 'mime' => $mimeType);

                return new StoredFile(
                    id: 900 + count($this->writes),
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: is_file($localPath) ? (int) filesize($localPath) : 1,
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                    visibility: $visibility,
                    slug: $filename,
                );
            }

            public function supportsVisibility(string $visibility): bool
            {
                return in_array($visibility, array('public', 'private'), true);
            }
        };

        $this->app->instance(FileVisibilityStoreInterface::class, $store);
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
}
