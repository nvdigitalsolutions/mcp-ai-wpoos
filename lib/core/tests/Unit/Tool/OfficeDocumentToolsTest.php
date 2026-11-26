<?php

/**
 * The office tools: `generate_word`, `generate_excel`.
 *
 * Two properties matter here, and both are about not overstating what the tool
 * accepts:
 *
 *  - **The content shape that reaches the writer is the worker's**, with the
 *    forms the route understands and nothing else. A dropped malformed paragraph
 *    is better than a document lost to one bad element.
 *  - **A workbook with no rows is refused.** The route would happily write an
 *    empty `.xlsx`, which reads as success and contains nothing.
 *
 * Neither tool declares `consumes-tokens` or `requires-model`, even though the
 * WordPress flagships do: the worker's `docx`/`exceljs` routes are deterministic
 * formatters, so claiming a model dependency would gate every call for a cost it
 * never incurs.
 *
 * @package Nvoos\Core\Tests
 * @since   2.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\OfficeWriterInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\GenerateExcelTool;
use Nvoos\Core\Tool\GenerateWordTool;
use PHPUnit\Framework\TestCase;

final class OfficeDocumentToolsTest extends TestCase
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

    // ─── generate_word ─────────────────────────────────────────────────

    public function test_word_refuses_an_empty_document(): void
    {
        $result = $this->wordTool($this->writer())->execute(array());

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_word_without_an_engine_is_a_named_capability_error(): void
    {
        $writer = $this->writer(available: false);
        $result = $this->wordTool($writer)->execute(array('title' => 'Report'));

        $this->assertSame('no_document_engine', $result['error']['code']);
        $this->assertSame(array(), $writer->documents);
    }

    public function test_word_passes_the_worker_content_shape(): void
    {
        $writer = $this->writer();

        $result = $this->wordTool($writer)->execute(array(
            'title' => 'Quarterly Report',
            'header' => 'Confidential',
            'footer' => 'Acme',
            'paragraphs' => array(
                'Opening line.',
                array('heading' => 'Results', 'level' => 'HEADING_1'),
                array('text' => 'Details', 'bullet' => true),
            ),
            'table' => array(
                'headers' => array('Region', 'Total'),
                'rows' => array(array('EMEA', '10')),
            ),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('docx', $result['data']['format']);

        $content = $writer->documents[0];
        $this->assertSame('Quarterly Report', $content['title']);
        $this->assertSame('Confidential', $content['header']);
        $this->assertSame('Acme', $content['footer']);
        $this->assertCount(3, $content['paragraphs']);
        $this->assertSame('Opening line.', $content['paragraphs'][0]);
        $this->assertSame(array('Region', 'Total'), $content['table']['headers']);
        $this->assertSame(array(array('EMEA', '10')), $content['table']['rows']);
    }

    public function test_word_drops_malformed_paragraphs_instead_of_losing_the_document(): void
    {
        $writer = $this->writer();

        $this->wordTool($writer)->execute(array(
            'paragraphs' => array('Good.', 42, null, array(), array('text' => 'Fine')),
        ));

        $this->assertSame(
            array('Good.', array('text' => 'Fine')),
            $writer->documents[0]['paragraphs']
        );
    }

    public function test_word_drops_a_table_with_no_rows(): void
    {
        $writer = $this->writer();

        $result = $this->wordTool($writer)->execute(array(
            'title' => 'Report',
            'table' => array('headers' => array('A'), 'rows' => array()),
        ));

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('table', $writer->documents[0]);
    }

    public function test_word_stores_under_the_title(): void
    {
        $store = $this->recordingStore();

        $result = $this->wordTool($this->writer(), $store)->execute(array('title' => 'Quarterly Report'));

        $this->assertTrue($result['success']);
        $this->assertSame('quarterly-report.docx', $store->writes[0]['filename']);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            $store->writes[0]['mimeType']
        );
    }

    public function test_word_wraps_an_engine_failure(): void
    {
        $result = $this->wordTool($this->writer(failure: 'docx refused'))->execute(array('title' => 'Report'));

        $this->assertSame('word_failed', $result['error']['code']);
    }

    public function test_word_does_not_claim_a_model_dependency(): void
    {
        $flags = $this->wordTool($this->writer())->getCapabilityFlags();

        $this->assertNotContains('requires-model', $flags);
        $this->assertNotContains('consumes-tokens', $flags);
        $this->assertContains('write', $flags);
    }

    // ─── generate_excel ────────────────────────────────────────────────

    public function test_excel_refuses_a_workbook_with_no_sheets(): void
    {
        $result = $this->excelTool($this->writer())->execute(array('sheets' => array()));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_excel_refuses_a_sheet_with_no_rows(): void
    {
        $result = $this->excelTool($this->writer())->execute(array(
            'sheets' => array(
                array('name' => 'Empty', 'columns' => array('A'), 'rows' => array()),
                array('name' => 'Data', 'columns' => array('A'), 'rows' => array(array('x'))),
            ),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['sheets'], 'The empty sheet is dropped.');
    }

    public function test_excel_without_an_engine_is_a_named_capability_error(): void
    {
        $writer = $this->writer(available: false);

        $result = $this->excelTool($writer)->execute(array(
            'sheets' => array(array('name' => 'Q1', 'rows' => array(array('x')))),
        ));

        $this->assertSame('no_document_engine', $result['error']['code']);
        $this->assertSame(array(), $writer->workbooks);
    }

    public function test_excel_reports_the_sheet_and_row_counts(): void
    {
        $writer = $this->writer();

        $result = $this->excelTool($writer)->execute(array(
            'sheets' => array(
                array('name' => 'Q1', 'columns' => array(array('header' => 'Region', 'key' => 'region')), 'rows' => array(array('EMEA'), array('APAC'))),
                array('name' => 'Q2', 'rows' => array(array('EMEA'))),
            ),
            'creator' => 'Acme',
            'title' => 'Sales',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['sheets']);
        $this->assertSame(3, $result['data']['rows']);
        $this->assertSame('Q1', $writer->workbooks[0][0]['name']);
        $this->assertSame('Acme', $writer->options[0]['creator']);
    }

    public function test_excel_stores_the_workbook(): void
    {
        $store = $this->recordingStore();

        $result = $this->excelTool($this->writer(), $store)->execute(array(
            'sheets' => array(array('name' => 'Q1', 'rows' => array(array('x')))),
            'filename' => 'Sales Report',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('sales-report.xlsx', $store->writes[0]['filename']);
    }

    // ─── Doubles ───────────────────────────────────────────────────────

    private function wordTool(
        OfficeWriterInterface $writer,
        ?FileVisibilityStoreInterface $store = null
    ): GenerateWordTool {
        return new GenerateWordTool($this->errors, $writer, $store);
    }

    private function excelTool(
        OfficeWriterInterface $writer,
        ?FileVisibilityStoreInterface $store = null
    ): GenerateExcelTool {
        return new GenerateExcelTool($this->errors, $writer, $store);
    }

    /**
     * A real file, because the registry bridge checks `is_file()`.
     */
    private function tempFile(string $name): string
    {
        $path = \sys_get_temp_dir() . '/nvoos-office-' . \bin2hex(\random_bytes(4)) . '-' . $name;
        \file_put_contents($path, 'PK-test');
        $this->temporary[] = $path;

        return $path;
    }

    /**
     * A recording office writer.
     */
    private function writer(bool $available = true, ?string $failure = null): OfficeWriterInterface
    {
        return new class ($available, $failure, $this->tempFile('doc.docx'), $this->tempFile('sheet.xlsx')) implements OfficeWriterInterface {
            /** @var list<array<string, mixed>> */
            public array $documents = array();

            /** @var list<array<int, array<string, mixed>>> */
            public array $workbooks = array();

            /** @var list<array<string, mixed>> */
            public array $options = array();

            public function __construct(
                private readonly bool $available,
                private readonly ?string $failure,
                private readonly string $docxPath,
                private readonly string $xlsxPath,
            ) {
            }

            public function docx(array $content): array
            {
                $this->documents[] = $content;

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                return array(
                    'path' => $this->docxPath,
                    'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'bytes' => 4096,
                );
            }

            public function xlsx(array $sheets, array $options = array()): array
            {
                $this->workbooks[] = array_values($sheets);
                $this->options[] = $options;

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                return array(
                    'path' => $this->xlsxPath,
                    'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'bytes' => 8192,
                );
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }

    /**
     * A file store that records each write.
     */
    private function recordingStore(): FileVisibilityStoreInterface
    {
        return new class implements FileVisibilityStoreInterface {
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
                $this->writes[] = array(
                    'localPath' => $localPath,
                    'filename' => $filename,
                    'mimeType' => $mimeType,
                    'visibility' => $visibility,
                );

                return new StoredFile(
                    id: 700,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: 4096,
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable(),
                    publicUrl: 'https://example.test/files/' . $filename,
                    visibility: $visibility,
                    slug: 'stored',
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
}
