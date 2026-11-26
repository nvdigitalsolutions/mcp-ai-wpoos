<?php

/**
 * `excel_data_export`.
 *
 * The write half of the `excel_data_import` pair. Three things are worth
 * pinning, because each is a way a spreadsheet can be wrong while still opening:
 *
 *  - **A ragged row must not shift its neighbours.** Cells are bound by column
 *    key, so a short row leaves gaps where it stopped rather than sliding its
 *    values left into the wrong columns.
 *  - **A header row appears only when one was asked for.** ExcelJS writes a
 *    header for every column that declares one, so an export with no `headers`
 *    must declare none — otherwise the caller's first data row is displaced.
 *  - **A non-scalar cell is refused, not coerced.** A nested array would be
 *    stringified by the writer and produce a file that looked like a success.
 *
 * The writer is a recording double: the assertions are about the sheet the tool
 * built, and a mock would make them assertions about the mock.
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
use Nvoos\Core\Tool\ExcelDataExportTool;
use PHPUnit\Framework\TestCase;

final class ExcelDataExportToolTest extends TestCase
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

        $this->assertSame('excel_data_export', $tool->getSlug());
        $this->assertSame('upload_files', $tool->getRequiredCapability());
        $this->assertContains('write', $tool->getCapabilityFlags());
    }

    /**
     * `local-only` is true in WordPress because PhpSpreadsheet runs in process.
     * Here the rows go to the media worker, so claiming it would tell the
     * necessity gate that nothing left the estate.
     */
    public function test_it_does_not_claim_to_be_local_only(): void
    {
        $this->assertNotContains('local-only', $this->tool($this->writer())->getCapabilityFlags());
    }

    public function test_the_schema_requires_data(): void
    {
        $schema = $this->tool($this->writer())->getParametersSchema();

        $this->assertSame(array('data'), $schema['required']);
        $this->assertArrayHasKey('headers', $schema['properties']);
    }

    // ─── Column binding ────────────────────────────────────────────────────

    public function test_a_row_is_bound_to_columns_by_key(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array(
                'data' => array(array('EMEA', 12), array('APAC', 7)),
                'headers' => array('Region', 'Units'),
            ),
            array('user_id' => 3),
        );

        $sheet = $writer->calls[0]['sheets'][0];

        $this->assertSame(
            array(array('c1' => 'EMEA', 'c2' => 12), array('c1' => 'APAC', 'c2' => 7)),
            $sheet['rows'],
        );
    }

    /**
     * The reason rows are keyed: a positional row would slide "APAC" into the
     * Region column and leave a hole at the end.
     */
    public function test_a_ragged_row_leaves_gaps_instead_of_shifting(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array(
                'data' => array(array('EMEA', 12, 'x'), array('APAC')),
                'headers' => array('Region', 'Units', 'Note'),
            ),
            array('user_id' => 3),
        );

        $rows = $writer->calls[0]['sheets'][0]['rows'];

        $this->assertSame(array('c1' => 'APAC', 'c2' => null, 'c3' => null), $rows[1]);
    }

    public function test_the_widest_row_decides_the_column_count(): void
    {
        $writer = $this->writer();

        $result = $this->tool($writer)->execute(
            array('data' => array(array('a'), array('a', 'b', 'c'))),
            array('user_id' => 3),
        );

        $this->assertSame(3, $result['data']['columns']);
    }

    public function test_a_short_header_list_still_names_every_column(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array('data' => array(array('a', 'b', 'c')), 'headers' => array('Only')),
            array('user_id' => 3),
        );

        $columns = $writer->calls[0]['sheets'][0]['columns'];

        $this->assertSame('Only', $columns[0]['header']);
        $this->assertSame('Column 2', $columns[1]['header']);
        $this->assertSame('Column 3', $columns[2]['header']);
    }

    // ─── Header row presence ───────────────────────────────────────────────

    public function test_headers_become_the_first_row(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array('data' => array(array('a')), 'headers' => array('Region')),
            array('user_id' => 3),
        );

        $this->assertSame('Region', $writer->calls[0]['sheets'][0]['columns'][0]['header']);
    }

    /**
     * ExcelJS writes a header row for every column that declares one, so an
     * export with no `headers` must declare none — otherwise the caller's first
     * data row is displaced by an invented header.
     */
    public function test_no_header_is_declared_when_none_was_given(): void
    {
        $writer = $this->writer();

        $result = $this->tool($writer)->execute(
            array('data' => array(array('a', 'b'))),
            array('user_id' => 3),
        );

        $this->assertArrayNotHasKey('header', $writer->calls[0]['sheets'][0]['columns'][0]);
        $this->assertFalse($result['data']['has_header']);
    }

    public function test_header_presence_is_reported(): void
    {
        $result = $this->tool($this->writer())->execute(
            array('data' => array(array('a')), 'headers' => array('Region')),
            array('user_id' => 3),
        );

        $this->assertTrue($result['data']['has_header']);
    }

    // ─── Sheet names ───────────────────────────────────────────────────────

    public function test_an_illegal_character_in_the_title_is_removed(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array('data' => array(array('a')), 'title' => 'Q1/Q2: report*'),
            array('user_id' => 3),
        );

        $this->assertSame('Q1 Q2 report', $writer->calls[0]['sheets'][0]['name']);
    }

    public function test_a_title_longer_than_thirty_one_characters_is_truncated(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array('data' => array(array('a')), 'title' => str_repeat('x', 60)),
            array('user_id' => 3),
        );

        $this->assertSame(31, \strlen($writer->calls[0]['sheets'][0]['name']));
    }

    public function test_an_empty_title_falls_back_to_export(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(array('data' => array(array('a'))), array('user_id' => 3));

        $this->assertSame('Export', $writer->calls[0]['sheets'][0]['name']);
    }

    public function test_a_title_of_only_illegal_characters_falls_back_to_export(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array('data' => array(array('a')), 'title' => '///'),
            array('user_id' => 3),
        );

        $this->assertSame('Export', $writer->calls[0]['sheets'][0]['name']);
    }

    // ─── Refusals ──────────────────────────────────────────────────────────

    public function test_data_is_required(): void
    {
        $writer = $this->writer();

        $result = $this->tool($writer)->execute(array('data' => array()), array('user_id' => 3));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $writer->calls);
    }

    public function test_a_non_array_row_is_named(): void
    {
        $result = $this->tool($this->writer())->execute(
            array('data' => array(array('a'), 'not-a-row')),
            array('user_id' => 3),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('Row 2', $result['error']['message']);
    }

    /**
     * A nested array would be stringified to "[object Object]" by the writer and
     * return a plausible-looking file.
     */
    public function test_a_nested_cell_is_refused_and_located(): void
    {
        $writer = $this->writer();

        $result = $this->tool($writer)->execute(
            array('data' => array(array('a', array('nested')))),
            array('user_id' => 3),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('Row 1, column 2', $result['error']['message']);
        $this->assertSame(array(), $writer->calls);
    }

    public function test_a_null_cell_is_allowed(): void
    {
        $result = $this->tool($this->writer())->execute(
            array('data' => array(array('a', null, 0, false))),
            array('user_id' => 3),
        );

        $this->assertTrue($result['success']);
    }

    /**
     * The WordPress tool downgrades to CSV when its engine is absent. That
     * changes the format of a file whose name still says `.xlsx`, so this
     * refuses instead.
     */
    public function test_a_missing_engine_is_named_rather_than_downgraded(): void
    {
        $writer = $this->writer(available: false);

        $result = $this->tool($writer)->execute(array('data' => array(array('a'))), array('user_id' => 3));

        $this->assertFalse($result['success']);
        $this->assertSame('no_document_engine', $result['error']['code']);
        $this->assertSame(array(), $writer->calls);
    }

    public function test_a_writer_failure_becomes_excel_failed(): void
    {
        $result = $this->tool($this->writer(failure: 'exceljs threw'))->execute(
            array('data' => array(array('a'))),
            array('user_id' => 3),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('excel_failed', $result['error']['code']);
        $this->assertStringContainsString('exceljs threw', $result['error']['message']);
    }

    // ─── Storage ───────────────────────────────────────────────────────────

    public function test_it_stores_the_workbook_by_default(): void
    {
        $store = $this->recordingStore();

        $result = $this->tool($this->writer(), $store)->execute(
            array('data' => array(array('a')), 'title' => 'Regional Sales'),
            array('user_id' => 4),
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $store->calls);
        $this->assertSame('regional-sales', $store->calls[0]['filename']);
        $this->assertSame(4, $store->calls[0]['userId']);
        $this->assertSame(52, $result['data']['media'][0]['attachment_id'] ?? null);
    }

    public function test_save_to_media_false_skips_the_store(): void
    {
        $store = $this->recordingStore();

        $this->tool($this->writer(), $store)->execute(
            array('data' => array(array('a')), 'save_to_media' => false),
            array('user_id' => 4),
        );

        $this->assertSame(array(), $store->calls);
    }

    public function test_the_payload_reports_the_shape(): void
    {
        $result = $this->tool($this->writer())->execute(
            array('data' => array(array('a', 'b')), 'save_to_media' => false),
            array('user_id' => 4),
        );

        $this->assertSame('xlsx', $result['data']['format']);
        $this->assertSame(2, $result['data']['columns']);
        $this->assertSame(1, $result['data']['rows']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $result['data']['mime_type']);
    }

    // ─── Doubles ───────────────────────────────────────────────────────────

    private function writer(bool $available = true, ?string $failure = null): OfficeWriterInterface
    {
        $path = \tempnam(\sys_get_temp_dir(), 'nvoos-xlsx-');
        $this->assertNotFalse($path);
        $this->temporary[] = $path;

        return new class ($available, $failure, $path) implements OfficeWriterInterface {
            /** @var list<array{sheets: array<int, mixed>, options: array<string, mixed>}> */
            public array $calls = array();

            public function __construct(
                private readonly bool $available,
                private readonly ?string $failure,
                private readonly string $path,
            ) {
            }

            public function docx(array $content): array
            {
                throw new \LogicException('Not used by this tool.');
            }

            public function xlsx(array $sheets, array $options = array()): array
            {
                $this->calls[] = array('sheets' => $sheets, 'options' => $options);

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                \file_put_contents($this->path, 'PK test');

                return array(
                    'path' => $this->path,
                    'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'bytes' => 4096,
                    'format' => 'xlsx',
                );
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
                    id: 52,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: 4096,
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
    ): ExcelDataExportTool {
        return new ExcelDataExportTool($this->errors, $writer, $store);
    }
}
