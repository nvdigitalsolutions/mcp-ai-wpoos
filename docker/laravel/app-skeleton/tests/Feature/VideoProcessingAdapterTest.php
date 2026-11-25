<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\VideoProcessingInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\AdjustVideoSpeedTool;
use Nvoos\Core\Tool\CompressVideoTool;
use Nvoos\Core\Tool\ConvertVideoFormatTool;
use Nvoos\Core\Tool\GenerateVideoThumbnailsTool;
use Nvoos\Core\Tool\ExtractAudioTool;
use Nvoos\Core\Tool\GetVideoMetadataTool;
use Nvoos\Core\Tool\OptimizeForPlatformTool;
use Nvoos\Core\Tool\ResizeVideoResolutionTool;
use Nvoos\Core\Tool\TranscodeVideoTool;
use Nvoos\Core\Tool\TrimVideoTool;
use Nvoos\Laravel\Adapter\MediaWorkerVideo;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Video reaches the worker, in two steps — Proposal 164 §164.4.5.
 *
 * `/api/video/process` writes to the **worker's** disk and answers a filename;
 * `/api/video/download/<name>` is how this host gets the bytes. The adapter does
 * both inside `process()`, so the intermediate name never escapes — and the
 * filename is re-validated here before it becomes a URL, because a worker that
 * had been compromised must not be able to make this host fetch `../../etc`.
 *
 * The second thing pinned here is the `thumbnail` container: ffmpeg picks the
 * encoder from the output extension, so a "thumbnail" asked for as `mp4` comes
 * back as a one-frame video. The tool requests `jpg`.
 */
final class VideoProcessingAdapterTest extends TestCase
{
    private const CAPABILITIES = array(
        'video_processing' => true,
    );

    /**
     * A few bytes standing in for an encoded video.
     */
    private const STUB_BYTES = 'STUB-VIDEO-BYTES';

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/oos-video-'.bin2hex(random_bytes(5));

        mkdir($this->workDir, 0775, true);

        file_put_contents($this->source(), 'fake mp4 bytes');

        config([
            'oos.media_worker.url' => 'http://worker.test',
            'oos.media_worker.output_dir' => $this->workDir.'/out',
        ]);

        Cache::flush();

