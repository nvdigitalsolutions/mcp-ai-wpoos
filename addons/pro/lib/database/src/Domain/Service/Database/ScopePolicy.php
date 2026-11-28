<?php

/**
 * Scope enforcement: tiers, relations, columns, and the mandatory row filter.
 *
 * Proposal 167 sections 167.6.3–167.6.5. This class answers policy questions and
 * returns *data* — it never builds SQL. That separation matters: the grammar owns
 * syntax, so a policy decision can be tested without a statement, and a syntax
 * change cannot accidentally loosen a grant.
 *
 * ## Fail closed
 *
 * Every method throws {@see DatabaseRefusal} rather than returning false, so a
 * caller cannot forget to check the answer. There is no "deny" return value to
 * mishandle.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Service\Database;

use Nvoos\Database\Domain\ValueObject\Database\CatalogueDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\RowFilterRule;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;

final class ScopePolicy
{
    /**
     * Require a tier.
     */
    public function assertTier(ScopeDescriptor $scope, GrantTier $tier): void
    {
        if ($scope->hasTier($tier)) {
            return;
        }

        throw DatabaseRefusal::of(
            RefusalCode::TIER_REQUIRED,
            'Scope ' . $scope->id . ' lacks tier ' . $tier->value . '.'
        );
    }

    /**
     * Resolve and authorise a relation.
     *
     * @param string $relation `schema.table`, case-insensitive.
     */
    public function assertRelation(
        ScopeDescriptor $scope,
        CatalogueDescriptor $catalogue,
        string $relation,
    ): TableDescriptor {
        $relation = strtolower(trim($relation));
        $parts = explode('.', $relation);

        if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
            throw DatabaseRefusal::of(
                RefusalCode::TABLE_NOT_ALLOWED,
                'Relation must be schema-qualified: ' . $relation
            );
        }

        $table = $catalogue->table($parts[0], $parts[1]);

        if (null === $table) {
            throw DatabaseRefusal::of(
                RefusalCode::RELATION_NOT_FOUND,
                'Relation not in the scope catalogue: ' . $relation
            );
        }

        if (! $scope->allowsTable($table)) {
            throw DatabaseRefusal::of(
                RefusalCode::TABLE_NOT_ALLOWED,
                'Relation outside the scope allowlist: ' . $relation
            );
        }

        return $table;
    }

    /**
     * Authorise a column against the scope and the relation.
     */
    public function assertColumn(ScopeDescriptor $scope, TableDescriptor $table, string $column): string
    {
        $column = strtolower(trim($column));

        if (! $table->hasColumn($column)) {
            throw DatabaseRefusal::of(
                RefusalCode::COLUMN_NOT_FOUND,
                'Column not in relation ' . $table->key() . ': ' . $column
            );
        }

        if (! $scope->allowsColumn($table, $column)) {
            throw DatabaseRefusal::of(
                RefusalCode::COLUMN_NOT_ALLOWED,
                'Column outside the scope projection: ' . $table->key() . '.' . $column
            );
        }

        return $table->column($column)?->name ?? $column;
    }

    /**
     * Resolve the mandatory predicates for a relation.
     *
     * Returns predicates, not SQL: the caller binds the values, which keeps
     * placeholder numbering in one place.
     *
     * A rule that names a column the relation does not have is a **refusal**, not
     * a skipped rule. Skipping it would silently widen the result to every row,
     * which is the exact failure mode the row filter exists to prevent.
     *
     * @return list<array{column: string, operator: FilterOperator, value: mixed}>
     */
    public function composeRowFilter(
        ScopeDescriptor $scope,
        TableDescriptor $table,
        ?string $tenantId,
    ): array {
        $predicates = array();

        /** @var RowFilterRule $rule */
        foreach ($scope->rowFilterFor($table) as $rule) {
            $column = strtolower(trim($rule->column));

            if (! $table->hasColumn($column)) {
                throw DatabaseRefusal::of(
                    RefusalCode::FILTER_UNMAPPABLE,
                    sprintf(
                        'Row filter column %s does not exist on %s.',
                        $column,
                        $table->key()
                    )
                );
            }

            $value = $rule->value;

            if (RowFilterRule::FROM_TENANT === $rule->valueFrom) {
                if (null === $tenantId || '' === trim($tenantId)) {
                    // No tenant resolved means no filter value; returning rows
                    // would be a cross-tenant read.
                    throw DatabaseRefusal::of(
                        RefusalCode::FILTER_UNMAPPABLE,
                        'Row filter requires a tenant, and none was resolved.'
                    );
                }

                $value = $tenantId;
            }

            if ($rule->operator->needsValue() && null === $value) {
                throw DatabaseRefusal::of(
                    RefusalCode::FILTER_UNMAPPABLE,
                    'Row filter resolved to an empty value.'
                );
            }

            $predicates[] = array(
                'column' => $column,
                'operator' => $rule->operator,
                'value' => $value,
            );
        }

        return $predicates;
    }

    /**
     * Assert that this scope may perform a write of the given size.
     */
    public function assertWriteAllowed(ScopeDescriptor $scope, int $affectedRows, int $maxAffectedRows): void
    {
        $this->assertTier($scope, GrantTier::APPLY_WRITE);

        if ($maxAffectedRows < 1) {
            throw DatabaseRefusal::of(RefusalCode::TIER_REQUIRED, 'Scope does not permit writes.');
        }

        if ($affectedRows > $maxAffectedRows) {
            throw DatabaseRefusal::of(
                RefusalCode::LIMIT_EXCEEDED,
                sprintf('Write would affect %d rows; the cap is %d.', $affectedRows, $maxAffectedRows)
            );
        }
    }
}
