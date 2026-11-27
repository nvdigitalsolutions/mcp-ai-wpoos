<?php

/**
 * One option a product offers, e.g. `Size` with `S`, `M`, `L`.
 *
 * Proposal 164 section 164.111. The option **names** belong to the product and
 * the option **values** belong to the variant — that split is the one thing all
 * three sources agree on, in their own syntax:
 *
 * - the Laravel model (Thornburgh 2023) puts `attributes.name` on the product
 *   and the chosen `value` on the `attribute_sku` pivot;
 * - WooCommerce serialises the parent product's `_product_attributes` and puts
 *   the chosen term on the variation as `attribute_pa_<name>`;
 * - Shopify returns `product.options[]` and `variant.selectedOptions[]`.
 *
 * Keeping the definition separate from the selection is what lets a variant be
 * validated against its product: a selection naming an option the product does
 * not define, or a value the option does not offer, is a data error the adapter
 * must not invent a home for.
 *
 * Not to be confused with {@see Option}, the optional-value monad that shares this
 * namespace: this class is a product attribute definition, not a container.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

final readonly class ProductOption implements \JsonSerializable
{
    /**
     * @param string       $name   Option name, e.g. `Size`.
     * @param list<string> $values Every selectable value, in display order.
     *
     * @throws \InvalidArgumentException When the name is blank, the value list is
     *                                   empty, or a value repeats.
     */
    public function __construct(
        public string $name,
        public array $values,
    ) {
        $name = trim($name);

        if ('' === $name) {
            throw new \InvalidArgumentException('A product option must have a name.');
        }

        if (array() === $values) {
            throw new \InvalidArgumentException(
                sprintf('Product option "%s" has no values.', $name)
            );
        }

        $seen = array();

        foreach ($values as $value) {
            if (! is_string($value) || '' === trim($value)) {
                throw new \InvalidArgumentException(
                    sprintf('Product option "%s" has a blank value.', $name)
                );
            }

            $key = mb_strtolower(trim($value));

            if (isset($seen[$key])) {
                throw new \InvalidArgumentException(
                    sprintf('Product option "%s" repeats the value "%s".', $name, $value)
                );
            }

            $seen[$key] = true;
        }
    }

    /**
     * Whether this option offers a value, compared case-insensitively.
     */
    public function offers(string $value): bool
    {
        foreach ($this->values as $candidate) {
            if (0 === strcasecmp(trim($candidate), trim($value))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, values: list<string>}
     */
    public function jsonSerialize(): array
    {
        return array(
            'name'   => $this->name,
            'values' => $this->values,
        );
    }
}
