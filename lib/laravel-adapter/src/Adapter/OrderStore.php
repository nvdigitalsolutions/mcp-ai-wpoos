<?php
/**
 * Laravel adapter: OrderStoreInterface over Eloquent.
 *
 * Proposal 164 section 164.174. The second bound commerce contract, and the store
 * `get_uninvoiced_orders` reads. Everything the deferred order tools need is a
 * filtered page; everything they write is a saved order.
 *
 * ## The filter contract, implemented literally
 *
 * `uninvoicedOnly` becomes `whereNull('invoiced_at')`, which is the `_invoiced
 * NOT EXISTS` query the reference tool performs, expressed as a column.
 *
 * `minTotal` compares only orders in its own currency. An order in another
 * currency cannot be compared to the bound, so it is excluded rather than ranked
 * against it — comparing euros to dollars is not a smaller result set, it is a
 * wrong one.
 *
 * The count and the page are separate queries over the same filter, so `total` is
 * the number of matching orders across every page. A filter applied after the
 * page is a page-size change, and that is the defect this adapter exists to not
 * reproduce.
 *
 * ## Lines are a snapshot
 *
 * `save()` upserts lines by id: a line with an id is updated in place, a line
 * without one is inserted, and a stored line whose id is absent from the incoming
 * set is deleted. Line identity survives an update, because an invoice may
 * reference it. Lines are never derived from a product, so a catalogue change
 * cannot rewrite a past order.
 *
 * ## Portability
 *
 * Written for MariaDB and PostgreSQL alike: no PostgreSQL-only type, no
 * MySQL-only function. Money is an integer minor unit plus an ISO code, and every
 * comparison is a scalar column comparison.
 *
 * @package Nvoos\Laravel
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\OrderStoreInterface;
use Nvoos\Core\Domain\Entity\Order;
use Nvoos\Core\Domain\Entity\OrderCollection;
use Nvoos\Core\Domain\Entity\OrderLine;
use Nvoos\Core\Domain\Entity\OrderQuery;
use Nvoos\Core\Domain\Service\Order\OrderStatusTransition;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OrderStatus;
use Nvoos\Laravel\Models\NvoosOrder;
use Nvoos\Laravel\Models\NvoosOrderLine;

class OrderStore implements OrderStoreInterface {

	/**
	 * One order by storage id, with its lines.
	 */
	public function find( int $id ): ?Order {
		$row = NvoosOrder::query()->with( 'lines' )->find( $id );

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * One page of matching orders, newest first unless asked otherwise.
	 */
	public function query( OrderQuery $query ): OrderCollection {
		$builder = NvoosOrder::query();

		        if ( $query->uninvoicedOnly ) {
		            $builder->whereNull( 'invoiced_at' );
		        }

		        if ( '' !== $query->search ) {
		            // The wildcards in a caller's term are escaped, so a search for a
		            // literal percent sign does not become a match-everything query.
		            $term = '%' . addcslashes( $query->search, '%_\\' ) . '%';

		            $builder->where(
		                static function ( $scoped ) use ( $term ): void {
		                    $scoped->where( 'number', 'like', $term )
		                        ->orWhere( 'billing_email', 'like', $term );
		                }
		            );
		        }

		if ( null !== $query->status ) {
			$builder->where( 'status', $query->status->value );
		}

		if ( null !== $query->customerId ) {
			$builder->where( 'customer_id', $query->customerId );
		}

		if ( null !== $query->createdAfter ) {
			$builder->where( 'created_at', '>=', $query->createdAfter );
		}

		if ( null !== $query->createdBefore ) {
			$builder->where( 'created_at', '<=', $query->createdBefore );
		}

		if ( null !== $query->minTotal ) {
			$builder->where( 'total_currency', $query->minTotal->currency )
				->where( 'total_minor', '>=', $query->minTotal->minorUnits );
		}

		$total = (int) ( clone $builder )->count();

		$rows = $builder
			->with( 'lines' )
			->orderBy( $this->column( $query->orderBy ), $query->order )
			->offset( $query->getOffset() )
			->limit( $query->perPage )
			->get();

		$items = array();

		foreach ( $rows as $row ) {
			$items[] = $this->hydrate( $row );
		}

		return new OrderCollection(
			$items,
			$total,
			$query->page,
			$query->perPage,
			(int) ceil( $total / $query->perPage )
		);
	}

	/**
	 * Persist an order and its lines, returning the stored order.
	 */
	public function save( Order $order ): Order {
		        $model = null === $order->id
			            ? new NvoosOrder()
			            : NvoosOrder::query()->findOrFail( $order->id );

			        // The transition rule is enforced at the boundary as well as in
			        // `Order::withStatus()`, so a caller that builds an order directly
			        // cannot move it anywhere the rule forbids. A status this model does not
			        // name (an imported custom status) is left alone rather than guessed at.
			        if ( null !== $order->id ) {
			            $stored = OrderStatus::tryFrom( (string) $model->status );

			            if ( null !== $stored ) {
			                OrderStatusTransition::assert( $stored, $order->status );
			            }
			        }

		$model->fill(
			array(
				'number'                  => $order->number,
				'status'                  => $order->status->value,
				'total_minor'             => $order->total->minorUnits,
				'total_currency'          => $order->total->currency,
				'subtotal_minor'          => $order->subtotal?->minorUnits,
				'subtotal_currency'       => $order->subtotal?->currency,
				'discount_total_minor'    => $order->discountTotal?->minorUnits,
				'discount_total_currency' => $order->discountTotal?->currency,
				'shipping_total_minor'    => $order->shippingTotal?->minorUnits,
				'shipping_total_currency' => $order->shippingTotal?->currency,
				'tax_total_minor'         => $order->taxTotal?->minorUnits,
				'tax_total_currency'      => $order->taxTotal?->currency,
				'customer_id'             => $order->customerId,
				'billing_name'            => $order->billingName,
				'billing_email'           => $order->billingEmail,
				'invoiced_at'             => $order->invoicedAt,
				'external_ref'            => $order->externalRef,
			)
		);

		// Assigned explicitly so an imported historical order keeps its date;
		// Eloquent only stamps a created_at that is still clean.
		if ( null === $model->created_at ) {
			$model->created_at = $order->createdAt;
		}

		$model->save();

		$this->writeLines( $model, $order->lines );

		$model->load( 'lines' );

		return $this->hydrate( $model );
	}

	/**
	 * Upsert the order's lines by id, then delete the ones no longer present.
	 *
	 * @param array<int, OrderLine> $lines Incoming lines, in display order.
	 */
	private function writeLines( NvoosOrder $order, array $lines ): void {
		$keep = array();

		foreach ( $lines as $position => $line ) {
			$attributes = array(
				'name'                => $line->name,
				'sku'                 => $line->sku,
				'quantity'            => $line->quantity,
				'unit_price_minor'    => $line->unitPrice->minorUnits,
				'unit_price_currency' => $line->unitPrice->currency,
				'line_total_minor'    => $line->lineTotal->minorUnits,
				'line_total_currency' => $line->lineTotal->currency,
				'product_id'          => $line->productId,
				'variant_id'          => $line->variantId,
				'position'            => $position,
			);

			if ( null === $line->id ) {
				$row = $order->lines()->create( $attributes );
			} else {
				// Scoped to this order, so an id from another order cannot be
				// moved here by an update.
				$row = NvoosOrderLine::query()
					->where( 'order_id', $order->id )
					->findOrFail( $line->id );

				$row->fill( $attributes )->save();
			}

			$keep[] = (int) $row->id;
		}

		NvoosOrderLine::query()
			->where( 'order_id', $order->id )
			->when(
				array() !== $keep,
				static fn ( $builder ) => $builder->whereNotIn( 'id', $keep )
			)
			->delete();
	}

	/**
	 * The column a sort field maps to.
	 */
	private function column( string $orderBy ): string {
		return match ( $orderBy ) {
			'total' => 'total_minor',
			'id'    => 'id',
			default => 'created_at',
		};
	}

	/**
	 * A stored row as a domain order.
	 */
	private function hydrate( NvoosOrder $row ): Order {
		$lines = array();

		foreach ( $row->lines as $line ) {
			$lines[] = new OrderLine(
				name: (string) $line->name,
				quantity: (int) $line->quantity,
				unitPrice: Money::fromMinorUnits( (int) $line->unit_price_minor, (string) $line->unit_price_currency ),
				lineTotal: Money::fromMinorUnits( (int) $line->line_total_minor, (string) $line->line_total_currency ),
				id: (int) $line->id,
				sku: null === $line->sku ? null : (string) $line->sku,
				productId: null === $line->product_id ? null : (int) $line->product_id,
				variantId: null === $line->variant_id ? null : (int) $line->variant_id,
			);
		}

		$createdAt = $this->moment( $row->created_at )
			?? $this->moment( $row->updated_at )
			?? new \DateTimeImmutable();

		return new Order(
			id: (int) $row->id,
			number: (string) $row->number,
			status: OrderStatus::from( (string) $row->status ),
			createdAt: $createdAt,
			total: Money::fromMinorUnits( (int) $row->total_minor, (string) $row->total_currency ),
			lines: $lines,
			subtotal: $this->money( $row->subtotal_minor, $row->subtotal_currency ),
			discountTotal: $this->money( $row->discount_total_minor, $row->discount_total_currency ),
			shippingTotal: $this->money( $row->shipping_total_minor, $row->shipping_total_currency ),
			taxTotal: $this->money( $row->tax_total_minor, $row->tax_total_currency ),
			customerId: null === $row->customer_id ? null : (int) $row->customer_id,
			billingName: null === $row->billing_name ? null : (string) $row->billing_name,
			billingEmail: null === $row->billing_email ? null : (string) $row->billing_email,
			invoicedAt: $this->moment( $row->invoiced_at ),
			externalRef: null === $row->external_ref ? null : (string) $row->external_ref,
			updatedAt: $this->moment( $row->updated_at ),
		);
	}

	/**
	 * A nullable stored amount as Money, or null when either half is absent.
	 *
	 * @param mixed $minor    The stored minor units.
	 * @param mixed $currency The stored ISO code.
	 */
	private function money( mixed $minor, mixed $currency ): ?Money {
		if ( null === $minor || null === $currency ) {
			return null;
		}

		return Money::fromMinorUnits( (int) $minor, (string) $currency );
	}

	/**
	 * A stored timestamp as an immutable moment, or null.
	 *
	 * @param mixed $value The stored attribute.
	 */
	private function moment( mixed $value ): ?\DateTimeImmutable {
		return $value instanceof \DateTimeInterface
			? \DateTimeImmutable::createFromInterface( $value )
			: null;
	}
}
