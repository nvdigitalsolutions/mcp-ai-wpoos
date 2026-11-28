<?php

/**
 * One user-supplied predicate in a structured query.
 *
 * Proposal 167 section 167.6.6. A filter names a column, an operator from the
 * closed vocabulary, and a value. It never contains SQL, and its value is always
 * bound — the grammar emits `column op :pN`.
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
use Nvoos\Database\Domain\Service\Database\FilterOperator;
use Nvoos\Database\Domain\Service\Database\RefusalCode;

final readonly class FilterClause
{
    public function __construct(
        public string $column,
        public FilterOperator $operator,
        public mixed $value = null,
    ) {
        if ('' === trim($column)) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Filter has an empty column.');
        }

        if ($operator->needsValue() && null === $value) {
            throw DatabaseRefusal::of(
                RefusalCode::STATEMENT_NOT_ADMITTED,
                'Filter operator ' . $operator->value . ' requires a value.'
            );
        }

        if ($operator->bindingCount($value) === 0 && $operator->needsValue()) {
            throw DatabaseRefusal::of(
                RefusalCode::STATEMENT_NOT_ADMITTED,
                'Filter operator ' . $operator->value . ' received an empty value set.'
            );
        }
    }

    /**
     * @param array<string, mixed> $filter Raw filter from a tool argument.
     */
    public static function fromArray(array $filter): self
    {
        $operator = FilterOperator::tryFrom(strtolower(trim((string) ($filter['op'] ?? ''))));

        if (null === $operator) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Unknown filter operator.');
        }

        return new self(
            (string) ($filter['column'] ?? ''),
            $operator,
            $filter['value'] ?? null,
        );
    }
}
