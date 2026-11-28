<?php

/**
 * `db_describe` — the scope-filtered catalogue.
 *
 * Proposal 167 phase P4, task 4.4. WordPress parity: same slug, same schema,
 * same `tables` / `count` / `truncated` payload, same 60-relation cap.
 *
 * A relation or column outside the scope is absent rather than redacted: the
 * catalogue is the grammar's identifier source, so "absent" and "cannot be
 * queried" are the same fact. That is why this tool cannot be used to discover
 * what exists — only what is granted.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Database;

final class DescribeTool extends DatabaseTool
{
    /**
     * Maximum relations returned in one call.
     */
    private const MAX_TABLES = 60;

    public function getSlug(): string
    {
        return 'db_describe';
    }

    public function getName(): string
    {
        return 'Describe Database Tables';
    }

    public function getDescription(): string
    {
        return 'Describe the tables and columns this assistant may read. Optionally name one relation (schema.table) to describe it in detail. Only relations inside the configured scope are returned.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'connection' => array(
                    'type'        => 'string',
                    'description' => 'Connection id from db_list_connections. Optional when only one connection is available.',
                ),
                'relation'   => array(
                    'type'        => 'string',
                    'description' => 'A single schema-qualified relation, e.g. "reporting.orders".',
                ),
            ),
            'required'             => array(),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        return $this->guard(function () use ($arguments, $context): array {
            $connection = $this->connection($arguments, $context);
            $relation   = $this->relation($arguments);
            $catalogue  = $this->databases->describe($connection, '' !== $relation ? $relation : null);

            $tables    = array();
            $truncated = false;

            foreach ($catalogue->tables as $table) {
                if (count($tables) >= self::MAX_TABLES) {
                    $truncated = true;
                    break;
                }

                $columns = array();

                foreach ($table->columns as $column) {
                    $columns[] = array(
                        'name'     => $column->name,
                        'type'     => $column->type,
                        'nullable' => $column->nullable,
                    );
                }

                $tables[] = array(
                    'relation'    => $table->key(),
                    'is_view'     => $table->isView,
                    'primary_key' => $table->primaryKey,
                    'columns'     => $columns,
                );
            }

            return array(
                'success'    => true,
                'connection' => $connection,
                'tables'     => $tables,
                'count'      => count($tables),
                'truncated'  => $truncated,
            );
        });
    }
}
