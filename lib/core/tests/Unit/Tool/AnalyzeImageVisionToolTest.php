<?php

/**
 * The image-understanding tool.
 *
 * The router and the file registry are mocks, so what is pinned here is the
 * tool's own work: the two image sources, the refusal of everything else, the
 * exact multimodal message handed to the client, and the two failure classes
 * (a provider refusal vs an empty answer).
 *
 * The Gemini/Anthetic conversions are the clients' job and are tested where they
 * live; this file only asserts that the tool emits the OpenAI shape they both
 * understand, with the media type the bytes actually are.
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
use Nvoos\Core\Tool\AnalyzeImageVisionTool;
use PHPUnit\Framework\TestCase;

final class AnalyzeImageVisionToolTest extends TestCase
{
    /** A 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private ErrorFactoryInterface $errors;

    /** @var list<string> */
    private array $tempFiles = array();

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        // Mirror how the real factories answer: the Laravel one says "is this a
        // Throwable", the core router also treats an `error` array as one. The
        // tool must branch on isError() rather than on the value's type, so the
        // mock has to be able to say yes to both shapes.
        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => $value instanceof \Throwable
                || (\is_array($value) && isset($value['error']))
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @\unlink($path);
        }

        parent::tearDown();
    }

    public function test_it_sends_the_prompt_and_the_image_in_the_openai_shape(): void
    {
        $captured = array();

        $client = $this->client(array(
            'id'      => 'chatcmpl-1',
            'model'   => 'gpt-4o',
            'choices' => array( array( 'message' => array( 'role' => 'assistant', 'content' => 'A single dark pixel.' ) ) ),
            'usage'   => array( 'total_tokens' => 42 ),
        ), $captured);

        $result = $this->tool($this->routerWith($client))->execute(array(
            'image_data' => 'data:image/png;base64,' . self::PNG,
            'prompt'     => 'What is in this image?',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('A single dark pixel.', $result['data']['text']);
        $this->assertSame('openai', $result['data']['provider']);
        $this->assertSame(42, $result['data']['usage']['total_tokens']);
        $this->assertSame('image/png', $result['data']['mime']);

        $messages = $captured[0];

        $this->assertCount(1, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('What is in this image?', $messages[0]['content'][0]['text']);
        $this->assertSame('image_url', $messages[0]['content'][1]['type']);

        // The declared media type survives into the data URI: a PNG sent to
        // Gemini as image/jpeg is a confident wrong answer waiting to happen.
        $this->assertStringStartsWith('data:image/png;base64,', $messages[0]['content'][1]['image_url']['url']);
    }

    public function test_the_wordpress_default_prompt_is_used_when_none_is_given(): void
    {
        $captured = array();

        $this->tool($this->routerWith($this->client($this->answer('ok'), $captured)))->execute(array(
            'image_data' => 'data:image/png;base64,' . self::PNG,
        ));

        $this->assertSame(
            'Describe this image in detail, including objects, people, text, colors, and composition.',
            $captured[0][0]['content'][0]['text']
        );
    }

    public function test_a_remote_url_is_refused_and_names_both_alternatives(): void
    {
        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with($this->logicalAnd(
                $this->stringContains('not fetched'),
                $this->stringContains('file_id')
            ))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($this->routerWith($this->client()))->execute(array(
            'image_data' => 'https://example.test/cat.png',
        ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_exactly_one_source_is_required(): void
    {
        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with($this->stringContains('not both'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($this->routerWith($this->client()))->execute(array(
            'file_id'    => 3,
            'image_data' => 'data:image/png;base64,' . self::PNG,
        ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_no_source_is_a_validation_failure_that_mentions_the_url_rule(): void
    {
        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with($this->stringContains('does not fetch remote URLs'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($this->routerWith($this->client()))->execute(array());

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_registry_image_is_read_and_its_own_mime_type_is_used(): void
    {
        $path = $this->tempImage((string) \base64_decode(self::PNG, true));

        $files = $this->createMock(FileStoreInterface::class);
        $files->method('getMetadata')->with(7)->willReturn($this->stored(7, 'image/webp', $path));
        $files->method('userCanAccess')->with(7, 5)->willReturn(true);

        $captured = array();

        $result = $this->tool($this->routerWith($this->client($this->answer('done'), $captured)), $files)
            ->execute(array( 'file_id' => 7 ), array( 'user_id' => 5 ));

        $this->assertTrue($result['success']);
        $this->assertSame('image/webp', $result['data']['mime']);
        $this->assertStringStartsWith('data:image/webp;base64,', $captured[0][0]['content'][1]['image_url']['url']);
    }

    public function test_a_file_the_caller_cannot_access_is_not_found_rather_than_forbidden(): void
    {
        $path = $this->tempImage('x');

        $files = $this->createMock(FileStoreInterface::class);
        $files->method('getMetadata')->willReturn($this->stored(7, 'image/png', $path));
        $files->method('userCanAccess')->willReturn(false);

        $this->errors->expects($this->once())
            ->method('notFound')
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'not_found' ) ));

        $result = $this->tool($this->routerWith($this->client()), $files)
            ->execute(array( 'file_id' => 7 ), array( 'user_id' => 5 ));

        // 404, not 403: whether the row exists for someone else is not something
        // a caller should be able to learn from the status.
        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_a_non_image_file_is_refused(): void
    {
        $path = $this->tempImage('x');

        $files = $this->createMock(FileStoreInterface::class);
        $files->method('getMetadata')->willReturn($this->stored(7, 'application/pdf', $path));

        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with($this->stringContains('not an image'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($this->routerWith($this->client()), $files)->execute(array( 'file_id' => 7 ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_host_without_a_file_registry_says_so(): void
    {
        $this->errors->expects($this->once())
            ->method('create')
            ->with('file_store_unavailable', $this->stringContains('image_data'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'file_store_unavailable' ) ));

        $result = $this->tool($this->routerWith($this->client()))->execute(array( 'file_id' => 7 ));

        $this->assertSame('file_store_unavailable', $result['error']['code']);
    }

    public function test_an_oversized_data_uri_is_refused(): void
    {
        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with($this->stringContains('over the 6000000-byte limit'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($this->routerWith($this->client()))->execute(array(
            'image_data' => 'data:image/png;base64,' . \str_repeat('A', 8400000),
        ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_unknown_provider_is_named_alongside_the_registered_ones(): void
    {
        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->with($this->stringContains('openai, gemini'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool($this->routerWith(null, array( 'openai', 'gemini' )))->execute(array(
            'image_data' => 'data:image/png;base64,' . self::PNG,
            'provider'   => 'nope',
        ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_no_default_provider_is_distinct_from_an_unknown_one(): void
    {
        $this->errors->expects($this->once())
            ->method('create')
            ->with('vision_provider_unavailable', $this->stringContains('No default AI provider is configured'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'vision_provider_unavailable' ) ));

        $result = $this->tool($this->routerWith(null, array( 'openai' )))->execute(array(
            'image_data' => 'data:image/png;base64,' . self::PNG,
        ));

        $this->assertSame('vision_provider_unavailable', $result['error']['code']);
    }

    public function test_a_provider_refusal_is_surfaced_with_its_own_message(): void
    {
        $error = new \RuntimeException('This model does not support image input.');

        $this->errors->method('normalize')->willReturn(array( 'message' => $error->getMessage() ));
        $this->errors->expects($this->once())
            ->method('create')
            ->with('vision_provider_error', $this->stringContains('does not support image input'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'vision_provider_error' ) ));

        $result = $this->tool($this->routerWith($this->client($error)))->execute(array(
            'image_data' => 'data:image/png;base64,' . self::PNG,
        ));

        $this->assertSame('vision_provider_error', $result['error']['code']);
    }

    public function test_an_empty_answer_is_an_error_not_a_blank_success(): void
    {
        $this->errors->expects($this->once())
            ->method('create')
            ->with('vision_empty_response', $this->anything())
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'vision_empty_response' ) ));

        $result = $this->tool($this->routerWith($this->client($this->answer('   '))))->execute(array(
            'image_data' => 'data:image/png;base64,' . self::PNG,
        ));

        $this->assertSame('vision_empty_response', $result['error']['code']);
    }

    public function test_a_host_without_a_router_says_so(): void
    {
        $this->errors->expects($this->once())
            ->method('create')
            ->with('vision_unavailable', $this->anything())
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'vision_unavailable' ) ));

        $result = (new AnalyzeImageVisionTool($this->errors))->execute(array(
            'image_data' => 'data:image/png;base64,' . self::PNG,
        ));

        $this->assertSame('vision_unavailable', $result['error']['code']);
    }

    public function test_it_declares_the_wordpress_capability_and_network_flags(): void
    {
        $tool = $this->tool($this->routerWith($this->client()));

        $this->assertSame('upload_files', $tool->getRequiredCapability());

        $this->assertContains('read-only', $tool->getCapabilityFlags());
        $this->assertContains('external-api', $tool->getCapabilityFlags());
        $this->assertContains('consumes-tokens', $tool->getCapabilityFlags());
        $this->assertContains('requires-credentials', $tool->getCapabilityFlags());

        // It calls out, so it is not local-only; and the call is synchronous, so
        // declaring `async` would route a finished request through the queue
        // path.
        $this->assertNotContains('local-only', $tool->getCapabilityFlags());
        $this->assertNotContains('async', $tool->getCapabilityFlags());
    }

    /**
     * A router whose `resolveForChat()` answers with the given client.
     *
     * @param list<string> $registered
     */
    private function routerWith(?AbstractProviderClient $client, array $registered = array( 'openai' )): ProviderRouter
    {
        $router = $this->createMock(ProviderRouter::class);
        $router->method('resolveForChat')->willReturn($client);
        $router->method('getRegisteredSlugs')->willReturn($registered);

        return $router;
    }

    /**
     * @param array<string, mixed>|\Throwable $response
     * @param array<int, mixed>                $captured Receives the messages.
     */
    private function client(mixed $response = null, array &$captured = array()): AbstractProviderClient
    {
        $client = $this->createMock(AbstractProviderClient::class);
        $client->method('getProviderSlug')->willReturn('openai');
        $client->method('chat')->willReturnCallback(
            static function (array $messages) use ($response, &$captured): mixed {
                $captured[] = $messages;

                return $response ?? array(
                    'choices' => array( array( 'message' => array( 'content' => 'answer' ) ) ),
                );
            }
        );

        return $client;
    }

    /**
     * @return array<string, mixed>
     */
    private function answer(string $text): array
    {
        return array( 'choices' => array( array( 'message' => array( 'content' => $text ) ) ) );
    }

    private function tempImage(string $contents): string
    {
        $path = (string) \tempnam(\sys_get_temp_dir(), 'nvoos-vision-');
        \file_put_contents($path, $contents);

        $this->tempFiles[] = $path;

        return $path;
    }

    private function stored(int $id, string $mime, string $path): StoredFile
    {
        return new StoredFile(
            id: $id,
            filename: 'image',
            mimeType: $mime,
            sizeBytes: (int) \filesize($path),
            localPath: $path,
            ownerId: 5,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function tool(ProviderRouter $router, ?FileStoreInterface $files = null): AnalyzeImageVisionTool
    {
        return new AnalyzeImageVisionTool($this->errors, $router, $files);
    }
}
