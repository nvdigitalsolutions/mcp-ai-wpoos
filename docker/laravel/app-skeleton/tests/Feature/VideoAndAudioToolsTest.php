<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\QueueClientInterface;
use Nvoos\Core\Domain\Entity\JobStatus;
use Nvoos\Core\Tool\CheckVideoStatusTool;
use Nvoos\Core\Tool\EditOmniVideoTool;
use Nvoos\Core\Tool\GenerateHiggsfieldVideoTool;
use Nvoos\Core\Tool\GenerateMusicTool;
use Nvoos\Core\Tool\GenerateOmniVideoTool;
use Nvoos\Core\Tool\GenerateOpenAISpeechTool;
use Nvoos\Core\Tool\GenerateSoraVideoTool;
use Nvoos\Core\Tool\GenerateVeoVideoTool;
use Nvoos\Core\Tool\TranscribeOpenAIAudioTool;
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
}
