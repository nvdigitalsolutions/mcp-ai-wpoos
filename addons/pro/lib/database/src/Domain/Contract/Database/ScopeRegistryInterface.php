<?php

/**
 * Scope resolution the read executor needs.
 *
 * Proposal 167 phase P4. A scope is a grant: a set of schemas, tables, columns,
 * a row filter and a tier set, bound to a connection and one or more
 * principals. Resolving one — and refusing when the principal holds none, or the
 * requested one lacks the tier — is the whole contract.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Contract\Database;

use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;

interface ScopeRegistryInterface
{
    /**
     * Every scope this principal holds on a connection.
     *
     * @return list<ScopeDescriptor>
     */
    public function for_principal(PrincipalRef $principal, string $connection): array;

    /**
     * The scope to use, or a refusal.
     *
     * @param string|null $scope_id Explicit scope id, or null for the policy default.
     * @param GrantTier   $tier     Tier the caller needs.
     * @throws \Nvoos\Database\Domain\Service\Database\DatabaseRefusal
     */
    public function resolve_for(
        PrincipalRef $principal,
        string $connection,
        ?string $scope_id,
        GrantTier $tier,
    ): ScopeDescriptor;
}
