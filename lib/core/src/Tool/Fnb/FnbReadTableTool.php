<?php

/**
 * Read a Food & Beverage data table.
 *
 * Port of `WP_MCP_AI_Tool_Fnb_Read_Table` (§164.71). The WordPress tool pages a
 * resolved table with `limit`, reports `count` against `total_rows`, and says
 * whether the answer is truncated.
 *
 * Two arguments do not cross:
 *
 * - `connection_id` selected a Drive connection. With one table source per host
 *   there is nothing to select, so the argument is absent rather than accepted
 *   and ignored — the rejected-then-ignored shape this programme keeps finding.
 * - `connection_id`/`connection_name` in the payload go with it. `source` stays,
 *   and reads `fnb_data_source`.
 *
 * `limit` keeps the WordPress floor: a value below one falls back to 200 rather
 * than returning nothing, because "zero rows" and "you asked for zero" are the
 * same payload and only one of them is useful.
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

class FnbReadTableTool extends FnbTool
{
    /** The WordPress default page size. */
    public const DEFAULT_LIMIT = 200;

    public function __construct(
        ErrorFactoryInterface $e,
        protected readonly FnbTableSource $tables,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'fnb_read_table';
    }

    public function getName(): string
    {
        return 'F&B Read Table';
    }

    public function getDescription(): string
    {
        return 'Read a Food & Beverage data table (1.0 Menu … 17.0 Asset log, plus "assumptions") as rows with source citations. Use before any metric or report so every figure is grounded in data.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'table_id' => array(
                    'type'        => 'string',
                    'description' => 'Spec table ID: 1.0…17.0 or "assumptions".',
                ),
                'limit'    => array(
                    'type'        => 'integer',
                    'description' => 'Maximum rows to return (default ' . self::DEFAULT_LIMIT . ').',
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

        $tableId = Text::singleLine($this->scalar($arguments, 'table_id'));
        $limit   = $this->limit($arguments);

        // An empty or unknown id answers `unknown_table` from the source, as it
        // does in WordPress.
        $table = $this->tables->getTable($tableId);

        if ($this->errors->isError($table)) {
            return $table;
        }

        $rows = array_slice($table['rows'], 0, $limit);

        return $this->success(
            sprintf('Read %d of %d rows from %s.', count($rows), $table['count'], $table['label']),
            array(
                'table_id'   => $table['table_id'],
                'label'      => $table['label'],
                'columns'    => $table['columns'],
                'rows'       => $rows,
                'count'      => count($rows),
                'total_rows' => $table['count'],
                'truncated'  => count($rows) < $table['count'],
                'source'     => $table['source'],
            )
        );
    }

    /**
     * The page size, floored to the WordPress default.
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
}
