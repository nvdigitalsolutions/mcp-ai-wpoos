<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\GenerateVideoCaptionsTool;
use Nvoos\Core\Tool\TranscribeVideoTool;
use RuntimeException;
use Tests\TestCase;

/**
 * Transcription and captions — Proposal 164 §164.27.
 *
 * The pipeline has three boundaries and each is faked at its own seam: the media
 * worker for the audio extraction, OpenAI for the transcription, and the file
 * registry for the stored subtitle. Nothing here reaches a network or a disk the
 * test did not create.
 *
 * Two behaviours are pinned beyond "it works". The first is that `dry_run`
 * spends nothing — an agent that previews a batch must not be billed for it. The
 * second is that a batch loses only the items that failed: a video with no audio
 * track must not cost the other transcripts in the same call.
 */
final class VideoTranscriptionTest extends TestCase
{
    /** A two-segment `verbose_json` answer. */
    private const TRANSCRIPT = array(
        'text'     => 'Hello there. General Kenobi.',
        'language' => 'en',
        'duration' => 6.5,
        'segments' => array(
            array('start' => 1.0, 'end' => 2.0, 'text' => ' Hello there.'),
            array('start' => 2.5, 'end' => 3.25, 'text' => ' General Kenobi.'),
        ),
    );

    private string $workDir;

    /** @var array<string, mixed> */
    private array $transcript = self::TRANSCRIPT;

    /** @var array<string, bool> */
    private array $capabilities = array('video_processing' => true);

    /** @var list<array{url: string, data: mixed}> */
    private array $workerCalls = array();

    /** @var list<array{url: string, data: mixed}> */
    private array $openAiCalls = array();

    private object $visibility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/oos-transcript-'.bin2hex(random_bytes(5));

        mkdir($this->workDir, 0775, true);

        file_put_contents($this->workDir.'/source.mp4', 'fake mp4 bytes');
        file_put_contents($this->workDir.'/speech.mp3', 'fake mp3 bytes');

        config(array(
            'oos.media_worker.url' => 'http://worker.test',
            'oos.media_worker.output_dir' => $this->workDir.'/out',
            'services.openai.key' => 'sk-test',
        ));

        Cache::flush();

        $this->transcript   = self::TRANSCRIPT;
        $this->capabilities = array('video_processing' => true);

