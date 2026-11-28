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
	): Order {
		return new Order(
			id: $id,
			number: $number,
			status: $status,
			createdAt: $createdAt ?? new \DateTimeImmutable( '2024-01-01 00:00:00' ),
			total: Money::fromMinorUnits( $totalMinor, $currency ),
			lines: $lines,
			customerId: 5,
			billingName: 'Ada Lovelace',
			billingEmail: $billingEmail,
			invoicedAt: $invoicedAt,
		);
	}

	/**
	 * One order line for a fixture.
	 */
	private function line( string $name, int $unitMinor, int $quantity = 1 ): OrderLine {
		return new OrderLine(
			name: $name,
			quantity: $quantity,
			unitPrice: Money::fromMinorUnits( $unitMinor, 'USD' ),
			lineTotal: Money::fromMinorUnits( $unitMinor * $quantity, 'USD' ),
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

		$store->save( $this->order( 'C-1', new \DateTimeImmutable( '2024-12-30 09:00:00' ), 1000 ) );
		$store->save( $this->order( 'C-2', new \DateTimeImmutable( '2025-01-02 09:00:00' ), 2000 ) );
		$store->save( $this->order( 'C-3', new \DateTimeImmutable( '2025-02-10 09:00:00' ), 4000 ) );

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
}
