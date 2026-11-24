<?php

/**
 * Build an Excel workbook from structured sheets.
 *
 * Proposal 164 §164.4.3. The sheet shape is the media worker's (`exceljs`):
 * `{name, columns, rows, style}` where `columns` are `{header, key, width}` and
 * `rows` are arrays or objects keyed by column key.
 *
 * The tool validates the outer shape — a workbook needs at least one sheet with
 * at least one row — because the route would otherwise accept `[{ }]` and write
 * an empty workbook that looks like a success.
 *
 * @package Nvoos\Core
 * @since   2.4.0
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

class GenerateExcelTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * @param ErrorFactoryInterface             $e      Error factory.
     * @param OfficeWriterInterface             $writer The bound office writer.
     * @param FileVisibilityStoreInterface|null $store  Optional: persist the file.
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
        return 'generate_excel';
    }

    public function getName(): string
    {
        return 'Generate Excel Spreadsheet';
    }

    public function getDescription(): string
    {
        return 'Build an .xlsx workbook from one or more sheets, each with named columns and rows, and return the stored file.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'sheets' => array(
                    'type' => 'array',
                    'description' => 'One entry per worksheet: {"name": "Q1", "columns": [{"header": "Region", "key": "region", "width": 20}], "rows": [{"region": "EMEA"}]}. Rows may be arrays (positional) or objects (keyed by column key).',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'name' => array('type' => 'string'),
                            'columns' => array('type' => 'array', 'items' => array()),
                            'rows' => array('type' => 'array', 'items' => array()),
                        ),
                    ),
                    'minItems' => 1,
                ),
                'creator' => array(
                    'type' => 'string',
                    'description' => 'Workbook creator, recorded in the file properties.',
                ),
                'title' => array(
                    'type' => 'string',
                    'description' => 'Workbook title, recorded in the file properties.',
                ),
                'filename' => array(
                    'type' => 'string',
                    'description' => 'Output filename without the extension. Defaults to the title, then "spreadsheet".',
                ),
            ) + GeneratedMediaPersister::parameters(),
            'required' => array('sheets'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'upload_files';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('requires-capability', 'state-changing', 'write');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $sheets = $this->sheets($arguments['sheets'] ?? array());

        if (array() === $sheets) {
            return $this->errors->validationFailed(
                'At least one sheet with one row is required.',
                array('sheets' => array('Give a sheet with columns and rows.')),
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
                array(
                    'creator' => $this->stringParam($arguments, 'creator'),
                    'title' => $this->stringParam($arguments, 'title'),
                )
            );

            $payload = array(
                'format' => 'xlsx',
                'sheets' => \count($sheets),
                'rows' => $this->rowCount($sheets),
                'mime_type' => $result['mime_type'],
                'bytes' => $result['bytes'],
            );

            if ($this->boolParam($arguments, 'save_to_media', true) && null !== $this->store) {
                $payload = (new GeneratedMediaPersister($this->store))->attachFile(
                    $payload,
                    $result['path'],
                    $context,
                    $this->outputName($arguments),
                    $this->stringParam($arguments, 'visibility', FileVisibilityStoreInterface::VISIBILITY_PUBLIC),
                    $result['mime_type'],
                );
            }

            return $this->success('Spreadsheet generated.', $payload);
        } catch (\Throwable $e) {
            return $this->errors->create('excel_failed', $e->getMessage());
        }
    }

    /**
     * Keep the sheets that carry data, and drop the ones that would write an
     * empty worksheet that looks like a success.
     *
     * @param mixed $value Raw `sheets` argument.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sheets(mixed $value): array
    {
        if (!\is_array($value)) {
            return array();
        }

        $sheets = array();

        foreach ($value as $sheet) {
            if (!\is_array($sheet)) {
                continue;
            }

            $rows = isset($sheet['rows']) && \is_array($sheet['rows']) ? \array_values($sheet['rows']) : array();

            if (array() === $rows) {
                continue;
            }

            $sheets[] = array(
                'name' => $this->stringParam($sheet, 'name'),
                'columns' => isset($sheet['columns']) && \is_array($sheet['columns'])
                    ? \array_values($sheet['columns'])
                    : array(),
                'rows' => $rows,
            ) + (isset($sheet['style']) && \is_array($sheet['style']) ? array('style' => $sheet['style']) : array());
        }

        return $sheets;
    }

    /**
     * Total rows across the sheets, for the payload.
     *
     * @param array<int, array<string, mixed>> $sheets
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
     * @param array<string, mixed> $arguments
     */
    private function outputName(array $arguments): string
    {
        foreach (array('filename', 'file_name', 'title') as $key) {
            $value = $this->stringParam($arguments, $key);

            if ('' !== $value) {
                return $this->stem($value) . '.xlsx';
            }
        }

        return 'spreadsheet.xlsx';
    }

    private function stem(string $value): string
    {
        $stem = \pathinfo(\basename($value), PATHINFO_FILENAME);
        $stem = (string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower($stem));

        return '' !== \trim($stem, '-') ? \trim($stem, '-') : 'spreadsheet';
    }
}
