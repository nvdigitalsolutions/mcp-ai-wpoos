<?php

/**
 * An order, as the store recorded it.
 *
 * Proposal 164 section 164.174. This is the aggregate the ecommerce toolkit was
 * missing: a dozen deferred tools bottom out in "there is no order model", and
 * the first of them only needs to know which orders have no invoice marker.
 *
 * ## Totals are stored, not computed
 *
 * Every amount on an order is a snapshot taken at checkout. Tax rates change,
 * catalogue prices change, and an invoice has to keep saying what the customer
 * actually paid — so `total` is a fact, not `sum(lines)`. The component totals
 * are nullable because a source may record only a total; `null` means "the
 * source did not say", which is different from zero and is never invented.
 *
 * ## The invoice marker is a timestamp
 *
 * WooCommerce records invoicing as the mere existence of an `_invoiced` meta key,
 * which answers "has this been invoiced" and nothing else. The model keeps a
 * `\DateTimeImmutable` instead: `null` still answers the same question, and the
 * timestamp additionally answers when, which is the next question a billing pass
 * asks. Nothing is lost, because a marker with no time can always be written as a
 * time.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

use Nvoos\Core\Domain\Error\ConflictException;
use Nvoos\Core\Domain\Service\Order\OrderStatusTransition;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OrderStatus;

final readonly class Order implements \JsonSerializable
{
    /**
     * @param string                  $number         The customer-facing order number, not the storage id.
     * @param \DateTimeImmutable      $createdAt      When the order was placed.
     * @param Money                   $total          What the customer owes or paid, as recorded.
     * @param list<OrderLine>         $lines          The line items, as bought.
     * @param Money|null              $subtotal       Before discounts, shipping and tax, when recorded.
     * @param Money|null              $discountTotal  The total discounted, when recorded.
     * @param Money|null              $shippingTotal  The shipping charged, when recorded.
     * @param Money|null              $taxTotal       The tax charged, when recorded.
     * @param \DateTimeImmutable|null $invoicedAt     When an invoice was raised; null means not invoiced.
     * @param string|null             $externalRef    The source's own identity for this order.
     *
     * @throws \InvalidArgumentException When a component total or a line is in another currency.
     */
    public function __construct(
        public ?int $id,
        public string $number,
        public OrderStatus $status,
        public \DateTimeImmutable $createdAt,
        public Money $total,
        public array $lines = array(),
        public ?Money $subtotal = null,
        public ?Money $discountTotal = null,
        public ?Money $shippingTotal = null,
        public ?Money $taxTotal = null,
        public ?int $customerId = null,
        public ?string $billingName = null,
        public ?string $billingEmail = null,
        public ?\DateTimeImmutable $invoicedAt = null,
        public ?string $externalRef = null,
        public ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->assertSameCurrency($total, $subtotal, 'subtotal');
        $this->assertSameCurrency($total, $discountTotal, 'discount total');
        $this->assertSameCurrency($total, $shippingTotal, 'shipping total');
        $this->assertSameCurrency($total, $taxTotal, 'tax total');

        foreach ($lines as $line) {
            if (! $line instanceof OrderLine) {
                throw new \InvalidArgumentException('Order lines are OrderLine values.');
            }

            if ($line->currency() !== $total->currency) {
                throw new \InvalidArgumentException(
                    sprintf('A line in %s cannot sit on an order in %s.', $line->currency(), $total->currency)
                );
            }
        }
    }

    /**
     * The currency the order is denominated in.
     */
    public function currency(): string
    {
        return $this->total->currency;
    }

    /**
     * How many line items the order holds.
     *
     * Counted from the lines rather than stored separately, so the number cannot
     * drift away from the lines it counts.
     */
    public function itemCount(): int
    {
        return count($this->lines);
    }

    /**
     * Whether an invoice has been raised for this order.
     */
    public function isInvoiced(): bool
    {
        return null !== $this->invoicedAt;
    }

    /**
     * A copy of this order in another status.
     *
     * The guard lives here rather than only in the tool that changes statuses,
     * because a rule enforced at one call site is a rule the next caller skips.
     * Setting the status the order already has returns `$this`, so a caller can
     * tell a no-op from a change by identity.
     *
     * @throws ConflictException When the transition is not allowed.
     */
    public function withStatus(OrderStatus $status): self
    {
        if ($status === $this->status) {
            return $this;
        }

        OrderStatusTransition::assert($this->status, $status);

        return new self(
            id: $this->id,
            number: $this->number,
            status: $status,
            createdAt: $this->createdAt,
            total: $this->total,
            lines: $this->lines,
            subtotal: $this->subtotal,
            discountTotal: $this->discountTotal,
            shippingTotal: $this->shippingTotal,
            taxTotal: $this->taxTotal,
            customerId: $this->customerId,
            billingName: $this->billingName,
            billingEmail: $this->billingEmail,
            invoicedAt: $this->invoicedAt,
            externalRef: $this->externalRef,
            updatedAt: $this->updatedAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array(
            'id'             => $this->id,
            'number'         => $this->number,
            'status'         => $this->status->value,
            'currency'       => $this->currency(),
            'total'          => (float) $this->total->toDecimal(),
            'subtotal'       => null === $this->subtotal ? null : (float) $this->subtotal->toDecimal(),
            'discount_total' => null === $this->discountTotal ? null : (float) $this->discountTotal->toDecimal(),
            'shipping_total' => null === $this->shippingTotal ? null : (float) $this->shippingTotal->toDecimal(),
            'tax_total'      => null === $this->taxTotal ? null : (float) $this->taxTotal->toDecimal(),
            'item_count'     => $this->itemCount(),
            'customer_id'    => $this->customerId,
            'billing_name'   => $this->billingName,
            'billing_email'  => $this->billingEmail,
            'invoiced_at'    => $this->invoicedAt?->format(\DATE_ATOM),
            'created_at'     => $this->createdAt->format(\DATE_ATOM),
            'updated_at'     => $this->updatedAt?->format(\DATE_ATOM),
            'external_ref'   => $this->externalRef,
            'lines'          => $this->lines,
        );
    }

    /**
     * @throws \InvalidArgumentException When a recorded component disagrees with the order's currency.
     */
    private function assertSameCurrency(Money $total, ?Money $component, string $what): void
    {
        if (null !== $component && $component->currency !== $total->currency) {
            throw new \InvalidArgumentException(
                sprintf('The %s is in %s but the order is in %s.', $what, $component->currency, $total->currency)
            );
        }
    }
}
