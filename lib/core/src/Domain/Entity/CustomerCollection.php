<?php

/**
 * One page of customers, with the totals a caller needs to iterate.
 *
 * Proposal 164 section 164.184. `total` is the number of matching accounts
 * across every page rather than the size of the page, which is the distinction
 * the WordPress reference lost when it reported `total` from a paged query in
 * some places and the page size in others.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

final readonly class CustomerCollection implements \JsonSerializable
{
    /**
     * @param list<Customer> $items      The page.
     * @param int            $total      Matching accounts across every page.
     * @param int            $page       1-based page number.
     * @param int            $perPage    Accounts per page.
     * @param int            $totalPages Pages at this page size.
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
        public int $totalPages,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array(
            'customers'   => array_map(
                static fn (Customer $customer): array => $customer->jsonSerialize(),
                $this->items
            ),
            'total'       => $this->total,
            'page'        => $this->page,
            'per_page'    => $this->perPage,
            'total_pages' => $this->totalPages,
        );
    }
}