        $this->fakeStores();
        $this->fakeWorkerAndProvider();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/out/*') ?: array() as $file) {
            @unlink($file);
        }

        @unlink($this->workDir.'/source.mp4');
        @unlink($this->workDir.'/speech.mp3');
        @rmdir($this->workDir.'/out');
        @rmdir($this->workDir);

        parent::tearDown();
    }

    // ─── transcribe_video ────────────────────────────────────────────────

    public function test_a_video_is_extracted_then_transcribed(): void
    {
        $result = $this->transcribe(array('video_ids' => array(1)));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['successful']);
        $this->assertSame('Hello there. General Kenobi.', $result['data']['processed'][0]['text']);
        $this->assertSame(2, $result['data']['processed'][0]['segment_count']);
        $this->assertSame('en', $result['data']['processed'][0]['language']);
        $this->assertSame(6.5, $result['data']['processed'][0]['duration']);

        // The extraction is the 64 kbps one: speech is downmixed anyway, and the
        // lower bitrate is what keeps a long clip inside the provider's ceiling.
        $process = $this->workerCall('/api/video/process');

        $this->assertNotNull($process, 'The worker was never asked to extract audio.');
        $this->assertSame('extract_audio', $this->field($process, 'operation'));
        $this->assertSame('64k', $this->field($process, 'audio_bitrate'));

        $openAi = $this->openAiCall();

        $this->assertNotNull($openAi);
        $this->assertSame('whisper-1', $this->field($openAi, 'model'));
        $this->assertSame('verbose_json', $this->field($openAi, 'response_format'));
        $this->assertSame('en', $this->field($openAi, 'language'));
        $this->assertContains('file', $this->partNames($openAi), 'The audio was never attached.');
    }

    public function test_the_extracted_audio_does_not_outlive_the_call(): void
    {
        $this->transcribe(array('video_ids' => array(1)));

        $this->assertSame(array(), glob($this->workDir.'/out/*') ?: array());
    }

    public function test_a_stored_audio_file_is_transcribed_without_touching_the_worker(): void
    {
        $result = $this->transcribe(array('video_ids' => array(2)));

        $this->assertTrue($result['success']);
        $this->assertSame('Hello there. General Kenobi.', $result['data']['processed'][0]['text']);
        $this->assertNull($this->workerCall('/api/video/process'), 'Audio was extracted again for nothing.');
    }

    public function test_a_missing_id_fails_alone_and_the_batch_survives(): void
    {
        $result = $this->transcribe(array('video_ids' => array(1, 99)));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['total']);
        $this->assertSame(1, $result['data']['successful']);
        $this->assertSame(1, $result['data']['failed']);
        $this->assertSame(1, $result['data']['processed'][0]['video_id']);
        $this->assertSame(99, $result['data']['errors'][0]['video_id']);
        $this->assertStringContainsString('No stored file with id 99', $result['data']['errors'][0]['message']);
    }

    public function test_dry_run_probes_the_duration_and_spends_nothing(): void
    {
        $result = $this->transcribe(array('video_ids' => array(1), 'dry_run' => true));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame(42.5, $result['data']['processed'][0]['duration']);
        $this->assertTrue($result['data']['processed'][0]['requires_extraction']);
        $this->assertTrue($result['data']['processed'][0]['audio_present']);

        $this->assertNull($this->openAiCall(), 'A dry run called the paid provider.');
        $this->assertNull($this->workerCall('/api/video/process'), 'A dry run extracted audio.');
    }

    public function test_segments_are_opt_in_because_they_dwarf_the_transcript(): void
    {
        $bare = $this->transcribe(array('video_ids' => array(2)));
        $full = $this->transcribe(array('video_ids' => array(2), 'include_segments' => true));

        $this->assertArrayNotHasKey('segments', $bare['data']['processed'][0]);
        $this->assertCount(2, $full['data']['processed'][0]['segments']);
        $this->assertSame(1.0, $full['data']['processed'][0]['segments'][0]['start']);
    }

    public function test_language_auto_omits_the_hint_so_the_provider_detects(): void
    {
        $this->transcribe(array('video_ids' => array(2), 'language' => 'auto'));

        $this->assertNull(
            $this->field($this->openAiCall(), 'language'),
            'An always-sent language decodes Spanish audio as English.',
        );
    }

    public function test_no_ids_is_a_validation_failure(): void
    {
        $result = $this->app->make(TranscribeVideoTool::class)->execute(array('video_ids' => array()));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('At least one video id is required.', $result->getMessage());
    }

    public function test_a_missing_key_is_named_rather_than_attempted(): void
    {
        config(array('services.openai.key' => null));

        $result = $this->app->make(TranscribeVideoTool::class)->execute(array('video_ids' => array(2)));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('No OpenAI API key configured.', $result->getMessage());
        $this->assertNull($this->openAiCall(), 'A host with no key called the provider anyway.');
    }

    // ─── generate_video_captions ─────────────────────────────────────────

    public function test_srt_captions_come_from_the_provider_timings(): void
    {
        $result = $this->captions(array('file_id' => 1));

        $this->assertTrue($result['success']);
        $this->assertSame('srt', $result['data']['format']);
        $this->assertSame('provider', $result['data']['timings']);
        $this->assertSame(2, $result['data']['cue_count']);
        $this->assertStringStartsWith("1\n00:00:01,000 --> 00:00:02,000\nHello there.", $result['data']['captions']);
    }

    public function test_the_subtitle_is_stored_with_the_type_its_bytes_have(): void
    {
        $result = $this->captions(array('file_id' => 1));

        $this->assertCount(1, $this->visibility->writes);
        $this->assertSame('application/x-subrip', $this->visibility->writes[0]['mime']);
        $this->assertArrayHasKey('media', $result['data']);
        $this->assertSame('application/x-subrip', $result['data']['media'][0]['mime_type']);
    }

    public function test_webvtt_escapes_the_characters_the_format_forbids(): void
    {
        $this->transcript = array(
            'text'     => 'R&D',
            'language' => 'en',
            'duration' => 2.0,
            'segments' => array(array('start' => 0.0, 'end' => 1.0, 'text' => 'R&D')),
        );

        $result = $this->captions(array('file_id' => 1, 'format' => 'vtt'));

        $this->assertStringStartsWith("WEBVTT\n\n", $result['data']['captions']);
        $this->assertStringContainsString('R&amp;D', $result['data']['captions']);
        $this->assertSame('text/vtt', $this->visibility->writes[0]['mime']);
    }

    public function test_ass_is_returned_as_text_with_the_reason_it_was_not_stored(): void
    {
        $result = $this->captions(array('file_id' => 1, 'format' => 'ass'));

        $this->assertStringContainsString('[Script Info]', $result['data']['captions']);
        $this->assertStringContainsString('Dialogue: 0,0:00:01.00,0:00:02.00', $result['data']['captions']);
        $this->assertArrayHasKey('media_skipped', $result['data']);
        $this->assertSame(array(), $this->visibility->writes);
    }

    public function test_auto_sync_off_reflows_one_cue_per_sentence(): void
    {
        // The provider's two segments are ignored; the two spoken sentences are not.
        $result = $this->captions(array('file_id' => 1, 'auto_sync' => false));

        $this->assertSame('proportional', $result['data']['timings']);
        $this->assertSame(2, $result['data']['cue_count']);
        $this->assertStringStartsWith("1\n00:00:00,000 -->", $result['data']['captions']);
        $this->assertStringContainsString('Hello there.', $result['data']['captions']);
        $this->assertStringContainsString('General Kenobi.', $result['data']['captions']);
    }

    public function test_the_wordpress_parameter_name_is_accepted(): void
    {
        $result = $this->captions(array('video_id' => 1));

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Hello there.', $result['data']['captions']);
    }

    public function test_an_unknown_caption_format_is_refused_with_the_list(): void
    {
        $result = $this->app->make(GenerateVideoCaptionsTool::class)
            ->execute(array('file_id' => 1, 'format' => 'txt'));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('The caption format must be one of: srt, vtt, ass.', $result->getMessage());
    }

    public function test_a_host_without_ffmpeg_refuses_before_uploading(): void
    {
        $this->capabilities = array('video_processing' => false);
        Cache::flush();

        $result = $this->app->make(GenerateVideoCaptionsTool::class)->execute(array('file_id' => 1));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('cannot extract audio from video', $result->getMessage());
        $this->assertNull($this->workerCall('/api/video/process'), 'no upload is spent before the refusal');
    }

    public function test_a_file_that_does_not_exist_is_named(): void
    {
        $result = $this->app->make(GenerateVideoCaptionsTool::class)->execute(array('file_id' => 99));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('No stored file with id 99.', $result->getMessage());
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function transcribe(array $arguments): array
    {
        $result = $this->app->make(TranscribeVideoTool::class)->execute($arguments);

        $this->assertIsArray($result, 'The tool returned an error: '.(is_object($result) ? $result->getMessage() : print_r($result, true)));

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function captions(array $arguments): array
    {
        $result = $this->app->make(GenerateVideoCaptionsTool::class)->execute($arguments);

        $this->assertIsArray($result, 'The tool returned an error: '.(is_object($result) ? $result->getMessage() : print_r($result, true)));

        return $result;
    }

    /**
     * Fake the worker and the provider, reading the mutable state at call time.
     *
     * Registered once rather than per test: Laravel stacks stub callbacks, and a
     * second `Http::fake()` would sit behind the first and never run.
     */
    private function fakeWorkerAndProvider(): void
    {
        Http::fake(function (ClientRequest $request) {
            $url  = $request->url();
            $json = array('Content-Type' => 'application/json');

            if (str_contains($url, 'api.openai.com')) {
                $this->openAiCalls[] = array('url' => $url, 'data' => $request->data());

                return Http::response($this->transcript, 200, $json);
            }

            if (str_contains($url, '/api/health')) {
                return Http::response(array('status' => 'ok', 'capabilities' => $this->capabilities), 200, $json);
            }

            if (str_contains($url, '/api/video/download/')) {
                return Http::response('AUDIO-BYTES', 200, array('Content-Type' => 'audio/mpeg'));
            }

            if (str_contains($url, '/api/video/info')) {
                $this->workerCalls[] = array('url' => $url, 'data' => $request->data());

                return Http::response(array(
                    'success'  => true,
                    'format'   => 'mov,mp4,m4a,3gp,3g2,mj2',
                    'duration' => 42.5,
                    'size'     => 1048576,
                    'bitrate'  => 197000,
                    'video'    => array(
                        'codec' => 'h264',
                        'width' => 1920,
                        'height' => 1080,
                        'fps' => 30.0,
                        'bitrate' => 180000,
                        'pixel_format' => 'yuv420p',
                    ),
                    'audio'    => array(
                        'codec' => 'aac',
                        'channels' => 2,
                        'sample_rate' => 48000,
                        'bitrate' => 128000,
                    ),
                ), 200, $json);
            }

            $this->workerCalls[] = array('url' => $url, 'data' => $request->data());

            return Http::response(array(
                'success'         => true,
                'operation'       => 'extract_audio',
                'original_size'   => 1048576,
                'output_size'     => 524288,
                'savings_percent' => '50.0%',
                'output_file'     => 'processed_1700000000.mp3',
            ), 200, $json);
        });
    }

