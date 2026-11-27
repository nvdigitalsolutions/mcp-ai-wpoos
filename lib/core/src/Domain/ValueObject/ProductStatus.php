<?php

/**
 * The three states a product can be in, canonically.
 *
 * Proposal 164 section 164.111. Every source spells these differently —
 * WooCommerce uses `publish` / `draft` / `private` / `pending`, Shopify's Admin
 * API uses `ACTIVE` / `DRAFT` / `ARCHIVED` — so the core holds the canonical
 * three and each adapter maps its provider's vocabulary inward.
 *
 * `private` and `pending` are deliberately **not** separate cases: both mean
 * "exists, not visible to shoppers", which is this model's `Draft`. A migration
 * that flattened them into `Draft` loses nothing a storefront can act on; the
 * original string belongs in the adapter's external reference, not here.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

enum ProductStatus: string
{
    case Draft     = 'draft';
    case Published = 'published';
    case Archived  = 'archived';

    /**
     * Whether a shopper-facing surface may show the product.
     */
    public function isVisible(): bool
    {
        return self::Published === $this;
    }

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
