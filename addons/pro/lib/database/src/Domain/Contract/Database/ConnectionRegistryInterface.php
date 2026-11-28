<?php

/**
 * Connection lookup the read executor needs.
 *
 * Proposal 167 phase P4. The WordPress implementation is option-backed and has
 * CRUD, probing and rotation; none of that is the executor's business. It needs
 * "what may this principal see?" and "give me this one or refuse".
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Contract\Database;

use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;

interface ConnectionRegistryInterface
{
    /**
     * Connections this principal may use.
     *
     * @return list<ConnectionDescriptor>
     */
    public function visible_to(PrincipalRef $principal): array;

    /**
     * One connection, or a refusal.
     *
     * @throws \Nvoos\Database\Domain\Service\Database\DatabaseRefusal
     */
    public function get_or_refuse(string $id): ConnectionDescriptor;
}
