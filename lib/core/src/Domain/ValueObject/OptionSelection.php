<?php

/**
 * The option values one variant chose, e.g. `Size: M`, `Colour: Red`.
 *
 * Proposal 164 section 164.111. This is the per-variant half of the split
 * described on {@see ProductOption}: the product says which options exist, the
 * variant says which value it picked for each.
 *
 * The map is sorted by option name on construction so that two selections built
 * from different sources — WooCommerce's `attribute_pa_size` postmeta, Shopify's
 * `selectedOptions` array, the `attribute_sku` pivot — compare equal when they
 * mean the same thing. Order is provider noise; identity is not.
 *
 * `key()` is the canonical signature used to match a variant across systems
 * when it has no SKU. It is lowercase and name-sorted, so `Red / M` and
 * `m / red` produce the same key. Two variants of one product that share a key
 * are duplicates, which is a validation error rather than something to store.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\ValueObject;

final readonly class OptionSelection implements \JsonSerializable
{
    /**
     * Option name → chosen value, sorted by name.
     *
     * @var array<string, string>
     */
    public array $values;

    /**
     * @param array<string, string> $values Option name → chosen value.
     *
     * @throws \InvalidArgumentException When a name or value is blank.
     */
    public function __construct(array $values)
    {
        if (array() === $values) {
            throw new \InvalidArgumentException('A variant selection cannot be empty.');
        }

        $normalised = array();

        foreach ($values as $name => $value) {
            if (! is_string($name) || '' === trim($name)) {
                throw new \InvalidArgumentException('A variant selection needs a non-blank option name.');
            }

            if (! is_scalar($value) || '' === trim((string) $value)) {
                throw new \InvalidArgumentException(
                    sprintf('The option "%s" has no selected value.', $name)
                );
            }

            $normalised[trim($name)] = trim((string) $value);
        }

        ksort($normalised, SORT_NATURAL | SORT_FLAG_CASE);

        $this->values = $normalised;
    }

    /**
     * The value chosen for an option, or null when the variant does not set it.
     */
    public function value(string $option): ?string
    {
        foreach ($this->values as $name => $value) {
            if (0 === strcasecmp($name, trim($option))) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The canonical, order-independent signature for this selection.
     */
    public function key(): string
    {
        $parts = array();

        foreach ($this->values as $name => $value) {
            $parts[] = mb_strtolower($name) . '=' . mb_strtolower($value);
        }

        return implode('|', $parts);
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->values;
    }
}
