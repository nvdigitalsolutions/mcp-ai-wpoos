<?php

/**
 * The three vision tools that read one image and return text.
 *
 * `analyze_image` is covered separately; what is pinned here is where these three
 * differ from it and from each other: an empty answer that is a **finding** for
 * OCR and alt-text rather than a failure, line structure that survives when the
 * caller asked for it, and the decorative-image sentinel.
 *
 * The image plumbing itself is `ImageInput`'s and is exercised through
 * `analyze_image`'s suite; one not-found case here proves these tools are wired to
 * it.
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
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Nvoos\Core\Tool\ExtractImageTextCompletionTool;
use Nvoos\Core\Tool\GenerateImageAltTextCompletionTool;
use Nvoos\Core\Tool\GenerateImageCaptionCompletionTool;
use PHPUnit\Framework\TestCase;

final class VisionImageToolsTest extends TestCase
{
    /** A 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => $value instanceof \Throwable
                || (\is_array($value) && isset($value['error']))
        );
    }

    public function test_every_tool_declares_its_slug_capability_and_flags(): void
    {
        $expected = array(
            'extract_image_text'     => array( ExtractImageTextCompletionTool::class, 'upload_files' ),
            'generate_image_alt_text' => array( GenerateImageAltTextCompletionTool::class, 'edit_posts' ),
            'generate_image_caption'  => array( GenerateImageCaptionCompletionTool::class, 'edit_posts' ),
        );

        foreach ($expected as $slug => [$class, $capability]) {
            $tool = new $class($this->errors);

            $this->assertSame($slug, $tool->getSlug());
            $this->assertSame($capability, $tool->getRequiredCapability(), "Wrong capability for {$slug}");

            $this->assertContains('requires-vision-model', $tool->getCapabilityFlags(), $slug);
            $this->assertContains('read-only', $tool->getCapabilityFlags(), $slug);
            $this->assertNotContains('local-only', $tool->getCapabilityFlags(), $slug);
        }
    }

    public function test_an_image_is_required_before_any_provider_call(): void
    {
        $this->errors->expects($this->exactly(3))
            ->method('validationFailed')
            ->with($this->stringContains('An image is required'))
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $router = $this->createMock(ProviderRouter::class);
        $router->expects($this->never())->method('resolveForChat');

        $tools = array(
            new ExtractImageTextCompletionTool($this->errors, $router),
            new GenerateImageAltTextCompletionTool($this->errors, $router),
            new GenerateImageCaptionCompletionTool($this->errors, $router),
        );

        foreach ($tools as $tool) {
            $result = $tool->execute(array());

            $this->assertSame('validation_failed', $result['error']['code']);
        }
    }

    public function test_a_missing_file_is_not_found(): void
    {
        $files = $this->createMock(FileStoreInterface::class);
        $files->method('getMetadata')->willReturn(null);

        $this->errors->expects($this->once())
            ->method('notFound')
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'not_found' ) ));

        $result = $this->tool(ExtractImageTextCompletionTool::class, $files)
            ->execute(array( 'file_id' => 7 ));

        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_an_image_with_no_text_is_a_result_not_a_failure(): void
    {
        $result = $this->tool(ExtractImageTextCompletionTool::class, null, '')
            ->execute($this->imageArguments());

        $this->assertTrue($result['success'], 'OCR of an image with no text must succeed.');
        $this->assertFalse($result['data']['has_text']);
        $this->assertSame('', $result['data']['text']);
        $this->assertSame(0, $result['data']['character_count']);
    }

    public function test_preserving_layout_keeps_the_line_breaks(): void
    {
        $result = $this->tool(ExtractImageTextCompletionTool::class, null, "Invoice\nTotal: 12.00")
            ->execute($this->imageArguments(array( 'preserve_layout' => true )));

        $this->assertTrue($result['data']['has_text']);
        $this->assertStringContainsString("\n", $result['data']['text'], 'Layout was requested but the lines were collapsed.');
        $this->assertSame("Invoice\nTotal: 12.00", $result['data']['text']);
    }

    public function test_without_layout_the_text_is_one_run(): void
    {
        $result = $this->tool(ExtractImageTextCompletionTool::class, null, "Invoice\nTotal: 12.00")
            ->execute($this->imageArguments());

        // The layout flag is reported as applied, and false here means collapsed.
        $this->assertFalse($result['data']['preserve_layout']);
        $this->assertSame('Invoice Total: 12.00', $result['data']['text']);
    }

    public function test_a_decorative_image_gets_empty_alt_text(): void
    {
        $result = $this->tool(GenerateImageAltTextCompletionTool::class, null, '[DECORATIVE]')
            ->execute($this->imageArguments());

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['decorative']);

        // Empty, not a description of the image: that is what a decorative image
        // needs, and a screen reader skips an empty alt.
        $this->assertSame('', $result['data']['alt_text']);
        $this->assertSame(0, $result['data']['characters']);
    }

    public function test_over_long_alt_text_is_reported_not_truncated(): void
    {
        $long = \str_repeat('a', 130);

        $result = $this->tool(GenerateImageAltTextCompletionTool::class, null, '"' . $long . '"')
            ->execute($this->imageArguments());

        $this->assertFalse($result['data']['decorative']);
        $this->assertFalse($result['data']['within_recommended_length']);
        $this->assertSame(130, $result['data']['characters']);

        // Not shortened: the caller decides whether to ask again.
        $this->assertSame($long, $result['data']['alt_text']);
        $this->assertSame(125, $result['data']['recommended_max']);
    }

    public function test_a_caption_that_only_contained_framing_is_an_error(): void
    {
        // Non-empty on the wire, empty once the quotes are stripped — so this
        // reaches the tool's own check rather than the base's empty-answer guard.
        $this->errors->expects($this->once())
            ->method('create')
            ->with('caption_empty', $this->anything())
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'caption_empty' ) ));

        $result = $this->tool(GenerateImageCaptionCompletionTool::class, null, '""')
            ->execute($this->imageArguments());

        $this->assertSame('caption_empty', $result['error']['code']);
    }

    public function test_a_caption_is_returned_with_its_length(): void
    {
        $result = $this->tool(GenerateImageCaptionCompletionTool::class, null, 'A customer tries the new till for the first time.')
            ->execute($this->imageArguments(array( 'context' => 'A retail launch post.' )));

        $this->assertTrue($result['success']);
        $this->assertSame('A customer tries the new till for the first time.', $result['data']['caption']);
        $this->assertSame(\mb_strlen($result['data']['caption']), $result['data']['characters']);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function imageArguments(array $extra = array()): array
    {
        return $extra + array( 'image_data' => 'data:image/png;base64,' . self::PNG );
    }

    /**
     * @param class-string $class
     */
    private function tool(string $class, ?FileStoreInterface $files = null, mixed $answer = 'ok'): mixed
    {
        return new $class($this->errors, $this->router($this->client($answer)), $files);
    }

    private function router(?AbstractProviderClient $client): ProviderRouter
    {
        $router = $this->createMock(ProviderRouter::class);
        $router->method('resolveForChat')->willReturn($client);
        $router->method('getRegisteredSlugs')->willReturn(array( 'openai' ));

        return $router;
    }

    private function client(mixed $answer): AbstractProviderClient
    {
        $client = $this->createMock(AbstractProviderClient::class);
        $client->method('getProviderSlug')->willReturn('openai');
        $client->method('chat')->willReturn(array(
            'model'   => 'gpt-4o',
            'choices' => array( array( 'message' => array( 'content' => $answer ) ) ),
            'usage'   => array( 'total_tokens' => 9 ),
        ));

        return $client;
    }
}