        $this->fakeStores();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/out/*') ?: array() as $file) {
            @unlink($file);
        }

        @unlink($this->workDir.'/source.mp4');
        @rmdir($this->workDir.'/out');
        @rmdir($this->workDir);

        parent::tearDown();
    }

    public function test_the_contract_resolves_to_the_worker_adapter(): void
    {
        $adapter = $this->app->make(VideoProcessingInterface::class);

        $this->assertInstanceOf(MediaWorkerVideo::class, $adapter);
        $this->assertTrue($adapter->isAvailable());
    }

    public function test_metadata_is_read_through_ffprobe(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(GetVideoMetadataTool::class)->execute(array(
            'video_url' => $this->source(),
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('/api/video/info', $this->path($captured));
        $this->assertSame(1920, $result['data']['video']['width']);
        $this->assertSame('h264', $result['data']['video']['codec']);
        $this->assertEqualsWithDelta(29.97, $result['data']['video']['fps'], 0.01);
        $this->assertSame(2, $result['data']['audio']['channels']);
    }

    public function test_trim_runs_the_operation_then_downloads_the_result(): void
    {
        $captured = null;
        $downloads = array();
        $this->fakeWorker($captured, $downloads);

        $result = $this->app->make(TrimVideoTool::class)->execute(array(
            'video_url' => $this->source(),
            'start' => 12.5,
            'duration' => 4,
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('trim', $this->field($captured, 'operation'));
        $this->assertSame('12.5', $this->field($captured, 'start'));
        $this->assertSame('4', $this->field($captured, 'duration'));
        $this->assertSame('mp4', $this->field($captured, 'format'));

        // The second step is what makes the bytes exist on this host.
        $this->assertCount(1, $downloads);
        $this->assertSame('/api/video/download/processed_1700000000.mp4', $downloads[0]);

        $stored = $this->workDir.'/out/video-trim-'.sha1(
            $this->source().'|trim|mp4|'.json_encode(array(
                'operation' => 'trim',
                'format' => 'mp4',
                'start' => 12.5,
                'duration' => 4.0,
            ))
        ).'.mp4';

        $this->assertFileExists($stored);
        $this->assertSame(self::STUB_BYTES, (string) file_get_contents($stored));
    }

    public function test_compress_reports_the_fixed_encoder_profile(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(CompressVideoTool::class)->execute(array(
            'video_url' => $this->source(),
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('compress', $this->field($captured, 'operation'));
        $this->assertSame('/api/video/process', $this->path($captured));
    }

    public function test_convert_remuxes_into_the_requested_container(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(ConvertVideoFormatTool::class)->execute(array(
            'video_url' => $this->source(),
            'format' => 'webm',
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('convert', $this->field($captured, 'operation'));
        $this->assertSame('webm', $this->field($captured, 'format'));
    }

    // ── Audio extraction (additive: no WordPress slug requests it) ────────────

    public function test_extract_audio_sends_the_container_and_the_bitrate(): void
    {
        $captured = null;
        $downloads = array();
        $this->fakeWorker($captured, $downloads, self::CAPABILITIES, array(
            'operation' => 'extract_audio',
            'reencoded' => true,
            'output_file' => 'processed_1700000000.m4a',
        ));

        $result = $this->app->make(ExtractAudioTool::class)->execute(array(
            'video_url' => $this->source(),
            'format' => 'm4a',
            'audio_bitrate' => '192k',
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success'], json_encode($result));
        $this->assertSame('/api/video/process', $this->path($captured));
        $this->assertSame('extract_audio', $this->field($captured, 'operation'));
        $this->assertSame('m4a', $this->field($captured, 'format'));
        $this->assertSame('192k', $this->field($captured, 'audio_bitrate'));

        // The download is what makes the bytes exist on this host.
        $this->assertCount(1, $downloads);
        $this->assertStringContainsString('/api/video/download/', $downloads[0]);
    }

    public function test_a_lossless_copy_is_reported_as_such(): void
    {
        $captured = null;
        $downloads = array();
        $this->fakeWorker($captured, $downloads, self::CAPABILITIES, array(
            'operation' => 'extract_audio',
            'reencoded' => false,
            'output_file' => 'processed_1700000000.m4a',
        ));

        $result = $this->app->make(ExtractAudioTool::class)->execute(array(
            'video_url' => $this->source(),
            'format' => 'm4a',
            'save_to_media' => false,
        ));

        // "Did this cost me a generation?" is the one question an extraction
        // caller always has, so the answer is in the envelope.
        $this->assertIsArray($result);
        $this->assertFalse($result['data']['reencoded']);
        $this->assertTrue($result['data']['lossless']);
    }

    public function test_a_re_encode_is_reported_as_such(): void
    {
        $captured = null;
        $downloads = array();
        $this->fakeWorker($captured, $downloads, self::CAPABILITIES, array(
            'operation' => 'extract_audio',
            'reencoded' => true,
            'output_file' => 'processed_1700000000.mp3',
        ));

        $result = $this->app->make(ExtractAudioTool::class)->execute(array(
            'video_url' => $this->source(),
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['data']['reencoded']);
        $this->assertFalse($result['data']['lossless']);
    }

    public function test_an_unknown_audio_container_is_refused_before_a_request(): void
    {
        Http::fake();

        $result = $this->app->make(ExtractAudioTool::class)->execute(array(
            'video_url' => $this->source(),
            'format' => 'wav',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('must be one of: mp3, m4a, aac', $result->getMessage());

        Http::assertNothingSent();
    }

    public function test_extract_audio_refuses_a_host_without_ffmpeg(): void
    {
        $captured = null;
        $downloads = array();
        $this->fakeWorker($captured, $downloads, array('video_processing' => false));

        $result = $this->app->make(ExtractAudioTool::class)->execute(array(
            'video_url' => $this->source(),
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('ffmpeg', $result->getMessage());
        $this->assertNull($captured, 'no upload is spent before the refusal');
    }

    public function test_a_resolution_preset_maps_to_a_height_and_keeps_the_aspect_ratio(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(ResizeVideoResolutionTool::class)->execute(array(
            'video_url' => $this->source(),
            'resolution' => '1080p',
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('resize', $this->field($captured, 'operation'));
        $this->assertSame('1080', $this->field($captured, 'height'));
        $this->assertNull($this->field($captured, 'width'), 'a preset sets one side only');
    }

    public function test_a_preset_and_a_dimension_together_are_refused(): void
    {
        $result = $this->app->make(ResizeVideoResolutionTool::class)->execute(array(
            'video_url' => $this->source(),
            'resolution' => '720p',
            'width' => 1000,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('not both', $result->getMessage());
    }

    public function test_speed_sends_the_factor_and_keeps_the_pitch_by_default(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(AdjustVideoSpeedTool::class)->execute(array(
            'video_url' => $this->source(),
            'speed_factor' => 2.0,
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('speed', $this->field($captured, 'operation'));
        $this->assertSame('2', $this->field($captured, 'speed'));
        $this->assertSame('true', $this->field($captured, 'maintain_pitch'), 'pitch is kept unless asked otherwise');
    }

    public function test_speed_can_ask_for_the_pitch_to_move_with_it(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $this->app->make(AdjustVideoSpeedTool::class)->execute(array(
            'video_url' => $this->source(),
            'speed_factor' => 0.5,
            'maintain_pitch' => false,
            'save_to_media' => false,
        ));

        $this->assertSame('0.5', $this->field($captured, 'speed'));
        $this->assertSame('false', $this->field($captured, 'maintain_pitch'));
    }

    public function test_a_speed_factor_of_one_is_refused_as_a_no_op(): void
    {
        $result = $this->app->make(AdjustVideoSpeedTool::class)->execute(array(
            'video_url' => $this->source(),
            'speed_factor' => 1.0,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('without changing it', $result->getMessage());
    }

    public function test_a_speed_factor_outside_the_range_is_refused_with_the_bounds(): void
    {
        $result = $this->app->make(AdjustVideoSpeedTool::class)->execute(array(
            'video_url' => $this->source(),
            'speed_factor' => 8,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('between 0.25 and 4', $result->getMessage());
    }

    public function test_a_missing_speed_factor_is_refused_rather_than_defaulted(): void
    {
        $result = $this->app->make(AdjustVideoSpeedTool::class)->execute(array(
            'video_url' => $this->source(),
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('speed_factor is required', $result->getMessage());
    }

    public function test_a_thumbnail_asks_for_a_jpeg_not_a_one_frame_video(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(GenerateVideoThumbnailsTool::class)->execute(array(
            'video_url' => $this->source(),
            'start' => 3,
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('thumbnail', $this->field($captured, 'operation'));
        $this->assertSame('jpg', $this->field($captured, 'format'));
        $this->assertNull($result['data']['at_start_warning'], 'frame 3 is not frame zero');
    }

    public function test_a_thumbnail_at_frame_zero_warns_about_the_fade_in(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(GenerateVideoThumbnailsTool::class)->execute(array(
            'video_url' => $this->source(),
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertStringContainsString('black fade-in', (string) $result['data']['at_start_warning']);
    }

    public function test_a_platform_preset_fills_the_frame_it_names(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(OptimizeForPlatformTool::class)->execute(array(
            'video_url' => $this->source(),
            'platform' => 'instagram',
            'content_type' => 'feed',
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('fit', $this->field($captured, 'operation'));
        $this->assertSame('1080', $this->field($captured, 'width'));
        $this->assertSame('1350', $this->field($captured, 'height'), 'a 4:5 feed frame, not a square');
        $this->assertSame('cover', $this->field($captured, 'fit'));

        // The preset is reported back, because `store()` keeps a fixed payload.
        $this->assertSame(1080, $result['data']['width']);
        $this->assertSame('4:5', $result['data']['aspect']);
    }

    public function test_a_vertical_placement_gets_a_vertical_frame(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $this->app->make(OptimizeForPlatformTool::class)->execute(array(
            'video_url' => $this->source(),
            'platform' => 'tiktok',
            'content_type' => 'reel',
            'fit' => 'contain',
            'save_to_media' => false,
        ));

        $this->assertSame('1080', $this->field($captured, 'width'));
        $this->assertSame('1920', $this->field($captured, 'height'));
        $this->assertSame('contain', $this->field($captured, 'fit'));
    }

    public function test_a_placement_the_platform_does_not_have_is_refused_with_the_ones_it_does(): void
    {
        $result = $this->app->make(OptimizeForPlatformTool::class)->execute(array(
            'video_url' => $this->source(),
            'platform' => 'twitter',
            'content_type' => 'story',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('no "story" placement', $result->getMessage());
        $this->assertStringContainsString('Supported: feed', $result->getMessage());
    }

    public function test_an_unknown_platform_is_refused_with_the_supported_ones(): void
    {
        $result = $this->app->make(OptimizeForPlatformTool::class)->execute(array(
            'video_url' => $this->source(),
            'platform' => 'myspace',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('Unknown platform: myspace', $result->getMessage());
        $this->assertStringContainsString('youtube, instagram, tiktok', $result->getMessage());
    }

    public function test_transcode_sends_the_encoder_and_bitrates(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $result = $this->app->make(TranscodeVideoTool::class)->execute(array(
            'video_url' => $this->source(),
            'output_format' => 'mkv',
            'video_codec' => 'libx265',
            'video_bitrate' => '5M',
            'audio_bitrate' => '192k',
            'save_to_media' => false,
        ));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('transcode', $this->field($captured, 'operation'));
        $this->assertSame('mkv', $this->field($captured, 'format'));
        $this->assertSame('libx265', $this->field($captured, 'vcodec'));
        $this->assertSame('5m', $this->field($captured, 'video_bitrate'), 'the bitrate is normalised');
        $this->assertSame('192k', $this->field($captured, 'audio_bitrate'));
    }

    public function test_a_height_preset_is_sent_without_a_width(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $this->app->make(TranscodeVideoTool::class)->execute(array(
            'video_url' => $this->source(),
            'resolution' => '1280x720',
            'save_to_media' => false,
        ));

        $this->assertSame('720', $this->field($captured, 'height'));
        $this->assertNull($this->field($captured, 'width'), 'the width is derived so the aspect ratio is kept');
    }

    public function test_a_codec_that_does_not_belong_in_the_container_is_refused(): void
    {
        $result = $this->app->make(TranscodeVideoTool::class)->execute(array(
            'video_url' => $this->source(),
            'output_format' => 'webm',
            'video_codec' => 'libx264',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('does not take libx264', $result->getMessage());
        $this->assertStringContainsString('libvpx-vp9', $result->getMessage());
    }

    public function test_remove_audio_is_forwarded(): void
    {
        $captured = null;
        $this->fakeWorker($captured);

        $this->app->make(TranscodeVideoTool::class)->execute(array(
            'video_url' => $this->source(),
            'remove_audio' => true,
            'save_to_media' => false,
        ));

        $this->assertSame('true', $this->field($captured, 'remove_audio'));
    }

    public function test_an_unknown_resolution_is_refused_with_the_supported_ones(): void
    {
        $result = $this->app->make(TranscodeVideoTool::class)->execute(array(
            'video_url' => $this->source(),
            'resolution' => '4096x2160',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('Unknown resolution: 4096x2160', $result->getMessage());
        $this->assertStringContainsString('1920x1080', $result->getMessage());
    }

    public function test_a_host_without_ffmpeg_refuses_by_name(): void
    {
        $captured = null;
        $downloads = array();

        $this->fakeWorker($captured, $downloads, array('video_processing' => false));

        $result = $this->app->make(CompressVideoTool::class)->execute(array(
            'video_url' => $this->source(),
            'save_to_media' => false,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('ffmpeg', $result->getMessage());
    }

    /**
     * A name the worker answers is interpolated into a URL path, so the charset
     * alone cannot be the rule: `..` is made entirely of allowed characters and
     * would climb out of `/api/video/download/` once the client normalises it.
     *
     * @return array<string, array{0: string}>
     */
    public static function hostileOutputNames(): array
    {
        return array(
            'parent traversal' => array('../../etc/passwd'),
            'nested traversal' => array('processed/../../../etc/passwd'),
            'a dot segment' => array('./out.mp4'),
            'absolute path' => array('/etc/passwd'),
            'a doubled separator' => array('processed//out.mp4'),
            'a trailing separator' => array('processed/'),
            'percent-encoded traversal' => array('..%2F..%2Fetc%2Fpasswd'),
            'an empty answer' => array(''),
        );
    }

