<?php

/**
 * `pro_pdf_document`.
 *
 * Three operations over one composer, so the tests are organised by operation
 * plus the two behaviours that are easy to get wrong:
 *
 *  - **`generate` accepts `content`.** The WordPress tool required the
 *    `description` its only caller never sent, which is why `generate_pdf` never
 *    produced a file (Proposal 164 §164.31). A regression here would be silent —
 *    the tool would report a capability error that looked intentional.
 *  - **Formatting reaches the stylesheet.** The WordPress tool accepted `color`
 *    and `line_height` and then dropped them on the floor. A caller who set them
 *    got default typography and no indication.
 *
 * CSS injection is also pinned: `esc_attr()` is an attribute escaper, so the
 * WordPress formatter let a `}` in `font_family` close the rule and open one of
 * the caller's choosing. {@see HtmlFormatter} refuses those characters instead.
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
use Nvoos\Core\Tool\ProPdfDocumentTool;
use PHPUnit\Framework\TestCase;

final class ProPdfDocumentToolTest extends TestCase
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

        $this->assertSame('pro_pdf_document', $tool->getSlug());
        $this->assertSame('upload_files', $tool->getRequiredCapability());
        $this->assertNotContains('requires-model', $tool->getCapabilityFlags());
    }

    public function test_the_schema_publishes_the_three_operations(): void
    {
        $schema = $this->tool($this->renderer())->getParametersSchema();

        $this->assertSame(array('generate', 'structure', 'format'), $schema['properties']['operation']['enum']);
        $this->assertSame(array('operation'), $schema['required']);
    }

    public function test_the_page_size_enum_is_the_renderers(): void
    {
        $schema = $this->tool($this->renderer(array('A4', 'Tabloid')))->getParametersSchema();

        $this->assertSame(array('A4', 'Tabloid'), $schema['properties']['page_size']['enum']);
    }

    // ─── generate ──────────────────────────────────────────────────────────

    public function test_generate_renders_content(): void
    {
        $renderer = $this->renderer();

        $result = $this->tool($renderer)->execute(
            array('operation' => 'generate', 'content' => 'A body of text.', 'title' => 'Minutes'),
            array('user_id' => 7),
        );

        $this->assertTrue($result['success']);
        $this->assertSame('generate', $result['data']['operation']);
        $this->assertStringContainsString('A body of text.', $renderer->calls[0]['html']);
    }

    /**
     * The WordPress behaviour, kept as an explicit refusal rather than a silent
     * no-op: the engine is a deterministic formatter, so a description cannot be
     * expanded into a document here.
     */
    public function test_generate_with_only_a_description_names_the_missing_capability(): void
    {
        $renderer = $this->renderer();

        $result = $this->tool($renderer)->execute(
            array('operation' => 'generate', 'description' => 'A quarterly report about sales.'),
            array('user_id' => 7),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('no_content_generator', $result['error']['code']);
        $this->assertSame(array(), $renderer->calls);
    }

    public function test_generate_with_neither_field_is_a_validation_failure(): void
    {
        $result = $this->tool($this->renderer())->execute(array('operation' => 'generate'));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── structure ─────────────────────────────────────────────────────────

    public function test_structure_renders_headings_and_bodies(): void
    {
        $renderer = $this->renderer();

        $result = $this->tool($renderer)->execute(
            array(
                'operation' => 'structure',
                'sections' => array(
                    array('heading' => 'Introduction', 'content' => 'Opening.', 'level' => 2),
                    array('heading' => 'Detail', 'content' => 'Middle.', 'level' => 3),
                ),
            ),
            array('user_id' => 7),
        );

        $this->assertTrue($result['success']);

        $html = $renderer->calls[0]['html'];

        $this->assertStringContainsString('<h2>Introduction</h2>', $html);
        $this->assertStringContainsString('<h3>Detail</h3>', $html);
        $this->assertStringContainsString('Opening.', $html);
    }

    public function test_a_heading_level_outside_one_to_six_is_clamped(): void
    {
        $renderer = $this->renderer();

        $this->tool($renderer)->execute(
            array(
                'operation' => 'structure',
                'sections' => array(array('heading' => 'Deep', 'content' => 'x', 'level' => 9)),
            ),
            array('user_id' => 7),
        );

        $this->assertStringContainsString('<h6>Deep</h6>', $renderer->calls[0]['html']);
    }

    public function test_structure_without_sections_is_a_validation_failure(): void
    {
        $result = $this->tool($this->renderer())->execute(array('operation' => 'structure', 'content' => 'Body.'));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    /**
     * Sections are not strictly the structure operation's property: `compose()`
     * prefers them whenever present, so a caller who supplied them must never be
     * told there was nothing to render.
     */
    public function test_sections_are_accepted_by_the_flat_operations(): void
    {
        $renderer = $this->renderer();

        $result = $this->tool($renderer)->execute(
            array(
                'operation' => 'generate',
                'sections' => array(array('heading' => 'Only', 'content' => 'A section.')),
            ),
            array('user_id' => 7),
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('<h2>Only</h2>', $renderer->calls[0]['html']);
    }

    // ─── format ────────────────────────────────────────────────────────────

    /**
     * The gap that made the WordPress tool's schema a promise it did not keep:
     * `color` and `line_height` were accepted and then ignored.
     */
    public function test_format_applies_colour_and_line_height(): void
    {
        $renderer = $this->renderer();

        $this->tool($renderer)->execute(
            array(
                'operation' => 'format',
                'content' => 'Styled.',
                'formatting' => array('color' => '#112233', 'line_height' => 2.25, 'font_size' => 14),
            ),
            array('user_id' => 7),
        );

        $html = $renderer->calls[0]['html'];

        $this->assertStringContainsString('color: #112233', $html);
        $this->assertStringContainsString('line-height: 2.25', $html);
        $this->assertStringContainsString('font-size: 14pt', $html);
    }

    /**
     * `esc_attr()` does not stop a `}` from closing the rule, so the WordPress
     * formatter allowed a stylesheet breakout through `font_family`.
     */
    public function test_a_stylesheet_breakout_in_font_family_is_refused(): void
    {
        $renderer = $this->renderer();

        $this->tool($renderer)->execute(
            array(
                'operation' => 'format',
                'content' => 'Styled.',
                'formatting' => array('font_family' => 'Arial} body{display:none'),
            ),
            array('user_id' => 7),
        );

        $html = $renderer->calls[0]['html'];

        $this->assertStringNotContainsString('body{display:none', $html);
        $this->assertStringContainsString('Arial, Helvetica, sans-serif', $html);
    }

    public function test_a_url_in_a_font_family_is_refused(): void
    {
        $renderer = $this->renderer();

        $this->tool($renderer)->execute(
            array(
                'operation' => 'format',
                'content' => 'Styled.',
                'formatting' => array('font_family' => 'x url(https://evil.test/a.css)'),
            ),
            array('user_id' => 7),
        );

        $this->assertStringNotContainsString('evil.test', $renderer->calls[0]['html']);
    }

    // ─── Operation routing ─────────────────────────────────────────────────

    public function test_an_unknown_operation_is_rejected_before_the_engine(): void
    {
        $renderer = $this->renderer();

        $result = $this->tool($renderer)->execute(array('operation' => 'shred', 'content' => 'x'));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $renderer->calls);
    }

    // ─── Page geometry ─────────────────────────────────────────────────────

    public function test_page_size_is_matched_case_insensitively(): void
    {
        $renderer = $this->renderer(array('A4', 'Letter'));

        $result = $this->tool($renderer)->execute(
            array('operation' => 'generate', 'content' => 'x', 'page_size' => 'a4'),
            array('user_id' => 7),
        );

        $this->assertSame('A4', $result['data']['page_size']);
        $this->assertSame('A4', $renderer->calls[0]['options']['format']);
    }

    public function test_an_unknown_page_size_falls_back_rather_than_failing(): void
    {
        $renderer = $this->renderer(array('Letter', 'Legal'));

        $result = $this->tool($renderer)->execute(
            array('operation' => 'generate', 'content' => 'x', 'page_size' => 'A0'),
            array('user_id' => 7),
        );

        $this->assertTrue($result['success']);
        $this->assertSame('Letter', $result['data']['page_size']);
    }

    public function test_landscape_is_passed_to_the_renderer(): void
    {
        $renderer = $this->renderer();

        $result = $this->tool($renderer)->execute(
            array('operation' => 'generate', 'content' => 'x', 'orientation' => 'landscape'),
            array('user_id' => 7),
        );

        $this->assertTrue($renderer->calls[0]['options']['landscape']);
        $this->assertSame('landscape', $result['data']['orientation']);
    }

    // ─── The engine ────────────────────────────────────────────────────────

    public function test_it_refuses_when_no_engine_is_bound(): void
    {
        $result = $this->tool($this->renderer(available: false))->execute(
            array('operation' => 'generate', 'content' => 'Body.'),
            array('user_id' => 7),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('no_pdf_engine', $result['error']['code']);
    }

    public function test_a_renderer_failure_becomes_pdf_failed(): void
    {
        $result = $this->tool($this->renderer(failure: 'browser crashed'))->execute(
            array('operation' => 'generate', 'content' => 'Body.'),
            array('user_id' => 7),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('pdf_failed', $result['error']['code']);
    }

    // ─── Storage ───────────────────────────────────────────────────────────

    public function test_it_stores_by_default(): void
    {
        $store = $this->recordingStore();

        $result = $this->tool($this->renderer(), $store)->execute(
            array('operation' => 'generate', 'content' => 'Body.', 'title' => 'Audit Report'),
            array('user_id' => 5),
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $store->calls);
        $this->assertSame('audit-report', $store->calls[0]['filename']);
        $this->assertSame(5, $store->calls[0]['userId']);
    }

    /**
     * The WordPress spelling of the same switch, so a request ported verbatim
     * still controls storage.
     */
    public function test_the_wordpress_upload_flag_is_honoured(): void
    {
        $store = $this->recordingStore();

        $this->tool($this->renderer(), $store)->execute(
            array('operation' => 'generate', 'content' => 'Body.', 'upload' => false),
            array('user_id' => 5),
        );

        $this->assertSame(array(), $store->calls);
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
        $path = \tempnam(\sys_get_temp_dir(), 'nvoos-pro-pdf-');
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
                    'bytes' => 2048,
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
                    id: 43,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: 2048,
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
    ): ProPdfDocumentTool {
        return new ProPdfDocumentTool($this->errors, $pdf, $store);
    }
}
