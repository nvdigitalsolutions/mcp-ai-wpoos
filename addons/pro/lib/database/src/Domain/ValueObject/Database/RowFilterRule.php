<?php

/**
 * One predicate that is ALWAYS applied to a scoped query.
 *
 * Proposal 167 section 167.6.3. A row filter is the application-layer substitute
 * for row-level security, and therefore has to hold on engines that have none —
 * MySQL and MariaDB. It differs from an ordinary filter in three ways:
 *
 *  1. It is authored by an operator, never by the model.
 *  2. Its value comes from the resolved tenant (`scope_tenant`) or from a literal
 *     in the scope; a tool argument can never supply it.
 *  3. It is ANDed into every statement targeting a relation it applies to. If it
 *     cannot be applied, the call is refused — never widened.
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

final readonly class RowFilterRule
{
    /** Resolve the value from the authenticated tenant. */
    public const FROM_TENANT = 'scope_tenant';

    /** Use the literal stored in the scope. */
    public const FROM_LITERAL = 'literal';

    /**
     * Operators a row filter may use.
     *
     * Deliberately narrower than the request vocabulary: a pattern or a range on
     * a security predicate is a way to widen it, so only exact/negative/set
     * comparisons are permitted.
     *
     * @var list<FilterOperator>
     */
    private const ALLOWED = array(
        FilterOperator::EQUALS,
        FilterOperator::NOT_EQUALS,
        FilterOperator::IN,
        FilterOperator::NOT_IN,
        FilterOperator::IS_NULL,
        FilterOperator::IS_NOT_NULL,
    );

    /**
     * @param string          $column    Column the predicate applies to.
     * @param FilterOperator  $operator  One of the ALLOWED operators.
     * @param string          $valueFrom `scope_tenant` or `literal`.
     * @param mixed           $value     Literal value; unused for `scope_tenant`.
     * @param string|null     $relation  `schema.table` this applies to, or null for every relation.
     */
    public function __construct(
        public string $column,
        public FilterOperator $operator,
        public string $valueFrom = self::FROM_TENANT,
        public mixed $value = null,
        public ?string $relation = null,
    ) {
        if ('' === trim($column)) {
            throw DatabaseRefusal::of(RefusalCode::FILTER_UNMAPPABLE, 'Row filter has an empty column.');
        }

        if (! in_array($operator, self::ALLOWED, true)) {
            throw DatabaseRefusal::of(
                RefusalCode::FILTER_UNMAPPABLE,
                'Row filter operator ' . $operator->value . ' is not permitted.'
            );
        }

        if (! in_array($valueFrom, array( self::FROM_TENANT, self::FROM_LITERAL ), true)) {
            throw DatabaseRefusal::of(RefusalCode::FILTER_UNMAPPABLE, 'Row filter has an unknown value source.');
        }

        if (self::FROM_LITERAL === $valueFrom && $operator->needsValue() && null === $value) {
            throw DatabaseRefusal::of(RefusalCode::FILTER_UNMAPPABLE, 'Row filter literal has no value.');
        }
    }

    /**
     * @param array<string, mixed> $rule Stored rule.
     */
    public static function fromArray(array $rule): self
    {
        $operator = FilterOperator::tryFrom(strtolower(trim((string) ($rule['op'] ?? $rule['operator'] ?? ''))));

        if (null === $operator) {
            throw DatabaseRefusal::of(RefusalCode::FILTER_UNMAPPABLE, 'Row filter has an unknown operator.');
        }

        $relation = isset($rule['relation']) && '' !== (string) $rule['relation']
            ? strtolower((string) $rule['relation'])
            : null;

        return new self(
            column: (string) ($rule['column'] ?? ''),
            operator: $operator,
            valueFrom: (string) ($rule['value_from'] ?? self::FROM_TENANT),
            value: $rule['value'] ?? null,
            relation: $relation,
        );
    }

    /**
     * Whether this rule applies to a relation.
     */
    public function appliesTo(string $relationKey): bool
    {
        return null === $this->relation || $this->relation === strtolower($relationKey);
    }
}
