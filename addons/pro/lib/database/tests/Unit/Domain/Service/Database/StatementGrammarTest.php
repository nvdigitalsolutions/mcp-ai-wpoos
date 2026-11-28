<?php

/**
 * Grammar tests: the structural injection guarantee.
 *
 * The assertions that matter most are the negative ones — that a caller's value
 * never appears in the compiled SQL, that an ungranted relation or column cannot
 * be named, and that the mandatory row filter is present in **every** statement
 * shape. A grammar that emitted a correct-looking SELECT without the filter would
 * pass a smoke test and leak a tenant.
 *
 * @package Nvoos\Database\Tests
 * @since   3.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Tests\Unit\Domain\Service\Database;

use Nvoos\Database\Domain\Service\Database\AdmissionClassifier;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\Service\Database\StatementGrammar;
use Nvoos\Database\Domain\ValueObject\Database\AggregateQuery;
use Nvoos\Database\Domain\ValueObject\Database\CatalogueDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ColumnDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\RawQuery;
use Nvoos\Database\Domain\ValueObject\Database\SearchQuery;
use Nvoos\Database\Domain\ValueObject\Database\SelectQuery;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;
use PHPUnit\Framework\TestCase;

final class StatementGrammarTest extends TestCase
{
    use RefusalAssertions;

    private StatementGrammar $grammar;

    protected function setUp(): void
    {
        $this->grammar = new StatementGrammar();
    }

    public function test_select_compiles_relation_projection_and_limit(): void
    {
        $statement = $this->grammar->select(
            DatabaseFixtures::scope(),
            DatabaseFixtures::catalogue(),
            SelectQuery::fromArray(
                array(
                    'relation' => 'reporting.orders',
                    'columns' => array( 'order_id', 'total_minor' ),
                    'limit' => 10,
                )
            ),
            DatabaseFixtures::tenant(),
            'pgsql',
            DatabaseFixtures::limits()
        );

        self::assertStringContainsString('SELECT "order_id", "total_minor"', $statement->sql);
        self::assertStringContainsString('FROM "reporting"."orders"', $statement->sql);
        self::assertStringContainsString('LIMIT 10', $statement->sql);
    }

    public function test_select_never_contains_a_caller_value(): void
    {
        $statement = $this->grammar->select(
            DatabaseFixtures::scope(),
            DatabaseFixtures::catalogue(),
            SelectQuery::fromArray(
                array(
                    'relation' => 'reporting.orders',
                    'filters' => array(
                        array( 'column' => 'status', 'op' => 'eq', 'value' => 'super-secret-status' ),
                    ),
                )
            ),
            DatabaseFixtures::tenant(),
            'pgsql',
            DatabaseFixtures::limits()
        );

        self::assertStringNotContainsString('super-secret-status', $statement->sql);
        self::assertContains('super-secret-status', array_values($statement->bindings));
    }

    public function test_select_always_applies_the_row_filter(): void
    {
        $statement = $this->grammar->select(
            DatabaseFixtures::scope(),
            DatabaseFixtures::catalogue(),
            SelectQuery::fromArray(array( 'relation' => 'reporting.orders' )),
            DatabaseFixtures::tenant(),
            'pgsql',
            DatabaseFixtures::limits()
        );

        self::assertStringContainsString('"tenant_id" = :p', $statement->sql);
        self::assertContains(DatabaseFixtures::tenant(), array_values($statement->bindings));
    }

    public function test_select_refuses_when_no_tenant_is_resolved(): void
    {
        $this->assertRefusal(
            RefusalCode::FILTER_UNMAPPABLE,
            fn (): mixed => $this->grammar->select(
                DatabaseFixtures::scope(),
                DatabaseFixtures::catalogue(),
                SelectQuery::fromArray(array( 'relation' => 'reporting.orders' )),
                null,
                'pgsql',
                DatabaseFixtures::limits()
            )
        );
    }

    public function test_select_refuses_a_relation_missing_from_the_catalogue(): void
    {
        $this->assertRefusal(
            RefusalCode::RELATION_NOT_FOUND,
            fn (): mixed => $this->grammar->select(
                DatabaseFixtures::scope(),
                DatabaseFixtures::catalogue(),
                SelectQuery::fromArray(array( 'relation' => 'reporting.secrets' )),
                DatabaseFixtures::tenant(),
                'pgsql',
                DatabaseFixtures::limits()
            )
        );
    }

    public function test_select_refuses_a_relation_outside_the_scope(): void
    {
        $catalogue = new CatalogueDescriptor(
            array(
                'reporting.orders' => DatabaseFixtures::ordersTable(),
                'reporting.users' => new TableDescriptor(
                    'reporting',
                    'users',
                    array( 'id' => new ColumnDescriptor('id', 'bigint', false) ),
                    array( 'id' )
                ),
            )
        );

        $this->assertRefusal(
            RefusalCode::TABLE_NOT_ALLOWED,
            fn (): mixed => $this->grammar->select(
                DatabaseFixtures::scope(),
                $catalogue,
                SelectQuery::fromArray(array( 'relation' => 'reporting.users' )),
                DatabaseFixtures::tenant(),
                'pgsql',
                DatabaseFixtures::limits()
            )
        );
    }

    public function test_select_refuses_an_excluded_column(): void
    {
        $this->assertRefusal(
            RefusalCode::COLUMN_NOT_ALLOWED,
            fn (): mixed => $this->grammar->select(
                DatabaseFixtures::scope(),
                DatabaseFixtures::catalogue(),
                SelectQuery::fromArray(
                    array(
                        'relation' => 'reporting.orders',
                        'columns' => array( 'notes' ),
                    )
                ),
                DatabaseFixtures::tenant(),
                'pgsql',
                DatabaseFixtures::limits()
            )
        );
    }

    public function test_select_refuses_an_unknown_column(): void
    {
        $this->assertRefusal(
            RefusalCode::COLUMN_NOT_FOUND,
            fn (): mixed => $this->grammar->select(
                DatabaseFixtures::scope(),
                DatabaseFixtures::catalogue(),
                SelectQuery::fromArray(
                    array(
                        'relation' => 'reporting.orders',
                        'columns' => array( 'nope' ),
                    )
                ),
                DatabaseFixtures::tenant(),
                'pgsql',
                DatabaseFixtures::limits()
            )
        );
    }

    public function test_select_defaults_to_primary_key_order(): void
    {
        $statement = $this->grammar->select(
            DatabaseFixtures::scope(),
            DatabaseFixtures::catalogue(),
            SelectQuery::fromArray(array( 'relation' => 'reporting.orders' )),
            DatabaseFixtures::tenant(),
            'pgsql',
            DatabaseFixtures::limits()
        );

        self::assertStringContainsString('ORDER BY "order_id" ASC', $statement->sql);
    }

    public function test_select_appends_the_primary_key_as_a_tiebreaker(): void
    {
        $statement = $this->grammar->select(
            DatabaseFixtures::scope(),
            DatabaseFixtures::catalogue(),
            SelectQuery::fromArray(
                array(
                    'relation' => 'reporting.orders',
                    'order' => array( array( 'column' => 'placed_at', 'direction' => 'desc' ) ),
                )
            ),
            DatabaseFixtures::tenant(),
            'pgsql',
            DatabaseFixtures::limits()
        );

        self::assertStringContainsString('ORDER BY "placed_at" DESC, "order_id" DESC', $statement->sql);
    }

    public function test_select_refuses_offset_above_the_ceiling(): void
    {
        $this->assertRefusal(
            RefusalCode::LIMIT_EXCEEDED,
            fn (): mixed => $this->grammar->select(
                DatabaseFixtures::scope(),
                DatabaseFixtures::catalogue(),
                SelectQuery::fromArray(
                    array(
                        'relation' => 'reporting.orders',
                        'order' => array( array( 'column' => 'placed_at', 'direction' => 'asc' ) ),
                        'offset' => 5000,
                    )
                ),
                DatabaseFixtures::tenant(),
                'pgsql',
                DatabaseFixtures::limits()
            )
        );
    }

    public function test_select_compiles_a_cursor_as_a_row_value_comparison(): void
    {
        $statement = $this->grammar->select(
            DatabaseFixtures::scope(),
            DatabaseFixtures::catalogue(),
            SelectQuery::fromArray(
                array(
                    'relation' => 'reporting.orders',
                    'order' => array( array( 'column' => 'placed_at', 'direction' => 'desc' ) ),
                )
            ),
            DatabaseFixtures::tenant(),
            'pgsql',
            DatabaseFixtures::limits(),
            array( '2026-11-01 00:00:00', 88123 )
        );

        self::assertStringContainsString('("placed_at", "order_id") < (', $statement->sql);
    }

    public function test_select_refuses_a_cursor_on_mixed_directions(): void
    {
        $this->assertRefusal(
            RefusalCode::STATEMENT_NOT_ADMITTED,
            fn (): mixed => $this->grammar->select(
                DatabaseFixtures::scope(),
                DatabaseFixtures::catalogue(),
                SelectQuery::fromArray(
                    array(
                        'relation' => 'reporting.orders',
                        'order' => array(
                            array( 'column' => 'placed_at', 'direction' => 'desc' ),
                            array( 'column' => 'order_id', 'direction' => 'asc' ),
                        ),
                    )
                ),
                DatabaseFixtures::tenant(),
                'pgsql',
                DatabaseFixtures::limits(),
                array( '2026-11-01', 1 )
            )
        );
    }

    public function test_like_escapes_wildcards_in_the_caller_value(): void
    {
        $statement = $this->grammar->select(
            DatabaseFixtures::scope(),
            DatabaseFixtures::catalogue(),
            SelectQuery::fromArray(
                array(
                    'relation' => 'reporting.orders',
                    'filters' => array(
                        array( 'column' => 'status', 'op' => 'like', 'value' => '100%' ),
                    ),
                )
            ),
            DatabaseFixtures::tenant(),
            'pgsql',
            DatabaseFixtures::limits()
        );

        // `100%` becomes `%100!%%`: the caller's percent is escaped, the
        // surrounding wildcards are the grammar's.
        self::assertContains('%100!%%', array_values($statement->bindings));
        self::assertStringContainsString("ESCAPE '!'", $statement->sql);
    }

    public function test_in_list_binds_each_value(): void
    {
        $statement = $this->grammar->select(
            DatabaseFixtures::scope(),
            DatabaseFixtures::catalogue(),
            SelectQuery::fromArray(
                array(
                    'relation' => 'reporting.orders',
                    'filters' => array(
                        array( 'column' => 'status', 'op' => 'in', 'value' => array( 'paid', 'shipped' ) ),
                    ),
                )
            ),
            DatabaseFixtures::tenant(),
            'pgsql',
            DatabaseFixtures::limits()
        );

        self::assertStringContainsString('"status" IN (:p0, :p1)', $statement->sql);
        self::assertSame('paid', $statement->bindings[':p0']);
        self::assertSame('shipped', $statement->bindings[':p1']);
    }

    public function test_aggregate_compiles_metrics_and_grouping(): void
    {
        $statement = $this->grammar->aggregate(
            DatabaseFixtures::scope(),
            DatabaseFixtures::catalogue(),
            AggregateQuery::fromArray(
                array(
                    'relation' => 'reporting.orders',
                    'group_by' => array( 'status' ),
                    'metrics' => array(
                        array( 'op' => 'count', 'as' => 'orders' ),
                        array( 'op' => 'sum', 'column' => 'total_minor', 'as' => 'revenue' ),
                    ),
                    'order' => array( array( 'column' => 'revenue', 'direction' => 'desc' ) ),
                )
            ),
            DatabaseFixtures::tenant(),
            'pgsql',
            DatabaseFixtures::limits()
        );

        self::assertStringContainsString('COUNT(*) AS "orders"', $statement->sql);
        self::assertStringContainsString('SUM("total_minor") AS "revenue"', $statement->sql);
        self::assertStringContainsString('GROUP BY "status"', $statement->sql);
        self::assertStringContainsString('ORDER BY "revenue" DESC', $statement->sql);
    }

    public function test_aggregate_refuses_percentiles_off_postgres(): void
    {
        $this->assertRefusal(
            RefusalCode::UNSUPPORTED_METRIC,
            fn (): mixed => $this->grammar->aggregate(
                DatabaseFixtures::scope(),
                DatabaseFixtures::catalogue(),
                AggregateQuery::fromArray(
                    array(
                        'relation' => 'reporting.orders',
                        'metrics' => array(
                            array( 'op' => 'median', 'column' => 'total_minor', 'as' => 'median_total' ),
                        ),
                    )
                ),
                DatabaseFixtures::tenant(),
                'mysql',
                DatabaseFixtures::limits()
            )
        );
    }

    public function test_aggregate_uses_percentile_cont_on_postgres(): void
    {
        $statement = $this->grammar->aggregate(
            DatabaseFixtures::scope(),
            DatabaseFixtures::catalogue(),
            AggregateQuery::fromArray(
                array(
                    'relation' => 'reporting.orders',
                    'metrics' => array(
                        array( 'op' => 'p90', 'column' => 'total_minor', 'as' => 'p90_total' ),
                    ),
                )
            ),
            DatabaseFixtures::tenant(),
            'pgsql',
            DatabaseFixtures::limits()
        );

        self::assertStringContainsString('percentile_cont(0.9) WITHIN GROUP (ORDER BY "total_minor")', $statement->sql);
    }

    public function test_aggregate_refuses_ordering_by_an_ungrouped_column(): void
    {
        $this->assertRefusal(
            RefusalCode::STATEMENT_NOT_ADMITTED,
            fn (): mixed => $this->grammar->aggregate(
                DatabaseFixtures::scope(),
                DatabaseFixtures::catalogue(),
                AggregateQuery::fromArray(
                    array(
                        'relation' => 'reporting.orders',
                        'group_by' => array( 'status' ),
                        'metrics' => array( array( 'op' => 'count', 'as' => 'orders' ) ),
                        'order' => array( array( 'column' => 'placed_at', 'direction' => 'asc' ) ),
                    )
                ),
                DatabaseFixtures::tenant(),
                'pgsql',
                DatabaseFixtures::limits()
            )
        );
    }

    public function test_search_uses_the_portable_escape_character(): void
    {
        $statement = $this->grammar->search(
            DatabaseFixtures::scope(),
            DatabaseFixtures::catalogue(),
            SearchQuery::fromArray(
                array(
                    'relation' => 'reporting.orders',
                    'term' => '100%',
                )
            ),
            DatabaseFixtures::tenant(),
            'pgsql',
            DatabaseFixtures::limits()
        );

        self::assertStringContainsString('ILIKE', $statement->sql);
        self::assertStringContainsString("ESCAPE '!'", $statement->sql);
        self::assertContains('%100!%%', array_values($statement->bindings));
    }

    public function test_search_refuses_when_nothing_is_searchable(): void
    {
        $scope = DatabaseFixtures::scope(
            array(
                'columns' => array(
                    'reporting.orders' => array( 'exclude' => array( 'email', 'notes', 'status' ) ),
                ),
            )
        );

        $this->assertRefusal(
            RefusalCode::COLUMN_NOT_ALLOWED,
            fn (): mixed => $this->grammar->search(
                $scope,
                DatabaseFixtures::catalogue(),
                SearchQuery::fromArray(
                    array(
                        'relation' => 'reporting.orders',
                        'term' => 'acme',
                    )
                ),
                DatabaseFixtures::tenant(),
                'pgsql',
                DatabaseFixtures::limits()
            )
        );
    }

    public function test_raw_refuses_a_stacked_statement(): void
    {
        $this->assertRefusal(
            RefusalCode::STATEMENT_NOT_ADMITTED,
            fn (): mixed => $this->grammar->raw(
                new RawQuery('SELECT 1; DROP TABLE x'),
                'pgsql',
                new AdmissionClassifier()
            )
        );
    }

    public function test_raw_requires_placeholders_and_bindings_to_agree(): void
    {
        $this->assertRefusal(
            RefusalCode::STATEMENT_NOT_ADMITTED,
            fn (): mixed => $this->grammar->raw(
                new RawQuery('SELECT * FROM t WHERE id = :id'),
                'pgsql',
                new AdmissionClassifier()
            )
        );

        $this->assertRefusal(
            RefusalCode::STATEMENT_NOT_ADMITTED,
            fn (): mixed => $this->grammar->raw(
                new RawQuery('SELECT * FROM t', array( ':unused' => 1 )),
                'pgsql',
                new AdmissionClassifier()
            )
        );
    }

    public function test_raw_passes_an_admitted_statement_through_unchanged(): void
    {
        $statement = $this->grammar->raw(
            new RawQuery('SELECT id FROM t WHERE id = :id', array( ':id' => 7 )),
            'pgsql',
            new AdmissionClassifier()
        );

        self::assertSame('SELECT id FROM t WHERE id = :id', $statement->sql);
        self::assertSame(7, $statement->bindings[':id']);
    }

    public function test_quoting_differs_by_engine(): void
    {
        self::assertSame('"orders"', $this->grammar->quote('orders', 'pgsql'));
        self::assertSame('`orders`', $this->grammar->quote('orders', 'mysql'));
        self::assertSame('"a""b"', $this->grammar->quote('a"b', 'pgsql'));
        self::assertSame('`a``b`', $this->grammar->quote('a`b', 'mysql'));
    }

    public function test_quoting_refuses_a_nul_byte(): void
    {
        $this->assertRefusal(
            RefusalCode::STATEMENT_NOT_ADMITTED,
            fn (): string => $this->grammar->quote("a\0b", 'pgsql')
        );
    }

    public function test_explain_prefix_is_engine_specific(): void
    {
        self::assertSame('EXPLAIN (FORMAT JSON) ', $this->grammar->explainPrefix('pgsql', false));
        self::assertSame('EXPLAIN (FORMAT JSON, ANALYZE true) ', $this->grammar->explainPrefix('pgsql', true));
        self::assertSame('EXPLAIN FORMAT=JSON ', $this->grammar->explainPrefix('mysql', false));

        $this->assertRefusal(
            RefusalCode::UNSUPPORTED_METRIC,
            fn (): string => $this->grammar->explainPrefix('sqlite', false)
        );
    }
}
