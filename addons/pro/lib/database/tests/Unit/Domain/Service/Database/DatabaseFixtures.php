<?php

/**
 * Shared fixtures for the database kernel tests.
 *
 * Not collected as a test (the suite only collects `*Test.php`). The shape mirrors
 * the implementation plan's §7.1 fixture: one relation with an exact-decimal
 * column, a sensitive column, an excluded column, and a tenant column used by the
 * mandatory row filter.
 *
 * @package Nvoos\Database\Tests
 * @since   3.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Tests\Unit\Domain\Service\Database;

use Nvoos\Database\Domain\ValueObject\Database\CatalogueDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ColumnDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;

final class DatabaseFixtures
{
    public static function ordersTable(): TableDescriptor
    {
        return new TableDescriptor(
            'reporting',
            'orders',
            array(
                'order_id' => new ColumnDescriptor('order_id', 'bigint', false),
                'tenant_id' => new ColumnDescriptor('tenant_id', 'uuid', false),
                'total_minor' => new ColumnDescriptor('total_minor', 'numeric(12,2)', false),
                'placed_at' => new ColumnDescriptor('placed_at', 'timestamptz', false),
                'email' => new ColumnDescriptor('email', 'text', true),
                'notes' => new ColumnDescriptor('notes', 'text', true),
                'status' => new ColumnDescriptor('status', 'text', false),
            ),
            array( 'order_id' )
        );
    }

    public static function catalogue(): CatalogueDescriptor
    {
        return new CatalogueDescriptor(
            array( 'reporting.orders' => self::ordersTable() )
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function scope(array $overrides = array()): ScopeDescriptor
    {
        return ScopeDescriptor::fromArray(
            array_merge(
                array(
                    'id' => 'analytics-readonly',
                    'label' => 'Analytics read-only',
                    'connection' => 'warehouse',
                    'schemas' => array( 'reporting' ),
                    'tables' => array( 'reporting.orders' ),
                    'columns' => array(
                        'reporting.orders' => array(
                            'sensitive' => array( 'email' ),
                            'exclude' => array( 'notes' ),
                            // `notes` is also declared searchable but excluded; the
                            // descriptor must filter it out rather than widen.
                            'searchable' => array( 'email', 'notes' ),
                        ),
                    ),
                    'row_filter' => array(
                        array(
                            'column' => 'tenant_id',
                            'op' => 'eq',
                            'value_from' => 'scope_tenant',
                        ),
                    ),
                    'tiers' => array( 'metadata', 'read' ),
                    'principals' => array( 'assistant:42' ),
                    'version' => 3,
                ),
                $overrides
            )
        );
    }

    public static function limits(): DatabaseLimits
    {
        return new DatabaseLimits();
    }

    public static function tenant(): string
    {
        return '11111111-1111-1111-1111-111111111111';
    }
}
