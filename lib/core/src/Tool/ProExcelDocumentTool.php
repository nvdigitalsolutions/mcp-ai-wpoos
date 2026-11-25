<?php

/**
 * Build a workbook from data, a table, or several sheets — with freeze panes,
 * auto-filter and formulas.
 *
 * Port of `WP_MCP_AI_Tool_Pro_Excel_Document` under Proposal 164 §164.35/§164.36.
 * The WordPress tool declares four operations; this one implements three. `chart`
 * is refused rather than accepted: the worker writes values, columns, views,
 * filters and formulas, and a chart would need a fourth thing it does not do.
 *
 * ## Why the worker changed first
 *
 * §164.33 deferred this tool because the worker's `excel` route wrote values
 * only, so `auto_filter`, `freeze_header` and `formulas` would all have been
 * parameters that did nothing. §164.35 found all three are native to ExcelJS, so
 * the route now applies `views`, `autoFilter` and a validated formula map — and
 * the parameters here are real.
 *
 * ## The formula channel is explicit, and that is a security boundary
 *
 * OWASP's spreadsheet guidance recommends typed output precisely because a string
 * cell cannot become a formula. Values therefore pass through as data and stay
 * text — `=1+1` in a data cell is the *string* `=1+1` — and a formula only exists
 * if the caller asked for one in `formulas`, addressed by cell reference. The
 * worker validates the reference and strips a leading `=`, so both spellings work
 * and a typo cannot address a cell outside the sheet.
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

class ProExcelDocumentTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * Operations the tool accepts.
     *
     * `chart` is absent on purpose — see the class docblock.
     *
     * @var list<string>
     */
    private const OPERATIONS = array('generate', 'table', 'multi_sheet');

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
        return 'pro_excel_document';
    }

    public function getName(): string
    {
        return 'Pro Excel Document';
    }

    public function getDescription(): string
    {
        return 'Build an .xlsx from rows, a table, or several sheets, with an optional frozen header, auto-filter and explicit formulas. Returns the stored file when a file registry is available.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'operation' => array(
                    'type' => 'string',
                    'description' => '"table" renders one sheet from data and headers, "multi_sheet" renders several, "generate" is an alias for table. Charts are not supported.',
                    'enum' => self::OPERATIONS,
                ),
                'data' => array(
                    'type' => 'array',
                    'description' => 'Rows for the single-sheet operations. A cell is a string, number, boolean or null; a string that looks like a formula stays a string.',
                    'items' => array('type' => 'array', 'items' => array()),
                ),
                'headers' => array(
                    'type' => 'array',
                    'description' => 'Column headers for the single-sheet operations. They become row 1.',
                    'items' => array('type' => 'string'),
                ),
                'sheets' => array(
                    'type' => 'array',
                    'description' => 'Worksheets for the multi_sheet operation: [{"name": "Q1", "headers": [...], "data": [[...]]}].',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'name' => array('type' => 'string'),
                            'headers' => array('type' => 'array', 'items' => array('type' => 'string')),
                            'data' => array('type' => 'array', 'items' => array('type' => 'array', 'items' => array())),
                        ),
                    ),
                    'minItems' => 1,
                ),
                'formulas' => array(
                    'type' => 'array',
                    'description' => 'Formulas to place, each {"cell": "D2", "formula": "SUM(B2:C2)"}. The leading "=" is optional. Applied to the first sheet unless {"sheet": "Q1"} is given.',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'cell' => array('type' => 'string'),
                            'formula' => array('type' => 'string'),
                            'sheet' => array('type' => 'string'),
                        ),
                        'required' => array('cell', 'formula'),
                    ),
                ),
                'formatting' => array(
                    'type' => 'object',
                    'description' => 'Header styling and table affordances. All four are honoured by the bound writer.',
                    'properties' => array(
                        'header_bg' => array(
                            'type' => 'string',
                            'description' => 'ARGB or 6-digit hex header fill, e.g. "FFEFEFEF".',
                        ),
                        'header_bold' => array(
                            'type' => 'boolean',
                            'description' => 'Bold the header row. Default true.',
                        ),
                        'freeze_header' => array(
                            'type' => 'boolean',
                            'description' => 'Freeze the header row so it stays visible while scrolling.',
                        ),
                        'auto_filter' => array(
                            'type' => 'boolean',
                            'description' => 'Add an auto-filter over the header row.',
                        ),
                    ),
                ),
                'title' => array(
                    'type' => 'string',
                    'description' => 'Workbook title, recorded in the file properties and used for the default filename.',
                ),
                'author' => array(
                    'type' => 'string',
                    'description' => 'Accepted for parity with the WordPress tool; recorded in the payload.',
                ),
                'description' => array(
                    'type' => 'string',
                    'description' => 'Accepted for parity with the WordPress tool. This host renders deterministically and has no model to expand a description, so a description-only call is refused with no_content_generator.',
                ),
                'model' => array(
                    'type' => 'string',
                    'description' => 'Accepted for parity and ignored: this path has no model.',
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array('operation'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    /**
     * Canonical flags, without `requires-model` or `consumes-tokens`.
     *
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $operation = strtolower($this->stringParam($arguments, 'operation'));

        if (!in_array($operation, self::OPERATIONS, true)) {
            return $this->errors->validationFailed(
                'Choose an operation: ' . implode(', ', self::OPERATIONS) . '. Charts are not supported by the bound writer.',
                array('operation' => array('Unknown or unsupported operation.')),
            );
        }

        $sheets = $this->sheets($operation, $arguments);

        if (null === $sheets) {
            return $this->errors->validationFailed(
                'Nothing to write. Give data with headers, or at least one sheet with rows.',
                array('data' => array('At least one row is required.')),
            );
        }

        if (!$this->writer->isAvailable()) {
            return $this->errors->create(
                'no_document_engine',
                'No office writer is bound on this host. Configure the media worker (MEDIA_WORKER_URL).',
            );
        }

        try {
            $result = $this->writer->xlsx(
                $sheets,
                array('title' => $this->stringParam($arguments, 'title'))
            );

            $payload = array(
                'format' => 'xlsx',
                'operation' => $operation,
                'sheets' => \count($sheets),
                'rows' => $this->rowCount($sheets),
                'formulas' => \count($this->formulaMap($arguments)),
                'mime_type' => $result['mime_type'],
                'bytes' => $result['bytes'],
            );

            if ($this->shouldStore($arguments) && null !== $this->store) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    $result['path'],
                    $context,
                    $this->outputName($arguments),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    $result['mime_type'],
                );
            }

            return $this->success(
                sprintf('Built a workbook with %d sheet(s).', \count($sheets)),
                $payload,
            );
        } catch (\Throwable $e) {
            return $this->errors->create('excel_failed', $e->getMessage());
        }
    }

    /**
     * The sheet definitions the writer consumes, or null when there is nothing.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function sheets(string $operation, array $arguments): ?array
    {
        $formatting = $this->objectParam($arguments, 'formatting');
        $formulas = $this->formulaMap($arguments);

        $definitions = array();

        if ('multi_sheet' === $operation) {
            foreach ($this->objectListParam($arguments, 'sheets') as $sheet) {
                $rows = $this->rows($sheet['data'] ?? null);
                $headers = $this->headers($sheet['headers'] ?? null);

                if (array() === $rows) {
                    continue;
                }

                $definitions[] = $this->sheet(
                    (string) ($sheet['name'] ?? ''),
                    $headers,
                    $rows,
                    $formatting,
                    $formulas,
                );
            }

            return array() === $definitions ? null : $definitions;
        }

        $rows = $this->rows($arguments['data'] ?? null);

        if (array() === $rows) {
            return null;
        }

        return array(
            $this->sheet(
                $this->stringParam($arguments, 'title'),
                $this->headers($arguments['headers'] ?? null),
                $rows,
                $formatting,
                $formulas,
            ),
        );
    }

    /**
     * One sheet definition, applying the formatting the caller asked for.
     *
     * @param array<int, string>           $headers   Column labels.
     * @param array<int, array<int, mixed>> $rows     Cell values.
     * @param array<string, mixed>         $formatting Formatting arguments.
     * @param array<string, array<string, string>> $formulas Formulas keyed by sheet name.
     *
     * @return array<string, mixed>
     */
    private function sheet(
        string $name,
        array $headers,
        array $rows,
        array $formatting,
        array $formulas,
    ): array {
        $sheetName = $this->sheetName($name);
        $columns = $this->columns($headers);

        $definition = array(
            'name' => $sheetName,
            'columns' => $columns,
            'rows' => $this->keyedRows($rows, \count($columns)),
        );

        if (array() !== $headers) {
            $definition['style'] = array(
                'headerFont' => array('bold' => false !== ($formatting['header_bold'] ?? true)),
            );

            $fill = $formatting['header_bg'] ?? '';

            if (is_string($fill) && 1 === preg_match('/^[0-9A-Fa-f]{6,8}$/', $fill)) {
                $definition['style']['headerFill'] = strtoupper($fill);
            }
        }

        if (array() !== $headers && !empty($formatting['freeze_header'])) {
            $definition['views'] = array(
                array('state' => 'frozen', 'xSplit' => 0, 'ySplit' => 1, 'topLeftCell' => 'A2', 'activeCell' => 'A1'),
            );
        }

        if (array() !== $headers && !empty($formatting['auto_filter'])) {
            $definition['autoFilter'] = 'A1:' . $this->columnLetter(\count($headers) - 1) . '1';
        }

        $forSheet = $formulas[$sheetName] ?? array();

        if (array() !== $forSheet) {
            $definition['formulas'] = $forSheet;
        }

        return $definition;
    }

    /**
     * The formula map, grouped by sheet name.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<string, array<string, string>>
     */
    private function formulaMap(array $arguments): array
    {
        $list = $arguments['formulas'] ?? null;

        if (!is_array($list)) {
            return array();
        }

        $default = $this->sheetName($this->stringParam($arguments, 'title'));
        $grouped = array();

        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $cell = $entry['cell'] ?? '';
            $formula = $entry['formula'] ?? '';

            if (!is_string($cell) || !is_string($formula) || '' === trim($cell) || '' === trim($formula)) {
                continue;
            }

            $sheet = isset($entry['sheet']) && is_string($entry['sheet']) && '' !== trim($entry['sheet'])
                ? $this->sheetName($entry['sheet'])
                : $default;

            $grouped[$sheet][strtoupper(trim($cell))] = trim($formula);
        }

        return $grouped;
    }

    /**
     * Column definitions for the writer, from the header labels.
     *
     * @param array<int, string> $headers Column labels.
     *
     * @return array<int, array<string, mixed>>
     */
    private function columns(array $headers): array
    {
        if (array() === $headers) {
            return array();
        }

        $columns = array();

        foreach ($headers as $index => $header) {
            $columns[] = array(
                'header' => '' !== trim($header) ? $header : 'Column ' . ($index + 1),
                'key' => 'c' . ($index + 1),
                'width' => 15,
            );
        }

        return $columns;
    }

    /**
     * Bind each row to its columns by key so a short row cannot shift its neighbours.
     *
     * With no headers there are no keys, so rows stay positional.
     *
     * @param array<int, array<int, mixed>> $rows    Cell values.
     * @param int                           $columns Column count.
     *
     * @return array<int, mixed>
     */
    private function keyedRows(array $rows, int $columns): array
    {
        if (0 === $columns) {
            return $rows;
        }

        $keyed = array();

        foreach ($rows as $row) {
            $entry = array();

            for ($index = 0; $index < $columns; $index++) {
                $entry['c' . ($index + 1)] = $row[$index] ?? null;
            }

            $keyed[] = $entry;
        }

        return $keyed;
    }

    /**
     * Normalise a row list, refusing a cell that is not a scalar.
     *
     * @param mixed $value Raw rows.
     *
     * @return array<int, array<int, mixed>>
     */
    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return array();
        }

        $rows = array();

        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cells = array();

            foreach ($row as $cell) {
                $cells[] = null === $cell || is_scalar($cell) ? $cell : null;
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    /**
     * @param mixed $value Raw headers.
     *
     * @return array<int, string>
     */
    private function headers(mixed $value): array
    {
        if (!is_array($value)) {
            return array();
        }

        $headers = array();

        foreach ($value as $header) {
            $headers[] = is_scalar($header) ? (string) $header : '';
        }

        return $headers;
    }

    /**
     * The zero-based column index as a spreadsheet letter, e.g. 0 → A, 26 → AA.
     */
    private function columnLetter(int $index): string
    {
        $letter = '';
        $index = max(0, $index);

        do {
            $letter = \chr(65 + ($index % 26)) . $letter;
            $index = \intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $letter;
    }

    /**
     * Reduce a title to a legal worksheet name.
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

        return '' === $name ? 'Sheet1' : $name;
    }

    /**
     * Total rows across the sheets.
     *
     * @param array<int, array<string, mixed>> $sheets Sheet definitions.
     */
    private function rowCount(array $sheets): int
    {
        $total = 0;

        foreach ($sheets as $sheet) {
            $total += \count($sheet['rows'] ?? array());
        }

        return $total;
    }

    /**
     * Whether the registry row should be written.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function shouldStore(array $arguments): bool
    {
        foreach (array('save_to_media', 'upload') as $key) {
            if (array_key_exists($key, $arguments)) {
                return (bool) $arguments[$key];
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<int, mixed>
     */
    private function objectListParam(array $arguments, string $key): array
    {
        $value = $arguments[$key] ?? null;

        if (!is_array($value)) {
            return array();
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /**
     * The display name handed to the registry.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function outputName(array $arguments): string
    {
        foreach (array('filename', 'file_name', 'title') as $key) {
            $value = $arguments[$key] ?? '';

            if (is_string($value) && '' !== trim($value)) {
                return $this->stem($value);
            }
        }

        return 'spreadsheet';
    }

    /**
     * A lowercase, hyphenated stem safe to hand to the store as a display name.
     */
    private function stem(string $value): string
    {
        $stem = pathinfo(basename($value), PATHINFO_FILENAME);
        $stem = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($stem));
        $stem = trim($stem, '-');

        return '' === $stem ? 'spreadsheet' : $stem;
    }
}
