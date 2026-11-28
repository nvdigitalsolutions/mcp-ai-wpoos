<?php

/**
 * Optional port capability: read the scope a call will run under.
 *
 * Proposal 167 phase P4. The main port ({@see DatabaseQueryInterface}) executes
 * a query; it deliberately does not expose grants, because a tool that can read
 * a scope can start making its own policy decisions. One tool genuinely needs
 * the descriptor rather than the decision: `db_profile_table` must skip a
 * column the scope marks sensitive, and "skip" is a shaping rule the executor
 * cannot apply on the tool's behalf.
 *
 * This is a *separate* interface for that reason. A host that does not implement
 * it still serves every other database tool; the profile tool refuses with
 * `db_scope_not_allowed` rather than profiling a column blind. That is why the
 * capability is small and additive — no existing implementer breaks.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Contract\Database;

use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;

interface ScopeAwareInterface
{
    /**
     * The scope descriptor a call would run under, at READ tier.
     *
     * @param string       $connection Connection id.
     * @param string       $scope      Scope id, or '' for the policy default.
     * @param PrincipalRef $principal  Calling principal.
     * @throws \Nvoos\Database\Domain\Service\Database\DatabaseRefusal When no scope is granted.
     */
    public function scopeDescriptor(string $connection, string $scope, PrincipalRef $principal): ScopeDescriptor;
}
