<?php

/**
 * Filter a Food & Beverage data table by a column value and/or a date range.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Search_Table` and
 * `WP_MCP_AI_Fnb_Data_Source::search_table()` (§164.71). The matching rules are
 * the WordPress ones, including the parts a reader has to be told:
 *
 * 1. **A column with no value matches "is present".** `column: item_id` and no
 *    `value` keeps the rows where that cell is non-empty; with a value it is a
 *    case-insensitive substring test.
 * 2. **A row with no parseable date passes a date filter.** The range is only
 *    applied when the date cell parses; an empty or malformed one is not
 *    excluded. That is the same shape as §164.69's "never contacted is not a
 *    breach", and it is pinned rather than corrected.
 * 3. **`column` and `date_column` are keys, not labels.** WordPress runs them
 *    through `sanitize_key()`, so `Item ID` becomes `itemid` and resolves to
 *    nothing. A caller passes canonical names (`item_id`).
 *
 * The scan is a full pass over the table's rows, capped at `limit` matches, as
 * in WordPress: there is no index to push the filter into, because the table is
 * whatever the host's reader returned.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Fnb;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Support\Text;

class FnbSearchTableTool extends FnbTool
{
    /** The WordPress default match cap. */
    public const DEFAULT_LIMIT = 500;

    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbTableSource $tables,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_search_table';
    }

    public function getName(): string
    {
        return 'F&B Search Table';
    }

    public function getDescription(): string
    {
        return 'Filter a Food & Beverage data table by a column value (e.g. item_id, ingredient_id, supplier_id) and/or a date range, returning the matching rows.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'table_id'    => array(
                    'type'        => 'string',
                    'description' => 'Spec table ID: 1.0…17.0.',
                ),
                'column'      => array(
                    'type'        => 'string',
                    'description' => 'Canonical column to filter on (e.g. item_id, ingredient_id, date).',
                ),
                'value'       => array(
                    'type'        => 'string',
                    'description' => 'Value to match (case-insensitive substring). Omit to match rows where the column is present.',
                ),
                'date_column' => array(
                    'type'        => 'string',
                    'description' => 'Date column for range filtering (default "date").',
                    'default'     => 'date',
                ),
                'from'        => array(
                    'type'        => 'string',
                    'description' => 'Start date (Y-m-d).',
                ),
                'to'          => array(
                    'type'        => 'string',
                    'description' => 'End date (Y-m-d).',
                ),
                'limit'       => array(
                    'type'        => 'integer',
                    'description' => 'Maximum rows (default ' . self::DEFAULT_LIMIT . ').',
                    'default'     => self::DEFAULT_LIMIT,
                    'minimum'     => 1,
                ),
            ),
            'required'             => array('table_id'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, 'read');

        $tableId    = Text::singleLine($this->scalar($arguments, 'table_id'));
        $column     = Text::sanitizeKey($this->scalar($arguments, 'column'));
        $value      = Text::singleLine($this->scalar($arguments, 'value'));
        $dateColumn = isset($arguments['date_column'])
            ? Text::sanitizeKey($this->scalar($arguments, 'date_column'))
            : 'date';
        $from       = isset($arguments['from']) ? Text::singleLine($this->scalar($arguments, 'from')) : null;
        $to         = isset($arguments['to']) ? Text::singleLine($this->scalar($arguments, 'to')) : null;
        $limit      = $this->limit($arguments);

        $table = $this->tables->getTable($tableId);

        if ($this->errors->isError($table)) {
            return $table;
        }

        $headerIndex = $table['header_index'];
        $matches     = array();

        foreach ($table['rows'] as $row) {
            if ('' !== $column) {
                $cell = (string) FnbTableCatalogue::cell($tableId, $headerIndex, $row, $column);

                if ('' === $value) {
                    if ('' === $cell) {
                        continue;
                    }
                } elseif (false === stripos($cell, $value)) {
                    continue;
                }
            }

            if (null !== $from || null !== $to) {
                $cellDate = FnbTableCatalogue::dateOnly(
                    FnbTableCatalogue::cell($tableId, $headerIndex, $row, $dateColumn)
                );

                // An unparseable or absent date is not excluded; only a date
                // that parses is compared. Pinned from the WordPress code.
                if (null !== $from && '' !== $cellDate && $cellDate < $from) {
                    continue;
                }

                if (null !== $to && '' !== $cellDate && $cellDate > $to) {
                    continue;
                }
            }

            $matches[] = $row;

            if (count($matches) >= $limit) {
                break;
            }
        }

        return $this->success(
            sprintf('Matched %d of %d rows in %s.', count($matches), $table['count'], $table['label']),
            array(
                'table_id'     => $tableId,
                'label'        => $table['label'],
                'columns'      => $table['columns'],
                'header_index' => $headerIndex,
                'rows'         => $matches,
                'count'        => count($matches),
                // WordPress hardcodes `fnb_data_source` here even when the rows
                // came from a CPT; one value is not a decision, so the table's
                // own source is reported.
                'source'       => $table['source'],
            )
        );
    }

    /**
     * The match cap, floored to the WordPress default.
     *
     * @param array<string, mixed> $arguments
     */
    private function limit(array $arguments): int
    {
        if (! isset($arguments['limit'])) {
            return self::DEFAULT_LIMIT;
        }

        $limit = abs((int) $arguments['limit']);

        return $limit > 0 ? $limit : self::DEFAULT_LIMIT;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function scalar(array $arguments, string $key): string
    {
        $value = $arguments[ $key ] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
