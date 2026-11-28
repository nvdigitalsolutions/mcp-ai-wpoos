<?php

/**
 * One customer account.
 *
 * Proposal 164 section 164.184. This is the identity half of a customer: who
 * they are, not what they bought. Spend, order counts and recency are order
 * aggregate questions, answered by `OrderStoreInterface`, and keeping them out of
 * this value is what lets a lookup list an account that has never ordered.
 *
 * ## One name, because the host has one
 *
 * The reference exposed `username`, `display_name`, `first_name` and
 * `last_name` as four separate fields, read from WooCommerce's customer object,
 * which is a WordPress user with usermeta. The host this runtime ships with
 * stores a single `name` column, so the port reports one name rather than four
 * fields of which three would always be empty. A caller that needs a greeting
 * name can split it; guessing which half is the given name from one string is
 * how a mail merge says "Dear Dr".
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Entity;

final readonly class Customer implements \JsonSerializable
{
    /**
     * @param int                     $id           The account id orders and logs refer to.
     * @param string                  $name         The account name as the host stores it.
     * @param string                  $email        The account email.
     * @param string|null             $role         The host role, in the host vocabulary.
     * @param \DateTimeImmutable|null $registeredAt When the account was created.
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?string $role = null,
        public ?\DateTimeImmutable $registeredAt = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array(
            'id'            => $this->id,
            'name'          => $this->name,
            'email'         => $this->email,
            'role'          => $this->role,
            'registered_at' => $this->registeredAt?->format('Y-m-d'),
        );
    }
}