    #[DataProvider('hostileOutputNames')]
    public function test_an_output_name_the_host_will_not_fetch_is_refused(string $name): void
    {
        $json = array('Content-Type' => 'application/json');

        $health = Http::response(array('status' => 'ok', 'capabilities' => self::CAPABILITIES), 200, $json);
        $process = Http::response(array(
            'success' => true,
            'operation' => 'compress',
            'original_size' => 100,
            'output_size' => 50,
            'savings_percent' => '50.0%',
            // A worker must not be able to steer this host's URL path.
            'output_file' => $name,
        ), 200, $json);

        Http::fake(function (ClientRequest $request) use ($health, $process) {
            return str_contains($request->url(), '/api/health') ? $health : $process;
        });

        $result = $this->app->make(CompressVideoTool::class)->execute(array(
            'video_url' => $this->source(),
            'save_to_media' => false,
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('will not fetch', $result->getMessage());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function source(): string
    {
        return $this->workDir.'/source.mp4';
    }

    /**
     * Fake health, `/info`, `/process` and the download route.
     *
     * @param array{url: string, data: mixed}|null $captured
     * @param list<string>|null                    $downloads      Receives each download path, by reference.
     * @param array<string, bool>                  $capabilities
     * @param array<string, mixed>                 $processOverrides Fields the fake `/process` answers.
     */
    private function fakeWorker(
        ?array &$captured,
        ?array &$downloads = null,
        array $capabilities = self::CAPABILITIES,
        array $processOverrides = array(),
    ): void {
        $captured = null;
        $downloads = array();

        $json = array('Content-Type' => 'application/json');

        $health = Http::response(array('status' => 'ok', 'capabilities' => $capabilities), 200, $json);

        $info = Http::response(array(
            'success' => true,
            'format' => 'mov,mp4,m4a,3gp,3g2,mj2',
            'duration' => 42.5,
            'size' => 10485760,
            'bitrate' => 1970000,
            'video' => array(
                'codec' => 'h264',
                'width' => 1920,
                'height' => 1080,
                'fps' => 29.97,
                'bitrate' => 1800000,
                'pixel_format' => 'yuv420p',
            ),
            'audio' => array(
                'codec' => 'aac',
                'channels' => 2,
                'sample_rate' => 48000,
                'bitrate' => 128000,
            ),
        ), 200, $json);

        $process = Http::response($processOverrides + array(
            'success' => true,
            'operation' => 'trim',
            'original_size' => 10485760,
            'output_size' => 1048576,
            'savings_percent' => '90.0%',
            'output_file' => 'processed_1700000000.mp4',
        ), 200, $json);

        $bytes = Http::response(self::STUB_BYTES, 200, array('Content-Type' => 'video/mp4'));

        Http::fake(function (ClientRequest $request) use (&$captured, &$downloads, $health, $info, $process, $bytes) {
            $url = $request->url();

            if (str_contains($url, '/api/health')) {
                return $health;
            }

            if (str_contains($url, '/api/video/download/')) {
                $downloads[] = (string) parse_url($url, PHP_URL_PATH);

                return $bytes;
            }

            if (str_contains($url, '/api/video/info')) {
                $captured = array('url' => $url, 'data' => $request->data());

                return $info;
            }

            $captured = array('url' => $url, 'data' => $request->data());

            return $process;
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
     * Recording file stores so nothing reaches the real registry.
     */
    private function fakeStores(): void
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
                $this->writes[] = array('name' => $filename, 'mime' => $mimeType);

                return new StoredFile(
                    id: 800 + count($this->writes),
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

        $files = new class($path) implements FileStoreInterface {
            public function __construct(private readonly string $path)
            {
            }

            public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                return $this->getMetadata(1) ?? throw new \RuntimeException('no file');
            }

            public function getPath(int $fileId): ?string
            {
                return $this->path;
            }

            public function getMetadata(int $fileId): ?StoredFile
            {
                return new StoredFile(
                    id: $fileId,
                    filename: 'source.mp4',
                    mimeType: 'video/mp4',
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
                return array();
            }
        };

        $this->app->instance(FileVisibilityStoreInterface::class, $visibility);
        $this->app->instance(FileStoreInterface::class, $files);
    }
}
