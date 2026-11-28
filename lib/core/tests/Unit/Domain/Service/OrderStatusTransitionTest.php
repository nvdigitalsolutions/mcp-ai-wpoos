<?php

/**
 * The order status graph, exhaustively.
 *
 * Proposal 164 section 164.175. The interesting cases are the ones a reviewer
 * would challenge: `refunded` has no way out, `processing` may go back to
 * `on-hold`, and `cancelled` and `failed` return to `pending` but not straight
 * to `processing`.
 *
 * The matrix test walks all 49 pairs rather than asserting the edges it happens
 * to remember, so an edge added in either direction fails a test that is not
 * about that edge.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service;

use Nvoos\Core\Domain\Error\ConflictException;
use Nvoos\Core\Domain\Service\Order\OrderStatusTransition;
use Nvoos\Core\Domain\ValueObject\OrderStatus;
use PHPUnit\Framework\TestCase;

final class OrderStatusTransitionTest extends TestCase
{
    public function test_the_graph_is_exactly_this(): void
    {
        $expected = array(
            'pending'    => array('processing', 'on-hold', 'cancelled', 'failed'),
            'on-hold'    => array('processing', 'cancelled', 'failed'),
            'processing' => array('on-hold', 'completed', 'cancelled', 'refunded', 'failed'),
            'completed'  => array('refunded'),
            'cancelled'  => array('pending'),
            'failed'     => array('pending', 'cancelled'),
            'refunded'   => array(),
        );

        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                $allowed = $from === $to || in_array($to->value, $expected[$from->value], true);

                $this->assertSame(
                    $allowed,
                    OrderStatusTransition::can($from, $to),
                    sprintf('%s -> %s', $from->value, $to->value)
                );
            }
        }
    }

    public function test_the_same_status_is_always_allowed(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $this->assertTrue(OrderStatusTransition::can($status, $status));
        }
    }

    public function test_a_refunded_order_has_no_next_status(): void
    {
        $this->assertSame(array(), OrderStatusTransition::allowedFrom(OrderStatus::Refunded));

        foreach (OrderStatus::cases() as $to) {
            if (OrderStatus::Refunded === $to) {
                continue;
            }

            $this->assertFalse(
                OrderStatusTransition::can(OrderStatus::Refunded, $to),
                sprintf('refunded -> %s', $to->value)
            );
        }
    }

    public function test_allowed_from_never_contains_the_current_status(): void
    {
        foreach (OrderStatus::cases() as $status) {
            $this->assertNotContains($status, OrderStatusTransition::allowedFrom($status));
        }
    }

    public function test_assert_refuses_with_what_was_allowed(): void
    {
        try {
            OrderStatusTransition::assert(OrderStatus::Refunded, OrderStatus::Processing);
            $this->fail('A refunded order was allowed to reopen.');
        } catch (ConflictException $exception) {
            $this->assertSame(409, $exception->getCode());
            $this->assertSame('refunded', $exception->context['from']);
            $this->assertSame('processing', $exception->context['to']);
            $this->assertSame(array(), $exception->context['allowed']);
        }
    }

    public function test_assert_allows_a_legal_move(): void
    {
        OrderStatusTransition::assert(OrderStatus::Pending, OrderStatus::Processing);

        $this->assertTrue(true);
    }
}
