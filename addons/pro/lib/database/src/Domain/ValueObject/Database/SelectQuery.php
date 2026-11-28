<?php

/**
 * A structured read: project, filter, order, page.
 *
 * Proposal 167 section 167.6.6. This is the primary query shape and it contains
 * no SQL. `columns` empty means "every column the scope allows", which the
 * grammar resolves from the scope rather than from the caller.
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

final readonly class SelectQuery
{
    /**
     * @param string             $relation Allowed `schema.table`.
     * @param list<string>       $columns  Requested projection; empty means all allowed.
     * @param list<FilterClause> $filters  Predicates, ANDed.
     * @param list<OrderClause>  $order    Ordering terms.
     * @param int|null           $limit    Requested row count; capped by the scope.
     * @param int|null           $offset   Legacy offset; refused above the scope ceiling.
     * @param string|null        $cursor   Opaque keyset cursor.
     */
    public function __construct(
        public string $relation,
        public array $columns = array(),
        public array $filters = array(),
        public array $order = array(),
        public ?int $limit = null,
        public ?int $offset = null,
        public ?string $cursor = null,
    ) {
        if ('' === trim($relation)) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Select has no relation.');
        }
    }

    /**
     * @param array<string, mixed> $args Tool arguments.
     */
    public static function fromArray(array $args): self
    {
        $columns = array();

        foreach ((array) ($args['columns'] ?? array()) as $column) {
            if (is_string($column) && '' !== trim($column)) {
                $columns[] = strtolower(trim($column));
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
            columns: $columns,
            filters: $filters,
            order: $order,
            limit: isset($args['limit']) ? max(1, (int) $args['limit']) : null,
            offset: isset($args['offset']) ? max(0, (int) $args['offset']) : null,
            cursor: isset($args['cursor']) && '' !== (string) $args['cursor'] ? (string) $args['cursor'] : null,
        );
    }

    /**
     * Whether this query paginates by offset rather than by cursor.
     */
    public function usesOffset(): bool
    {
        return null !== $this->offset && $this->offset > 0;
    }
}
