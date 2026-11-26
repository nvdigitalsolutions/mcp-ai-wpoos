<?php

/**
 * The Food & Beverage table foundation: the catalogue, the cell helpers, the
 * source, and the two read tools.
 *
 * Three kinds of assertion carry the weight here:
 *
 * - **The catalogue is generated, so the test checks the generator's output is
 *   usable**, not that it matches a hand copy: 18 tables, the canonical keys the
 *   metrics will read, and a column that resolves by all three passes
 *   (canonical → table alias → header dictionary).
 * - **The coercions are the toolkit's arithmetic.** A spreadsheet serial date
 *   and an epoch timestamp differ only by magnitude, and `"1,234.5"` is not a
 *   number to `is_numeric`. Both are pinned with real values.
 * - **The search keeps two WordPress behaviours** that look like bugs and are
 *   the contract: a column with no value means "is present", and a row whose
 *   date does not parse passes a date filter.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\TabularReaderInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\Fnb\FnbReadTableTool;
use Nvoos\Core\Tool\Fnb\FnbSearchTableTool;
use Nvoos\Core\Tool\Fnb\FnbTableCatalogue;
use Nvoos\Core\Tool\Fnb\FnbTableSource;
use PHPUnit\Framework\TestCase;

final class FnbTableToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && false === ( $value['success'] ?? true )
        );
    }

    // ─── The catalogue ────────────────────────────────────────────────────

    public function test_the_catalogue_holds_the_eighteen_spec_tables(): void
    {
        $tables = FnbTableCatalogue::tables();

        $this->assertCount(18, $tables);
        $this->assertArrayHasKey('1.0', $tables);
        $this->assertArrayHasKey('17.0', $tables);
        $this->assertArrayHasKey(FnbTableCatalogue::ASSUMPTIONS, $tables);
        $this->assertSame('Ingredients', FnbTableCatalogue::label('3.0'));
    }

    public function test_a_header_normalises_the_wordpress_way(): void
    {
        $this->assertSame('item_id', FnbTableCatalogue::normaliseHeader(' Item ID '));
        $this->assertSame('cost_per_portion_lkr', FnbTableCatalogue::normaliseHeader('Cost Per Portion (LKR)'));
        // Superscript digits are transliterated before the ASCII-only pass.
        $this->assertSame('qty3_sold', FnbTableCatalogue::normaliseHeader('Qty³ Sold'));
    }

    public function test_the_alias_dictionary_resolves_a_vendor_spelling(): void
    {
        $this->assertSame('invoice', FnbTableCatalogue::resolveHeaderAlias('Inv No'));
        $this->assertSame('total_covers', FnbTableCatalogue::resolveHeaderAlias('Covers'));
        // An unknown header comes back unchanged, so normalisation still applies.
        $this->assertSame('Zzz', FnbTableCatalogue::resolveHeaderAlias('Zzz'));
    }

    public function test_a_column_resolves_by_canonical_alias_then_dictionary(): void
    {
        // 1. The canonical key itself.
        $this->assertSame(2, FnbTableCatalogue::resolveColumn('6.0', array('date', 'item_id', 'revenue'), 'revenue'));

        // 2. A per-table alias: 6.0 maps `price` onto `price_lkr`.
        $this->assertSame(1, FnbTableCatalogue::resolveColumn('6.0', array('date', 'price_lkr'), 'price'));

        // 3. The header dictionary: the operator's `inv no` is the canonical
        //    `invoice` on 8.0, whose own aliases do not list `inv_no`.
        $this->assertSame(1, FnbTableCatalogue::resolveColumn('8.0', array('delivery_date', 'inv_no'), 'invoice'));

        $this->assertFalse(FnbTableCatalogue::resolveColumn('6.0', array('date'), 'revenue'));
    }

    public function test_cell_returns_the_fallback_for_an_absent_or_empty_column(): void
    {
        $headers = array('date', 'item_id');

        $this->assertSame('ING-001', FnbTableCatalogue::cell('3.0', $headers, array('2026-07-01', 'ING-001'), 'item_id'));
        $this->assertSame('n/a', FnbTableCatalogue::cell('3.0', $headers, array('2026-07-01', ''), 'item_id', 'n/a'));
        $this->assertSame('', FnbTableCatalogue::cell('3.0', $headers, array('2026-07-01', 'X'), 'ingredient_id'));
    }

    public function test_a_spreadsheet_serial_and_an_epoch_are_told_apart_by_magnitude(): void
    {
        // A Sheets/Excel serial: 46023 days after 1899-12-30.
        $this->assertSame('2026-01-01', FnbTableCatalogue::dateOnly(46023));
        // 1704067200 is 2024-01-01T00:00:00Z.
        $this->assertSame('2024-01-01', FnbTableCatalogue::dateOnly(1704067200));
    }

    public function test_a_date_value_reduces_to_the_day_or_keeps_a_month(): void
    {
        $this->assertSame('2026-07-01', FnbTableCatalogue::dateOnly('2026-07-01 00:00:00'));
        $this->assertSame('2026-07', FnbTableCatalogue::dateOnly('2026-07'));
        $this->assertSame('', FnbTableCatalogue::dateOnly('not a date'));
        $this->assertSame('', FnbTableCatalogue::dateOnly(0));
        $this->assertSame('', FnbTableCatalogue::dateOnly(-5));
        $this->assertSame('2026-07', FnbTableCatalogue::monthOf('2026-07-01'));
        $this->assertSame('', FnbTableCatalogue::monthOf(''));
    }

    public function test_float_cell_strips_thousands_separators(): void
    {
        $this->assertSame(1234.5, FnbTableCatalogue::floatCell('1,234.5'));
        $this->assertSame(12.0, FnbTableCatalogue::floatCell(' 12 '));
        $this->assertSame(3.0, FnbTableCatalogue::floatCell(3));
        $this->assertSame(0.0, FnbTableCatalogue::floatCell('abc'));
    }

    // ─── The source ───────────────────────────────────────────────────────

    public function test_an_unknown_table_is_a_typed_error(): void
    {
        $result = $this->source($this->reader())->getTable('99.0');

        $this->assertSame('unknown_table', $result['error']['code']);
    }

    public function test_an_unmapped_table_says_so_instead_of_reading_nothing(): void
    {
        $result = $this->source($this->reader())->getTable('3.0');

        $this->assertSame('table_not_configured', $result['error']['code']);
    }

    public function test_no_reader_is_a_typed_error(): void
    {
        $result = $this->source(null, $this->map('3.0'))->getTable('3.0');

        $this->assertSame('table_source_unavailable', $result['error']['code']);
    }

    public function test_an_unavailable_reader_is_a_typed_error(): void
    {
        $result = $this->source($this->reader(false), $this->map('3.0'))->getTable('3.0');

        $this->assertSame('table_source_unavailable', $result['error']['code']);
    }

    public function test_a_reader_failure_is_a_typed_error(): void
    {
        $result = $this->source($this->reader(true, array(), array(), 'The worker is down.'), $this->map('3.0'))->getTable('3.0');

        $this->assertSame('table_unreadable', $result['error']['code']);
        $this->assertStringContainsString('The worker is down.', $result['error']['message']);
    }

    public function test_a_table_with_no_header_row_is_unreadable(): void
    {
        $result = $this->source($this->reader(true, array(), array()), $this->map('3.0'))->getTable('3.0');

        $this->assertSame('table_unreadable', $result['error']['code']);
    }

    public function test_the_table_carries_the_header_index_and_its_source(): void
    {
        $reader = $this->reader(
            true,
            array('Date', 'Item ID', 'Revenue (LKR)'),
            array(array('2026-07-01', 'ING-001', '1,200'))
        );

        $table = $this->source($reader, $this->map('6.0'))->getTable('6.0');

        $this->assertSame('6.0', $table['table_id']);
        $this->assertSame('Daily sales', $table['label']);
        $this->assertSame(array('Date', 'Item ID', 'Revenue (LKR)'), $table['columns']);
        $this->assertSame(array('date', 'item_id', 'revenue_lkr'), $table['header_index']);
        $this->assertSame(1, $table['count']);
        $this->assertSame('fnb_data_source', $table['source']);
        // `max_rows = 0` reads the whole sheet; the tools page, the source does not.
        $this->assertSame(0, $reader->calls[0]['options']['max_rows']);
    }

    public function test_the_sheet_and_format_reach_the_reader(): void
    {
        $reader = $this->reader(true, array('Date'), array(array('2026-07-01')));

        $settings = array(
            FnbTableSource::SETTINGS_OPTION => array(
                'table_map' => array(
                    '6.0' => array('path' => '/data/sales.xlsx', 'sheet' => 'Daily sales', 'format' => 'XLSX'),
                ),
            ),
        );

        $this->source($reader, $settings)->getTable('6.0');

        $this->assertSame('/data/sales.xlsx', $reader->calls[0]['path']);
        $this->assertSame('Daily sales', $reader->calls[0]['options']['sheet']);
        // The format is lower-cased before it reaches the reader.
        $this->assertSame('xlsx', $reader->calls[0]['options']['format']);
    }

    public function test_a_resolved_table_is_cached_and_the_second_read_skips_the_reader(): void
    {
        $reader = $this->reader(true, array('Date'), array(array('2026-07-01')));
        $cache  = $this->cache();

        $source = $this->source($reader, $this->map('6.0'), $cache);

        $first  = $source->getTable('6.0');
        $second = $source->getTable('6.0');

        $this->assertSame($first, $second);
        $this->assertCount(1, $reader->calls);
        $this->assertCount(1, $cache->store);
        $this->assertStringStartsWith(FnbTableSource::CACHE_PREFIX, array_key_first($cache->store));
    }

    // ─── fnb_read_table ───────────────────────────────────────────────────

    public function test_the_read_tool_pages_and_says_when_it_truncated(): void
    {
        $result = $this->read($this->source($this->ingredients(), $this->map('3.0')))->execute(
            array('table_id' => '3.0', 'limit' => 2),
            self::CONTEXT,
        );

        $this->assertTrue($result['success']);
        $this->assertSame('Ingredients', $result['data']['label']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame(3, $result['data']['total_rows']);
        $this->assertTrue($result['data']['truncated']);
        $this->assertCount(2, $result['data']['rows']);
        $this->assertSame('fnb_data_source', $result['data']['source']);
        // The WordPress payload key set, minus the Drive connection pair.
        $this->assertArrayNotHasKey('connection_id', $result['data']);
    }

    public function test_the_read_tool_defaults_to_two_hundred_rows(): void
    {
        $rows = array();

        for ($i = 1; $i <= 250; $i++) {
            $rows[] = array('ING-' . $i);
        }

        $result = $this->read(
            $this->source($this->reader(true, array('Ingredient ID'), $rows), $this->map('3.0'))
        )->execute(array('table_id' => '3.0'), self::CONTEXT);

        $this->assertSame(200, $result['data']['count']);
        $this->assertSame(250, $result['data']['total_rows']);
        $this->assertTrue($result['data']['truncated']);
    }

    public function test_the_read_tool_requires_a_user(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->read($this->source($this->ingredients(), $this->map('3.0')))->execute(array('table_id' => '3.0'));
    }

    // ─── fnb_search_table ─────────────────────────────────────────────────

    public function test_a_column_value_is_a_case_insensitive_substring(): void
    {
        $result = $this->search($this->source($this->ingredients(), $this->map('3.0')))->execute(
            array('table_id' => '3.0', 'column' => 'ingredient_id', 'value' => 'ing-00'),
            self::CONTEXT,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['data']['count']);
    }

    public function test_a_column_with_no_value_requires_a_present_cell(): void
    {
        $rows = array(
            array('ING-001', 'Salt'),
            array('', 'Pepper'),
        );

        $result = $this->search(
            $this->source($this->reader(true, array('Ingredient ID', 'Ingredient'), $rows), $this->map('3.0'))
        )->execute(array('table_id' => '3.0', 'column' => 'ingredient_id'), self::CONTEXT);

        $this->assertSame(1, $result['data']['count']);
        $this->assertSame(array('ING-001', 'Salt'), $result['data']['rows'][0]);
    }

    public function test_the_date_range_is_inclusive(): void
    {
        $result = $this->search($this->source($this->sales(), $this->map('6.0')))->execute(
            array('table_id' => '6.0', 'from' => '2026-07-02', 'to' => '2026-07-02'),
            self::CONTEXT,
        );

        $dates = array_map(
            static fn (array $row): mixed => FnbTableCatalogue::cell('6.0', $result['data']['header_index'], $row, 'date'),
            $result['data']['rows']
        );

        // The boundary day is in; the day before it is out.
        $this->assertContains('2026-07-02', $dates);
        $this->assertNotContains('2026-07-01', $dates);
    }

    public function test_a_row_with_no_parseable_date_passes_the_range(): void
    {
        // WordPress only compares a date that parses; an empty cell is not
        // excluded. Pinned, because it reads like a bug.
        $result = $this->search($this->source($this->sales(), $this->map('6.0')))->execute(
            array('table_id' => '6.0', 'from' => '2026-07-02', 'to' => '2026-07-02'),
            self::CONTEXT,
        );

        $this->assertSame(2, $result['data']['count']);
    }

    public function test_the_match_cap_is_applied(): void
    {
        $result = $this->search($this->source($this->ingredients(), $this->map('3.0')))->execute(
            array('table_id' => '3.0', 'limit' => 1),
            self::CONTEXT,
        );

        $this->assertSame(1, $result['data']['count']);
    }

    public function test_the_search_payload_keeps_the_wordpress_shape(): void
    {
        $result = $this->search($this->source($this->ingredients(), $this->map('3.0')))->execute(
            array('table_id' => '3.0'),
            self::CONTEXT,
        );

        foreach (array('table_id', 'label', 'columns', 'header_index', 'rows', 'count', 'source') as $key) {
            $this->assertArrayHasKey($key, $result['data']);
        }

        $this->assertSame('Ingredients', $result['data']['label']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function read(FnbTableSource $source): FnbReadTableTool
    {
        return new FnbReadTableTool($this->errors, $source);
    }

    private function search(FnbTableSource $source): FnbSearchTableTool
    {
        return new FnbSearchTableTool($this->errors, $source);
    }

    /**
     * A source over the given reader, settings and cache.
     *
     * @param array<string, mixed>|null $settings
     */
    private function source(
        ?TabularReaderInterface $reader,
        ?array $settings = null,
        ?CacheStoreInterface $cache = null,
    ): FnbTableSource {
        $store = $this->createMock(SettingsStoreInterface::class);
        $store->method('get')->willReturnCallback(
            static fn (string $key, mixed $default = null): mixed => null !== $settings && isset($settings[ $key ])
                ? $settings[ $key ]
                : $default
        );

        return new FnbTableSource($this->errors, $reader, $store, $cache);
    }

    /**
     * A settings payload mapping one table to a path.
     *
     * @return array<string, mixed>
     */
    private function map(string $tableId): array
    {
        return array(
            FnbTableSource::SETTINGS_OPTION => array(
                'table_map' => array(
                    $tableId => array('path' => '/data/' . $tableId . '.csv'),
                ),
            ),
        );
    }

    /**
     * The 3.0 Ingredients fixture: three rows, one canonical column.
     */
    private function ingredients(): TabularReaderInterface
    {
        return $this->reader(
            true,
            array('Ingredient ID', 'Ingredient'),
            array(
                array('ING-001', 'Salt'),
                array('ING-002', 'Pepper'),
                array('ING-003', 'Oil'),
            )
        );
    }

    /**
     * The 6.0 Daily sales fixture: two dated rows and one undated row.
     */
    private function sales(): TabularReaderInterface
    {
        return $this->reader(
            true,
            array('Date', 'Item ID', 'Revenue (LKR)'),
            array(
                array('2026-07-01', 'IT-1', '1,000'),
                array('2026-07-02', 'IT-1', '2,000'),
                array('', 'IT-2', '500'),
            )
        );
    }

    /**
     * A tabular reader that records its calls.
     *
     * @param list<string>       $columns
     * @param list<list<string>> $rows
     */
    private function reader(
        bool $available = true,
        array $columns = array(),
        array $rows = array(),
        string $throws = '',
    ): TabularReaderInterface {
        return new class ($available, $columns, $rows, $throws) implements TabularReaderInterface {
            /** @var list<array{path: string, options: array<string, mixed>}> */
            public array $calls = array();

            /**
             * @param list<string>       $columns
             * @param list<list<string>> $rows
             */
            public function __construct(
                private readonly bool $available,
                private readonly array $columns,
                private readonly array $rows,
                private readonly string $throws,
            ) {
            }

            public function read(string $localPath, array $options = array()): array
            {
                $this->calls[] = array('path' => $localPath, 'options' => $options);

                if ('' !== $this->throws) {
                    throw new \RuntimeException($this->throws);
                }

                return array('columns' => $this->columns, 'rows' => $this->rows, 'meta' => array());
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function getSupportedFormats(): array
            {
                return array('csv', 'xlsx');
            }
        };
    }

    /**
     * An in-memory cache.
     */
    private function cache(): CacheStoreInterface
    {
        return new class () implements CacheStoreInterface {
            /** @var array<string, mixed> */
            public array $store = array();

            public function getValue(string $key, mixed $default = null): mixed
            {
                return $this->store[ $key ] ?? $default;
            }

            public function setValue(string $key, mixed $value, int $ttl = 3600): bool
            {
                $this->store[ $key ] = $value;

                return true;
            }

            public function deleteValue(string $key): bool
            {
                unset($this->store[ $key ]);

                return true;
            }

            public function increment(string $key, int $by = 1, int $ttl = 3600): int
            {
                $this->store[ $key ] = (int) ( $this->store[ $key ] ?? 0 ) + $by;

                return (int) $this->store[ $key ];
            }

            public function remember(string $key, int $ttl, callable $callback): mixed
            {
                if (! isset($this->store[ $key ])) {
                    $this->store[ $key ] = $callback();
                }

                return $this->store[ $key ];
            }
        };
    }
}
