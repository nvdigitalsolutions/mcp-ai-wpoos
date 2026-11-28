<?php

/**
 * SQLite session profile.
 *
 * Proposal 167 section 4.3.4. Only the test harness uses SQLite: it has no
 * unprivileged role and no statement timeout, so the read-only guarantee is the
 * connection pragma and the deadline in the executor is the only time control.
 *
 * @package Nvoos\Laravel
 * @since   1.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Database\Engine;

use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;

final class SqliteProfile extends AbstractProfile
{
    public function name(): string
    {
        return ConnectionDescriptor::SQLITE;
    }

    public function begin_statement(): string
    {
        return 'BEGIN DEFERRED';
    }

    /**
     * {@inheritDoc}
     *
     * `PRAGMA` takes no placeholder, but the value is a constant, so there is
     * nothing to bind.
     *
     * @return list<CompiledStatement>
     */
    public function session_statements(
        ConnectionDescriptor $connection,
        ScopeDescriptor $scope,
        DatabaseLimits $limits,
        string $application_name,
        ?string $tenant_id,
    ): array {
        unset($connection, $scope, $limits, $application_name, $tenant_id);

        return array(
            new CompiledStatement('PRAGMA query_only = ON'),
        );
    }

    public function explain_statement(CompiledStatement $statement, bool $analyze): CompiledStatement
    {
        unset($analyze);

        return new CompiledStatement('EXPLAIN QUERY PLAN ' . $statement->sql, $statement->bindings);
    }

    public function supports_explain_analyze(ConnectionDescriptor $connection): bool
    {
        return false;
    }
}
