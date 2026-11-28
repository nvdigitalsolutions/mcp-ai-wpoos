<?php

/**
 * A grouped aggregate.
 *
 * Proposal 167 section 167.6.6. `groupBy` empty produces a single-row summary;
 * otherwise one row per group. Metrics come from the closed vocabulary and are
 * validated against the engine's capabilities before the statement is built.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\ValueObject\Database;

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\RefusalCode;

final readonly class AggregateQuery
{
    /**
     * @param string              $relation Allowed `schema.table`.
     * @param list<string>        $groupBy  Grouping columns.
     * @param list<MetricClause>  $metrics  At least one.
     * @param list<FilterClause>  $filters  Predicates, ANDed.
     * @param list<OrderClause>   $order    Ordering terms.
     * @param int|null            $limit    Requested row count; capped by the scope.
     */
    public function __construct(
        public string $relation,
        public array $groupBy = array(),
        public array $metrics = array(),
        public array $filters = array(),
        public array $order = array(),
        public ?int $limit = null,
    ) {
        if ('' === trim($relation)) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Aggregate has no relation.');
        }

        if (array() === $metrics) {
            throw DatabaseRefusal::of(RefusalCode::UNSUPPORTED_METRIC, 'Aggregate has no metrics.');
        }
    }

    /**
     * @param array<string, mixed> $args Tool arguments.
     */
    public static function fromArray(array $args): self
    {
        $groupBy = array();

        foreach ((array) ($args['group_by'] ?? array()) as $column) {
            if (is_string($column) && '' !== trim($column)) {
                $groupBy[] = strtolower(trim($column));
            }
        }

        $metrics = array();

        foreach ((array) ($args['metrics'] ?? array()) as $metric) {
            if (is_array($metric)) {
                $metrics[] = MetricClause::fromArray($metric);
            }
        }

        $filters = array();

        foreach ((array) ($args['filters'] ?? array()) as $filter) {
            if (is_array($filter)) {
                $filters[] = FilterClause::fromArray($filter);
            }
        }

        $order = array();

        foreach ((array) ($args['order'] ?? array()) as $term) {
            if (is_array($term)) {
                $order[] = OrderClause::fromArray($term);
            }
        }

        return new self(
            relation: strtolower(trim((string) ($args['relation'] ?? ''))),
            groupBy: $groupBy,
            metrics: $metrics,
            filters: $filters,
            order: $order,
            limit: isset($args['limit']) ? max(1, (int) $args['limit']) : null,
        );
    }
}
