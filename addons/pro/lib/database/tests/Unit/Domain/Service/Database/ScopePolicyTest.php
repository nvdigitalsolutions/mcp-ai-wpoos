<?php

/**
 * Scope policy tests.
 *
 * The row-filter cases are the point: a filter that names a column the relation
 * does not have must **refuse**, not skip. Skipping widens the result to every
 * tenant, which is the failure the filter exists to prevent.
 *
 * @package Nvoos\Database\Tests
 * @since   3.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Tests\Unit\Domain\Service\Database;

use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\Service\Database\ScopePolicy;
use Nvoos\Database\Domain\ValueObject\Database\ColumnDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;
use PHPUnit\Framework\TestCase;

final class ScopePolicyTest extends TestCase
{
    use RefusalAssertions;

    private ScopePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ScopePolicy();
    }

    public function test_it_accepts_a_granted_tier(): void
    {
        $this->policy->assertTier(DatabaseFixtures::scope(), GrantTier::READ);

        self::assertTrue(true);
    }

    public function test_it_refuses_a_tier_the_scope_lacks(): void
    {
        $this->assertRefusal(
            RefusalCode::TIER_REQUIRED,
            fn (): mixed => $this->policy->assertTier(DatabaseFixtures::scope(), GrantTier::RAW_SQL)
        );
    }

    public function test_it_resolves_a_granted_relation(): void
    {
        $table = $this->policy->assertRelation(
            DatabaseFixtures::scope(),
            DatabaseFixtures::catalogue(),
            'REPORTING.ORDERS'
        );

        self::assertSame('reporting.orders', $table->key());
    }

    public function test_it_refuses_a_relation_missing_from_the_catalogue(): void
    {
        $this->assertRefusal(
            RefusalCode::RELATION_NOT_FOUND,
            fn (): mixed => $this->policy->assertRelation(
                DatabaseFixtures::scope(),
                DatabaseFixtures::catalogue(),
                'reporting.absent'
            )
        );
    }

    public function test_it_refuses_an_unqualified_relation(): void
    {
        $this->assertRefusal(
            RefusalCode::TABLE_NOT_ALLOWED,
            fn (): mixed => $this->policy->assertRelation(
                DatabaseFixtures::scope(),
                DatabaseFixtures::catalogue(),
                'orders'
            )
        );
    }

    public function test_it_refuses_a_column_outside_the_projection(): void
    {
        $this->assertRefusal(
            RefusalCode::COLUMN_NOT_ALLOWED,
            fn (): mixed => $this->policy->assertColumn(
                DatabaseFixtures::scope(),
                DatabaseFixtures::ordersTable(),
                'notes'
            )
        );
    }

    public function test_it_refuses_an_unknown_column(): void
    {
        $this->assertRefusal(
            RefusalCode::COLUMN_NOT_FOUND,
            fn (): mixed => $this->policy->assertColumn(
                DatabaseFixtures::scope(),
                DatabaseFixtures::ordersTable(),
                'absent'
            )
        );
    }

    public function test_it_composes_the_row_filter_with_the_resolved_tenant(): void
    {
        $predicates = $this->policy->composeRowFilter(
            DatabaseFixtures::scope(),
            DatabaseFixtures::ordersTable(),
            DatabaseFixtures::tenant()
        );

        self::assertCount(1, $predicates);
        self::assertSame('tenant_id', $predicates[0]['column']);
        self::assertSame(DatabaseFixtures::tenant(), $predicates[0]['value']);
    }

    public function test_it_refuses_when_no_tenant_is_resolved(): void
    {
        $this->assertRefusal(
            RefusalCode::FILTER_UNMAPPABLE,
            fn (): array => $this->policy->composeRowFilter(
                DatabaseFixtures::scope(),
                DatabaseFixtures::ordersTable(),
                null
            )
        );
    }

    public function test_it_refuses_when_the_filter_column_does_not_exist_on_the_relation(): void
    {
        $scope = DatabaseFixtures::scope(
            array(
                'row_filter' => array(
                    array( 'column' => 'account_id', 'op' => 'eq', 'value_from' => 'scope_tenant' ),
                ),
            )
        );

        $this->assertRefusal(
            RefusalCode::FILTER_UNMAPPABLE,
            fn (): array => $this->policy->composeRowFilter(
                $scope,
                DatabaseFixtures::ordersTable(),
                DatabaseFixtures::tenant()
            )
        );
    }

    public function test_a_relation_may_exist_without_the_filter_column(): void
    {
        // A scope may allow a table that has no tenant column only if it carries
        // no tenant-wide filter; this proves composeRowFilter is per-relation.
        $scope = DatabaseFixtures::scope(array( 'row_filter' => array() ));
        $table = new TableDescriptor(
            'reporting',
            'countries',
            array( 'code' => new ColumnDescriptor('code', 'text', false) ),
            array( 'code' )
        );

        self::assertSame(array(), $this->policy->composeRowFilter($scope, $table, null));
    }

    public function test_it_refuses_a_write_without_the_tier(): void
    {
        $this->assertRefusal(
            RefusalCode::TIER_REQUIRED,
            fn (): mixed => $this->policy->assertWriteAllowed(DatabaseFixtures::scope(), 1, 10)
        );
    }

    public function test_it_refuses_a_write_above_the_cap(): void
    {
        $scope = DatabaseFixtures::scope(
            array( 'tiers' => array( 'metadata', 'read', 'propose_write', 'apply_write' ) )
        );

        $this->assertRefusal(
            RefusalCode::LIMIT_EXCEEDED,
            fn (): mixed => $this->policy->assertWriteAllowed($scope, 11, 10)
        );
    }

    public function test_it_allows_a_write_at_the_cap(): void
    {
        $scope = DatabaseFixtures::scope(
            array( 'tiers' => array( 'metadata', 'read', 'propose_write', 'apply_write' ) )
        );

        $this->policy->assertWriteAllowed($scope, 10, 10);

        self::assertTrue(true);
    }
}
