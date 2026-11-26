<?php

/**
 * `pro_word_document`.
 *
 * The tool adds three things `generate_word` cannot express — heading-level
 * sections, styled runs, and template scaffolds — so each operation is tested
 * for the shape it produces rather than only that it succeeded.
 *
 * Two behaviours carry the honesty rule:
 *
 *  - **A description alone is refused.** There is no model behind this path, and
 *    a tool that quietly returns an empty document would look like a success.
 *  - **Page geometry is not declared.** The bound writer emits one
 *    default-geometry section, so `orientation` and `page_margins` are absent
 *    from the schema instead of accepted and dropped — the defect this cluster
 *    keeps finding in the WordPress originals.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\OfficeWriterInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\ProWordDocumentTool;
use PHPUnit\Framework\TestCase;

final class ProWordDocumentToolTest extends TestCase
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
        $tool = $this->tool($this->writer());

        $this->assertSame('pro_word_document', $tool->getSlug());
        $this->assertSame('upload_files', $tool->getRequiredCapability());
        $this->assertNotContains('requires-model', $tool->getCapabilityFlags());
    }

    public function test_the_schema_publishes_the_four_operations(): void
    {
        $schema = $this->tool($this->writer())->getParametersSchema();

        $this->assertSame(
            array('generate', 'structure', 'format', 'template'),
            $schema['properties']['operation']['enum'],
        );
    }

    /**
     * The writer emits one default-geometry section, so promising page geometry
     * would be the "accepted then ignored" defect this cluster keeps fixing.
     */
    public function test_it_does_not_declare_page_geometry(): void
    {
        $schema = $this->tool($this->writer())->getParametersSchema();

        $this->assertArrayNotHasKey('orientation', $schema['properties']);
        $this->assertArrayNotHasKey('page_margins', $schema['properties']);
        $this->assertFalse($schema['additionalProperties']);
    }

    public function test_the_template_enum_lists_the_five_scaffolds(): void
    {
        $schema = $this->tool($this->writer())->getParametersSchema();

        $this->assertSame(
            array('business_letter', 'report', 'resume', 'memo', 'proposal'),
            $schema['properties']['template']['enum'],
        );
    }

    // ─── generate ──────────────────────────────────────────────────────────

    public function test_generate_splits_the_body_into_paragraphs(): void
    {
        $writer = $this->writer();

        $result = $this->tool($writer)->execute(
            array('operation' => 'generate', 'content' => "One.\n\nTwo.", 'title' => 'Memo'),
            array('user_id' => 2),
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array('One.', 'Two.'), $writer->calls[0]['content']['paragraphs']);
        $this->assertSame('Memo', $writer->calls[0]['content']['title']);
    }

    public function test_a_description_alone_names_the_missing_capability(): void
    {
        $writer = $this->writer();

        $result = $this->tool($writer)->execute(
            array('operation' => 'generate', 'description' => 'A letter about pricing.'),
            array('user_id' => 2),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('no_content_generator', $result['error']['code']);
        $this->assertSame(array(), $writer->calls);
    }

    public function test_generate_with_neither_field_is_a_validation_failure(): void
    {
        $result = $this->tool($this->writer())->execute(array('operation' => 'generate'));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── structure ─────────────────────────────────────────────────────────

    public function test_structure_emits_heading_levels(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array(
                'operation' => 'structure',
                'sections' => array(
                    array('heading' => 'Intro', 'content' => 'Opening.', 'level' => 1),
                    array('heading' => 'Detail', 'content' => 'Middle.', 'level' => 3),
                ),
            ),
            array('user_id' => 2),
        );

        $paragraphs = $writer->calls[0]['content']['paragraphs'];

        $this->assertSame(array('heading' => 'Intro', 'level' => 'HEADING_1'), $paragraphs[0]);
        $this->assertSame('Opening.', $paragraphs[1]);
        $this->assertSame(array('heading' => 'Detail', 'level' => 'HEADING_3'), $paragraphs[2]);
    }

    public function test_a_section_level_is_clamped(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array('operation' => 'structure', 'sections' => array(array('heading' => 'Deep', 'level' => 42))),
            array('user_id' => 2),
        );

        $this->assertSame('HEADING_6', $writer->calls[0]['content']['paragraphs'][0]['level']);
    }

    public function test_structure_without_sections_is_a_validation_failure(): void
    {
        $result = $this->tool($this->writer())->execute(
            array('operation' => 'structure', 'content' => 'Body.'),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── format ────────────────────────────────────────────────────────────

    public function test_format_emits_a_styled_run(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array(
                'operation' => 'format',
                'content' => 'Styled body.',
                'formatting' => array(
                    'bold' => true,
                    'italic' => true,
                    'color' => '#112233',
                    'font_size' => 11,
                    'font_family' => 'Georgia',
                ),
            ),
            array('user_id' => 2),
        );

        $run = $writer->calls[0]['content']['paragraphs'][0]['runs'][0];

        $this->assertTrue($run['bold']);
        $this->assertTrue($run['italics']);
        $this->assertSame('112233', $run['color']);
        $this->assertSame(22, $run['size'], 'The writer takes half-points.');
        $this->assertSame('Georgia', $run['font']);
    }

    public function test_a_malformed_colour_is_dropped_rather_than_passed_through(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array(
                'operation' => 'format',
                'content' => 'Body.',
                'formatting' => array('color' => 'red; background: url(x)'),
            ),
            array('user_id' => 2),
        );

        $this->assertArrayNotHasKey('color', $writer->calls[0]['content']['paragraphs'][0]['runs'][0]);
    }

    // ─── template ──────────────────────────────────────────────────────────

    public function test_a_template_needs_no_body(): void
    {
        $writer = $this->writer();

        $result = $this->tool($writer)->execute(
            array('operation' => 'template', 'template' => 'resume'),
            array('user_id' => 2),
        );

        $this->assertTrue($result['success']);

        $headings = \array_column($writer->calls[0]['content']['paragraphs'], 'heading');

        $this->assertSame(array('Profile', 'Experience', 'Education', 'Skills'), $headings);
    }

    public function test_a_template_without_a_name_is_a_validation_failure(): void
    {
        $result = $this->tool($this->writer())->execute(array('operation' => 'template', 'content' => 'x'));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_flat_body_lands_under_the_scaffolds_body_heading(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array('operation' => 'template', 'template' => 'memo', 'content' => 'The body.'),
            array('user_id' => 2),
        );

        $paragraphs = $writer->calls[0]['content']['paragraphs'];
        $bodyIndex = array_search('Body', array_column($paragraphs, 'heading'), true);

        $this->assertSame('The body.', $paragraphs[$bodyIndex + 1]);
    }

    public function test_a_flat_body_is_appended_when_the_scaffold_has_no_body_heading(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array('operation' => 'template', 'template' => 'resume', 'content' => 'Appended.'),
            array('user_id' => 2),
        );

        $paragraphs = $writer->calls[0]['content']['paragraphs'];

        $this->assertSame('Appended.', $paragraphs[\count($paragraphs) - 1]);
    }

    public function test_sections_fill_the_matching_scaffold_heading(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array(
                'operation' => 'template',
                'template' => 'resume',
                'sections' => array(array('heading' => 'skills', 'content' => 'PHP, SQL')),
            ),
            array('user_id' => 2),
        );

        $paragraphs = $writer->calls[0]['content']['paragraphs'];
        $index = array_search('Skills', array_column($paragraphs, 'heading'), true);

        $this->assertSame('PHP, SQL', $paragraphs[$index + 1]);
        $this->assertSame(1, \substr_count(implode('|', array_column($paragraphs, 'heading')), 'Skills'), 'A matched section must not be repeated at the end.');
    }

    public function test_an_unmatched_section_is_appended_rather_than_lost(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array(
                'operation' => 'template',
                'template' => 'resume',
                'sections' => array(array('heading' => 'References', 'content' => 'On request.')),
            ),
            array('user_id' => 2),
        );

        $headings = array_column($writer->calls[0]['content']['paragraphs'], 'heading');

        $this->assertContains('References', $headings);
    }

    public function test_a_template_supplies_a_default_title(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array('operation' => 'template', 'template' => 'proposal'),
            array('user_id' => 2),
        );

        $this->assertSame('Proposal', $writer->calls[0]['content']['title']);
    }

    // ─── Header and footer ─────────────────────────────────────────────────

    public function test_header_and_footer_are_passed_through(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array('operation' => 'generate', 'content' => 'x', 'header' => 'Confidential', 'footer' => 'Draft'),
            array('user_id' => 2),
        );

        $this->assertSame('Confidential', $writer->calls[0]['content']['header']);
        $this->assertSame('Draft', $writer->calls[0]['content']['footer']);
    }

    public function test_an_absent_header_is_not_emitted(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(array('operation' => 'generate', 'content' => 'x'), array('user_id' => 2));

        $this->assertArrayNotHasKey('header', $writer->calls[0]['content']);
    }

    // ─── Operation routing and engine ──────────────────────────────────────

    public function test_an_unknown_operation_is_rejected_before_the_engine(): void
    {
        $writer = $this->writer();

        $result = $this->tool($writer)->execute(array('operation' => 'shred', 'content' => 'x'));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $writer->calls);
    }

    public function test_a_missing_engine_is_named(): void
    {
        $result = $this->tool($this->writer(available: false))->execute(
            array('operation' => 'generate', 'content' => 'x'),
            array('user_id' => 2),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('no_document_engine', $result['error']['code']);
    }

    public function test_a_writer_failure_becomes_word_failed(): void
    {
        $result = $this->tool($this->writer(failure: 'docx threw'))->execute(
            array('operation' => 'generate', 'content' => 'x'),
            array('user_id' => 2),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('word_failed', $result['error']['code']);
    }

    // ─── Storage ───────────────────────────────────────────────────────────

    public function test_it_stores_by_default(): void
    {
        $store = $this->recordingStore();

        $result = $this->tool($this->writer(), $store)->execute(
            array('operation' => 'generate', 'content' => 'x', 'title' => 'Board Minutes'),
            array('user_id' => 6),
        );

        $this->assertTrue($result['success']);
        $this->assertSame('board-minutes', $store->calls[0]['filename']);
        $this->assertSame(6, $store->calls[0]['userId']);
        $this->assertSame(61, $result['data']['media'][0]['attachment_id'] ?? null);
    }

    public function test_the_wordpress_upload_flag_is_honoured(): void
    {
        $store = $this->recordingStore();

        $this->tool($this->writer(), $store)->execute(
            array('operation' => 'generate', 'content' => 'x', 'upload' => false),
            array('user_id' => 6),
        );

        $this->assertSame(array(), $store->calls);
    }

    // ─── Doubles ───────────────────────────────────────────────────────────

    private function writer(bool $available = true, ?string $failure = null): OfficeWriterInterface
    {
        $path = \tempnam(\sys_get_temp_dir(), 'nvoos-docx-');
        $this->assertNotFalse($path);
        $this->temporary[] = $path;

        return new class ($available, $failure, $path) implements OfficeWriterInterface {
            /** @var list<array{content: array<string, mixed>}> */
            public array $calls = array();

            public function __construct(
                private readonly bool $available,
                private readonly ?string $failure,
                private readonly string $path,
            ) {
            }

            public function docx(array $content): array
            {
                $this->calls[] = array('content' => $content);

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                \file_put_contents($this->path, 'PK test');

                return array(
                    'path' => $this->path,
                    'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'bytes' => 3072,
                    'format' => 'docx',
                );
            }

            public function xlsx(array $sheets, array $options = array()): array
            {
                throw new \LogicException('Not used by this tool.');
            }

            public function isAvailable(): bool
            {
                return $this->available;
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
                    id: 61,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: 3072,
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
        OfficeWriterInterface $writer,
        ?FileVisibilityStoreInterface $store = null,
    ): ProWordDocumentTool {
        return new ProWordDocumentTool($this->errors, $writer, $store);
    }
}
