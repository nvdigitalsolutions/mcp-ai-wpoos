<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Nvoos\Core\Application\Media\AudioInput;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Entity\JobStatus;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\CheckVideoStatusTool;
use Nvoos\Core\Tool\EditOmniVideoTool;
use Nvoos\Core\Tool\GenerateHiggsfieldVideoTool;
use Nvoos\Core\Tool\GenerateMusicTool;
use Nvoos\Core\Tool\GenerateOmniVideoTool;
use Nvoos\Core\Tool\GenerateOpenAISpeechTool;
use Nvoos\Core\Tool\GenerateSoraVideoTool;
use Nvoos\Core\Tool\GenerateVeoVideoTool;
use Nvoos\Core\Tool\TranscribeOpenAIAudioTool;
use RuntimeException;
use Tests\TestCase;

/**
 * Batch 2 evidence: video and audio tools (Proposal 160 §6).
 *
 * Every provider tool here goes through `HttpClientInterface`, so `Http::fake()`
 * is the boundary fake — the same finding that closed batch 1. The one
 * exception is `check_video_status`, which reads the job queue rather than a
 * provider and is exercised against a fake `QueueClientInterface`.
 *
 * The video generators submit asynchronous jobs and return an id; they do not
 * poll. `transcribe_openai_audio` is the only tool here that fetches its input
 * first, so its test fakes the fetch and the transcription endpoint separately.
 */
class VideoAndAudioToolsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(array(
            'services.openai.key' => 'sk-test',
            'services.gemini.key' => 'gemini-test',
            'services.mubert.key' => 'mubert-test',
            'services.higgsfield.key' => 'key-id:key-secret',
        ));
    }

    /**
     * @param class-string $class
     */
    private function execute(string $class, array $arguments = array(), array $context = array()): array
    {
        $result = $this->app->make($class)->execute($arguments, $context);

        $this->assertIsArray($result, $class . ' returned a non-array result: ' . print_r($result, true));

        return $result;
    }

    // ─── Gemini video ────────────────────────────────────────────────────

    public function test_generate_omni_video_submits_a_job(): void
    {
        Http::fake(array(
            'generativelanguage.googleapis.com/*' => Http::response(array('name' => 'operations/omni-1')),
        ));

        $result = $this->execute(GenerateOmniVideoTool::class, array(
            'prompt' => 'a wave at dusk',
            'async' => true,
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('operations/omni-1', $result['data']['job_id']);
        $this->assertSame('queued', $result['data']['status']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_contains($request->url(), ':predict')
            && str_contains($request->url(), 'key=gemini-test'));
    }

    public function test_edit_omni_video_submits_a_job(): void
    {
        Http::fake(array(
            'generativelanguage.googleapis.com/*' => Http::response(array('name' => 'operations/omni-2')),
        ));

        $result = $this->execute(EditOmniVideoTool::class, array(
            'video_id' => 'vid-1',
            'prompt' => 'make it night',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('operations/omni-2', $result['data']['job_id']);
        $this->assertSame('vid-1', $result['data']['video_id']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_contains($request->url(), ':edit'));
    }

    public function test_generate_veo_video_submits_a_job(): void
    {
        Http::fake(array(
            'generativelanguage.googleapis.com/*' => Http::response(array('name' => 'operations/veo-1')),
        ));

        $result = $this->execute(GenerateVeoVideoTool::class, array(
            'prompt' => 'a kite',
            'duration' => 6,
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('operations/veo-1', $result['data']['job_id']);
        $this->assertSame(6, $result['data']['duration']);
    }

    // ─── Sora ────────────────────────────────────────────────────────────

    public function test_generate_sora_video_returns_the_job_id(): void
    {
        Http::fake(array(
            'api.openai.com/v1/video/generations' => Http::response(array(
                'id' => 'vid_123',
                'status' => 'queued',
            )),
        ));

        $result = $this->execute(GenerateSoraVideoTool::class, array('prompt' => 'a city at night'));

        $this->assertTrue($result['success']);
        $this->assertSame('vid_123', $result['data']['job_id']);
        $this->assertSame('queued', $result['data']['status']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_ends_with($request->url(), '/v1/video/generations')
            && $request->hasHeader('Authorization', 'Bearer sk-test'));
    }

    // ─── Speech, music and Higgsfield ────────────────────────────────────

    public function test_generate_openai_speech_returns_base64_audio(): void
    {
        Http::fake(array(
            'api.openai.com/v1/audio/speech' => Http::response('AUDIO', 200, array('Content-Type' => 'audio/mpeg')),
        ));

        $result = $this->execute(GenerateOpenAISpeechTool::class, array(
            'text' => 'hello there',
            'voice' => 'alloy',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(base64_encode('AUDIO'), $result['data']['audio_base64']);
        $this->assertSame(5, $result['data']['audio_bytes']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_ends_with($request->url(), '/v1/audio/speech'));
    }

    public function test_generate_music_returns_base64_audio(): void
    {
        Http::fake(array(
            'api-b2b.mubert.com/*' => Http::response(array(
                'audio' => 'MUSIC',
                'duration' => 30,
                'format' => 'wav',
            )),
        ));

        $result = $this->execute(GenerateMusicTool::class, array('prompt' => 'calm piano'));

        $this->assertTrue($result['success']);
        $this->assertSame('MUSIC', $result['data']['audio_base64']);
        $this->assertSame(30, $result['data']['duration']);

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_contains($request->url(), 'mubert.com'));
    }

    public function test_generate_higgsfield_video_returns_the_request_id(): void
    {
        Http::fake(array(
            'api.higgsfield.ai/*' => Http::response(array(
                'request_id' => 'req-1',
                'status_url' => 'https://api.higgsfield.ai/status/req-1',
            )),
        ));

        $result = $this->execute(GenerateHiggsfieldVideoTool::class, array('prompt' => 'a lighthouse'));

        $this->assertTrue($result['success']);
        $this->assertSame('req-1', $result['data']['request_id']);
        $this->assertSame('https://api.higgsfield.ai/status/req-1', $result['data']['status_url']);
    }

    // ─── Transcription ───────────────────────────────────────────────────

    public function test_transcribe_openai_audio_fetches_then_posts(): void
    {
        Http::fake(array(
            'https://audio.test/*' => Http::response('RAW-AUDIO'),
            'api.openai.com/v1/audio/transcriptions' => Http::response(array('text' => 'hello world')),
        ));

        $result = $this->execute(TranscribeOpenAIAudioTool::class, array(
            'audio_url' => 'https://audio.test/clip.mp3',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('hello world', $result['data']['text']);

        // Both legs are real requests: the input fetch and the transcription.
        Http::assertSent(static fn ($request): bool => 'GET' === $request->method()
            && 'https://audio.test/clip.mp3' === $request->url());
        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && str_ends_with($request->url(), '/v1/audio/transcriptions'));
    }

    // ─── Job status (queue, not provider) ────────────────────────────────

    public function test_check_video_status_reports_a_terminal_job(): void
    {
        $this->app->instance(QueueClientInterface::class, new class implements QueueClientInterface {
            public function enqueue(string $handler, array $payload, array $options = array()): string
            {
                return 'job-1';
            }

            public function getStatus(string $jobId): JobStatus
            {
                return new JobStatus(
                    jobId: $jobId,
                    status: 'completed',
                    result: array('video_url' => 'https://cdn.test/1.mp4'),
                    attempts: 2,
                );
            }

            public function cancel(string $jobId): bool
            {
                return true;
            }

            public function schedule(string $handler, array $payload, string $cronExpression): string
            {
                return 'sched-1';
            }

            public function unschedule(string $scheduleId): void
            {
            }

            public function listJobs(array $filters = array(), int $limit = 50): array
            {
                return array();
            }
        });

        $result = $this->execute(CheckVideoStatusTool::class, array('job_id' => 'job-1'));

        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['data']['status']);
        $this->assertSame(2, $result['data']['attempts']);
        $this->assertSame(array('video_url' => 'https://cdn.test/1.mp4'), $result['data']['result']);
    }

    // ─── Transcription sources ───────────────────────────────────────────

    /** @var array{url: string, body: string, parts: mixed}|null */
    private ?array $posted = null;

    public function test_a_stored_file_is_posted_with_its_own_name_and_type(): void
    {
        $this->registry(array(7 => array(
            'name' => 'memo.m4a',
            'mime' => 'audio/mp4',
            'bytes' => 'M4A-BYTES',
        )));

        $this->fakeTranscription();

        $result = $this->execute(TranscribeOpenAIAudioTool::class, array('file_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame('hello world', $result['data']['text']);
        $this->assertSame('memo.m4a', $result['data']['filename']);

        // The bug this replaces: no file part at all, so the provider answered 400.
        $body = (string) ($this->posted['body'] ?? '');

        $this->assertStringContainsString('name="file"; filename="memo.m4a"', $body);
        $this->assertStringContainsString("Content-Type: audio/mp4\r\n\r\nM4A-BYTES", $body);
    }

    public function test_the_wordpress_attachment_id_is_the_same_id(): void
    {
        $this->registry(array(
            7 => array('name' => 'memo.mp3', 'mime' => 'audio/mpeg', 'bytes' => 'MP3'),
            8 => array('name' => 'other.mp3', 'mime' => 'audio/mpeg', 'bytes' => 'MP3'),
        ));

        $this->fakeTranscription();

        $result = $this->execute(TranscribeOpenAIAudioTool::class, array('attachment_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertStringContainsString(
            'filename="memo.mp3"',
            (string) ($this->posted['body'] ?? ''),
            'The WordPress parameter name resolved to a different file.',
        );
    }

    public function test_two_different_ids_are_refused_rather_than_resolved_by_precedence(): void
    {
        $this->registry(array(
            7 => array('name' => 'a.mp3', 'mime' => 'audio/mpeg', 'bytes' => 'A'),
            8 => array('name' => 'b.mp3', 'mime' => 'audio/mpeg', 'bytes' => 'B'),
        ));

        Http::fake();

        $result = $this->app->make(TranscribeOpenAIAudioTool::class)
            ->execute(array('file_id' => 7, 'attachment_id' => 8));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('supply one', $result->getMessage());
    }

    public function test_a_url_and_an_id_together_are_refused(): void
    {
        Http::fake();

        $result = $this->app->make(TranscribeOpenAIAudioTool::class)
            ->execute(array('audio_url' => 'https://audio.test/clip.mp3', 'file_id' => 7));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('not both', $result->getMessage());
    }

    public function test_a_video_points_at_the_tool_that_extracts_audio(): void
    {
        $this->registry(array(7 => array(
            'name' => 'clip.mp4',
            'mime' => 'video/mp4',
            'bytes' => 'MP4-BYTES',
        )));

        Http::fake();

        $result = $this->app->make(TranscribeOpenAIAudioTool::class)->execute(array('file_id' => 7));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('transcribe_video', $result->getMessage());
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        $this->registry(array());

        Http::fake();

        $result = $this->app->make(TranscribeOpenAIAudioTool::class)->execute(array('file_id' => 99));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('No file with that id exists.', $result->getMessage());
    }

    public function test_no_source_at_all_is_a_validation_failure(): void
    {
        Http::fake();

        $result = $this->app->make(TranscribeOpenAIAudioTool::class)->execute(array());

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('audio_url, file_id or attachment_id', $result->getMessage());
    }

    public function test_a_fetched_file_is_named_from_its_url(): void
    {
        Http::fake(array(
            'https://audio.test/*' => Http::response('WAV-BYTES'),
            'api.openai.com/v1/audio/transcriptions' => Http::response(array('text' => 'ok')),
        ));

        $result = $this->execute(TranscribeOpenAIAudioTool::class, array(
            'audio_url' => 'https://audio.test/clip.wav',
        ));

        // The part used to say audio.mp3 whatever the source was.
        $this->assertSame('clip.wav', $result['data']['filename']);
    }

    public function test_a_fetched_body_over_the_ceiling_is_refused_before_the_upload(): void
    {
        Http::fake(array(
            'https://audio.test/*' => Http::response(str_repeat('a', AudioInput::MAX_BYTES + 1)),
        ));

        $result = $this->app->make(TranscribeOpenAIAudioTool::class)
            ->execute(array('audio_url' => 'https://audio.test/huge.mp3'));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString((string) AudioInput::MAX_BYTES, $result->getMessage());

        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'api.openai.com'));
    }

    // ─── Helpers for the registry and provider fakes ─────────────────────

    /**
     * Bind a file registry the tool can resolve ids from.
     *
     * @param array<int, array{name: string, mime: string, bytes: string}> $map
     */
    private function registry(array $map): void
    {
        $this->app->instance(FileStoreInterface::class, new class($map) implements FileStoreInterface {
            /**
             * @param array<int, array{name: string, mime: string, bytes: string}> $map
             */
            public function __construct(private readonly array $map)
            {
            }

            public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                throw new \RuntimeException('not used');
            }

            public function getPath(int $fileId): ?string
            {
                return $this->getMetadata($fileId)?->localPath;
            }

            public function getMetadata(int $fileId): ?StoredFile
            {
                $row = $this->map[$fileId] ?? null;

                if (null === $row) {
                    return null;
                }

                $path = sys_get_temp_dir().'/oos-audio-'.bin2hex(random_bytes(5));

                file_put_contents($path, $row['bytes']);

                return new StoredFile(
                    id: $fileId,
                    filename: $row['name'],
                    mimeType: $row['mime'],
                    sizeBytes: strlen($row['bytes']),
                    localPath: $path,
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
        });
    }

    /**
     * Fake the transcription endpoint and capture the next POST.
     *
     * The tool sends a hand-built multipart body through `HttpClientInterface`,
     * so `Request::data()` is empty here — the parts only exist in the raw body
     * Laravel was handed. Asserting on those bytes is also a stronger claim:
     * the filename and the type are on the wire, not merely in the envelope.
     */
    private function fakeTranscription(): void
    {
        $this->posted = null;

        Http::fake(function ($request) {
            $this->posted = array(
                'url'   => $request->url(),
                'body'  => (string) $request->body(),
                'parts' => $request->data(),
            );

            return Http::response(array('text' => 'hello world'));
        });
    }
}
