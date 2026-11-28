<?php

/**
 * Masking and exclusion tests.
 *
 * Masking and exclusion are deliberately different: an excluded column never
 * appears in the SQL, a sensitive column appears with a placeholder. These tests
 * pin both, and pin the `read_unmasked` exception so a scope cannot be widened by
 * accident when the tier is added for another purpose.
 *
 * @package Nvoos\Database\Tests
 * @since   3.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Tests\Unit\Domain\Service\Database;

use Nvoos\Database\Domain\Service\Database\ColumnMasker;
use PHPUnit\Framework\TestCase;

final class ColumnMaskerTest extends TestCase
{
    private ColumnMasker $masker;

    protected function setUp(): void
    {
        $this->masker = new ColumnMasker();
    }

    public function test_it_masks_sensitive_columns_and_drops_excluded_ones(): void
    {
        $result = $this->masker->maskRow(
            array(
                'order_id' => 5,
                'email' => 'buyer@example.com',
                'notes' => 'internal note',
            ),
            DatabaseFixtures::ordersTable(),
            DatabaseFixtures::scope(),
            'reporting.orders'
        );

        self::assertSame(5, $result['row']['order_id']);
        self::assertSame(ColumnMasker::MASK, $result['row']['email']);
        self::assertArrayNotHasKey('notes', $result['row']);
        self::assertSame(array( 'reporting.orders.email' ), $result['masked']);
    }

    public function test_it_leaves_sensitive_columns_alone_with_the_unmasked_tier(): void
    {
        $scope = DatabaseFixtures::scope(
            array( 'tiers' => array( 'metadata', 'read', 'read_unmasked' ) )
        );

        $result = $this->masker->maskRow(
            array( 'email' => 'buyer@example.com' ),
            DatabaseFixtures::ordersTable(),
            $scope,
            'reporting.orders'
        );

        self::assertSame('buyer@example.com', $result['row']['email']);
        self::assertSame(array(), $result['masked']);
    }

    public function test_exclusion_applies_even_with_the_unmasked_tier(): void
    {
        $scope = DatabaseFixtures::scope(
            array( 'tiers' => array( 'metadata', 'read', 'read_unmasked' ) )
        );

        $result = $this->masker->maskRow(
            array( 'notes' => 'internal note' ),
            DatabaseFixtures::ordersTable(),
            $scope,
            'reporting.orders'
        );

        self::assertArrayNotHasKey('notes', $result['row']);
    }

    public function test_a_row_without_sensitive_values_reports_nothing_masked(): void
    {
        $result = $this->masker->maskRow(
            array( 'order_id' => 5, 'status' => 'paid' ),
            DatabaseFixtures::ordersTable(),
            DatabaseFixtures::scope(),
            'reporting.orders'
        );

        self::assertSame(array(), $result['masked']);
    }
}
