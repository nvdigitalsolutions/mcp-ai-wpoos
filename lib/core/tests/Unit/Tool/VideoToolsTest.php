<?php

/**
 * `analyze_video` and `generate_video_caption` — the shared video path.
 *
 * Four things carry the weight:
 *
 * - a non-video is refused **before** a completion is spent, so a PDF cannot be
 *   described to the model as a video;
 * - the bytes leave as a `video/…` data URI, because that is what the provider needs in
 *   order to reason about motion;
 * - an oversized video is refused with both sizes rather than sent and rejected;
 * - a caption is bounded here, not merely requested in the prompt, and says when it was
 *   cut.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Nvoos\Core\Tool\AnalyzeVideoCompletionTool;
use Nvoos\Core\Tool\GenerateVideoCaptionCompletionTool;
use PHPUnit\Framework\TestCase;

final class VideoToolsTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    /** @var list<string> */
    private array $temporary = array();

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => $value instanceof \Throwable
                || ( \is_array($value) && isset($value['error']) )
        );

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array( 'code' => $code, 'message' => $message ),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array( 'code' => 'validation_failed', 'message' => $message ),
            )
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (\is_file($path)) {
                \unlink($path);
            }
        }

        $this->temporary = array();
    }

    // ── Shape ────────────────────────────────────────────────────────────────

    public function test_both_tools_declare_their_slug_capability_and_flags(): void
    {
        $analyze = new AnalyzeVideoCompletionTool($this->errors);
        $caption = new GenerateVideoCaptionCompletionTool($this->errors);

        $this->assertSame('analyze_video', $analyze->getSlug());
        $this->assertSame('generate_video_caption', $caption->getSlug());
        $this->assertSame('read', $analyze->getRequiredCapability());
        $this->assertContains('read-only', $analyze->getCapabilityFlags());
        $this->assertContains('consumes-tokens', $analyze->getCapabilityFlags());
        $this->assertNotContains('write', $analyze->getCapabilityFlags());
    }

    // ── Shared validation ────────────────────────────────────────────────────

    public function test_a_file_id_is_required_and_remote_urls_are_named_as_unsupported(): void
    {
        $result = $this->analyze($this->store(null), '')
            ->execute(array());

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('does not fetch remote URLs', $result['error']['message']);
    }

    public function test_a_host_without_a_file_store_says_so(): void
    {
        $result = (new AnalyzeVideoCompletionTool($this->errors))
            ->execute(array( 'file_id' => 1 ));

        $this->assertSame('file_store_unavailable', $result['error']['code']);
    }

    public function test_a_missing_file_is_named(): void
    {
        $result = $this->analyze($this->store(null), '')->execute(array( 'file_id' => 42 ));

        $this->assertSame('file_not_found', $result['error']['code']);
    }

    public function test_a_file_the_caller_may_not_read_answers_like_a_missing_one(): void
    {
        $file = $this->file('video/mp4', 'binary');

        $result = $this->analyze($this->store($file, false), '')
            ->execute(array( 'file_id' => 1 ), array( 'user_id' => 9 ));

        $this->assertSame('file_not_found', $result['error']['code']);
    }

    public function test_a_non_video_is_refused_before_any_completion(): void
    {
        $file = $this->file('application/pdf', 'not a video');

        $captured = array();

        $result = $this->analyze($this->store($file), 'anything', $captured)
            ->execute(array( 'file_id' => 1 ));

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('application/pdf', $result['error']['message']);
        $this->assertSame(array(), $captured, 'A refusal must not spend a completion.');
    }

    public function test_an_oversized_video_is_refused_with_both_sizes(): void
    {
        $file = $this->file('video/mp4', \str_repeat('x', 12000001));

        $result = $this->analyze($this->store($file), '')->execute(array( 'file_id' => 1 ));

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('11.4 MB', $result['error']['message']);
        $this->assertStringContainsString('12000000-byte ceiling', $result['error']['message']);
    }

    public function test_the_bytes_leave_as_a_video_data_uri(): void
    {
        $file = $this->file('video/mp4', 'abcdef');

        $captured = array();

        $this->analyze($this->store($file), '{"x":1}', $captured)
            ->execute(array( 'file_id' => 1 ));

        $this->assertNotEmpty($captured);

        $content = $captured[0][0]['content'];

        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('image_url', $content[1]['type']);
        $this->assertStringStartsWith(
            'data:video/mp4;base64,',
            $content[1]['image_url']['url']
        );
    }

    // ── analyze_video ────────────────────────────────────────────────────────

    public function test_the_analysis_type_changes_what_is_asked(): void
    {
        $file = $this->file('video/mp4', 'abcdef');

        $captured = array();

        $this->analyze($this->store($file), '{"x":1}', $captured)
            ->execute(array( 'file_id' => 1, 'analysis_type' => 'scene_breakdown' ));

        $prompt = $captured[0][0]['content'][0]['text'];

        $this->assertStringContainsString('Break it into scenes', $prompt);
        $this->assertStringNotContainsString('approximate timestamps', $prompt);
    }

    public function test_timestamps_are_only_asked_for_when_requested(): void
    {
        $file = $this->file('video/mp4', 'abcdef');

        $captured = array();

        $this->analyze($this->store($file), '{"x":1}', $captured)
            ->execute(array( 'file_id' => 1, 'include_timestamps' => true ));

        $prompt = $captured[0][0]['content'][0]['text'];

        $this->assertStringContainsString('approximate timestamps', $prompt);
    }

    public function test_the_analysis_is_returned_with_its_type(): void
    {
        $file = $this->file('video/mp4', 'abcdef');

        $result = $this->analyze($this->store($file), "A cat crosses the kitchen.\n")
            ->execute(array( 'file_id' => 1, 'analysis_type' => 'timeline' ));

        $this->assertTrue($result['success']);
        $this->assertSame("A cat crosses the kitchen.\n", $result['data']['analysis']);
        $this->assertSame('timeline', $result['data']['analysis_type']);
        $this->assertSame('video.mp4', $result['data']['filename']);
    }

    // ── generate_video_caption ───────────────────────────────────────────────

    public function test_a_long_caption_is_truncated_and_says_so(): void
    {
        $file = $this->file('video/mp4', 'abcdef');

        $result = $this->caption($this->store($file), \str_repeat('word ', 100))
            ->execute(array( 'file_id' => 1, 'max_length' => 50 ));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['truncated']);
        $this->assertSame(50, \mb_strlen($result['data']['caption']));
        $this->assertSame(50, $result['data']['max_length']);
        $this->assertStringContainsString('shortened', $result['message']);
    }

    public function test_a_short_caption_is_untouched(): void
    {
        $file = $this->file('video/mp4', 'abcdef');

        $result = $this->caption($this->store($file), 'A cat crosses a kitchen.')
            ->execute(array( 'file_id' => 1 ));

        $this->assertFalse($result['data']['truncated']);
        $this->assertSame('A cat crosses a kitchen.', $result['data']['caption']);
    }

    public function test_surrounding_quotes_are_stripped(): void
    {
        $file = $this->file('video/mp4', 'abcdef');

        $result = $this->caption($this->store($file), '"A cat crosses a kitchen."')
            ->execute(array( 'file_id' => 1 ));

        $this->assertSame('A cat crosses a kitchen.', $result['data']['caption']);
    }

    public function test_the_max_length_is_clamped_to_the_supported_range(): void
    {
        $file = $this->file('video/mp4', 'abcdef');

        $result = $this->caption($this->store($file), 'short')
            ->execute(array( 'file_id' => 1, 'max_length' => 10 ));

        $this->assertSame(50, $result['data']['max_length']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function analyze(FileStoreInterface $store, string $answer, array &$captured = array()): AnalyzeVideoCompletionTool
    {
        return new AnalyzeVideoCompletionTool(
            $this->errors,
            $this->router($this->client($answer, $captured)),
            $store
        );
    }

    private function caption(FileStoreInterface $store, string $answer): GenerateVideoCaptionCompletionTool
    {
        return new GenerateVideoCaptionCompletionTool(
            $this->errors,
            $this->router($this->client($answer)),
            $store
        );
    }

    private function file(string $mime, string $bytes): StoredFile
    {
        $path = \tempnam(\sys_get_temp_dir(), 'vid_');

        $this->assertNotFalse($path, 'Could not create a temporary file.');

        \file_put_contents($path, $bytes);

        $this->temporary[] = $path;

        return new StoredFile(
            id: 1,
            filename: 'video.mp4',
            mimeType: $mime,
            sizeBytes: \strlen($bytes),
            localPath: $path,
            ownerId: 3,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function store(?StoredFile $file, bool $mayAccess = true): FileStoreInterface
    {
        $store = $this->createMock(FileStoreInterface::class);

        $store->method('getMetadata')->willReturnCallback(
            static fn (int $id): ?StoredFile => null !== $file && 1 === $id ? $file : null
        );

        $store->method('getPath')->willReturnCallback(
            static fn (int $id): ?string => null !== $file && 1 === $id ? $file->localPath : null
        );

        $store->method('userCanAccess')->willReturn($mayAccess);

        return $store;
    }

    private function router(?AbstractProviderClient $client): ProviderRouter
    {
        $router = $this->createMock(ProviderRouter::class);
        $router->method('resolveForChat')->willReturn($client);
        $router->method('getRegisteredSlugs')->willReturn(array( 'gemini' ));

        return $router;
    }

    /**
     * @param array<int, mixed> $captured
     */
    private function client(string $answer, array &$captured = array()): AbstractProviderClient
    {
        $client = $this->createMock(AbstractProviderClient::class);
        $client->method('getProviderSlug')->willReturn('gemini');
        $client->method('chat')->willReturnCallback(
            static function (array $messages) use ($answer, &$captured): mixed {
                $captured[] = $messages;

                return array(
                    'model'   => 'gemini-test',
                    'choices' => array( array( 'message' => array( 'content' => $answer ) ) ),
                    'usage'   => array( 'total_tokens' => 12 ),
                );
            }
        );

        return $client;
    }
}
