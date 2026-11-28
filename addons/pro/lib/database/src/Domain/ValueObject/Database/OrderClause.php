<?php

/**
 * One ordering term in a structured query.
 *
 * Proposal 167 section 167.6.8. Ordering is also the pagination key: a keyset
 * cursor is a tuple of the ordered columns' values, so an ordering that is not
 * unique produces a cursor that can skip or repeat rows. The grammar appends the
 * primary key as a tie-breaker when the caller's ordering is not already unique,
 * and refuses when the relation has no key at all.
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

final readonly class OrderClause
{
    public const ASC = 'asc';
    public const DESC = 'desc';

    public function __construct(
        public string $column,
        public string $direction = self::ASC,
    ) {
        if ('' === trim($column)) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Order term has an empty column.');
        }

        if (! in_array($direction, array( self::ASC, self::DESC ), true)) {
            throw DatabaseRefusal::of(RefusalCode::STATEMENT_NOT_ADMITTED, 'Order direction must be asc or desc.');
        }
    }

    /**
     * @param array<string, mixed> $order Raw order term.
     */
    public static function fromArray(array $order): self
    {
        return new self(
            (string) ($order['column'] ?? ''),
            strtolower(trim((string) ($order['direction'] ?? self::ASC))),
        );
    }

    public function sql(): string
    {
        return strtoupper($this->direction);
    }
}