    /**
     * @return array{url: string, data: mixed}|null
     */
    private function workerCall(string $fragment): ?array
    {
        foreach ($this->workerCalls as $call) {
            if (str_contains($call['url'], $fragment)) {
                return $call;
            }
        }

        return null;
    }

    /**
     * @return array{url: string, data: mixed}|null
     */
    private function openAiCall(): ?array
    {
        return $this->openAiCalls[0] ?? null;
    }

    /**
     * @param array{url: string, data: mixed}|null $call
     */
    private function field(?array $call, string $name): ?string
    {
        foreach ((array) ($call['data'] ?? array()) as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name) {
                $contents = $part['contents'] ?? null;

                return is_scalar($contents) ? (string) $contents : null;
            }
        }

        return null;
    }

    /**
     * @param array{url: string, data: mixed}|null $call
     *
     * @return list<string>
     */
    private function partNames(?array $call): array
    {
        $names = array();

        foreach ((array) ($call['data'] ?? array()) as $part) {
            if (is_array($part) && isset($part['name'])) {
                $names[] = (string) $part['name'];
            }
        }

        return $names;
    }

    /**
     * Two files the tools can resolve, and one id they cannot.
     */
    private function fakeStores(): void
    {
        $this->visibility = new class implements FileVisibilityStoreInterface {
            /** @var list<array{name: string, mime: string}> */
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

        $map = array(
            1 => array('path' => $this->workDir.'/source.mp4', 'mime' => 'video/mp4', 'name' => 'source.mp4'),
            2 => array('path' => $this->workDir.'/speech.mp3', 'mime' => 'audio/mpeg', 'name' => 'speech.mp3'),
        );

        $files = new class($map) implements FileStoreInterface {
            /**
             * @param array<int, array{path: string, mime: string, name: string}> $map
             */
            public function __construct(private readonly array $map)
            {
            }

            public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                return $this->getMetadata(1) ?? throw new \RuntimeException('no file');
            }

            public function getPath(int $fileId): ?string
            {
                return $this->map[$fileId]['path'] ?? null;
            }

            public function getMetadata(int $fileId): ?StoredFile
            {
                $row = $this->map[$fileId] ?? null;

                if (null === $row) {
                    return null;
                }

                return new StoredFile(
                    id: $fileId,
                    filename: $row['name'],
                    mimeType: $row['mime'],
                    sizeBytes: is_file($row['path']) ? (int) filesize($row['path']) : 1,
                    localPath: $row['path'],
                    ownerId: 7,
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                );
            }

            public function userCanAccess(int $fileId, int $userId): bool
            {
                return isset($this->map[$fileId]);
            }

            public function delete(int $fileId): void
            {
            }

            public function findByMetadata(array $criteria, int $limit = 50): array
            {
                return array();
            }
        };

        $this->app->instance(FileVisibilityStoreInterface::class, $this->visibility);
        $this->app->instance(FileStoreInterface::class, $files);
    }
}
