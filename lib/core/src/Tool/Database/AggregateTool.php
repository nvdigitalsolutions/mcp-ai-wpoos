<?php

/**
 * `db_aggregate` — grouped metrics.
 *
 * Proposal 167 phase P4, task 4.4. WordPress parity: same slug, same schema,
 * same payload.
 *
 * Metrics are a closed enum, never SQL: the grammar emits `COUNT(*)`,
 * `SUM(col)` and the PostgreSQL percentile form, and refuses a metric the
 * engine cannot compute. That is why `db_aggregate` cannot be used as a
 * general-purpose query builder.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Database;

final class AggregateTool extends DatabaseTool
{
    public function getSlug(): string
    {
        return 'db_aggregate';
    }

    public function getName(): string
    {
        return 'Aggregate Database Rows';
    }

    public function getDescription(): string
    {
        return 'Compute counts, sums, averages, minima, maxima, distinct counts or percentiles for one relation, optionally grouped by columns. Metrics are a closed list; raw SQL is not accepted.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array_merge(
                $this->connectionProperties(),
                array(
                    'relation' => $this->relationSchema(),
                    'group_by' => array(
                        'type'        => 'array',
                        'description' => 'Columns to group by, in order.',
                        'maxItems'    => 8,
                        'items'       => array('type' => 'string'),
                    ),
                    'metrics'  => array(
                        'type'     => 'array',
                        'minItems' => 1,
                        'maxItems' => 12,
                        'items'    => array(
                            'type'                 => 'object',
                            'properties'           => array(
                                'metric' => array(
                                    'type' => 'string',
                                    'enum' => array('count', 'count_distinct', 'sum', 'avg', 'min', 'max', 'median', 'p90'),
                                ),
                                'column' => array(
                                    'type'        => 'string',
                                    'description' => 'Required for every metric except count.',
                                ),
                                'alias'  => array(
                                    'type'        => 'string',
                                    'description' => 'Output column name; defaults to the metric name.',
                                ),
                            ),
                            'required'             => array('metric'),
                            'additionalProperties' => false,
                        ),
                    ),
                    'filters'  => $this->filterSchema(),
                    'order'    => $this->orderSchema(),
                    'limit'    => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                    ),
                ),
            ),
            'required'             => array('relation', 'metrics'),
            'additionalProperties' => false,
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        return $this->guard(function () use ($arguments, $context): array {
            $connection = $this->connection($arguments, $context);
            $scope      = $this->scope($arguments);
            $query      = $this->aggregateQuery($arguments);
            $result     = $this->databases->aggregate($connection, $scope, $query);

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
