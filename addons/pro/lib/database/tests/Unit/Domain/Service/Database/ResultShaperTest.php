<?php

/**
 * Shaping tests: caps, fidelity, and the binary boundary.
 *
 * The cell cap matters because a single `text` column can hold megabytes; without
 * it one row is enough to spend a turn's budget. The numeric case matters because
 * a `numeric`/`decimal` value cannot round-trip through a PHP float.
 *
 * @package Nvoos\Database\Tests
 * @since   3.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Tests\Unit\Domain\Service\Database;

use Nvoos\Database\Domain\Service\Database\ResultShaper;
use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use PHPUnit\Framework\TestCase;

final class ResultShaperTest extends TestCase
{
    private ResultShaper $shaper;

    protected function setUp(): void
    {
        $this->shaper = new ResultShaper();
    }

    public function test_it_preserves_integers_and_strings(): void
    {
        $truncated = false;

        $shaped = $this->shaper->shapeRow(
            array( 'order_id' => 5, 'status' => 'paid', 'email' => null ),
            DatabaseFixtures::ordersTable(),
            DatabaseFixtures::limits(),
            $truncated
        );

        self::assertSame(5, $shaped['order_id']);
        self::assertSame('paid', $shaped['status']);
        self::assertNull($shaped['email']);
        self::assertFalse($truncated);
    }

    public function test_it_stringifies_a_float_from_a_numeric_column(): void
    {
        $truncated = false;

        $shaped = $this->shaper->shapeRow(
            array( 'total_minor' => 12.5 ),
            DatabaseFixtures::ordersTable(),
            DatabaseFixtures::limits(),
            $truncated
        );

        self::assertSame('12.5', $shaped['total_minor']);
    }

    public function test_it_caps_a_cell_and_sets_the_flag(): void
    {
        $truncated = false;

        $shaped = $this->shaper->shapeRow(
            array( 'notes' => str_repeat('a', 200) ),
            DatabaseFixtures::ordersTable(),
            new DatabaseLimits(cellBytes: 40),
            $truncated
        );

        self::assertTrue($truncated);
        self::assertStringEndsWith(ResultShaper::TRUNCATION_MARKER, (string) $shaped['notes']);
        self::assertLessThanOrEqual(40, strlen((string) $shaped['notes']));
    }

    public function test_it_replaces_non_utf8_with_a_marker(): void
    {
        $truncated = false;

        $shaped = $this->shaper->shapeRow(
            array( 'notes' => "\xFF\xFE\x00binary" ),
            DatabaseFixtures::ordersTable(),
            DatabaseFixtures::limits(),
            $truncated
        );

        self::assertSame(ResultShaper::BINARY_MARKER, $shaped['notes']);
        self::assertTrue($truncated);
    }

    public function test_row_bytes_grows_with_content(): void
    {
        $small = $this->shaper->rowBytes(array( 'a' => 'x' ));
        $large = $this->shaper->rowBytes(array( 'a' => str_repeat('x', 500) ));

        self::assertGreaterThan(0, $small);
        self::assertGreaterThan($small, $large);
    }

    public function test_a_row_stays_under_the_byte_cap_when_the_cap_is_used(): void
    {
        $limits = new DatabaseLimits(cellBytes: 50);
        $truncated = false;

        $shaped = $this->shaper->shapeRow(
            array( 'notes' => str_repeat('z', 5000) ),
            DatabaseFixtures::ordersTable(),
            $limits,
            $truncated
        );

        self::assertLessThan(200, $this->shaper->rowBytes($shaped));
    }
}
