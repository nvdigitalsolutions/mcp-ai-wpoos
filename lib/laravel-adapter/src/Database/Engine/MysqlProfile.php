<?php

/**
 * MySQL session profile.
 *
 * Proposal 167 section 4.3.2. `max_execution_time` is in milliseconds and covers
 * `SELECT` only, which is exactly the statement class this port admits — the
 * deadline check in the executor covers the rest.
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

class MysqlProfile extends AbstractProfile
{
    /**
     * Lock wait, in seconds.
     */
    protected const LOCK_WAIT_SECONDS = 2;

    public function name(): string
    {
        return ConnectionDescriptor::MYSQL;
    }

    public function begin_statement(): string
    {
        return 'START TRANSACTION READ ONLY';
    }

    /**
     * {@inheritDoc}
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
        $statements = array(
            new CompiledStatement(
                'SET SESSION max_execution_time = :p0',
                array(':p0' => max(1, $limits->timeoutMs)),
            ),
            new CompiledStatement(
                'SET SESSION innodb_lock_wait_timeout = :p0',
                array(':p0' => self::LOCK_WAIT_SECONDS),
            ),
            new CompiledStatement(
                'SET @nvoos_application_name = :p0',
                array(':p0' => $this->applicationName($application_name, 64)),
            ),
        );

        if (null !== $tenant_id && '' !== $tenant_id) {
            $statements[] = new CompiledStatement(
                'SET @nvoos_tenant_id = :p0',
                array(':p0' => $tenant_id),
            );
        }

        return $statements;
    }

    public function explain_statement(CompiledStatement $statement, bool $analyze): CompiledStatement
    {
        unset($analyze);

        return new CompiledStatement('EXPLAIN FORMAT=JSON ' . $statement->sql, $statement->bindings);
    }

    public function supports_explain_analyze(ConnectionDescriptor $connection): bool
    {
        return false;
    }
}
