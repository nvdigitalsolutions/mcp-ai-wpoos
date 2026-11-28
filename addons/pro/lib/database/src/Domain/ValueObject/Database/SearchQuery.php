<?php

/**
 * A term search across a relation's declared searchable columns.
 *
 * Proposal 167 section 167.6.6. Narrower than `db_select` on purpose: the caller
 * supplies one term and no operators, so there is no expression to construct and
 * no way to widen the scope's declared search surface.
 *
 * The grammar escapes `%` and `_` in the term before binding. A search for
 * `100%` therefore matches the literal string, not everything — the wildcard
 * escape lesson generalised from the Laravel `ProductCatalog` LIKE escaping.
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

final readonly class SearchQuery
{
    /**
     * @param string       $relation Allowed `schema.table`.
     * @param string       $term     Literal term to match.
     * @param list<string> $columns  Requested columns; empty means the scope's searchable set.
     * @param int|null     $limit    Requested row count; capped by the scope.
     */
    public function __construct(
        public string $relation,
        public string $term,
        public array $columns = array(),
        public ?int $limit = null,
    ) {
        if ('' === trim($relation)) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Search has no relation.');
        }

        if ('' === trim($term)) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Search has an empty term.');
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

        return new self(
            relation: strtolower(trim((string) ($args['relation'] ?? ''))),
            term: trim((string) ($args['term'] ?? '')),
            columns: $columns,
            limit: isset($args['limit']) ? max(1, (int) $args['limit']) : null,
        );
    }
}
