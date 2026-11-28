<?php
/**
 * OrderStore adapter: the filter contract, the invoice marker, and line identity.
 *
 * Proposal 164 section 164.174. This is the binding that lets the order tools be
 * `ready` rather than `unavailable`, so the cases here are about the contract's
 * exact meaning rather than about plumbing:
 *
 * - a minimum total filters **before** the page, which is the defect the
 *   reference tool had and this store exists to not reproduce;
 * - a minimum total in one currency excludes orders in another, because an order
 *   that cannot be compared cannot be said to satisfy a bound;
 * - `total` counts matches across pages, not the page;
 * - saving the invoice marker keeps the line ids, because an invoice may
 *   reference them.
 *
 * Money is built with `fromMinorUnits()` throughout: the adapter carries an
 * integer through storage, and a decimal-string fixture would test `Money`
 * instead.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Domain\Contract\OrderStoreInterface;
use Nvoos\Core\Domain\Entity\Order;
use Nvoos\Core\Domain\Entity\OrderCollection;
use Nvoos\Core\Domain\Entity\OrderLine;
use Nvoos\Core\Domain\Entity\OrderQuery;
use Nvoos\Core\Domain\Error\ConflictException;
use Nvoos\Core\Domain\Error\ValidationException;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OrderGrouping;
use Nvoos\Core\Domain\ValueObject\OrderStatus;
use Nvoos\Laravel\Adapter\OrderStore;

final class OrderStoreTest extends TestCase {

	use RefreshDatabase;

	/**
	 * The bound adapter under test.
	 */
	private function store(): OrderStoreInterface {
		return $this->app->make( OrderStoreInterface::class );
	}

	/**
	 * Ids from a result set, in the order the adapter returned them.
	 *
	 * @param OrderCollection $collection Result set.
	 *
	 * @return list<int|null>
	 */
	private function ids( OrderCollection $collection ): array {
		return array_values(
			array_map(
				static fn ( Order $order ): ?int => $order->id,
				$collection->items
			)
		);
	}

	public function test_the_container_binds_the_contract_to_this_adapter(): void {
		$this->assertInstanceOf( OrderStore::class, $this->store() );
	}

	public function test_create_and_find_round_trip(): void {
		$store = $this->store();

		$created = $store->save(
			$this->order(
				number: '#1001',
				createdAt: new \DateTimeImmutable( '2024-01-15 10:30:00' ),
				totalMinor: 2499,
				lines: array( $this->line( 'Classic Tee', 1999, 1 ), $this->line( 'Sticker', 500, 1 ) )
			)
		);

		$this->assertNotNull( $created->id );

		$found = $store->find( (int) $created->id );

		$this->assertNotNull( $found );
		$this->assertSame( '#1001', $found->number );
		$this->assertSame( OrderStatus::Pending, $found->status );
		$this->assertSame( 2499, $found->total->minorUnits );
		$this->assertSame( 'USD', $found->currency() );
		$this->assertSame( 2, $found->itemCount() );
		$this->assertFalse( $found->isInvoiced() );
		$this->assertSame( '2024-01-15 10:30:00', $found->createdAt->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( array( 'Classic Tee', 'Sticker' ), $this->names( $found ) );
	}

	public function test_find_returns_null_for_a_missing_order(): void {
		$this->assertNull( $this->store()->find( 999 ) );
	}

	public function test_uninvoiced_only_excludes_invoiced_orders(): void {
		$store = $this->store();

		$store->save( $this->order( number: '#1', createdAt: new \DateTimeImmutable( '2024-01-02 00:00:00' ) ) );
		$store->save(
			$this->order(
				number: '#2',
				createdAt: new \DateTimeImmutable( '2024-01-01 00:00:00' ),
				invoicedAt: new \DateTimeImmutable( '2024-01-03 00:00:00' )
			)
		);

		$result = $store->query( new OrderQuery( uninvoicedOnly: true ) );

		$this->assertSame( 1, $result->total );
		$this->assertSame( array( '#1' ), $this->numbers( $result ) );
	}

	public function test_a_minimum_total_excludes_another_currency(): void {
		$store = $this->store();

		$store->save( $this->order( number: '#usd', totalMinor: 20000, currency: 'USD' ) );
		$store->save( $this->order( number: '#eur', totalMinor: 20000, currency: 'EUR' ) );

		$result = $store->query(
			new OrderQuery( minTotal: Money::fromMinorUnits( 10000, 'USD' ) )
		);

		$this->assertSame( 1, $result->total );
		$this->assertSame( array( '#usd' ), $this->numbers( $result ) );
	}

	public function test_a_minimum_total_filters_before_the_page(): void {
		$store = $this->store();

		// The newest order is the cheapest: a filter applied after the page
		// would return nothing.
		$store->save( $this->order( number: '#cheap', createdAt: new \DateTimeImmutable( '2024-01-03 00:00:00' ), totalMinor: 1000 ) );
		$store->save( $this->order( number: '#dear', createdAt: new \DateTimeImmutable( '2024-01-02 00:00:00' ), totalMinor: 50000 ) );
		$store->save( $this->order( number: '#middling', createdAt: new \DateTimeImmutable( '2024-01-01 00:00:00' ), totalMinor: 2000 ) );

		$result = $store->query(
			new OrderQuery( minTotal: Money::fromMinorUnits( 10000, 'USD' ), perPage: 1 )
		);

		$this->assertSame( 1, $result->total );
		$this->assertSame( array( '#dear' ), $this->numbers( $result ) );
	}

	public function test_the_date_range_is_inclusive(): void {
		$store = $this->store();

		$store->save( $this->order( number: '#first', createdAt: new \DateTimeImmutable( '2024-01-01 00:00:00' ) ) );
		$store->save( $this->order( number: '#last', createdAt: new \DateTimeImmutable( '2024-01-31 23:59:59' ) ) );
		$store->save( $this->order( number: '#after', createdAt: new \DateTimeImmutable( '2024-02-01 00:00:00' ) ) );

		$result = $store->query(
			new OrderQuery(
				createdAfter: new \DateTimeImmutable( '2024-01-01 00:00:00' ),
				createdBefore: new \DateTimeImmutable( '2024-01-31 23:59:59' ),
			)
		);

		$this->assertSame( 2, $result->total );
	}

	public function test_the_total_counts_across_pages(): void {
		$store = $this->store();

		for ( $index = 1; $index <= 3; $index++ ) {
			$store->save(
				$this->order(
					number: '#' . $index,
					createdAt: new \DateTimeImmutable( '2024-01-0' . $index . ' 00:00:00' )
				)
			);
		}

		$page = $store->query( new OrderQuery( page: 2, perPage: 2 ) );

		$this->assertSame( 3, $page->total );
		$this->assertSame( 2, $page->totalPages );
		$this->assertSame( 1, count( $page->items ) );
	}

	public function test_saving_the_marker_keeps_line_identity(): void {
		$store = $this->store();

		$created = $store->save(
			$this->order( number: '#7', lines: array( $this->line( 'Tee', 1999 ), $this->line( 'Cap', 1500 ) ) )
		);

		$before = array_map( static fn ( OrderLine $line ): ?int => $line->id, $created->lines );

		$marked = $store->save(
			$this->order(
				number: '#7',
				id: $created->id,
				createdAt: $created->createdAt,
				lines: $created->lines,
				invoicedAt: new \DateTimeImmutable( '2024-02-01 09:00:00' )
			)
		);

		$after = array_map( static fn ( OrderLine $line ): ?int => $line->id, $marked->lines );

		$this->assertTrue( $marked->isInvoiced() );
		$this->assertSame( $before, $after );
	}

	public function test_saving_replaces_lines_that_are_gone(): void {
		$store = $this->store();

		$created = $store->save(
			$this->order( number: '#8', lines: array( $this->line( 'Tee', 1999 ), $this->line( 'Cap', 1500 ) ) )
		);

		$kept = array( $created->lines[0] );

		$saved = $store->save(
			$this->order(
				number: '#8',
				id: $created->id,
				createdAt: $created->createdAt,
				lines: $kept
			)
		);

		$this->assertSame( 1, $saved->itemCount() );
		$this->assertSame( array( 'Tee' ), $this->names( $saved ) );
	}

	public function test_search_matches_the_number_or_the_email(): void {
		$store = $this->store();

		$store->save( $this->order( number: '#1001', billingEmail: 'ada@example.test' ) );
		$store->save( $this->order( number: '#1002', billingEmail: 'grace@example.test' ) );

		$this->assertSame( array( '#1002' ), $this->numbers( $store->query( new OrderQuery( search: '1002' ) ) ) );
		$this->assertSame( array( '#1002' ), $this->numbers( $store->query( new OrderQuery( search: 'grace@' ) ) ) );
	}

	public function test_search_escapes_wildcards(): void {
		$store = $this->store();

		$store->save( $this->order( number: '#1' ) );

		// Unescaped, a percent sign would match every row.
		$this->assertSame( 0, $store->query( new OrderQuery( search: '%' ) )->total );
	}

	public function test_the_search_is_combined_with_the_other_filters(): void {
		$store = $this->store();

		$store->save( $this->order( number: '#match-1', invoicedAt: new \DateTimeImmutable( '2024-01-02 00:00:00' ) ) );
		$store->save( $this->order( number: '#match-2' ) );

		// An orWhere added outside a group would leak past the marker filter.
		$result = $store->query( new OrderQuery( uninvoicedOnly: true, search: 'match' ) );

		$this->assertSame( array( '#match-2' ), $this->numbers( $result ) );
	}

	public function test_save_refuses_an_illegal_transition(): void {
		$store = $this->store();

		$created = $store->save( $this->order( number: '#9' ) );

		$this->expectException( ConflictException::class );

		$store->save(
			$this->order(
				number: '#9',
				id: $created->id,
				createdAt: $created->createdAt,
				status: OrderStatus::Refunded,
			)
		);
	}
	/**
	 * Order numbers from a result set.
	 *
	 * @param OrderCollection $collection Result set.
	 *
	 * @return list<string>
	 */
	private function numbers( OrderCollection $collection ): array {
		return array_values(
			array_map(
				static fn ( Order $order ): string => $order->number,
				$collection->items
			)
		);
	}

	/**
	 * Line names from one order, in stored order.
	 *
	 * @param Order $order The order.
	 *
	 * @return list<string>
	 */
	private function names( Order $order ): array {
		return array_values(
			array_map(
				static fn ( OrderLine $line ): string => $line->name,
				$order->lines
			)
		);
	}

	/**
	 * A domain order for a fixture.
	 *
	 * @param list<OrderLine> $lines Fixture lines.
	 */
	private function order(
		string $number,
		?\DateTimeImmutable $createdAt = null,
		int $totalMinor = 1999,
		string $currency = 'USD',
		?\DateTimeImmutable $invoicedAt = null,
		?int $id = null,
		array $lines = array(),
		OrderStatus $status = OrderStatus::Pending,
		string $billingEmail = 'ada@example.test',
		?int $customerId = 5,
	): Order {
		return new Order(
			id: $id,
			number: $number,
			status: $status,
			createdAt: $createdAt ?? new \DateTimeImmutable( '2024-01-01 00:00:00' ),
			total: Money::fromMinorUnits( $totalMinor, $currency ),
			lines: $lines,
			customerId: $customerId,
			billingName: 'Ada Lovelace',
			billingEmail: $billingEmail,
			invoicedAt: $invoicedAt,
		);
	}

	/**
	 * One order line for a fixture.
	 */
	private function line( string $name, int $unitMinor, int $quantity = 1, ?int $productId = null, string $currency = 'USD' ): OrderLine {
		return new OrderLine(
			name: $name,
			quantity: $quantity,
			unitPrice: Money::fromMinorUnits( $unitMinor, $currency ),
			lineTotal: Money::fromMinorUnits( $unitMinor * $quantity, $currency ),
			productId: $productId,
		);
	}

	/**
	 * A query covering February 2024, the fixture month for these cases.
	 */
	private function february(): OrderQuery {
		return new OrderQuery(
			createdAfter: new \DateTimeImmutable( '2024-02-01 00:00:00' ),
			createdBefore: new \DateTimeImmutable( '2024-02-29 23:59:59' ),
		);
	}

	public function test_analytics_separates_revenue_from_gross(): void {
		$store   = $this->store();
		$created = new \DateTimeImmutable( '2024-02-05 10:00:00' );

		$store->save( $this->order( 'A-1', $created, 10000, 'USD', null, null, array(), OrderStatus::Completed ) );
		$store->save( $this->order( 'A-2', $created, 5000, 'USD', null, null, array(), OrderStatus::Cancelled ) );
		$store->save( $this->order( 'A-3', $created, 4000, 'USD', null, null, array(), OrderStatus::Refunded ) );
		$store->save( $this->order( 'A-4', $created, 2000, 'USD', null, null, array(), OrderStatus::Failed ) );

		$analytics = $store->analytics( $this->february(), 'USD', OrderGrouping::Day );

		$this->assertSame( 4, $analytics->orderCount );
		$this->assertSame( 1, $analytics->revenueOrderCount );
		$this->assertSame( 21000, $analytics->grossTotal->minorUnits );
		$this->assertSame( 10000, $analytics->revenue->minorUnits );
		$this->assertSame( 10000, $analytics->averageOrderValueMinor() );
		$this->assertSame( 1, $analytics->statusCounts['cancelled'] );
		$this->assertSame( 1, $analytics->customerCount );
	}

	public function test_analytics_periods_carry_revenue_only(): void {
		$store   = $this->store();
		$created = new \DateTimeImmutable( '2024-02-05 10:00:00' );

		$store->save( $this->order( 'P-1', $created, 1000, 'USD', null, null, array(), OrderStatus::Completed ) );
		$store->save( $this->order( 'P-2', $created, 5000, 'USD', null, null, array(), OrderStatus::Cancelled ) );

		$periods = $store->analytics( $this->february(), 'USD', OrderGrouping::Day )->periods;

		$this->assertCount( 1, $periods );
		$this->assertSame( '2024-02-05', $periods[0]['period'] );
		$this->assertSame( 1, $periods[0]['orders'] );
		$this->assertSame( 1000, $periods[0]['revenue_minor'] );
	}

	public function test_analytics_excludes_another_currency_and_counts_it(): void {
		$store   = $this->store();
		$created = new \DateTimeImmutable( '2024-02-05 10:00:00' );

		$store->save( $this->order( 'B-1', $created, 10000, 'USD' ) );
		$store->save( $this->order( 'B-2', $created, 20000, 'EUR' ) );

		$analytics = $store->analytics( $this->february(), 'USD', OrderGrouping::Day );

		$this->assertSame( 1, $analytics->orderCount );
		$this->assertSame( 1, $analytics->excludedOrders );
		$this->assertSame( 10000, $analytics->grossTotal->minorUnits );
	}

	public function test_analytics_folds_days_into_iso_weeks_and_months(): void {
		$store = $this->store();

		$store->save( $this->order( 'C-1', new \DateTimeImmutable( '2024-12-30 09:00:00' ), 1000, 'USD', null, null, array(), OrderStatus::Completed ) );
		$store->save( $this->order( 'C-2', new \DateTimeImmutable( '2025-01-02 09:00:00' ), 2000, 'USD', null, null, array(), OrderStatus::Completed ) );
		$store->save( $this->order( 'C-3', new \DateTimeImmutable( '2025-02-10 09:00:00' ), 4000, 'USD', null, null, array(), OrderStatus::Completed ) );

		$query = new OrderQuery(
			createdAfter: new \DateTimeImmutable( '2024-12-01 00:00:00' ),
			createdBefore: new \DateTimeImmutable( '2025-02-28 23:59:59' ),
		);

		$weeks = $store->analytics( $query, 'USD', OrderGrouping::Week )->periods;

		$this->assertSame( array( '2025-W01', '2025-W07' ), array_column( $weeks, 'period' ) );
		$this->assertSame( 2, $weeks[0]['orders'] );
		$this->assertSame( 3000, $weeks[0]['revenue_minor'] );

		$months = $store->analytics( $query, 'USD', OrderGrouping::Month )->periods;

		$this->assertSame( array( '2024-12', '2025-01', '2025-02' ), array_column( $months, 'period' ) );
	}

	public function test_analytics_on_an_empty_selection_reports_zeroes(): void {
		$analytics = $this->store()->analytics( $this->february(), 'USD', OrderGrouping::Day );

		$this->assertSame( 0, $analytics->orderCount );
		$this->assertSame( 0, $analytics->revenueOrderCount );
		$this->assertSame( 0, $analytics->excludedOrders );
		$this->assertSame( 0, $analytics->grossTotal->minorUnits );
		$this->assertSame( array(), $analytics->statusCounts );
		$this->assertSame( array(), $analytics->periods );
	}

	public function test_analytics_refuses_a_currency_that_is_not_iso_4217(): void {
		$this->expectException( ValidationException::class );

		$this->store()->analytics( $this->february(), 'dollars', OrderGrouping::Day );
	}

	public function test_analytics_counts_distinct_buyers_and_ignores_guests(): void {
		$store   = $this->store();
		$created = new \DateTimeImmutable( '2024-02-05 10:00:00' );

		$store->save( $this->order( 'D-1', $created, 1000, 'USD', null, null, array(), OrderStatus::Completed, 'ada@example.test', 5 ) );
		$store->save( $this->order( 'D-2', $created, 2000, 'USD', null, null, array(), OrderStatus::Processing, 'ada@example.test', 5 ) );
		$store->save( $this->order( 'D-3', $created, 3000, 'USD', null, null, array(), OrderStatus::Completed, 'grace@example.test', 6 ) );
		$store->save( $this->order( 'D-4', $created, 4000, 'USD', null, null, array(), OrderStatus::Completed, 'guest@example.test', null ) );
		$store->save( $this->order( 'D-5', $created, 5000, 'USD', null, null, array(), OrderStatus::Cancelled, 'cancelled@example.test', 7 ) );

		$analytics = $store->analytics( $this->february(), 'USD', OrderGrouping::Day );

		// Four orders are revenue-bearing, including the guest order, but only
		// ada and grace are identified buyers; the cancelled order is neither.
		$this->assertSame( 2, $analytics->customerCount );
		$this->assertSame( 4, $analytics->revenueOrderCount );
	}

	public function test_analytics_reports_no_buyers_for_an_empty_selection(): void {
		$analytics = $this->store()->analytics( $this->february(), 'USD', OrderGrouping::Day );

		$this->assertSame( 0, $analytics->customerCount );
	}

	public function test_top_products_rolls_by_product_and_orders_by_revenue(): void {
		$store   = $this->store();
		$created = new \DateTimeImmutable( '2024-02-05 10:00:00' );

		$store->save(
			$this->order(
				'T-1',
				$created,
				5000,
				'USD',
				null,
				null,
				array(
					$this->line( 'Shirt', 2000, 1, 100 ),
					$this->line( 'Shirt', 2500, 1, 100 ),
					$this->line( 'Hat', 600, 3, 200 ),
				),
				OrderStatus::Completed
			)
		);

		$products = $store->topProducts( $this->february(), 'USD', 10 );

		$this->assertCount( 2, $products );
		$this->assertSame( 100, $products[0]->productId );
		$this->assertSame( 2, $products[0]->quantity );
		$this->assertSame( 4500, $products[0]->revenue->minorUnits );
		$this->assertSame( 200, $products[1]->productId );
		$this->assertSame( 3, $products[1]->quantity );
	}

	public function test_top_products_counts_only_paid_orders(): void {
		$store   = $this->store();
		$created = new \DateTimeImmutable( '2024-02-05 10:00:00' );

		$store->save( $this->order( 'P-1', $created, 1000, 'USD', null, null, array( $this->line( 'Mug', 1000, 1, 100 ) ), OrderStatus::Completed ) );
		$store->save( $this->order( 'P-2', $created, 9000, 'USD', null, null, array( $this->line( 'Cape', 9000, 1, 300 ) ), OrderStatus::Pending ) );
		$store->save( $this->order( 'P-3', $created, 8000, 'USD', null, null, array( $this->line( 'Cane', 8000, 1, 400 ) ), OrderStatus::Cancelled ) );

		$products = $store->topProducts( $this->february(), 'USD', 10 );

		$this->assertSame( array( 100 ), array_map( static fn ( $product ): int => $product->productId, $products ) );
	}

	public function test_top_products_excludes_another_currency(): void {
		$store   = $this->store();
		$created = new \DateTimeImmutable( '2024-02-05 10:00:00' );

		// Order refuses a line in a currency other than its own, so the order
		// currency is the only one that can differ here; the line check in the
		// adapter guards rows written outside the entity.
		$store->save( $this->order( 'C-1', $created, 1000, 'USD', null, null, array( $this->line( 'Mug', 1000, 1, 100 ) ), OrderStatus::Completed ) );
		$store->save( $this->order( 'C-2', $created, 3000, 'EUR', null, null, array( $this->line( 'Cape', 3000, 1, 300, 'EUR' ) ), OrderStatus::Completed ) );

		$products = $store->topProducts( $this->february(), 'USD', 10 );

		$this->assertCount( 1, $products );
		$this->assertSame( 100, $products[0]->productId );
		$this->assertSame( 1000, $products[0]->revenue->minorUnits );
	}

	public function test_top_products_skips_lines_without_a_product(): void {
		$store   = $this->store();
		$created = new \DateTimeImmutable( '2024-02-05 10:00:00' );

		$store->save(
			$this->order(
				'S-1',
				$created,
				6000,
				'USD',
				null,
				null,
				array(
					$this->line( 'Gift wrap', 5000, 1 ),
					$this->line( 'Mug', 1000, 1, 100 ),
				),
				OrderStatus::Completed
			)
		);

		$products = $store->topProducts( $this->february(), 'USD', 10 );

		$this->assertCount( 1, $products );
		$this->assertSame( 100, $products[0]->productId );
	}

	public function test_top_products_honours_the_limit_and_the_window(): void {
		$store = $this->store();

		$january  = new \DateTimeImmutable( '2024-01-05 10:00:00' );
		$february = new \DateTimeImmutable( '2024-02-05 10:00:00' );

		$store->save( $this->order( 'L-0', $january, 100, 'USD', null, null, array( $this->line( 'Old', 9900, 1, 900 ) ), OrderStatus::Completed ) );
		$store->save( $this->order( 'L-1', $february, 100, 'USD', null, null, array( $this->line( 'A', 3000, 1, 101 ) ), OrderStatus::Completed ) );
		$store->save( $this->order( 'L-2', $february, 100, 'USD', null, null, array( $this->line( 'B', 2000, 1, 102 ) ), OrderStatus::Completed ) );
		$store->save( $this->order( 'L-3', $february, 100, 'USD', null, null, array( $this->line( 'C', 1000, 1, 103 ) ), OrderStatus::Completed ) );

		$products = $store->topProducts( $this->february(), 'USD', 2 );

		$this->assertSame( array( 101, 102 ), array_map( static fn ( $product ): int => $product->productId, $products ) );
	}

	public function test_top_products_refuses_a_currency_that_is_not_iso_4217(): void {
		$this->expectException( ValidationException::class );

		$this->store()->topProducts( $this->february(), 'dollars', 10 );
	}

	public function test_top_products_refuses_a_limit_outside_one_to_one_hundred(): void {
		$this->expectException( ValidationException::class );

		$this->store()->topProducts( $this->february(), 'USD', 0 );
	}

	public function test_top_customers_groups_by_account_and_orders_by_spend(): void {
		$store   = $this->store();
		$created = new \DateTimeImmutable( '2024-02-05 10:00:00' );

		$store->save( $this->order( 'TC-1', $created, 10000, 'USD', null, null, array(), OrderStatus::Completed, 'ada@example.test', 5 ) );
		$store->save( $this->order( 'TC-2', $created, 5000, 'USD', null, null, array(), OrderStatus::Processing, 'ada@example.test', 5 ) );
		$store->save( $this->order( 'TC-3', $created, 20000, 'USD', null, null, array(), OrderStatus::Completed, 'grace@example.test', 6 ) );
		$store->save( $this->order( 'TC-4', $created, 90000, 'USD', null, null, array(), OrderStatus::Pending, 'pending@example.test', 7 ) );
		$store->save( $this->order( 'TC-5', $created, 70000, 'USD', null, null, array(), OrderStatus::Completed, 'guest@example.test', null ) );

		$customers = $store->topCustomers( $this->february(), 'USD', 10 );

		$this->assertSame( array( 6, 5 ), array_map( static fn ( $customer ): int => $customer->customerId, $customers ) );
		$this->assertSame( 2, $customers[1]->orderCount );
		$this->assertSame( 15000, $customers[1]->spend->minorUnits );
		$this->assertSame( 'ada@example.test', $customers[1]->email );
	}

	public function test_top_customers_honours_the_limit(): void {
		$store   = $this->store();
		$created = new \DateTimeImmutable( '2024-02-05 10:00:00' );

		$store->save( $this->order( 'TL-1', $created, 1000, 'USD', null, null, array(), OrderStatus::Completed, 'a@example.test', 5 ) );
		$store->save( $this->order( 'TL-2', $created, 3000, 'USD', null, null, array(), OrderStatus::Completed, 'b@example.test', 6 ) );
		$store->save( $this->order( 'TL-3', $created, 2000, 'USD', null, null, array(), OrderStatus::Completed, 'c@example.test', 7 ) );

		$customers = $store->topCustomers( $this->february(), 'USD', 1 );

		$this->assertCount( 1, $customers );
		$this->assertSame( 6, $customers[0]->customerId );
	}

	public function test_new_customer_count_uses_the_first_paid_order_ever(): void {
		$store = $this->store();

		$january  = new \DateTimeImmutable( '2024-01-05 10:00:00' );
		$february = new \DateTimeImmutable( '2024-02-05 10:00:00' );

		// Customer 5 bought before the window as well, so they are not new.
		$store->save( $this->order( 'NC-1', $january, 1000, 'USD', null, null, array(), OrderStatus::Completed, 'ada@example.test', 5 ) );
		$store->save( $this->order( 'NC-2', $february, 2000, 'USD', null, null, array(), OrderStatus::Completed, 'ada@example.test', 5 ) );

		// Customer 6 first bought inside it.
		$store->save( $this->order( 'NC-3', $february, 3000, 'USD', null, null, array(), OrderStatus::Completed, 'grace@example.test', 6 ) );

		// Customer 7 has only an unpaid order, which is not a first paid order.
		$store->save( $this->order( 'NC-4', $february, 4000, 'USD', null, null, array(), OrderStatus::Pending, 'pending@example.test', 7 ) );

		$this->assertSame( 1, $store->newCustomerCount( $this->february(), 'USD' ) );
	}

	public function test_new_customer_count_ignores_another_currency(): void {
		$store   = $this->store();
		$created = new \DateTimeImmutable( '2024-02-05 10:00:00' );

		$store->save( $this->order( 'NX-1', $created, 1000, 'EUR', null, null, array(), OrderStatus::Completed, 'ada@example.test', 5 ) );

		$this->assertSame( 0, $store->newCustomerCount( $this->february(), 'USD' ) );
	}

	public function test_top_customers_refuses_a_currency_that_is_not_iso_4217(): void {
		$this->expectException( ValidationException::class );

		$this->store()->topCustomers( $this->february(), 'dollars', 10 );
	}

	public function test_top_customers_refuses_a_limit_outside_one_to_ten_thousand(): void {
		$this->expectException( ValidationException::class );

		$this->store()->topCustomers( $this->february(), 'USD', 10001 );
	}
}
