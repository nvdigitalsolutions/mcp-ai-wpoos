<?php

/**
 * Immutable customer query specification.
 *
 * Proposal 164 section 164.184. Mirrors {@see OrderQuery} so an adapter author
 * has one pagination idiom to learn.
 *
 * `search` matches the account name and the email. Those are the two identifiers
 * a support call starts from; matching anything else would need a fuller user
 * profile than the host stores.
 *
 * `role` is a host role name, not a closed enum. The reference advertised
 * `customer`, `subscriber` and `all` in its schema but passed whatever string it
 * was given straight to the query, so `role: administrator` listed administrators
 * despite not being offered. Here the boundary is the declared `list_users`
 * capability rather than an enumeration of roles the host might not have: a
 * caller allowed to list users may list them, and a role the host does not use
 * returns nobody, which is a true answer.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

final readonly class CustomerQuery
{
    /** The fields a caller may sort by. */
    public const ORDER_BY = array('name', 'email', 'id');

    /**
     * @param string      $search  Free text matched against the name and email.
     * @param string|null $role    A host role to filter by, or null for every role.
     * @param string      $orderBy One of {@see self::ORDER_BY}.
     * @param string      $order   `ASC` or `DESC`.
     * @param int         $page    1-based page number.
     * @param int         $perPage Accounts per page, 1-100.
     *
     * @throws \InvalidArgumentException When pagination or the sort field is invalid.
     */
    public function __construct(
        public string $search = '',
        public ?string $role = null,
        public string $orderBy = 'name',
        public string $order = 'ASC',
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
            throw new \InvalidArgumentException('Unknown sort field: ' . $orderBy . '.');
        }

        if (! in_array(strtoupper($order), array('ASC', 'DESC'), true)) {
            throw new \InvalidArgumentException('A sort order is ASC or DESC.');
        }
    }

    /**
     * The row offset the page starts at.
     */
    public function getOffset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }
}
