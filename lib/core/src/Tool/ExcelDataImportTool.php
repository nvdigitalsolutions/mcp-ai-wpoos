<?php

/**
 * Import a spreadsheet into the agent's context as a matrix.
 *
 * Proposal 164 §164.4.3. The WordPress tool carries PhpSpreadsheet; this port
 * reads through the media worker, which already parses `xlsx`, `csv`, `tsv`,
 * `json`, `jsonl` and `ndjson` with ExcelJS.
 *
 * Three decisions worth stating, because two of them deviate from WordPress:
 *
 *  - **`max_rows` defaults to 500, not "all".** WordPress defaults to the whole
 *    file, which is fine for a PHP caller holding an array and wrong for a
 *    context window. A truncated answer says `truncated: true` and reports
 *    `source_rows`, so the caller knows to loop.
 *  - **`sheet` is a name or a 1-based index**, matching the worker. The
 *    WordPress tool's `sheet_index` is 0-based; accepting both would make
 *    "sheet 1" mean two different things depending on which runtime answered.
 *  - **`has_headers: false` reconstructs the first row.** The worker always
 *    treats row 1 as the header, so the tool synthesises Excel-style column
 *    names (`A`, `B`, …) and puts the captured row back as data.
 *
 * Values stay strings. A type guessed wrong (a phone number, a leading-zero
 * code) is worse than a string the caller can coerce with the column in hand.
 *
 * @package Nvoos\Core
 * @since   2.4.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\TabularReaderInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

class ExcelDataImportTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * Default row budget.
     *
     * A spreadsheet is the one import that can be enormous, and 500 rows is
     * already a large prompt. `0` means "everything the reader will give me".
     */
    public const DEFAULT_MAX_ROWS = 500;

    /**
     * @param ErrorFactoryInterface $e      Error factory.
     * @param TabularReaderInterface $reader The bound reader.
     * @param FileStoreInterface|null $files Optional: resolve `file_id` to a path.
     */
    public function __construct(
        ErrorFactoryInterface $e,
        private readonly TabularReaderInterface $reader,
        private readonly ?FileStoreInterface $files = null,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'excel_data_import';
    }

    public function getName(): string
    {
        return 'Import Excel Data';
    }

    public function getDescription(): string
    {
        return 'Read a stored spreadsheet or delimited file into columns and rows. Values are strings; the first row is treated as headers unless has_headers is false. Truncated results say so.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'file_id' => array(
                    'type' => 'integer',
                    'description' => 'Stored file id of the workbook to read.',
                    'minimum' => 1,
                ),
                'sheet' => array(
                    'type' => 'string',
                    'description' => 'Worksheet name, or a 1-based index as a string ("2"). Defaults to the first sheet.',
                ),
                'has_headers' => array(
                    'type' => 'boolean',
                    'description' => 'Whether the first row holds column headers. Default true; false synthesises A, B, C… and keeps the first row as data.',
                    'default' => true,
                ),
                'max_rows' => array(
                    'type' => 'integer',
                    'description' => 'Maximum data rows to return. Default 500; use 0 for every row the reader will give.',
                    'minimum' => 0,
                    'default' => self::DEFAULT_MAX_ROWS,
                ),
            ),
            'required' => array('file_id'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('read-only', 'requires-capability');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $fileId = $this->intParam($arguments, 'file_id');

        if ($fileId <= 0) {
            return $this->errors->validationFailed(
                'A stored file id is required.',
                array('file_id' => array('Give the id of a stored workbook.')),
            );
        }

        if (null === $this->files) {
            return $this->errors->create(
                'no_file_store',
                'No file registry is bound on this host, so a file id cannot be resolved.',
            );
        }

        $path = $this->files->getPath($fileId);

        if (null === $path || '' === $path) {
            return $this->errors->notFound('There is no stored file with id ' . $fileId . '.');
        }

        if (!$this->reader->isAvailable()) {
            return $this->errors->create(
                'no_tabular_engine',
                'No tabular reader is bound on this host. Configure the media worker (MEDIA_WORKER_URL).',
            );
        }

        $hasHeaders = $this->boolParam($arguments, 'has_headers', true);
        $maxRows = $this->intParam($arguments, 'max_rows', self::DEFAULT_MAX_ROWS);

        try {
            $matrix = $this->reader->read(
                $path,
                array(
                    'sheet' => $this->stringParam($arguments, 'sheet'),
                    'max_rows' => max(0, $maxRows),
                )
            );
        } catch (\Throwable $e) {
            return $this->errors->create('excel_import_failed', $e->getMessage());
        }

        $columns = $matrix['columns'];
        $rows = $matrix['rows'];
        $meta = $matrix['meta'];

        if (!$hasHeaders) {
            array_unshift($rows, $columns);
            $columns = $this->syntheticColumns(\count($columns));
            unset($meta['truncated']);
            $meta['truncated'] = false;
        }

        $sourceRows = isset($meta['source_total']) ? (int) $meta['source_total'] : \count($rows);

        return $this->success(
            'Spreadsheet read.',
            array(
                'file_id' => $fileId,
                'sheet' => $meta['sheet'] ?? null,
                'columns' => $columns,
                'rows' => $rows,
                'row_count' => \count($rows),
                'source_rows' => $sourceRows,
                'has_headers' => $hasHeaders,
                'truncated' => (bool) ($meta['truncated'] ?? false),
                'engine' => $meta['engine'] ?? null,
            ),
        );
    }

    /**
     * Excel-style column names for a headerless sheet: A, B, … Z, AA, AB, …
     *
     * @return list<string>
     */
    private function syntheticColumns(int $count): array
    {
        $columns = array();

        for ($index = 0; $index < $count; $index++) {
            $columns[] = $this->columnName($index + 1);
        }

        return $columns;
    }

    /**
     * 1 => A, 26 => Z, 27 => AA.
     */
    private function columnName(int $number): string
    {
        $name = '';

        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $name = \chr(65 + $remainder) . $name;
            $number = (int) (($number - $remainder - 1) / 26);
        }

        return $name;
    }
}
