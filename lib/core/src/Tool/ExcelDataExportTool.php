<?php

/**
 * Write a row-and-column dataset to an .xlsx workbook.
 *
 * Port of `WP_MCP_AI_Tool_Excel_Data_Export` under Proposal 164 §164.31 (slice
 * B). It is the write half of the pair whose read half — `excel_data_import` —
 * already ships, so a caller can round-trip a table without leaving the runtime.
 *
 * ## Rows are keyed, not positional
 *
 * The worker's `excel` route accepts both, but a positional row binds to the
 * column order by index. A short row then shifts every cell after the gap, and
 * the result is a spreadsheet that looks plausible and is wrong. Each cell is
 * addressed by column key here, so a ragged row leaves `null`s in the columns it
 * skipped instead of moving its neighbours.
 *
 * ## No silent downgrade to CSV
 *
 * The WordPress tool falls back to CSV when PhpSpreadsheet is absent and returns
 * whatever it managed. That changes the format of a file whose name still says
 * `.xlsx`, which is the kind of surprise a caller only discovers downstream. If
 * no office writer is bound this refuses with `no_document_engine`, naming the
 * missing engine, in line with the honesty rule in §164.4.8.
 *
 * ## Sheet names are sanitised here
 *
 * Excel refuses a worksheet name longer than 31 characters or containing any of
 * `[ ] : * ? / \`. The worker hands the name straight to `addWorksheet()`, so an
 * unsanitised title from the caller is a hard failure at the far end of the
 * request. The title is reduced to a legal name before it leaves this process.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Media\GeneratedMediaPersister;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\OfficeWriterInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

class ExcelDataExportTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * The longest worksheet name Excel accepts.
     */
    private const MAX_SHEET_NAME = 31;

    /**
     * @param ErrorFactoryInterface             $e      Error factory.
     * @param OfficeWriterInterface             $writer The bound office writer.
     * @param FileVisibilityStoreInterface|null $store  Optional: persist the workbook.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly OfficeWriterInterface $writer,
        private readonly ?FileVisibilityStoreInterface $store = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'excel_data_export';
    }

    public function getName(): string
    {
        return 'Excel Data Export';
    }

    public function getDescription(): string
    {
        return 'Write an array of rows to an .xlsx worksheet, optionally with a header row, and return the stored file. The write half of excel_data_import.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'data' => array(
                    'type' => 'array',
                    'description' => 'Rows to export. Each row is an array of cell values; a cell is a string, number, boolean or null.',
                    'items' => array(
                        'type' => 'array',
                        'items' => array(
                            'anyOf' => array(
                                array('type' => 'string'),
                                array('type' => 'number'),
                                array('type' => 'boolean'),
                                array('type' => 'null'),
                            ),
                        ),
                    ),
                    'minItems' => 1,
                ),
                'headers' => array(
                    'type' => 'array',
                    'description' => 'Optional column headers. When present they become row 1 and each row fills the columns by position.',
                    'items' => array('type' => 'string'),
                ),
                'title' => array(
                    'type' => 'string',
                    'description' => 'Worksheet and workbook title. Reduced to a legal Excel sheet name (31 characters, no [ ] : * ? / \\).',
                ),
                'filename' => array(
                    'type' => 'string',
                    'description' => 'Output filename without the extension. Defaults to the title, then "export".',
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array('data'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    /**
     * Canonical flags, minus the `local-only` the WordPress tool declares.
     *
     * `local-only` is true there because PhpSpreadsheet runs in the WordPress
     * process. Here the default tier is the media worker, so the rows leave the
     * host — declaring `local-only` would tell the read-only profile and the
     * necessity gate that nothing left the estate when something did.
     *
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $rows = $this->rows($arguments['data'] ?? null);

        if (null !== $rows['error']) {
            return $this->errors->validationFailed($rows['error'], $rows['fields']);
        }

        if (array() === $rows['rows']) {
            return $this->errors->validationFailed(
                'At least one row is required.',
                array('data' => array('Give one or more rows of cell values.')),
            );
        }

        if (!$this->writer->isAvailable()) {
            return $this->errors->create(
                'no_document_engine',
                'No office writer is bound on this host. Configure the media worker (MEDIA_WORKER_URL).',
            );
        }

        $headers = $this->givenHeaders($arguments['headers'] ?? null);
        $labels = $this->labels($headers, $rows['width']);
        $withHeader = array() !== $headers;
        $title = $this->sheetName($this->stringParam($arguments, 'title'));

        $sheets = array(
            array(
                'name' => $title,
                'columns' => $this->columns($labels, $withHeader),
                'rows' => $this->keyedRows($rows['rows'], \count($labels)),
                'style' => array('headerFill' => 'FFEFEFEF'),
            ),
        );

        try {
            $result = $this->writer->xlsx(
                $sheets,
                array('title' => $this->stringParam($arguments, 'title'))
            );

            $payload = array(
                'format' => 'xlsx',
                'sheet_name' => $title,
                'columns' => \count($labels),
                'rows' => \count($rows['rows']),
                'has_header' => $withHeader,
                'mime_type' => $result['mime_type'],
                'bytes' => $result['bytes'],
            );

            if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    $result['path'],
                    $context,
                    $this->outputName($arguments, $title),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    $result['mime_type'],
                );
            }

            return $this->success(
                sprintf('Exported %d row(s) to a workbook.', \count($rows['rows'])),
                $payload,
            );
        } catch (\Throwable $e) {
            return $this->errors->create('excel_failed', $e->getMessage());
        }
    }

    /**
     * Normalise `data` into a rectangular list of cell values.
     *
     * A cell outside the four declared types is a caller mistake, not something
     * to coerce: a nested array would be stringified to "[object Object]" by the
     * writer and produce a file that looks like a success. The offending row and
     * column are named so the caller can find it.
     *
     * @param mixed $value Raw `data` argument.
     *
     * @return array{rows: array<int, array<int, mixed>>, width: int, error: string|null, fields: array<string, list<string>>}
     */
    private function rows(mixed $value): array
    {
        if (!\is_array($value)) {
            return array(
                'rows' => array(),
                'width' => 0,
                'error' => 'The data must be an array of rows.',
                'fields' => array('data' => array('Give an array of rows.')),
            );
        }

        $rows = array();
        $width = 0;

        foreach (\array_values($value) as $rowIndex => $row) {
            if (!\is_array($row)) {
                return array(
                    'rows' => array(),
                    'width' => 0,
                    'error' => sprintf('Row %d is not an array of cell values.', $rowIndex + 1),
                    'fields' => array('data' => array(sprintf('Row %d must be an array.', $rowIndex + 1))),
                );
            }

            $cells = array();

            foreach (\array_values($row) as $cellIndex => $cell) {
                if (null !== $cell && !\is_scalar($cell)) {
                    return array(
                        'rows' => array(),
                        'width' => 0,
                        'error' => sprintf(
                            'Row %d, column %d holds %s. A cell must be a string, number, boolean or null.',
                            $rowIndex + 1,
                            $cellIndex + 1,
                            \get_debug_type($cell),
                        ),
                        'fields' => array(
                            'data' => array(sprintf('Row %d, column %d is not a scalar value.', $rowIndex + 1, $cellIndex + 1)),
                        ),
                    );
                }

                $cells[] = $cell;
            }

            $width = \max($width, \count($cells));
            $rows[] = $cells;
        }

        return array('rows' => $rows, 'width' => $width, 'error' => null, 'fields' => array());
    }

    /**
     * Column labels, one per column, filling any the caller left unnamed.
     *
     * `$width` is the widest row, so a short header list still names every
     * column instead of leaving the tail unaddressed.
     *
     * @param list<string> $given The caller's headers, possibly empty.
     *
     * @return list<string>
     */
    private function labels(array $given, int $width): array
    {
        $labels = array();

        for ($index = 0; $index < \max($width, \count($given)); $index++) {
            $label = $given[$index] ?? '';

            $labels[] = '' !== \trim($label) ? $label : 'Column ' . ($index + 1);
        }

        return $labels;
    }

    /**
     * The caller's headers, when they are a list of strings.
     *
     * @param mixed $value Raw `headers` argument.
     *
     * @return list<string>
     */
    private function givenHeaders(mixed $value): array
    {
        if (!\is_array($value)) {
            return array();
        }

        $headers = array();

        foreach (\array_values($value) as $header) {
            $headers[] = \is_scalar($header) ? (string) $header : '';
        }

        return $headers;
    }

    /**
     * Column definitions for the worker.
     *
     * `header` is only emitted when the caller supplied one, because ExcelJS
     * writes a header row for every column that declares a header. Generating
     * "Column 1" labels for an export that asked for no header row would add a
     * row the caller did not request.
     *
     * @param list<string> $labels     Resolved labels.
     * @param bool         $withHeader Whether a header row exists.
     *
     * @return list<array<string, mixed>>
     */
    private function columns(array $labels, bool $withHeader): array
    {
        $columns = array();

        foreach ($labels as $index => $label) {
            $column = array(
                'key' => $this->columnKey($index),
                'width' => 15,
            );

            if ($withHeader) {
                $column['header'] = $label;
            }

            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * Bind each row to its columns by key so a short row cannot shift its neighbours.
     *
     * @param array<int, array<int, mixed>> $rows    Normalised rows.
     * @param int                           $columns Column count.
     *
     * @return list<array<string, mixed>>
     */
    private function keyedRows(array $rows, int $columns): array
    {
        $keyed = array();

        foreach ($rows as $row) {
            $entry = array();

            for ($index = 0; $index < $columns; $index++) {
                $entry[$this->columnKey($index)] = $row[$index] ?? null;
            }

            $keyed[] = $entry;
        }

        return $keyed;
    }

    /**
     * A stable column key, `c1`..`cN`.
     *
     * Prefixed because ExcelJS treats a numeric-looking key as an index.
     */
    private function columnKey(int $index): string
    {
        return 'c' . ($index + 1);
    }

    /**
     * Reduce a title to a legal worksheet name.
     *
     * Excel rejects `[ ] : * ? / \`, forbids a leading or trailing apostrophe, and
     * caps the name at 31 characters. Anything left empty falls back to `Export`.
     *
     * @param string $title Caller-supplied title.
     */
    private function sheetName(string $title): string
    {
        $name = \trim((string) \preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $title));
        $name = \trim((string) \preg_replace('/\s+/', ' ', $name), "'");
        $name = \trim($name);

        if (\function_exists('mb_substr')) {
            $name = \mb_substr($name, 0, self::MAX_SHEET_NAME);
        } else {
            $name = \substr($name, 0, self::MAX_SHEET_NAME);
        }

        return '' === $name ? 'Export' : $name;
    }

    /**
     * The display name handed to the registry.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function outputName(array $arguments, string $sheet): string
    {
        foreach (array('filename', 'file_name', 'title') as $key) {
            $value = $arguments[$key] ?? '';

            if (\is_string($value) && '' !== \trim($value)) {
                return $this->stem($value);
            }
        }

        return $this->stem($sheet);
    }

    /**
     * A lowercase, hyphenated stem safe to hand to the store as a display name.
     */
    private function stem(string $value): string
    {
        $stem = \pathinfo(\basename($value), PATHINFO_FILENAME);
        $stem = (string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower($stem));
        $stem = \trim($stem, '-');

        return '' === $stem ? 'export' : $stem;
    }
}
