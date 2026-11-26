<?php

/**
 * The F&B metric fixture, shared by the tool tests.
 *
 * One small workbook's worth of rows, chosen so every figure in the suite can be
 * checked by hand:
 *
 * - revenue 20 × 10 + 10 × 3 = 230 in September, 10 × 10 + 20 × 3 = 160 in
 *   August;
 * - covers 40 and 30; food stock used 80 + 150 − 60 = 170, beverage
 *   40 + 110 − 150 = 0;
 * - operating cost 4030 for September and −20 for August (no payroll, no
 *   ledger, but the electricity fixed charge still applies);
 * - one supplier price rise of +0.20 against 50 units = 10.00;
 * - waste 15 over stock used 170.
 *
 * `tables()` returns the matrix, `reader()` a tabular reader over it, and
 * `map()` the settings map a host would write. The two earlier test files keep
 * their own copies because they predate this helper; the later slices use it.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\TabularReaderInterface;

final class FnbFixture
{
    /**
     * The fixture tables, keyed by spec id.
     *
     * @return array<string, array{0: list<string>, 1: list<list<string>>}>
     */
    public static function tables(): array
    {
        return array(
            '1.0' => array(
                array('Item ID', 'Item', 'Section', 'Type', 'Price', 'Recipe Cost'),
                array(
                    array('IT-1', 'Burger', 'Mains', 'Food', '10', '4'),
                    array('IT-2', 'Soda', 'Drinks', 'Beverage', '3', '1'),
                ),
            ),
            '2.0' => array(
                array('Item ID', 'Ingredient ID', 'Qty Per Portion', 'Cost Per Portion'),
                array(
                    array('IT-1', 'ING-1', '2', '4'),
                    array('IT-2', 'ING-2', '1', '1'),
                ),
            ),
            '3.0' => array(
                array('Ingredient ID', 'Ingredient', 'Shelf Life Days'),
                array(
                    array('ING-1', 'Beef', '5'),
                    array('ING-2', 'Syrup', '365'),
                ),
            ),
            '4.0' => array(
                array('Supplier ID', 'Supplier', 'Order Cutoff', 'Lead Time'),
                array(
                    array('SUP-1', 'Acme Foods', '14:00', '2 days'),
                ),
            ),
            '5.0' => array(
                array('Ingredient ID', 'Pack Size', 'Pack Price', 'Effective From'),
                array(
                    array('ING-1', '10', '20', '2026-07-01'),
                    array('ING-1', '10', '30', '2026-08-15'),
                    array('ING-2', '10', '8', '2026-08-01'),
                    array('ING-2', '10', '10', '2026-09-10'),
                ),
            ),
            '6.0' => array(
                array('Date', 'Item ID', 'Type', 'Qty Sold', 'Price'),
                array(
                    array('2026-08-01', 'IT-1', 'Food', '10', '10'),
                    array('2026-08-01', 'IT-2', 'Beverage', '20', '3'),
                    array('2026-09-01', 'IT-1', 'Food', '20', '10'),
                    array('2026-09-01', 'IT-2', 'Beverage', '10', '3'),
                ),
            ),
            '7.0' => array(
                array('Date', 'Lunch Covers', 'Dinner Covers', 'Bar Only Guests'),
                array(
                    array('2026-08-01', '20', '10', '0'),
                    array('2026-09-01', '25', '10', '5'),
                ),
            ),
            '8.0' => array(
                array('Delivery Date', 'Ingredient ID', 'Category', 'Qty Delivered', 'Line Total'),
                array(
                    array('2026-09-05', 'ING-1', 'Food', '5', '150'),
                    array('2026-09-06', 'ING-2', 'Beverage', '100', '60'),
                    array('2026-09-20', 'ING-2', 'Beverage', '50', '50'),
                ),
            ),
            '9.0' => array(
                array('Count Date', 'Period Start', 'Ingredient ID', 'Category', 'Opening', 'Bought', 'Closing', 'Used', 'Closing Value'),
                array(
                    array('2026-08-31', '2026-08-01', 'ING-1', 'Food', '10', '5', '8', '7', '80'),
                    array('2026-08-31', '2026-08-01', 'ING-2', 'Beverage', '100', '20', '80', '40', '40'),
                    array('2026-09-25', '2026-09-20', 'ING-1', 'Food', '8', '5', '6', '14', '60'),
                    array('2026-09-30', '2026-09-01', 'ING-1', 'Food', '8', '5', '6', '7', '60'),
                    array('2026-09-30', '2026-09-01', 'ING-2', 'Beverage', '80', '100', '150', '30', '150'),
                ),
            ),
            '10.0' => array(
                array('Date', 'Ingredient ID', 'Qty', 'Value', 'Reason'),
                array(
                    array('2026-09-10', 'ING-1', '1', '10', 'Spoilage'),
                    array('2026-09-11', 'ING-1', '0.5', '5', 'Prep trim'),
                ),
            ),
            '11.0' => array(
                array('Month', 'Department', 'Total Payroll Cost', 'Overtime Hours', 'Casual Shifts'),
                array(
                    array('2026-09', 'Kitchen', '1000', '10', '2'),
                    array('2026-09', 'Front', '500', '5', '1'),
                ),
            ),
            '13.0' => array(
                array('Date', 'Electricity kWh', 'Water m3', 'LPG Cylinders', 'Total Covers'),
                array(
                    array('2026-09-30', '100', '10', '2', '300'),
                ),
            ),
            '14.0' => array(
                array('For Month', 'Group', 'Expense Line', 'Amount'),
                array(
                    array('2026-09', 'Operating', 'Rent', '2000'),
                    array('2026-09', 'Operating', 'Cleaning', '100'),
                    array('2026-09', 'Stock purchases', 'Misc', '999'),
                ),
            ),
            '15.0' => array(
                array('Month', 'Line', 'Budget'),
                array(
                    array('2026-09', 'Payroll', '1400'),
                    array('2026-09', 'Rent', '1800'),
                    array('2026-09', 'Cleaning', '100'),
                ),
            ),
            '16.0' => array(
                array('Date', 'Guests'),
                array(
                    array('2026-09-29', '20'),
                    array('2026-09-29', '10'),
                ),
            ),
            'assumptions' => array(
                array('Item', 'Value', 'Unit', 'Source'),
                array(
                    array('Electricity tariff', '0.5', 'per kWh', 'Utility bill'),
                    array('Electricity fixed charge', '100', 'per month', 'Utility bill'),
                    array('Water rate', '1', 'per m3', 'Utility bill'),
                    array('Water fixed charge', '0', 'per month', 'Utility bill'),
                    array('LPG cylinder price (37.5 kg)', '50', 'per cylinder', 'Supplier'),
                ),
            ),
        );
    }

    /**
     * The settings map a host would write for this fixture.
     *
     * @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables
     * @return array<string, array<string, string>>
     */
    public static function map(?array $tables = null): array
    {
        $map = array();

        foreach (array_keys($tables ?? self::tables()) as $tableId) {
            $map[ $tableId ] = array('path' => '/data/' . $tableId . '.csv');
        }

        return $map;
    }

    /**
     * A tabular reader over the fixture.
     *
     * @param array<string, array{0: list<string>, 1: list<list<string>>}>|null $tables
     */
    public static function reader(?array $tables = null): TabularReaderInterface
    {
        $tables ??= self::tables();

        return new class ($tables) implements TabularReaderInterface {
            /**
             * @param array<string, array{0: list<string>, 1: list<list<string>>}> $tables
             */
            public function __construct(private readonly array $tables)
            {
            }

            public function read(string $localPath, array $options = array()): array
            {
                $tableId = basename($localPath, '.csv');

                if (! isset($this->tables[ $tableId ])) {
                    throw new \RuntimeException('No fixture for ' . $localPath);
                }

                return array(
                    'columns' => $this->tables[ $tableId ][0],
                    'rows'    => $this->tables[ $tableId ][1],
                    'meta'    => array(),
                );
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getSupportedFormats(): array
            {
                return array('csv');
            }
        };
    }
}
