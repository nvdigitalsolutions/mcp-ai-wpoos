<?php

/**
 * The closed filter vocabulary for structured queries.
 *
 * Proposal 167 section 167.6.6. A closed list is what keeps `db_select` free of
 * SQL: the model selects an operator from this set and supplies a value, and the
 * grammar emits the fragment. Anything an operator does not cover is not
 * expressible, which is a deliberate capability limit rather than an oversight.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Service\Database;

enum FilterOperator: string
{
    case EQUALS = 'eq';
    case NOT_EQUALS = 'ne';
    case IN = 'in';
    case NOT_IN = 'not_in';
    case GREATER = 'gt';
    case GREATER_OR_EQUAL = 'gte';
    case LESS = 'lt';
    case LESS_OR_EQUAL = 'lte';
    case BETWEEN = 'between';
    case LIKE = 'like';
    case ILIKE = 'ilike';
    case IS_NULL = 'is_null';
    case IS_NOT_NULL = 'is_not_null';

    /**
     * How many bindings the operator consumes.
     *
     * `in`/`not_in` consume one per list element; `between` consumes two;
     * `is_null`/`is_not_null` consume none. The grammar uses this to keep
     * placeholder count and binding count in step, which is the class of bug
     * that turns a bound query into a driver error.
     */
    public function bindingCount(mixed $value): int
    {
        return match ($this) {
            self::IS_NULL, self::IS_NOT_NULL => 0,
            self::BETWEEN => 2,
            self::IN, self::NOT_IN => \is_array($value) ? \count($value) : 0,
            default => 1,
        };
    }

    /**
     * Whether the operator needs a value at all.
     */
    public function needsValue(): bool
    {
        return ! \in_array($this, array( self::IS_NULL, self::IS_NOT_NULL ), true );
    }

    /**
     * Whether the operator matches on a pattern rather than equality.
     *
     * Pattern operators escape `%` and `_` in the caller's value before binding,
     * so a search for `100%` does not match everything.
     */
    public function isPattern(): bool
    {
        return \in_array($this, array( self::LIKE, self::ILIKE ), true );
    }
}
