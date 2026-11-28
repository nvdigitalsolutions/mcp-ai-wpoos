<?php

/**
 * Read access to customer accounts.
 *
 * Proposal 164 section 164.184. This is deliberately a *different* store from
 * `OrderStoreInterface`: every aggregate there starts from orders, so it can
 * only describe people who bought. A directory describes the accounts the host
 * knows about, which includes the ones that never ordered — the distinction that
 * makes `woo_customers`' `list` and `search` answerable at all.
 *
 * It is read-only. The reference tool is read-only too, and account creation and
 * deletion are the host's business rather than a tool's: an account is an
 * identity, an audit subject and a login, and a port that wrote them would need a
 * policy for all three.
 *
 * The contract carries no spend, no order count and no recency. Those are
 * order aggregates, they need a currency to be meaningful, and a lookup that
 * quietly mixed the two would be the currency defect this family keeps removing.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\Entity\Customer;
use Nvoos\Core\Domain\Entity\CustomerCollection;
use Nvoos\Core\Domain\Entity\CustomerQuery;

interface CustomerDirectoryInterface
{
    /**
     * One account by its id, or null when it does not exist.
     */
    public function find(int $id): ?Customer;

    /**
     * One account by its email, or null when no account uses it.
     *
     * Emails are matched case-insensitively: the host stores whatever was
     * registered, and a caller should not have to reproduce its case to find
     * an identity.
     */
    public function findByEmail(string $email): ?Customer;

    /**
     * One page of accounts matching the query.
     *
     * The returned collection's `total` is the number of matching accounts
     * across every page, not the size of the page. Ordering is deterministic —
     * the requested field, then the id — so two runs on the same data agree.
     */
    public function query(CustomerQuery $query): CustomerCollection;
}
