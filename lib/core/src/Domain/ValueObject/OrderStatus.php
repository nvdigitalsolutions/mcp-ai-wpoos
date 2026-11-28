<?php

/**
 * The seven states an order can be in, canonically.
 *
 * Proposal 164 section 164.174. WooCommerce is the reference vocabulary, so the
 * cases carry its spellings exactly — including the hyphen in `on-hold` — and the
 * other sources map inward. A provider with a longer list (Shopify adds
 * `archived`, `partially_refunded`; a custom WooCommerce status is any registered
 * slug) maps to the nearest of these seven in its adapter, and keeps the original
 * string in the order's external reference. Seven is the set every order system
 * has in common, which is what a portable order model needs.
 *
 * This enum is deliberately only the status. The transition rules between them
 * belong to the tool that performs a transition, not to the vocabulary: a
 * vocabulary that refuses to name a state is not a vocabulary, it is a policy
 * engine, and the two change for different reasons.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

enum OrderStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case OnHold     = 'on-hold';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';
    case Refunded   = 'refunded';
    case Failed     = 'failed';

    /**
     * The canonical set, for a query filter or a schema enum.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $case): string => $case->value,
            self::cases()
        );
    }
}
