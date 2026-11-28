<?php

/**
 * Value-object tests: fail-closed defaults and strict validation.
 *
 * These objects are where a corrupted settings row becomes either a refusal or a
 * silent widening. Every case here is a "must not default to something generous".
 *
 * @package Nvoos\Database\Tests
 * @since   3.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Tests\Unit\Domain\Service\Database;

use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use PHPUnit\Framework\TestCase;

final class ValueObjectsTest extends TestCase
{
    use RefusalAssertions;

    public function test_limits_default_to_a_read_only_budget(): void
    {
        $limits = new DatabaseLimits();

        self::assertSame(500, $limits->maxRows);
        self::assertSame(0, $limits->maxAffectedRows);
        self::assertFalse($limits->writesPossible());
    }

    public function test_limits_fall_back_on_junk_instead_of_casting(): void
    {
        $limits = DatabaseLimits::fromArray(
            array(
                'max_rows' => 'not-a-number',
                'max_bytes' => -5,
                'timeout_ms' => 0,
                'max_affected_rows' => 'abc',
            )
        );

        self::assertSame(DatabaseLimits::DEFAULT_MAX_ROWS, $limits->maxRows);
        self::assertSame(DatabaseLimits::DEFAULT_MAX_BYTES, $limits->maxBytes);
        self::assertSame(DatabaseLimits::DEFAULT_TIMEOUT_MS, $limits->timeoutMs);
        self::assertSame(0, $limits->maxAffectedRows);
    }

    public function test_limits_accept_numeric_strings(): void
    {
        $limits = DatabaseLimits::fromArray(array( 'max_rows' => '25', 'max_affected_rows' => '10' ));

        self::assertSame(25, $limits->maxRows);
        self::assertSame(10, $limits->maxAffectedRows);
        self::assertTrue($limits->writesPossible());
    }

    public function test_scope_overrides_inherit_unspecified_values(): void
    {
        $base = new DatabaseLimits();
        $scoped = $base->withScopeOverrides(array( 'max_rows' => 50 ));

        self::assertSame(50, $scoped->maxRows);
        self::assertSame($base->maxBytes, $scoped->maxBytes);
        self::assertSame($base->timeoutMs, $scoped->timeoutMs);
    }

    public function test_effective_row_limit_cannot_exceed_the_scope(): void
    {
        $limits = new DatabaseLimits(maxRows: 100);

        self::assertSame(100, $limits->effectiveRowLimit(null));
        self::assertSame(10, $limits->effectiveRowLimit(10));
        self::assertSame(100, $limits->effectiveRowLimit(10000));
        self::assertSame(100, $limits->effectiveRowLimit(0));
    }

    public function test_a_wildcard_schema_is_refused(): void
    {
        $this->assertRefusal(
            RefusalCode::SCOPE_NOT_ALLOWED,
            fn (): ScopeDescriptor => ScopeDescriptor::fromArray(
                array(
                    'id' => 'bad',
                    'connection' => 'warehouse',
                    'schemas' => array( '*' ),
                    'tables' => array( '*' ),
                )
            )
        );
    }

    public function test_an_empty_table_list_grants_nothing(): void
    {
        $scope = ScopeDescriptor::fromArray(
            array(
                'id' => 'empty',
                'connection' => 'warehouse',
                'schemas' => array( 'reporting' ),
                'tables' => array(),
            )
        );

        self::assertFalse($scope->allowsTable(DatabaseFixtures::ordersTable()));
    }

    public function test_a_wildcard_table_is_scoped_by_schema(): void
    {
        $scope = ScopeDescriptor::fromArray(
            array(
                'id' => 'all-reporting',
                'connection' => 'warehouse',
                'schemas' => array( 'reporting' ),
                'tables' => array( '*' ),
            )
        );

        self::assertTrue($scope->allowsTable(DatabaseFixtures::ordersTable()));
    }

    public function test_an_unbound_scope_denies_everyone(): void
    {
        $scope = DatabaseFixtures::scope(array( 'principals' => array() ));

        self::assertFalse($scope->allowsPrincipal(new PrincipalRef(PrincipalRef::ASSISTANT, '42')));
    }

    public function test_a_principal_distinguishes_kinds(): void
    {
        $assistant = new PrincipalRef(PrincipalRef::ASSISTANT, '42');
        $user = new PrincipalRef(PrincipalRef::USER, '42');

        self::assertFalse($assistant->equals($user));
        self::assertSame('assistant:42', $assistant->key());
    }

    public function test_a_malformed_principal_is_dropped_not_widened(): void
    {
        self::assertNull(PrincipalRef::tryFromString('nonsense'));
        self::assertNull(PrincipalRef::tryFromString('robot:1'));

        $scope = DatabaseFixtures::scope(array( 'principals' => array( 'nonsense' ) ));

        self::assertFalse($scope->allowsPrincipal(new PrincipalRef(PrincipalRef::ASSISTANT, '42')));
    }

    public function test_a_connection_requires_host_and_database(): void
    {
        $this->assertRefusal(
            RefusalCode::CONNECTION_UNAVAILABLE,
            fn (): ConnectionDescriptor => ConnectionDescriptor::fromArray(
                array( 'id' => 'wh', 'engine' => 'pgsql', 'port' => 5432, 'database' => 'analytics' )
            )
        );
    }

    public function test_a_connection_refuses_an_unknown_engine(): void
    {
        $this->assertRefusal(
            RefusalCode::CONNECTION_UNAVAILABLE,
            fn (): ConnectionDescriptor => ConnectionDescriptor::fromArray(
                array( 'id' => 'wh', 'engine' => 'oracle', 'host' => 'db', 'port' => 1521, 'database' => 'x' )
            )
        );
    }

    public function test_verify_full_requires_a_ca_bundle(): void
    {
        $this->assertRefusal(
            RefusalCode::CONNECTION_UNAVAILABLE,
            fn (): ConnectionDescriptor => ConnectionDescriptor::fromArray(
                array(
                    'id' => 'wh',
                    'engine' => 'pgsql',
                    'host' => 'db',
                    'port' => 5432,
                    'database' => 'analytics',
                    'sslmode' => 'verify-full',
                )
            )
        );
    }

    public function test_a_connection_summary_never_includes_the_credential(): void
    {
        $connection = ConnectionDescriptor::fromArray(
            array(
                'id' => 'warehouse',
                'label' => 'Warehouse',
                'engine' => 'pgsql',
                'host' => 'db.internal',
                'port' => 5432,
                'database' => 'analytics',
                'credential' => 'v2.ciphertext',
            )
        );

        $summary = $connection->toSummary();

        self::assertArrayNotHasKey('credential', $summary);
        self::assertArrayNotHasKey('read_replica', $summary);
        self::assertSame('warehouse', $summary['id']);
        self::assertFalse($summary['queryable']);
    }

    public function test_a_connection_is_queryable_only_after_a_probe(): void
    {
        $record = array(
            'id' => 'warehouse',
            'engine' => 'pgsql',
            'host' => 'db',
            'port' => 5432,
            'database' => 'analytics',
            'probe' => array( 'reachable' => true, 'server_version' => '18.1', 'checked_at' => 1 ),
        );

        self::assertTrue(ConnectionDescriptor::fromArray($record)->isServable());

        $record['probe']['reachable'] = false;
        self::assertFalse(ConnectionDescriptor::fromArray($record)->isServable());
    }
}
