<?php

/**
 * Immutable order collection (paginated result set).
 *
 * Proposal 164 section 164.174. The same envelope as {@see ProductCollection},
 * because a caller that already pages a catalogue should not have to learn a
 * second pagination shape to page orders.
 *
 * `total` is the number of orders matching the query across every page, not the
 * size of `items`. A capped list that calls its own length a total is how a
 * billing pass misses invoices.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

final readonly class OrderCollection implements \JsonSerializable
{
    /**
     * @param list<Order> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
        public int $totalPages,
    ) {
    }

    public function hasItems(): bool
    {
        return array() !== $this->items;
    }

    public function hasMorePages(): bool
    {
        return $this->page < $this->totalPages;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array(
            'items'       => $this->items,
            'total'       => $this->total,
            'page'        => $this->page,
            'per_page'    => $this->perPage,
            'total_pages' => $this->totalPages,
        );
    }
}
