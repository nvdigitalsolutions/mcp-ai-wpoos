<?php

/**
 * `generate_pdf`.
 *
 * The WordPress tool this ports could never have produced a file: it delegated
 * `content` to a `pro_pdf_document` operation that required `description`, so
 * every call answered `wp_mcp_ai_missing_description` (Proposal 164 §164.31).
 * The first job of these tests is therefore to prove the port actually renders.
 *
 * The renderer and the store are recording doubles rather than mocks: almost
 * every assertion is about the request the tool built — the page size, the
 * orientation, whether the bytes were kept — and a mock would make those
 * assertions about the mock instead.
 *
 * @package Nvoos\Core\Tests
 * @since   2.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\HtmlToPdfInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\GeneratePdfTool;
use PHPUnit\Framework\TestCase;

final class GeneratePdfToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    /** @var list<string> */
    private array $temporary = array();

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => $code, 'message' => $message),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error' => array('code' => 'validation_failed', 'message' => $message),
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
    }

    // ─── Identity ──────────────────────────────────────────────────────────

    public function test_it_declares_its_slug_capability_and_flags(): void
    {
        $tool = $this->tool($this->renderer());

        $this->assertSame('generate_pdf', $tool->getSlug());
        $this->assertSame('upload_files', $tool->getRequiredCapability());
        $this->assertContains('write', $tool->getCapabilityFlags());
    }

    /**
     * The WordPress flagship declares `requires-model` and `consumes-tokens`.
     * Nothing in this path calls a model — the engine renders HTML — so claiming
     * the gate would charge every call for a cost it never incurs.
     */
    public function test_it_does_not_claim_a_model_dependency(): void
    {
        $flags = $this->tool($this->renderer())->getCapabilityFlags();

        $this->assertNotContains('requires-model', $flags);
        $this->assertNotContains('consumes-tokens', $flags);
    }

    public function test_the_schema_requires_content(): void
    {
        $schema = $this->tool($this->renderer())->getParametersSchema();

        $this->assertSame(array('content'), $schema['required']);
        $this->assertArrayHasKey('content', $schema['properties']);
        $this->assertArrayHasKey('title', $schema['properties']);
    }

    // ─── The failure the WordPress tool had ────────────────────────────────

    public function test_content_is_rendered_rather_than_refused(): void
    {
        $renderer = $this->renderer();

        $result = $this->tool($renderer)->execute(
            array('content' => 'First paragraph.', 'title' => 'Quarterly note'),
            array('user_id' => 7),
        );

        $this->assertTrue($result['success'], 'The port must render content; the WordPress tool answered missing_description.');
        $this->assertCount(1, $renderer->calls);
        $this->assertStringContainsString('First paragraph.', $renderer->calls[0]['html']);
        $this->assertStringContainsString('Quarterly note', $renderer->calls[0]['html']);
    }

    public function test_blank_content_is_a_validation_failure(): void
    {
        $result = $this->tool($this->renderer())->execute(array('content' => '   '));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── Markup is escaped, not stripped ───────────────────────────────────

    /**
     * `stringParam()` applies `strip_tags()`, which would delete the comparison
     * in "a < b". The formatter escapes body text instead, so the characters
     * reach the page and no tag can survive.
     */
    public function test_markup_characters_survive_as_text(): void
    {
        $renderer = $this->renderer();

        $this->tool($renderer)->execute(array('content' => 'Revenue a < b <script>x</script>'), array('user_id' => 7));

        $html = $renderer->calls[0]['html'];

        $this->assertStringContainsString('a &lt; b', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    // ─── The engine ────────────────────────────────────────────────────────

    public function test_it_refuses_when_no_engine_is_bound(): void
    {
        $renderer = $this->renderer(available: false);

        $result = $this->tool($renderer)->execute(array('content' => 'Body.'), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('no_pdf_engine', $result['error']['code']);
        $this->assertSame(array(), $renderer->calls, 'An unavailable engine must not be called.');
    }

    public function test_a_renderer_failure_becomes_pdf_failed(): void
    {
        $renderer = $this->renderer(failure: 'worker exploded');

        $result = $this->tool($renderer)->execute(array('content' => 'Body.'), array('user_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('pdf_failed', $result['error']['code']);
        $this->assertStringContainsString('worker exploded', $result['error']['message']);
    }

    // ─── Storage ───────────────────────────────────────────────────────────

    public function test_it_stores_the_rendered_file_by_default(): void
    {
        $store = $this->recordingStore();

        $result = $this->tool($this->renderer(), $store)->execute(
            array('content' => 'Body.', 'title' => 'Board Memo'),
            array('user_id' => 9),
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $store->calls);
        $this->assertSame('board-memo', $store->calls[0]['filename']);
        $this->assertSame('application/pdf', $store->calls[0]['mimeType']);
        $this->assertSame(9, $store->calls[0]['userId']);
        $this->assertSame(FileVisibilityStoreInterface::VISIBILITY_PUBLIC, $store->calls[0]['visibility']);
        $this->assertSame(42, $result['data']['media'][0]['attachment_id'] ?? null);
    }

    public function test_save_to_media_false_skips_the_store(): void
    {
        $store = $this->recordingStore();

        $result = $this->tool($this->renderer(), $store)->execute(
            array('content' => 'Body.', 'save_to_media' => false),
            array('user_id' => 9),
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $store->calls);
        $this->assertArrayNotHasKey('media', $result['data']);
    }

    public function test_private_visibility_is_honoured(): void
    {
        $store = $this->recordingStore();

        $this->tool($this->renderer(), $store)->execute(
            array('content' => 'Body.', 'visibility' => 'private'),
            array('user_id' => 9),
        );

        $this->assertSame(FileVisibilityStoreInterface::VISIBILITY_PRIVATE, $store->calls[0]['visibility']);
    }

    public function test_the_payload_reports_the_page_geometry(): void
    {
        $result = $this->tool($this->renderer())->execute(
            array('content' => 'Body.', 'save_to_media' => false),
            array('user_id' => 7),
        );

        $this->assertSame('A4', $result['data']['page_size']);
        $this->assertSame('portrait', $result['data']['orientation']);
        $this->assertSame('application/pdf', $result['data']['mime_type']);
        $this->assertSame(1024, $result['data']['bytes']);
    }

    // ─── Doubles ───────────────────────────────────────────────────────────

    /**
     * @param list<string> $formats
     */
    private function renderer(
        array $formats = array('A4', 'Letter', 'Legal'),
        bool $available = true,
        ?string $failure = null,
    ): HtmlToPdfInterface {
        $path = \tempnam(\sys_get_temp_dir(), 'nvoos-generate-pdf-');
        $this->assertNotFalse($path);
        $this->temporary[] = $path;

        return new class ($formats, $available, $failure, $path) implements HtmlToPdfInterface {
            /** @var list<array{html: string, options: array<string, mixed>}> */
            public array $calls = array();

            /**
             * @param list<string> $formats
             */
            public function __construct(
                private readonly array $formats,
                private readonly bool $available,
                private readonly ?string $failure,
                private readonly string $path,
            ) {
            }

            public function render(string $html, array $options = array()): array
            {
                $this->calls[] = array('html' => $html, 'options' => $options);

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                \file_put_contents($this->path, '%PDF-1.4 test');

                return array(
                    'path' => $this->path,
                    'mime_type' => 'application/pdf',
                    'bytes' => 1024,
                    'format' => 'a4 portrait',
                );
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function getSupportedPageFormats(): array
            {
                return $this->formats;
            }
        };
    }

    private function recordingStore(): FileVisibilityStoreInterface
    {
        return new class implements FileVisibilityStoreInterface {
            /** @var list<array<string, mixed>> */
            public array $calls = array();

            public function storeWithVisibility(
                string $localPath,
                string $filename,
                string $mimeType,
                int $userId,
                string $visibility,
                ?string $tenantId = null,
            ): StoredFile {
                $this->calls[] = array(
                    'localPath' => $localPath,
                    'filename' => $filename,
                    'mimeType' => $mimeType,
                    'userId' => $userId,
                    'visibility' => $visibility,
                    'tenantId' => $tenantId,
                );

                return new StoredFile(
                    id: 42,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: 1024,
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable(),
                    publicUrl: 'private' === $visibility ? null : 'https://example.test/files/' . $filename,
                    visibility: $visibility,
                    slug: 'stored-slug',
                );
            }

            public function supportsVisibility(string $visibility): bool
            {
                return \in_array(
                    $visibility,
                    array(self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE),
                    true
                );
            }
        };
    }

    private function tool(
        HtmlToPdfInterface $pdf,
        ?FileVisibilityStoreInterface $store = null,
    ): GeneratePdfTool {
        return new GeneratePdfTool($this->errors, $pdf, $store);
    }
}
