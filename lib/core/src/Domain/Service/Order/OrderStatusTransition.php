<?php

/**
 * Which status changes an order may make.
 *
 * Proposal 164 section 164.175. `OrderStatus` is a vocabulary — it names the
 * seven states every order system has in common. This is the policy: which of
 * those moves are a legitimate next step, which is a different question with a
 * different rate of change, which is why they are two types.
 *
 * ## Why a guard at all
 *
 * WooCommerce's `update_status()` will accept anything, so a caller can move an
 * order from `refunded` straight to `processing` and the store will record it.
 * The parity register named that as the reason `bulk_order_status_update` was
 * deferred — *"a bulk update that skips the rule is how an order jumps from
 * refunded to processing"* — and the same hole exists in `woo_orders`. A status
 * is a claim about what happened to money; a claim that contradicts the previous
 * claim is a bug a guard can catch before it is written.
 *
 * ## The graph
 *
 * ```
 * pending    -> processing, on-hold, cancelled, failed
 * on-hold    -> processing, cancelled, failed
 * processing -> on-hold, completed, cancelled, refunded, failed
 * completed  -> refunded
 * cancelled  -> pending
 * failed     -> pending, cancelled
 * refunded   -> (nothing)
 * ```
 *
 * Two edges are deliberate and might otherwise look wrong. `processing` may go
 * back to `on-hold`, because a stock problem discovered after payment is a real
 * thing that happens. `cancelled` and `failed` may return to `pending`, because
 * re-opening a retry is the only way back into the flow; they may not jump
 * straight to `processing`, because that would skip the payment retry the state
 * exists to represent. `refunded` is terminal: money has gone back, and there is
 * no next state that is true.
 *
 * ## Setting the status it already has is not a transition
 *
 * `can( $x, $x )` is true. Re-applying the same intent is a no-op, not a
 * conflict, and a retried call must not fail. Callers that want to distinguish
 * the two look for a change, which `Order::withStatus()` reports by returning the
 * same instance.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Order;

use Nvoos\Core\Domain\Error\ConflictException;
use Nvoos\Core\Domain\ValueObject\OrderStatus;

final class OrderStatusTransition
{
    /**
     * The allowed moves, by current status.
     *
     * @var array<string, list<OrderStatus>>
     */
    private const ALLOWED = array(
        'pending'    => array(
            OrderStatus::Processing,
            OrderStatus::OnHold,
            OrderStatus::Cancelled,
            OrderStatus::Failed,
        ),
        'on-hold'    => array(
            OrderStatus::Processing,
            OrderStatus::Cancelled,
            OrderStatus::Failed,
        ),
        'processing' => array(
            OrderStatus::OnHold,
            OrderStatus::Completed,
            OrderStatus::Cancelled,
            OrderStatus::Refunded,
            OrderStatus::Failed,
        ),
        'completed'  => array(OrderStatus::Refunded),
        'cancelled'  => array(OrderStatus::Pending),
        'failed'     => array(
            OrderStatus::Pending,
            OrderStatus::Cancelled,
        ),
        'refunded'   => array(),
    );

    /**
     * Whether an order may move between two statuses.
     *
     * True when the statuses are the same: applying the same intent twice is a
     * no-op rather than a conflict.
     */
    public static function can(OrderStatus $from, OrderStatus $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::allowedFrom($from), true);
    }

    /**
     * The statuses an order in `$from` may move to, excluding itself.
     *
     * @return list<OrderStatus>
     */
    public static function allowedFrom(OrderStatus $from): array
    {
        return self::ALLOWED[$from->value] ?? array();
    }

    /**
     * Refuse a move that is not allowed.
     *
     * The context carries `from`, `to` and `allowed`, so a caller can answer
     * "what can I do instead" without a second read.
     *
     * @throws ConflictException When the move is not allowed.
     */
    public static function assert(OrderStatus $from, OrderStatus $to): void
    {
        if (self::can($from, $to)) {
            return;
        }

        throw new ConflictException(
            sprintf('An order cannot move from %s to %s.', $from->value, $to->value),
            array(
                'from'    => $from->value,
                'to'      => $to->value,
                'allowed' => array_map(
                    static fn (OrderStatus $status): string => $status->value,
                    self::allowedFrom($from)
                ),
            )
        );
    }
}
