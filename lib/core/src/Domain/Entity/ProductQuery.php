<?php

/**
 * Immutable product query specification.
 *
 * Proposal 164 section 164.111. Mirrors {@see ContentQuery} so an adapter author
 * has one pagination idiom to learn. Every filter is optional; the defaults mean
 * "one page of everything, newest first".
 *
 * `sku` and `externalRef` are exact-match lookups rather than search terms: they
 * are the two identities a migration matches on, and a fuzzy match on an
 * identity is not an identity.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

use Nvoos\Core\Domain\ValueObject\ProductStatus;

final readonly class ProductQuery
{
    /**
     * @param string             $search      Free-text search across name, SKU and description.
     * @param ProductStatus|null $status      Filter by canonical status.
     * @param string|null        $sku         Exact variant SKU; returns the owning product.
     * @param string|null        $externalRef Exact source identity.
     * @param string|null        $vendor      Filter by vendor.
     * @param list<string>       $tags        Products carrying all of these tags.
     * @param bool               $inStockOnly Only products with a sellable variant.
     * @param string             $orderBy     One of `name`, `price`, `created_at`, `updated_at`, `id`.
     * @param string             $order       `ASC` or `DESC`.
     * @param int                $page        1-based page number.
     * @param int                $perPage     Items per page, 1-100.
     *
     * @throws \InvalidArgumentException When pagination or the sort direction is invalid.
     */
    public function __construct(
        public string $search = '',
        public ?ProductStatus $status = null,
        public ?string $sku = null,
        public ?string $externalRef = null,
        public ?string $vendor = null,
        public array $tags = array(),
        public bool $inStockOnly = false,
        public string $orderBy = 'created_at',
        public string $order = 'DESC',
        public int $page = 1,
        public int $perPage = 20,
    ) {
        if ($page < 1) {
            throw new \InvalidArgumentException('A page number starts at 1.');
        }

        if ($perPage < 1 || $perPage > 100) {
            throw new \InvalidArgumentException('A page size must be between 1 and 100.');
        }

        if (! in_array(strtoupper($order), array('ASC', 'DESC'), true)) {
            throw new \InvalidArgumentException('Sort direction must be ASC or DESC.');
        }
    }

    /**
     * The zero-based offset for a LIMIT/OFFSET adapter.
     */
    public function getOffset(): int
    {
        return ( $this->page - 1 ) * $this->perPage;
    }

    /**
     * The next page's query, for a paging loop.
     */
    public function nextPage(): self
    {
        return new self(
            search: $this->search,
            status: $this->status,
            sku: $this->sku,
            externalRef: $this->externalRef,
            vendor: $this->vendor,
            tags: $this->tags,
            inStockOnly: $this->inStockOnly,
            orderBy: $this->orderBy,
            order: $this->order,
            page: $this->page + 1,
            perPage: $this->perPage,
        );
    }
}
