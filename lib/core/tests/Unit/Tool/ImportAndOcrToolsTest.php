<?php

/**
 * The import and OCR tools: `excel_data_import`, `ocr_pdf_text`.
 *
 * Both exist because the media worker already owns the engine, so the tests are
 * about the two things a thin tool can still get wrong:
 *
 *  - **the shape it hands back** — a matrix is positional, and a headerless
 *    sheet has to be reconstructed rather than silently losing its first row;
 *  - **the budget it promises** — a truncated answer must say so, and a
 *    requested page/style budget must reach the engine rather than being
 *    dropped by a name mismatch.
 *
 * The engines are recording doubles: the assertions are about the request the
 * tool built.
 *
 * @package Nvoos\Core\Tests
 * @since   2.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\PdfOcrInterface;
use Nvoos\Core\Domain\Contract\TabularReaderInterface;
use Nvoos\Core\Tool\ExcelDataImportTool;
use Nvoos\Core\Tool\OcrPdfTextTool;
use PHPUnit\Framework\TestCase;

final class ImportAndOcrToolsTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => $code, 'message' => $message),
            )
        );

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message, array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => 'not_found', 'message' => $message),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error' => array('code' => 'validation_failed', 'message' => $message),
            )
        );
    }

    // ─── excel_data_import ─────────────────────────────────────────────

    public function test_import_requires_a_file_id(): void
    {
        $this->assertSame(
            'validation_failed',
            $this->importTool($this->reader())->execute(array())['error']['code']
        );
    }

    public function test_import_without_a_registry_says_so(): void
    {
        $result = $this->importTool($this->reader(), null)->execute(array('file_id' => 1));

        $this->assertSame('no_file_store', $result['error']['code']);
    }

    public function test_import_names_an_unknown_file(): void
    {
        $result = $this->importTool($this->reader(), $this->registry(array()))
            ->execute(array('file_id' => 77));

        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_import_without_an_engine_is_a_named_capability_error(): void
    {
        $reader = $this->reader(available: false);
        $result = $this->importTool($reader, $this->registry(array(1 => '/tmp/book.xlsx')))
            ->execute(array('file_id' => 1));

        $this->assertSame('no_tabular_engine', $result['error']['code']);
        $this->assertSame(array(), $reader->calls);
    }

    public function test_import_returns_the_matrix_and_its_counts(): void
    {
        $reader = $this->reader();

        $result = $this->importTool($reader, $this->registry(array(3 => '/tmp/book.xlsx')))
            ->execute(array('file_id' => 3, 'max_rows' => 10));

        $this->assertTrue($result['success']);
        $this->assertSame(array('Date', 'Item'), $result['data']['columns']);
        $this->assertSame(array(array('2026-10-01', 'A')), $result['data']['rows']);
        $this->assertSame(1, $result['data']['row_count']);
        $this->assertSame(2, $result['data']['source_rows']);
        $this->assertSame(true, $result['data']['truncated']);
        $this->assertSame('/tmp/book.xlsx', $reader->calls[0][0]);
        $this->assertSame(10, $reader->calls[0][1]['max_rows']);
    }

    public function test_a_headerless_sheet_reconstructs_the_first_row(): void
    {
        $reader = $this->reader(
            columns: array('2026-10-01', 'A'),
            rows: array(array('2026-10-02', 'B'))
        );

        $result = $this->importTool($reader, $this->registry(array(3 => '/tmp/book.csv')))
            ->execute(array('file_id' => 3, 'has_headers' => false));

        $this->assertTrue($result['success']);
        $this->assertSame(array('A', 'B'), $result['data']['columns'], 'Excel-style names, not data.');
        $this->assertSame(
            array(array('2026-10-01', 'A'), array('2026-10-02', 'B')),
            $result['data']['rows'],
            'The captured header row becomes the first data row again.'
        );
        $this->assertSame(false, $result['data']['has_headers']);
    }

    public function test_import_wraps_an_engine_failure(): void
    {
        $result = $this->importTool($this->reader(failure: 'legacy .xls is not supported'), $this->registry(array(1 => '/tmp/a.xls')))
            ->execute(array('file_id' => 1));

        $this->assertSame('excel_import_failed', $result['error']['code']);
        $this->assertSame('legacy .xls is not supported', $result['error']['message']);
    }

    public function test_import_is_read_only(): void
    {
        $flags = $this->importTool($this->reader())->getCapabilityFlags();

        $this->assertContains('read-only', $flags);
        $this->assertNotContains('write', $flags);
    }

    // ─── ocr_pdf_text ──────────────────────────────────────────────────

    public function test_ocr_requires_a_file_id(): void
    {
        $this->assertSame(
            'validation_failed',
            $this->ocrTool($this->ocr())->execute(array())['error']['code']
        );
    }

    public function test_ocr_without_an_engine_is_a_named_capability_error(): void
    {
        $ocr = $this->ocr(available: false);

        $result = $this->ocrTool($ocr, $this->registry(array(1 => '/tmp/a.pdf')))
            ->execute(array('file_id' => 1));

        $this->assertSame('no_ocr_engine', $result['error']['code']);
        $this->assertSame(array(), $ocr->calls);
    }

    public function test_ocr_returns_per_page_text_and_the_budget_it_used(): void
    {
        $ocr = $this->ocr();

        $result = $this->ocrTool($ocr, $this->registry(array(9 => '/tmp/scan.pdf')))
            ->execute(array('file_id' => 9, 'dpi' => 400, 'max_pages' => 5, 'language' => 'deu'));

        $this->assertTrue($result['success']);
        $this->assertSame('Page one text', $result['data']['text']);
        $this->assertSame(13, $result['data']['characters']);
        $this->assertSame(1, $result['data']['count']);
        $this->assertSame(7, $result['data']['total_pages']);
        $this->assertSame(true, $result['data']['truncated']);

        $this->assertSame(400, $ocr->calls[0][1]['dpi']);
        $this->assertSame(5, $ocr->calls[0][1]['max_pages']);
        $this->assertSame('deu', $ocr->calls[0][1]['language']);
    }

    public function test_ocr_defaults_to_300_dpi_and_20_pages(): void
    {
        $ocr = $this->ocr();

        $this->ocrTool($ocr, $this->registry(array(1 => '/tmp/a.pdf')))->execute(array('file_id' => 1));

        $this->assertSame(OcrPdfTextTool::DEFAULT_DPI, $ocr->calls[0][1]['dpi']);
        $this->assertSame(OcrPdfTextTool::DEFAULT_MAX_PAGES, $ocr->calls[0][1]['max_pages']);
        $this->assertSame('eng', $ocr->calls[0][1]['language']);
    }

    public function test_ocr_wraps_an_engine_failure(): void
    {
        $result = $this->ocrTool($this->ocr(failure: 'traineddata missing'), $this->registry(array(1 => '/tmp/a.pdf')))
            ->execute(array('file_id' => 1));

        $this->assertSame('pdf_ocr_failed', $result['error']['code']);
    }

    public function test_ocr_does_not_claim_local_only(): void
    {
        $flags = $this->ocrTool($this->ocr())->getCapabilityFlags();

        $this->assertContains('read-only', $flags);
        $this->assertNotContains('local-only', $flags, 'The pages leave the host.');
    }

    // ─── Doubles ───────────────────────────────────────────────────────

    private function importTool(
        TabularReaderInterface $reader,
        ?FileStoreInterface $files = null
    ): ExcelDataImportTool {
        return new ExcelDataImportTool($this->errors, $reader, $files);
    }

    private function ocrTool(
        PdfOcrInterface $ocr,
        ?FileStoreInterface $files = null
    ): OcrPdfTextTool {
        return new OcrPdfTextTool($this->errors, $ocr, $files);
    }

    /**
     * @param array<int, string> $paths
     */
    private function registry(array $paths): FileStoreInterface
    {
        $files = $this->createMock(FileStoreInterface::class);
        $files->method('getPath')->willReturnCallback(
            static fn (int $id): ?string => $paths[$id] ?? null
        );

        return $files;
    }

    /**
     * A recording tabular reader.
     *
     * @param list<string>       $columns
     * @param list<list<string>> $rows
     */
    private function reader(
        bool $available = true,
        ?string $failure = null,
        array $columns = array('Date', 'Item'),
        array $rows = array(array('2026-10-01', 'A'))
    ): TabularReaderInterface {
        return new class ($available, $failure, $columns, $rows) implements TabularReaderInterface {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $calls = array();

            /**
             * @param list<string>       $columns
             * @param list<list<string>> $rows
             */
            public function __construct(
                private readonly bool $available,
                private readonly ?string $failure,
                private readonly array $columns,
                private readonly array $rows,
            ) {
            }

            public function read(string $localPath, array $options = array()): array
            {
                $this->calls[] = array($localPath, $options);

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                return array(
                    'columns' => $this->columns,
                    'rows' => $this->rows,
                    'meta' => array('engine' => 'exceljs', 'sheet' => 'Sheet1', 'source_total' => 2, 'truncated' => true),
                );
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function getSupportedFormats(): array
            {
                return array('xlsx', 'csv', 'tsv', 'json');
            }
        };
    }

    /**
     * A recording OCR engine.
     */
    private function ocr(bool $available = true, ?string $failure = null): PdfOcrInterface
    {
        return new class ($available, $failure) implements PdfOcrInterface {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $calls = array();

            public function __construct(
                private readonly bool $available,
                private readonly ?string $failure,
            ) {
            }

            public function recognize(string $localPath, array $options = array()): array
            {
                $this->calls[] = array($localPath, $options);

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                return array(
                    'text' => 'Page one text',
                    'pages' => array(array('page' => 1, 'text' => 'Page one text', 'confidence' => 88.5)),
                    'count' => 1,
                    'total_pages' => 7,
                    'truncated' => true,
                    'dpi' => (int) ($options['dpi'] ?? 300),
                    'language' => (string) ($options['language'] ?? 'eng'),
                );
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }
}
