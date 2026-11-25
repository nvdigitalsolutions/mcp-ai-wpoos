<?php

/**
 * `pro_excel_document`.
 *
 * The worker suite proves the route applies views, filters and formulas, and that
 * a `=1+1` data value stays a string (`document.test.js`). What is left here is
 * the tool's own mapping: that the four WordPress parameters which used to be
 * inert now reach the sheet definition, that `chart` is refused rather than
 * silently accepted, and that a formula only exists when one was asked for.
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
use Nvoos\Core\Tool\ProExcelDocumentTool;
use PHPUnit\Framework\TestCase;

final class ProExcelDocumentToolTest extends TestCase
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

    public function test_it_declares_its_slug_and_flags(): void
    {
        $tool = $this->tool($this->writer());

        $this->assertSame('pro_excel_document', $tool->getSlug());
        $this->assertSame('upload_files', $tool->getRequiredCapability());
        $this->assertNotContains('requires-model', $tool->getCapabilityFlags());
    }

    /**
     * `chart` was deferred in §164.33 because the worker could not draw one, and
     * §164.36 did not add it. Accepting the operation would be the
     * accepted-then-ignored defect.
     */
    public function test_chart_is_not_an_offered_operation(): void
    {
        $schema = $this->tool($this->writer())->getParametersSchema();

        $this->assertSame(array('generate', 'table', 'multi_sheet'), $schema['properties']['operation']['enum']);
        $this->assertNotContains('chart', $schema['properties']['operation']['enum']);
    }

    public function test_asking_for_chart_is_a_validation_failure(): void
    {
        $writer = $this->writer();

        $result = $this->tool($writer)->execute(array('operation' => 'chart', 'data' => array(array(1))));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('Charts are not supported', $result['error']['message']);
        $this->assertSame(array(), $writer->calls);
    }

    // ─── Table ─────────────────────────────────────────────────────────────

    public function test_a_table_binds_rows_to_columns_by_key(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array(
                'operation' => 'table',
                'headers' => array('Region', 'Units'),
                'data' => array(array('EMEA', 12), array('APAC', 7)),
            ),
            array('user_id' => 2),
        );

        $sheet = $writer->calls[0]['sheets'][0];

        $this->assertSame(
            array(array('c1' => 'EMEA', 'c2' => 12), array('c1' => 'APAC', 'c2' => 7)),
            $sheet['rows'],
        );
        $this->assertSame('Region', $sheet['columns'][0]['header']);
    }

    /**
     * These three were inert parameters before the worker gained support for them.
     */
    public function test_freeze_and_filter_reach_the_sheet(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array(
                'operation' => 'table',
                'headers' => array('A', 'B'),
                'data' => array(array(1, 2)),
                'formatting' => array('freeze_header' => true, 'auto_filter' => true),
            ),
            array('user_id' => 2),
        );

        $sheet = $writer->calls[0]['sheets'][0];

        $this->assertSame('frozen', $sheet['views'][0]['state']);
        $this->assertSame(1, $sheet['views'][0]['ySplit']);
        $this->assertSame('A1:B1', $sheet['autoFilter']);
    }

    public function test_freeze_and_filter_are_absent_unless_asked_for(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array('operation' => 'table', 'headers' => array('A'), 'data' => array(array(1))),
            array('user_id' => 2),
        );

        $sheet = $writer->calls[0]['sheets'][0];

        $this->assertArrayNotHasKey('views', $sheet);
        $this->assertArrayNotHasKey('autoFilter', $sheet);
    }

    public function test_the_header_fill_and_bold_are_honoured(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array(
                'operation' => 'table',
                'headers' => array('A'),
                'data' => array(array(1)),
                'formatting' => array('header_bg' => 'ffefefef', 'header_bold' => false),
            ),
            array('user_id' => 2),
        );

        $style = $writer->calls[0]['sheets'][0]['style'];

        $this->assertSame('FFEFEFEF', $style['headerFill']);
        $this->assertFalse($style['headerFont']['bold']);
    }

    public function test_a_malformed_fill_is_dropped(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array(
                'operation' => 'table',
                'headers' => array('A'),
                'data' => array(array(1)),
                'formatting' => array('header_bg' => 'red; url(x)'),
            ),
            array('user_id' => 2),
        );

        $this->assertArrayNotHasKey('headerFill', $writer->calls[0]['sheets'][0]['style']);
    }

    // ─── Formulas, and the boundary ────────────────────────────────────────

    public function test_a_formula_is_placed_by_cell_reference(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array(
                'operation' => 'table',
                'headers' => array('A', 'B'),
                'data' => array(array(1, 2)),
                'formulas' => array(array('cell' => 'd2', 'formula' => '=SUM(A2:B2)')),
            ),
            array('user_id' => 2),
        );

        $this->assertSame(array('D2' => '=SUM(A2:B2)'), $writer->calls[0]['sheets'][0]['formulas']);
    }

    /**
     * The injection boundary: a data value that looks like a formula produces no
     * formula at all. The worker writes it as a string cell.
     */
    public function test_a_data_value_that_looks_like_a_formula_creates_no_formula(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array('operation' => 'table', 'headers' => array('Note'), 'data' => array(array('=1+1'))),
            array('user_id' => 2),
        );

        $sheet = $writer->calls[0]['sheets'][0];

        $this->assertArrayNotHasKey('formulas', $sheet);
        $this->assertSame('=1+1', $sheet['rows'][0]['c1']);
    }

    public function test_a_formula_can_target_a_named_sheet(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array(
                'operation' => 'multi_sheet',
                'sheets' => array(
                    array('name' => 'Q1', 'headers' => array('A'), 'data' => array(array(1))),
                    array('name' => 'Q2', 'headers' => array('A'), 'data' => array(array(2))),
                ),
                'formulas' => array(array('cell' => 'B2', 'formula' => 'A2*2', 'sheet' => 'Q2')),
            ),
            array('user_id' => 2),
        );

        $sheets = $writer->calls[0]['sheets'];

        $this->assertArrayNotHasKey('formulas', $sheets[0]);
        $this->assertSame(array('B2' => 'A2*2'), $sheets[1]['formulas']);
    }

    // ─── Multi-sheet ───────────────────────────────────────────────────────

    public function test_multi_sheet_writes_every_populated_sheet(): void
    {
        $writer = $this->writer();

        $result = $this->tool($writer)->execute(
            array(
                'operation' => 'multi_sheet',
                'sheets' => array(
                    array('name' => 'Q1', 'data' => array(array(1))),
                    array('name' => 'Empty', 'data' => array()),
                ),
            ),
            array('user_id' => 2),
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $writer->calls[0]['sheets'], 'A sheet with no rows is dropped rather than written empty.');
        $this->assertSame(1, $result['data']['sheets']);
    }

    public function test_an_illegal_sheet_name_is_reduced(): void
    {
        $writer = $this->writer();

        $this->tool($writer)->execute(
            array('operation' => 'table', 'headers' => array('A'), 'data' => array(array(1)), 'title' => 'Q1/Q2: x*'),
            array('user_id' => 2),
        );

        $this->assertSame('Q1 Q2 x', $writer->calls[0]['sheets'][0]['name']);
    }

    // ─── Refusals and engine ───────────────────────────────────────────────

    public function test_nothing_to_write_is_a_validation_failure(): void
    {
        $result = $this->tool($this->writer())->execute(array('operation' => 'table'));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_missing_engine_is_named(): void
    {
        $result = $this->tool($this->writer(available: false))->execute(
            array('operation' => 'table', 'headers' => array('A'), 'data' => array(array(1))),
            array('user_id' => 2),
        );

        $this->assertFalse($result['success']);
        $this->assertSame('no_document_engine', $result['error']['code']);
    }

    public function test_it_stores_by_default(): void
    {
        $store = $this->recordingStore();

        $result = $this->tool($this->writer(), $store)->execute(
            array('operation' => 'table', 'headers' => array('A'), 'data' => array(array(1)), 'title' => 'Q1 Sales'),
            array('user_id' => 5),
        );

        $this->assertTrue($result['success']);
        $this->assertSame('q1-sales', $store->calls[0]['filename']);
        $this->assertSame(71, $result['data']['media'][0]['attachment_id'] ?? null);
    }

    // ─── Doubles ───────────────────────────────────────────────────────────

    private function writer(bool $available = true): OfficeWriterInterface
    {
        $path = \tempnam(\sys_get_temp_dir(), 'nvoos-pro-xlsx-');
        $this->assertNotFalse($path);
        $this->temporary[] = $path;

        return new class ($available, $path) implements OfficeWriterInterface {
            /** @var list<array{sheets: array<int, mixed>, options: array<string, mixed>}> */
            public array $calls = array();

            public function __construct(
                private readonly bool $available,
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
                \file_put_contents($this->path, 'PK test');

                return array(
                    'path' => $this->path,
                    'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'bytes' => 5120,
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
                    id: 71,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: 5120,
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
    ): ProExcelDocumentTool {
        return new ProExcelDocumentTool($this->errors, $writer, $store);
    }
}
