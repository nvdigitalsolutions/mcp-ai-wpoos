<?php

/**
 * `db_select` — the structured read.
 *
 * Proposal 167 phase P4, task 4.4. WordPress parity: same slug, same schema
 * (including the keyset `cursor`), same payload.
 *
 * Columns, filters, ordering, limit and a keyset cursor; every value bound;
 * every identifier resolved from the scope-filtered catalogue. There is no SQL
 * string anywhere in the argument surface.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Database;

final class SelectTool extends DatabaseTool
{
    public function getSlug(): string
    {
        return 'db_select';
    }

    public function getName(): string
    {
        return 'Select Database Rows';
    }

    public function getDescription(): string
    {
        return 'Read rows from one table or view inside the assistant scope, with optional filters, ordering and keyset paging. Only columns the scope allows are returned; sensitive columns are masked.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array_merge(
                $this->connectionProperties(),
                array(
                    'relation' => $this->relationSchema(),
                    'columns'  => array(
                        'type'        => 'array',
                        'description' => 'Columns to return. Omit for every column the scope allows.',
                        'maxItems'    => 40,
                        'items'       => array('type' => 'string'),
                    ),
                    'filters'  => $this->filterSchema(),
                    'order'    => $this->orderSchema(),
                    'limit'    => array(
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'description' => 'Rows to return. The scope cap wins when it is lower.',
                    ),
                    'offset'   => array(
                        'type'        => 'integer',
                        'minimum'     => 0,
                        'description' => 'Row offset. Refused above the scope ceiling; prefer cursor paging.',
                    ),
                    'cursor'   => array(
                        'type'        => 'string',
                        'description' => 'The next_cursor value from a previous call, for stable keyset paging.',
                    ),
                ),
            ),
            'required'             => array('relation'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        return $this->guard(function () use ($arguments, $context): array {
            $connection = $this->connection($arguments, $context);
            $scope      = $this->scope($arguments);
            $query      = $this->selectQuery($arguments);
            $result     = $this->databases->select($connection, $scope, $query);

            return $this->envelope(
                $result,
                array(
                    'connection' => $connection,
                    'relation'   => $query->relation,
                ),
            );
        });
    }
}
