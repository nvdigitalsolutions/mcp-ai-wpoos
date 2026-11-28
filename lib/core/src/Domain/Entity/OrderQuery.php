<?php

/**
 * Immutable order query specification.
 *
 * Proposal 164 section 164.174. Mirrors {@see ProductQuery} so an adapter author
 * has one pagination idiom to learn. Every filter is optional; the defaults mean
 * "one page of everything, newest first".
 *
 * `minTotal` is a `Money` rather than a bare number because a minimum amount
 * without a currency is not a comparison. The reference WordPress tool compares
 * an untyped float against every order's total, which is only meaningful in a
 * single-currency store and silently meaningless in a mixed one. An adapter
 * therefore compares only orders in `minTotal`'s currency and excludes the rest;
 * an order it cannot compare cannot be said to satisfy the filter.
 *
 * `createdAfter` and `createdBefore` are **inclusive** bounds. The caller that
 * wants a whole day passes the end of that day, because "before 2024-01-31"
 * meaning "before midnight at its start" would exclude the day the caller named.
 *
 * `search` matches the order number and the billing email. Those are the two
 * identifiers a support call starts from; a substring match on the billing name
 * returns coincidences rather than answers, and would need a tokenised index to
 * be worth having.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Domain\ValueObject\OrderStatus;

final readonly class OrderQuery
{
    /** The fields a caller may sort by. */
    public const ORDER_BY = array('created_at', 'total', 'id');

    /**
     * @param bool                    $uninvoicedOnly Only orders carrying no invoice marker.
     * @param string                  $search         Free text matched against the order number and billing email.
     * @param Money|null              $minTotal       Minimum total, in the currency to compare in.
     * @param OrderStatus|null        $status         Filter by canonical status.
     * @param int|null                $customerId     Filter by customer.
     * @param \DateTimeImmutable|null $createdAfter   Inclusive lower bound on the creation time.
     * @param \DateTimeImmutable|null $createdBefore  Inclusive upper bound on the creation time.
     * @param string                  $orderBy        One of {@see self::ORDER_BY}.
     * @param string                  $order          `ASC` or `DESC`.
     * @param int                     $page           1-based page number.
     * @param int                     $perPage        Orders per page, 1-100.
     *
     * @throws \InvalidArgumentException When pagination, the sort field or the date range is invalid.
     */
    public function __construct(
        public bool $uninvoicedOnly = false,
        public string $search = '',
        public ?Money $minTotal = null,
        public ?OrderStatus $status = null,
        public ?int $customerId = null,
        public ?\DateTimeImmutable $createdAfter = null,
        public ?\DateTimeImmutable $createdBefore = null,
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

        if (! in_array($orderBy, self::ORDER_BY, true)) {
            throw new \InvalidArgumentException(
                sprintf('Sort by one of: %s.', implode(', ', self::ORDER_BY))
            );
        }

        if (! in_array(strtoupper($order), array('ASC', 'DESC'), true)) {
            throw new \InvalidArgumentException('Sort direction must be ASC or DESC.');
        }

        if (null !== $customerId && $customerId < 1) {
            throw new \InvalidArgumentException('A customer id starts at 1.');
        }

        if (null !== $createdAfter && null !== $createdBefore && $createdAfter > $createdBefore) {
            throw new \InvalidArgumentException('The start of the range is after its end.');
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
            uninvoicedOnly: $this->uninvoicedOnly,
            search: $this->search,
            minTotal: $this->minTotal,
            status: $this->status,
            customerId: $this->customerId,
            createdAfter: $this->createdAfter,
            createdBefore: $this->createdBefore,
            orderBy: $this->orderBy,
            order: $this->order,
            page: $this->page + 1,
            perPage: $this->perPage,
        );
    }
}
