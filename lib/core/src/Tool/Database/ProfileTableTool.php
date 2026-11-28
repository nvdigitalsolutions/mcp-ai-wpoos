<?php

/**
 * `db_profile_table` — a bounded column profile.
 *
 * Proposal 167 phase P4, task 4.4. WordPress parity: same slug, same schema,
 * same payload, same eight-column / five-value bounds.
 *
 * "Bounded" is the whole design: at most eight columns, three aggregate queries
 * per column, and no value listing for a column the scope marks sensitive.
 * Profiling a sensitive column would disclose its distribution (and its top
 * values) despite the masking that applies to row reads, so it is skipped and
 * reported as skipped rather than silently profiled.
 *
 * This is the one tool that needs the scope *descriptor* rather than a decision,
 * which is why the kernel offers it as the optional
 * {@see ScopeAwareInterface}. Not every host will implement that; the tool then
 * refuses instead of profiling blind.
 *
 * @package Nvoos\Core
 * @since   2.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Database;

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\FilterOperator;
use Nvoos\Database\Domain\Service\Database\Metric;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\AggregateQuery;
use Nvoos\Database\Domain\ValueObject\Database\FilterClause;
use Nvoos\Database\Domain\ValueObject\Database\MetricClause;
use Nvoos\Database\Domain\ValueObject\Database\OrderClause;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;

final class ProfileTableTool extends DatabaseTool
{
    /**
     * Maximum columns profiled per call.
     */
    private const MAX_COLUMNS = 8;

    /**
     * Top values returned per column.
     */
    private const TOP_VALUES = 5;

    public function getSlug(): string
    {
        return 'db_profile_table';
    }

    public function getName(): string
    {
        return 'Profile Database Table';
    }

    public function getDescription(): string
    {
        return 'Summarise a table: row count, null counts, distinct counts, minima, maxima and the five most frequent values for each profiled column. Sensitive columns are skipped, not profiled.';
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
                        'description' => 'Columns to profile. Defaults to the first eight readable columns.',
                        'maxItems'    => self::MAX_COLUMNS,
                        'items'       => array('type' => 'string'),
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
            $scope_id   = $this->scope($arguments);
            $relation   = $this->relation($arguments);
            $principal  = $this->principal($context);
            $scope      = $this->scopeAt($connection, $scope_id, $principal);

            $described = $this->databases->describe($connection, $relation);

            // `CatalogueDescriptor::$tables` is readonly, so `reset()` needs a copy
            // rather than the property itself.
            $described_tables = $described->tables;
            $table            = reset($described_tables);

            if (! $table instanceof TableDescriptor) {
                throw DatabaseRefusal::of(
                    RefusalCode::RELATION_NOT_FOUND,
                    'Relation is not readable: ' . $relation,
                );
            }

            $requested = $this->stringList($arguments['columns'] ?? array());
            $readable  = array_keys($table->columns);
            $chosen    = array() !== $requested
                ? array_values(array_intersect($requested, $readable))
                : array_slice($readable, 0, self::MAX_COLUMNS);
            $chosen    = array_slice($chosen, 0, self::MAX_COLUMNS);

            if (array() === $chosen) {
                throw DatabaseRefusal::of(
                    RefusalCode::COLUMN_NOT_ALLOWED,
                    'No readable column remains to profile.',
                );
            }

            $total = $this->databases->aggregate(
                $connection,
                $scope->id,
                new AggregateQuery(
                    relation: $table->key(),
                    metrics: array(new MetricClause(Metric::COUNT, 'n')),
                    limit: 1,
                ),
            );

            $row_count = (int) ($total->rows[0]['n'] ?? 0);
            $columns   = array();
            $skipped   = array();

            foreach ($chosen as $column) {
                if ($scope->isSensitive($table, $column)) {
                    $skipped[] = $column;
                    continue;
                }

                $columns[] = $this->profileColumn($connection, $scope->id, $table->key(), $column, $row_count);
            }

            return array(
                'success'    => true,
                'connection' => $connection,
                'relation'   => $table->key(),
                'row_count'  => $row_count,
                'columns'    => $columns,
                'skipped'    => $skipped,
                'scope'      => array(
                    'id'      => $scope->id,
                    'label'   => $scope->label,
                    'version' => $scope->version,
                ),
            );
        });
    }

    /**
     * Profile one column with three bounded aggregates.
     *
     * @return array<string, mixed>
     */
    private function profileColumn(
        string $connection,
        string $scope,
        string $relation,
        string $column,
        int $rowCount,
    ): array {
        $nulls = $this->databases->aggregate(
            $connection,
            $scope,
            new AggregateQuery(
                relation: $relation,
                metrics: array(new MetricClause(Metric::COUNT, 'n')),
                filters: array(new FilterClause($column, FilterOperator::IS_NULL)),
                limit: 1,
            ),
        );

        $stats = $this->databases->aggregate(
            $connection,
            $scope,
            new AggregateQuery(
                relation: $relation,
                metrics: array(
                    new MetricClause(Metric::COUNT_DISTINCT, 'distinct', $column),
                    new MetricClause(Metric::MIN, 'min', $column),
                    new MetricClause(Metric::MAX, 'max', $column),
                ),
                limit: 1,
            ),
        );

        $top = $this->databases->aggregate(
            $connection,
            $scope,
            new AggregateQuery(
                relation: $relation,
                groupBy: array($column),
                metrics: array(new MetricClause(Metric::COUNT, 'n')),
                order: array(new OrderClause('n', 'desc')),
                limit: self::TOP_VALUES,
            ),
        );

        $nullCount = (int) ($nulls->rows[0]['n'] ?? 0);
        $statsRow  = $stats->rows[0] ?? array();

        $frequent = array();

        foreach ($top->rows as $row) {
            $frequent[] = array(
                'value' => $row[$column] ?? null,
                'count' => (int) ($row['n'] ?? 0),
            );
        }

        return array(
            'name'          => $column,
            'nulls'         => $nullCount,
            'null_percent'  => $rowCount > 0 ? round($nullCount / $rowCount * 100, 1) : 0.0,
            'distinct'      => (int) ($statsRow['distinct'] ?? 0),
            'min'           => $statsRow['min'] ?? null,
            'max'           => $statsRow['max'] ?? null,
            'most_frequent' => $frequent,
        );
    }
}
